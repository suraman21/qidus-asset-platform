<?php
declare(strict_types=1);

namespace QidusAsset\Asset;

use DomainException;
use PDO;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;

/**
 * Private Attachment & Evidence Storage for Asset Dept (FR-01, FR-08, FR-09, FR-10, FR-11).
 * Validates extensions, inspects MIME magic bytes, stores outside web root,
 * indexes in `qa_documents` + `qa_document_versions`, and logs `qa_document_access_logs`.
 */
final class AssetAttachmentService
{
    private const MAX_BYTES = 10485760; // 10 MB

    /** @var array<string,list<string>> */
    private const ALLOWED_MIME_EXT = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    /**
     * Store an uploaded file (or base64 payload) safely outside web root.
     *
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function storeUploadedFile(PDO $pdo, string $tmpPath, string $originalName, array $meta, array $actor): array
    {
        if (!is_file($tmpPath)) {
            throw new DomainException('Uploaded file not found.');
        }
        $size = (int)filesize($tmpPath);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new DomainException('Attachment size must be between 1 byte and 10 MB.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = self::detectMime($tmpPath, $ext);
        if (!isset(self::ALLOWED_MIME_EXT[$mime]) || !in_array($ext, self::ALLOWED_MIME_EXT[$mime], true)) {
            throw new DomainException("Unsupported or mismatched file type ({$mime}). Allowed: jpg, png, webp, pdf.");
        }

        $raw = file_get_contents($tmpPath);
        if ($raw === false) {
            throw new DomainException('Unable to read uploaded file.');
        }

        return self::persistBytes($pdo, $raw, $originalName, $ext, $mime, $meta, $actor);
    }

    /**
     * Store a base64-encoded image/PDF attachment (for mobile API uploads).
     *
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function storeBase64(PDO $pdo, string $base64Data, string $originalName, array $meta, array $actor): array
    {
        $clean = preg_replace('#^data:[^;]+;base64,#i', '', trim($base64Data)) ?? '';
        $bin = base64_decode($clean, true);
        if ($bin === false || $bin === '' || strlen($bin) > self::MAX_BYTES) {
            throw new DomainException('Invalid or oversized base64 attachment (max 10 MB).');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $tmp = tempnam(sys_get_temp_dir(), 'qa_att_');
        if ($tmp === false) {
            throw new DomainException('Unable to allocate temporary validation buffer.');
        }
        try {
            file_put_contents($tmp, $bin);
            $mime = self::detectMime($tmp, $ext);
        } finally {
            @unlink($tmp);
        }

        if (!isset(self::ALLOWED_MIME_EXT[$mime]) || !in_array($ext, self::ALLOWED_MIME_EXT[$mime], true)) {
            throw new DomainException("Unsupported or mismatched attachment type ({$mime}).");
        }

        return self::persistBytes($pdo, $bin, $originalName, $ext, $mime, $meta, $actor);
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private static function persistBytes(
        PDO $pdo,
        string $bytes,
        string $originalName,
        string $ext,
        string $mime,
        array $meta,
        array $actor
    ): array {
        $sha = hash('sha256', $bytes);
        $relDir = 'attachments/' . gmdate('Y/m');
        $vaultBase = rtrim((string)qa_config('storage_path', QA_ROOT . '/storage/vault'), '/');
        $targetDir = $vaultBase . '/' . $relDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
            throw new DomainException('Failed to initialize private attachment storage.');
        }

        $safeFile = bin2hex(random_bytes(16)) . '.' . $ext;
        $storageKey = $relDir . '/' . $safeFile;
        $fullPath = $vaultBase . '/' . $storageKey;
        if (file_put_contents($fullPath, $bytes) === false) {
            throw new DomainException('Failed to write attachment to private storage.');
        }

        return Connection::transaction(function (PDO $tx) use ($pdo, $originalName, $storageKey, $mime, $bytes, $sha, $meta, $actor): array {
            $docNum = AssetService::nextSequenceCode($tx, 'DOC', 'qa_documents', 'doc_number');
            $docTypeRaw = (string)($meta['doc_type'] ?? 'other');
            $allowedTypes = ['letter', 'decision', 'contract', 'receipt', 'invoice', 'quotation', 'purchase_order', 'handover', 'inspection_report', 'maintenance_record', 'audit_report', 'other'];
            $docType = in_array($docTypeRaw, $allowedTypes, true) ? $docTypeRaw : 'other';

            $tx->prepare(
                "INSERT INTO qa_documents (
                    doc_number, department_id, doc_type, title_en, document_date,
                    current_version, approval_status, confidentiality, linked_entity_type, linked_entity_id, created_by
                 ) VALUES (
                    :dnum, :dept, :dtype, :title, CURDATE(),
                    1, 'APPROVED', 'department', :ltype, :lid, :by
                 )"
            )->execute([
                ':dnum' => $docNum,
                ':dept' => !empty($actor['department_id']) ? (int)$actor['department_id'] : 1,
                ':dtype' => $docType,
                ':title' => substr(trim((string)($meta['title_en'] ?? $originalName)), 0, 200),
                ':ltype' => $meta['linked_entity_type'] ?? 'asset',
                ':lid' => !empty($meta['linked_entity_id']) ? (int)$meta['linked_entity_id'] : null,
                ':by' => (int)$actor['uid'],
            ]);
            $docId = (int)$tx->lastInsertId();

            $tx->prepare(
                'INSERT INTO qa_document_versions (
                    document_id, version_number, original_filename, storage_key, mime_type,
                    file_size_bytes, sha256_hash, uploaded_by
                 ) VALUES (:did, 1, :orig, :skey, :mime, :sz, :sha, :by)'
            )->execute([
                ':did' => $docId,
                ':orig' => substr(basename($originalName), 0, 255),
                ':skey' => $storageKey,
                ':mime' => $mime,
                ':sz' => strlen($bytes),
                ':sha' => $sha,
                ':by' => (int)$actor['uid'],
            ]);

            $tx->prepare(
                "INSERT INTO qa_document_access_logs (document_id, version_number, user_id, action, ip_address)
                 VALUES (:did, 1, :uid, 'upload', :ip)"
            )->execute([
                ':did' => $docId,
                ':uid' => (int)$actor['uid'],
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            if (($meta['linked_entity_type'] ?? '') === 'asset' && !empty($meta['linked_entity_id'])) {
                $tx->prepare('UPDATE qa_assets SET primary_document_id = COALESCE(primary_document_id, :did) WHERE id = :aid')
                    ->execute([':did' => $docId, ':aid' => (int)$meta['linked_entity_id']]);
            }

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'asset_attachment.upload',
                'entity_type' => 'document',
                'entity_id' => (string)$docId,
                'evidence_sha256' => $sha,
                'after' => ['doc_number' => $docNum, 'linked_entity_type' => $meta['linked_entity_type'] ?? 'asset', 'linked_entity_id' => $meta['linked_entity_id'] ?? null],
            ]);

            return [
                'document_id' => $docId,
                'doc_number' => $docNum,
                'mime_type' => $mime,
                'file_size_bytes' => strlen($bytes),
                'sha256_hash' => $sha,
            ];
        });
    }

    /**
     * Resolve private attachment metadata + physical path and log download access.
     *
     * @param array<string,mixed> $actor
     * @return array{full_path:string,mime_type:string,original_filename:string,sha256_hash:string}
     */
    public static function resolveForDownload(PDO $pdo, int $docId, array $actor): array
    {
        $stmt = $pdo->prepare(
            'SELECT d.id, d.department_id, d.confidentiality, d.linked_entity_type, d.is_archived,
                    v.version_number, v.original_filename, v.storage_key, v.mime_type, v.sha256_hash
             FROM qa_documents d
             JOIN qa_document_versions v ON v.document_id = d.id AND v.version_number = d.current_version
             WHERE d.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $docId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new DomainException('Attachment not found.');
        }

        // Prevent IDOR across departments / restricted finance & leadership documents
        $assetEntityTypes = ['asset', 'asset_maintenance', 'asset_handover', 'asset_inspection', 'asset_disposal'];
        $role = (string)($actor['role'] ?? '');
        $isPrivileged = in_array($role, ['system_admin', 'chairperson', 'deputy_chairperson', 'auditor', 'secretary'], true);
        if (!$isPrivileged) {
            $linkedType = (string)($row['linked_entity_type'] ?? '');
            $conf = (string)($row['confidentiality'] ?? 'department');
            if (!in_array($linkedType, $assetEntityTypes, true)
                || $conf === 'leadership_finance'
                || $conf === 'restricted') {
                throw new DomainException('Access denied for this document scope.');
            }
        }

        $vaultBase = rtrim((string)qa_config('storage_path', QA_ROOT . '/storage/vault'), '/');
        $realBase = realpath($vaultBase);
        $fullPath = $vaultBase . '/' . ltrim((string)$row['storage_key'], '/');
        $realFull = realpath($fullPath);
        if ($realBase === false || $realFull === false || !str_starts_with($realFull, $realBase . DIRECTORY_SEPARATOR) || !is_file($realFull)) {
            throw new DomainException('Stored attachment file missing on disk.');
        }

        $pdo->prepare(
            "INSERT INTO qa_document_access_logs (document_id, version_number, user_id, action, ip_address)
             VALUES (:did, :ver, :uid, 'download', :ip)"
        )->execute([
            ':did' => $docId,
            ':ver' => (int)$row['version_number'],
            ':uid' => (int)$actor['uid'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return [
            'full_path' => $realFull,
            'mime_type' => (string)$row['mime_type'],
            'original_filename' => (string)$row['original_filename'],
            'sha256_hash' => (string)$row['sha256_hash'],
        ];
    }

    private static function detectMime(string $path, string $fallbackExt): string
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi !== false) {
                $detected = finfo_file($fi, $path);
                finfo_close($fi);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }
        return match ($fallbackExt) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
