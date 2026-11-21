<?php
declare(strict_types=1);

namespace QidusAsset\Finance;

use DomainException;
use PDO;
use QidusAsset\Asset\AssetService;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\EthiopianCalendar;

/**
 * Department 2C: Controlled Document Vault with Version History,
 * Confidentiality Scopes, Approval Workflow, and Audited Downloads.
 */
final class DocumentVaultService
{
    public const MAX_BYTES = 10485760;

    private const ALLOWED_MIME_EXT = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createFolder(PDO $pdo, array $input, array $actor): array
    {
        $nameEn = trim((string)($input['name_en'] ?? ''));
        if ($nameEn === '') {
            throw new DomainException('Folder name_en is required.');
        }
        $pdo->prepare(
            'INSERT INTO qa_doc_folders (parent_id, department_id, ec_year, name_en, name_am, is_restricted)
             VALUES (:pid, :did, :ey, :ne, :na, :res)'
        )->execute([
            ':pid' => !empty($input['parent_id']) ? (int)$input['parent_id'] : null,
            ':did' => !empty($input['department_id']) ? (int)$input['department_id'] : 2,
            ':ey' => !empty($input['ec_year']) ? (int)$input['ec_year'] : EthiopianCalendar::today((string)qa_config('timezone'))['year'],
            ':ne' => substr($nameEn, 0, 120),
            ':na' => $input['name_am'] ?? null,
            ':res' => !empty($input['is_restricted']) ? 1 : 0,
        ]);
        return ['id' => (int)$pdo->lastInsertId(), 'name_en' => $nameEn];
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function uploadNewDocument(PDO $pdo, string $tmpPath, string $originalName, array $meta, array $actor): array
    {
        if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            throw new DomainException('Uploaded file not found.');
        }
        $bytes = file_get_contents($tmpPath);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new DomainException('Invalid or oversized document (max 10 MB).');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = self::detectMime($tmpPath, $ext);
        if (!isset(self::ALLOWED_MIME_EXT[$mime]) || !in_array($ext, self::ALLOWED_MIME_EXT[$mime], true)) {
            throw new DomainException("Unsupported or mismatched document type ({$mime}).");
        }

        return self::createDocAndVersion($pdo, $bytes, $originalName, $ext, $mime, $meta, $actor);
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function uploadBase64Document(PDO $pdo, string $base64, string $originalName, array $meta, array $actor): array
    {
        $clean = preg_replace('#^data:[\w/+.-]+;base64,#i', '', trim($base64)) ?? '';
        $bytes = base64_decode($clean, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new DomainException('Invalid or oversized base64 document payload.');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $tmp = tempnam(sys_get_temp_dir(), 'qadoc_');
        if ($tmp === false) {
            throw new DomainException('Failed to create temp buffer.');
        }
        file_put_contents($tmp, $bytes);
        $mime = self::detectMime($tmp, $ext);
        @unlink($tmp);
        if (!isset(self::ALLOWED_MIME_EXT[$mime]) || !in_array($ext, self::ALLOWED_MIME_EXT[$mime], true)) {
            throw new DomainException("Unsupported or mismatched document type ({$mime}).");
        }

        return self::createDocAndVersion($pdo, $bytes, $originalName, $ext, $mime, $meta, $actor);
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function addVersion(PDO $pdo, int $docId, string $tmpPath, string $originalName, string $changeNote, array $actor): array
    {
        if (!is_file($tmpPath)) {
            throw new DomainException('Uploaded version file not found.');
        }
        $bytes = file_get_contents($tmpPath);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new DomainException('Invalid or oversized document version (max 10 MB).');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = self::detectMime($tmpPath, $ext);
        if (!isset(self::ALLOWED_MIME_EXT[$mime]) || !in_array($ext, self::ALLOWED_MIME_EXT[$mime], true)) {
            throw new DomainException("Unsupported or mismatched document type ({$mime}).");
        }

        [$storageKey, $sha] = self::writeVaultBytes($bytes, $ext);

        return Connection::transaction(function (PDO $tx) use ($docId, $originalName, $storageKey, $mime, $bytes, $sha, $changeNote, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_documents WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $docId]);
            $doc = $stmt->fetch();
            if (!$doc) {
                throw new DomainException('Document not found.');
            }
            $nextVer = ((int)$doc['current_version']) + 1;

            $tx->prepare(
                'INSERT INTO qa_document_versions (
                    document_id, version_number, original_filename, storage_key, mime_type,
                    file_size_bytes, sha256_hash, uploaded_by, change_note
                 ) VALUES (:did, :ver, :orig, :skey, :mime, :sz, :sha, :by, :note)'
            )->execute([
                ':did' => $docId,
                ':ver' => $nextVer,
                ':orig' => substr(basename($originalName), 0, 255),
                ':skey' => $storageKey,
                ':mime' => $mime,
                ':sz' => strlen($bytes),
                ':sha' => $sha,
                ':by' => (int)$actor['uid'],
                ':note' => substr(trim($changeNote), 0, 255),
            ]);

            $tx->prepare("UPDATE qa_documents SET current_version = :ver, approval_status = 'PENDING_APPROVAL' WHERE id = :id")
                ->execute([':ver' => $nextVer, ':id' => $docId]);

            $tx->prepare(
                "INSERT INTO qa_document_access_logs (document_id, version_number, user_id, action, ip_address)
                 VALUES (:did, :ver, :uid, 'upload', :ip)"
            )->execute([':did' => $docId, ':ver' => $nextVer, ':uid' => (int)$actor['uid'], ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'document.add_version',
                'entity_type' => 'document',
                'entity_id' => (string)$docId,
                'evidence_sha256' => $sha,
                'after' => ['version_number' => $nextVer],
            ]);

            return ['document_id' => $docId, 'version_number' => $nextVer, 'sha256_hash' => $sha];
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function decideDocument(PDO $pdo, int $docId, string $decision, array $actor): array
    {
        if (!in_array($decision, ['approve', 'return'], true)) {
            throw new DomainException('decision must be approve or return.');
        }

        return Connection::transaction(function (PDO $tx) use ($docId, $decision, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_documents WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $docId]);
            $doc = $stmt->fetch();
            if (!$doc) {
                throw new DomainException('Document not found.');
            }
            Rbac::assertNotSelfApproval((int)$actor['uid'], (int)$doc['created_by'], (string)$actor['role']);

            $nextStatus = $decision === 'approve' ? 'APPROVED' : 'RETURNED';
            $tx->prepare('UPDATE qa_documents SET approval_status = :st, approved_by = :by, approved_at = NOW() WHERE id = :id')
                ->execute([':st' => $nextStatus, ':by' => (int)$actor['uid'], ':id' => $docId]);

            $tx->prepare(
                'INSERT INTO qa_document_access_logs (document_id, version_number, user_id, action, ip_address)
                 VALUES (:did, :ver, :uid, :act, :ip)'
            )->execute([
                ':did' => $docId,
                ':ver' => (int)$doc['current_version'],
                ':uid' => (int)$actor['uid'],
                ':act' => $decision,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'document.' . $decision,
                'entity_type' => 'document',
                'entity_id' => (string)$docId,
                'after' => ['approval_status' => $nextStatus],
            ]);

            return ['id' => $docId, 'approval_status' => $nextStatus];
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function toggleArchive(PDO $pdo, int $docId, bool $archive, array $actor): array
    {
        $pdo->prepare('UPDATE qa_documents SET is_archived = :arch, archived_by = :by, archived_at = NOW() WHERE id = :id')
            ->execute([':arch' => $archive ? 1 : 0, ':by' => (int)$actor['uid'], ':id' => $docId]);
        $pdo->prepare(
            'INSERT INTO qa_document_access_logs (document_id, version_number, user_id, action, ip_address)
             VALUES (:did, 1, :uid, :act, :ip)'
        )->execute([
            ':did' => $docId,
            ':uid' => (int)$actor['uid'],
            ':act' => $archive ? 'archive' : 'restore',
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        return ['id' => $docId, 'is_archived' => $archive];
    }

    /**
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public static function listDocuments(PDO $pdo, array $filters, array $actor): array
    {
        $role = (string)($actor['role'] ?? '');
        $canRestricted = in_array($role, ['system_admin', 'chairperson', 'deputy_chairperson', 'auditor', 'finance_head', 'document_controller'], true);
        $canFinance = $canRestricted || in_array($role, ['finance_staff', 'procurement_officer'], true);

        $where = ['1=1'];
        $params = [];
        if (!$canRestricted) {
            if ($canFinance) {
                $where[] = "d.confidentiality <> 'restricted'";
            } else {
                $where[] = "d.confidentiality = 'department'";
            }
        }
        if (!empty($filters['doc_type'])) {
            $where[] = 'd.doc_type = :dt';
            $params[':dt'] = (string)$filters['doc_type'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(d.doc_number LIKE :q1 OR d.title_en LIKE :q2 OR d.external_ref_no LIKE :q3)';
            $qVal = '%' . trim((string)$filters['q']) . '%';
            $params[':q1'] = $qVal;
            $params[':q2'] = $qVal;
            $params[':q3'] = $qVal;
        }
        $showArchived = !empty($filters['archived']) ? 1 : 0;
        $where[] = 'd.is_archived = :arch';
        $params[':arch'] = $showArchived;

        $stmt = $pdo->prepare(
            'SELECT d.*, f.name_en AS folder_name
             FROM qa_documents d
             LEFT JOIN qa_doc_folders f ON f.id = d.folder_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT 100'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $actor
     * @return array{full_path:string,mime_type:string,original_filename:string,sha256_hash:string}
     */
    public static function resolveForDownload(PDO $pdo, int $docId, array $actor, ?int $versionNumber = null): array
    {
        $sql = $versionNumber !== null
            ? 'SELECT d.id, d.confidentiality, v.version_number, v.original_filename, v.storage_key, v.mime_type, v.sha256_hash
               FROM qa_documents d JOIN qa_document_versions v ON v.document_id = d.id AND v.version_number = :ver WHERE d.id = :id LIMIT 1'
            : 'SELECT d.id, d.confidentiality, v.version_number, v.original_filename, v.storage_key, v.mime_type, v.sha256_hash
               FROM qa_documents d JOIN qa_document_versions v ON v.document_id = d.id AND v.version_number = d.current_version WHERE d.id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $params = [':id' => $docId];
        if ($versionNumber !== null) {
            $params[':ver'] = $versionNumber;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            throw new DomainException('Document version not found.');
        }

        $role = (string)($actor['role'] ?? '');
        $conf = (string)($row['confidentiality'] ?? 'department');
        $canRestricted = in_array($role, ['system_admin', 'chairperson', 'deputy_chairperson', 'auditor', 'finance_head', 'document_controller'], true);
        $canFinance = $canRestricted || in_array($role, ['finance_staff', 'procurement_officer'], true);
        if (($conf === 'restricted' && !$canRestricted) || ($conf === 'leadership_finance' && !$canFinance)) {
            throw new DomainException('Access denied for document confidentiality level.');
        }

        $vaultBase = rtrim((string)qa_config('storage_path', QA_ROOT . '/storage/vault'), '/');
        $realBase = realpath($vaultBase);
        $realFull = realpath($vaultBase . '/' . ltrim((string)$row['storage_key'], '/'));
        if ($realBase === false || $realFull === false || !str_starts_with($realFull, $realBase . DIRECTORY_SEPARATOR) || !is_file($realFull)) {
            throw new DomainException('Stored document file missing on disk.');
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

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private static function createDocAndVersion(
        PDO $pdo,
        string $bytes,
        string $originalName,
        string $ext,
        string $mime,
        array $meta,
        array $actor
    ): array {
        [$storageKey, $sha] = self::writeVaultBytes($bytes, $ext);

        return Connection::transaction(function (PDO $tx) use ($originalName, $storageKey, $mime, $bytes, $sha, $meta, $actor): array {
            $docNum = AssetService::nextSequenceCode($tx, 'DOC', 'qa_documents', 'doc_number');
            $docTypeRaw = (string)($meta['doc_type'] ?? 'other');
            $allowedTypes = ['letter', 'decision', 'contract', 'receipt', 'invoice', 'quotation', 'purchase_order', 'handover', 'inspection_report', 'maintenance_record', 'audit_report', 'other'];
            $docType = in_array($docTypeRaw, $allowedTypes, true) ? $docTypeRaw : 'other';
            $confRaw = (string)($meta['confidentiality'] ?? 'department');
            $conf = in_array($confRaw, ['department', 'leadership_finance', 'restricted'], true) ? $confRaw : 'department';
            $ecYear = !empty($meta['ec_year']) ? (int)$meta['ec_year'] : EthiopianCalendar::today((string)qa_config('timezone'))['year'];

            $tx->prepare(
                "INSERT INTO qa_documents (
                    doc_number, external_ref_no, folder_id, department_id, doc_type,
                    title_en, title_am, description, tags, document_date, ec_year,
                    current_version, approval_status, confidentiality, linked_entity_type, linked_entity_id, created_by
                 ) VALUES (
                    :dnum, :xref, :fid, :dept, :dtype,
                    :te, :ta, :descr, :tags, :ddate, :ey,
                    1, 'PENDING_APPROVAL', :conf, :ltype, :lid, :by
                 )"
            )->execute([
                ':dnum' => $docNum,
                ':xref' => $meta['external_ref_no'] ?? null,
                ':fid' => !empty($meta['folder_id']) ? (int)$meta['folder_id'] : null,
                ':dept' => !empty($meta['department_id']) ? (int)$meta['department_id'] : 2,
                ':dtype' => $docType,
                ':te' => substr(trim((string)($meta['title_en'] ?? $originalName)), 0, 200),
                ':ta' => $meta['title_am'] ?? null,
                ':descr' => $meta['description'] ?? null,
                ':tags' => $meta['tags'] ?? null,
                ':ddate' => !empty($meta['document_date']) ? (string)$meta['document_date'] : gmdate('Y-m-d'),
                ':ey' => $ecYear,
                ':conf' => $conf,
                ':ltype' => $meta['linked_entity_type'] ?? 'finance_doc',
                ':lid' => !empty($meta['linked_entity_id']) ? (int)$meta['linked_entity_id'] : null,
                ':by' => (int)$actor['uid'],
            ]);
            $docId = (int)$tx->lastInsertId();

            $tx->prepare(
                'INSERT INTO qa_document_versions (
                    document_id, version_number, original_filename, storage_key, mime_type,
                    file_size_bytes, sha256_hash, uploaded_by, change_note
                 ) VALUES (:did, 1, :orig, :skey, :mime, :sz, :sha, :by, :note)'
            )->execute([
                ':did' => $docId,
                ':orig' => substr(basename($originalName), 0, 255),
                ':skey' => $storageKey,
                ':mime' => $mime,
                ':sz' => strlen($bytes),
                ':sha' => $sha,
                ':by' => (int)$actor['uid'],
                ':note' => 'Initial upload',
            ]);

            $tx->prepare(
                "INSERT INTO qa_document_access_logs (document_id, version_number, user_id, action, ip_address)
                 VALUES (:did, 1, :uid, 'upload', :ip)"
            )->execute([':did' => $docId, ':uid' => (int)$actor['uid'], ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'document.upload',
                'entity_type' => 'document',
                'entity_id' => (string)$docId,
                'evidence_sha256' => $sha,
                'after' => ['doc_number' => $docNum, 'confidentiality' => $conf],
            ]);

            return ['document_id' => $docId, 'doc_number' => $docNum, 'mime_type' => $mime, 'sha256_hash' => $sha];
        });
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function writeVaultBytes(string $bytes, string $ext): array
    {
        $sha = hash('sha256', $bytes);
        $relDir = 'documents/' . gmdate('Y/m');
        $vaultBase = rtrim((string)qa_config('storage_path', QA_ROOT . '/storage/vault'), '/');
        $targetDir = $vaultBase . '/' . $relDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
            throw new DomainException('Failed to initialize document vault directory.');
        }
        $storageKey = $relDir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (file_put_contents($vaultBase . '/' . $storageKey, $bytes) === false) {
            throw new DomainException('Failed to write document bytes.');
        }
        return [$storageKey, $sha];
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
