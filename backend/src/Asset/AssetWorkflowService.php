<?php
declare(strict_types=1);

namespace QidusAsset\Asset;

use DomainException;
use PDO;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\BiometricVerifier;
use QidusAsset\Security\Rbac;

final class AssetWorkflowService
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createRequest(PDO $pdo, array $input, array $actor): array
    {
        $itemName = trim((string)($input['requested_item_name'] ?? ''));
        $reason = trim((string)($input['reason'] ?? ''));
        $qty = max(1, (int)($input['quantity'] ?? 1));
        $deptId = !empty($input['department_id']) ? (int)$input['department_id'] : (int)($actor['department_id'] ?? 1);

        if ($itemName === '' || $reason === '') {
            throw new DomainException('requested_item_name and reason are required.');
        }

        return Connection::transaction(function (PDO $tx) use ($input, $actor, $itemName, $reason, $qty, $deptId): array {
            $yearId = OperationalYearService::assertYearWritable(
                $tx,
                !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null
            );
            $reqNum = AssetService::nextSequenceCode($tx, 'AREQ', 'qa_asset_requests', 'request_number');
            $urgRaw = (string)($input['urgency'] ?? 'normal');
            $urgency = in_array($urgRaw, ['low', 'normal', 'high', 'urgent'], true) ? $urgRaw : 'normal';

            $stmt = $tx->prepare(
                "INSERT INTO qa_asset_requests (
                    request_number, operational_year_id, requester_id, department_id,
                    asset_id, variant_id, requested_item_name, quantity, urgency, needed_by_date, reason, status
                 ) VALUES (
                    :num, :yid, :req_id, :dept_id,
                    :aid, :vid, :iname, :qty, :urg, :need_by, :reason, 'PENDING_DEPARTMENT_HEAD'
                 )"
            );
            $stmt->execute([
                ':num' => $reqNum,
                ':yid' => $yearId,
                ':req_id' => (int)$actor['uid'],
                ':dept_id' => $deptId,
                ':aid' => !empty($input['asset_id']) ? (int)$input['asset_id'] : null,
                ':vid' => !empty($input['variant_id']) ? (int)$input['variant_id'] : null,
                ':iname' => $itemName,
                ':qty' => $qty,
                ':urg' => $urgency,
                ':need_by' => !empty($input['needed_by_date']) ? (string)$input['needed_by_date'] : null,
                ':reason' => $reason,
            ]);
            $id = (int)$tx->lastInsertId();

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => $deptId,
                'action' => 'asset_request.create',
                'entity_type' => 'asset_request',
                'entity_id' => (string)$id,
                'after' => ['request_number' => $reqNum, 'item' => $itemName, 'quantity' => $qty],
            ]);

            $row = $tx->prepare('SELECT * FROM qa_asset_requests WHERE id = :id LIMIT 1');
            $row->execute([':id' => $id]);
            return $row->fetch() ?: ['id' => $id, 'request_number' => $reqNum];
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function departmentHeadDecision(PDO $pdo, int $requestId, string $decision, string $note, array $actor): array
    {
        if (!in_array($decision, ['approve', 'return', 'reject'], true)) {
            throw new DomainException('decision must be approve, return, or reject.');
        }

        return Connection::transaction(function (PDO $tx) use ($requestId, $decision, $note, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_asset_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch();
            if (!$req) {
                throw new DomainException('Asset request not found.');
            }
            if (!in_array($req['status'], ['PENDING_DEPARTMENT_HEAD', 'RETURNED_FOR_CORRECTION'], true)) {
                throw new DomainException('Request is not awaiting department head decision.');
            }

            Rbac::assertNotSelfApproval((int)$actor['uid'], (int)$req['requester_id'], (string)$actor['role']);

            $nextStatus = match ($decision) {
                'approve' => 'PENDING_DEPUTY_CHAIRPERSON',
                'return' => 'RETURNED_FOR_CORRECTION',
                'reject' => 'REJECTED',
            };

            $tx->prepare(
                'UPDATE qa_asset_requests
                 SET status = :st, dept_head_id = :by, dept_head_decision_at = NOW(), dept_head_note = :note
                 WHERE id = :id'
            )->execute([
                ':st' => $nextStatus,
                ':by' => (int)$actor['uid'],
                ':note' => substr(trim($note), 0, 500),
                ':id' => $requestId,
            ]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'asset_request.dept_head_' . $decision,
                'entity_type' => 'asset_request',
                'entity_id' => (string)$requestId,
                'before' => ['status' => $req['status']],
                'after' => ['status' => $nextStatus, 'note' => $note],
            ]);

            return ['id' => $requestId, 'status' => $nextStatus];
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function deputyDecision(PDO $pdo, int $requestId, string $decision, string $note, array $actor): array
    {
        if (!in_array($decision, ['approve', 'return', 'reject'], true)) {
            throw new DomainException('decision must be approve, return, or reject.');
        }

        return Connection::transaction(function (PDO $tx) use ($requestId, $decision, $note, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_asset_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch();
            if (!$req) {
                throw new DomainException('Asset request not found.');
            }
            if ($req['status'] !== 'PENDING_DEPUTY_CHAIRPERSON') {
                throw new DomainException('Request is not awaiting deputy chairperson decision.');
            }

            Rbac::assertNotSelfApproval((int)$actor['uid'], (int)$req['requester_id'], (string)$actor['role']);

            $nextStatus = match ($decision) {
                'approve' => 'APPROVED',
                'return' => 'RETURNED_FOR_CORRECTION',
                'reject' => 'REJECTED',
            };

            $tx->prepare(
                'UPDATE qa_asset_requests
                 SET status = :st, deputy_id = :by, deputy_decision_at = NOW(), deputy_note = :note
                 WHERE id = :id'
            )->execute([
                ':st' => $nextStatus,
                ':by' => (int)$actor['uid'],
                ':note' => substr(trim($note), 0, 500),
                ':id' => $requestId,
            ]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'asset_request.deputy_' . $decision,
                'entity_type' => 'asset_request',
                'entity_id' => (string)$requestId,
                'before' => ['status' => $req['status']],
                'after' => ['status' => $nextStatus, 'note' => $note],
            ]);

            return ['id' => $requestId, 'status' => $nextStatus];
        });
    }

    /**
     * Allow requester to resubmit a request that was RETURNED_FOR_CORRECTION.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function resubmitRequest(PDO $pdo, int $requestId, array $input, array $actor): array
    {
        return Connection::transaction(function (PDO $tx) use ($requestId, $input, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_asset_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $requestId]);
            $req = $stmt->fetch();
            if (!$req) {
                throw new DomainException('Asset request not found.');
            }
            if ((int)$req['requester_id'] !== (int)$actor['uid'] && $actor['role'] !== 'system_admin') {
                throw new DomainException('Only the original requester may resubmit this request.');
            }
            if ($req['status'] !== 'RETURNED_FOR_CORRECTION') {
                throw new DomainException('Only requests returned for correction can be resubmitted.');
            }

            $qty = isset($input['quantity']) ? max(1, (int)$input['quantity']) : (int)$req['quantity'];
            $reason = isset($input['reason']) && trim((string)$input['reason']) !== ''
                ? trim((string)$input['reason']) : (string)$req['reason'];

            $tx->prepare(
                "UPDATE qa_asset_requests
                 SET quantity = :qty, reason = :rsn, status = 'PENDING_DEPARTMENT_HEAD'
                 WHERE id = :id"
            )->execute([':qty' => $qty, ':rsn' => $reason, ':id' => $requestId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'asset_request.resubmit',
                'entity_type' => 'asset_request',
                'entity_id' => (string)$requestId,
                'before' => ['status' => $req['status']],
                'after' => ['status' => 'PENDING_DEPARTMENT_HEAD', 'quantity' => $qty],
            ]);

            return ['id' => $requestId, 'status' => 'PENDING_DEPARTMENT_HEAD'];
        });
    }

    /**
     * Create custody handover with drawn signatures + optional Android Keystore biometric signature.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createHandover(PDO $pdo, array $input, array $actor): array
    {
        $assetId = (int)($input['asset_id'] ?? 0);
        $handoverType = (string)($input['handover_type'] ?? 'issue');
        $receiverName = trim((string)($input['receiver_display_name'] ?? ''));
        $qty = max(1, (int)($input['quantity'] ?? 1));
        $method = (string)($input['verification_method'] ?? 'drawn_signature');
        $clientTxUuid = !empty($input['client_tx_uuid']) ? trim((string)$input['client_tx_uuid']) : null;

        if ($assetId <= 0 || $receiverName === '') {
            throw new DomainException('asset_id and receiver_display_name are required.');
        }
        if (!in_array($handoverType, ['issue', 'return', 'partial_return', 'transfer'], true)) {
            throw new DomainException('Invalid handover_type.');
        }
        if (!in_array($method, ['drawn_signature', 'biometric_keystore', 'pin_fallback', 'both'], true)) {
            throw new DomainException('Invalid verification_method.');
        }

        // Idempotent replay on client_tx_uuid
        if ($clientTxUuid !== null) {
            $dup = $pdo->prepare('SELECT * FROM qa_asset_handovers WHERE client_tx_uuid = :u LIMIT 1');
            $dup->execute([':u' => $clientTxUuid]);
            $existing = $dup->fetch();
            if ($existing) {
                $existing['idempotent_replay'] = true;
                return $existing;
            }
        }

        // Verify Keystore cryptographic signature when required
        $biometricKeyId = null;
        $biometricSigB64 = isset($input['biometric_sig_b64']) ? trim((string)$input['biometric_sig_b64']) : null;
        $payloadSha256 = isset($input['payload_sha256']) ? trim((string)$input['payload_sha256']) : null;

        if (in_array($method, ['biometric_keystore', 'both'], true)) {
            $deviceUuid = trim((string)($input['device_uuid'] ?? $actor['device_uuid'] ?? ''));
            $canonicalPayload = (string)($input['canonical_payload'] ?? '');
            if ($deviceUuid === '' || $biometricSigB64 === null || $biometricSigB64 === '' || $canonicalPayload === '') {
                throw new DomainException('device_uuid, canonical_payload, and biometric_sig_b64 are required for biometric_keystore verification.');
            }
            $dkStmt = $pdo->prepare(
                "SELECT id, user_id, public_key_pem, key_algorithm, status
                 FROM qa_device_keys
                 WHERE device_uuid = :du AND status = 'active'
                 LIMIT 1"
            );
            $dkStmt->execute([':du' => $deviceUuid]);
            $dk = $dkStmt->fetch();
            if (!$dk) {
                throw new DomainException('Approved active device key not found for device_uuid.');
            }
            if (!BiometricVerifier::verifySignature((string)$dk['public_key_pem'], $canonicalPayload, $biometricSigB64, (string)$dk['key_algorithm'])) {
                throw new DomainException('Biometric Keystore signature verification failed.');
            }
            $biometricKeyId = (int)$dk['id'];
            $payloadSha256 = hash('sha256', $canonicalPayload);
        }

        // Process drawn signature hashes/paths if provided as base64 PNGs
        $senderSigPath = null;
        $receiverSigPath = null;
        $senderSigSha = isset($input['sender_sig_sha256']) ? (string)$input['sender_sig_sha256'] : null;
        $receiverSigSha = isset($input['receiver_sig_sha256']) ? (string)$input['receiver_sig_sha256'] : null;

        if (!empty($input['sender_sig_b64'])) {
            [$senderSigPath, $senderSigSha] = self::persistSignatureImage((string)$input['sender_sig_b64'], 'sender');
        }
        if (!empty($input['receiver_sig_b64'])) {
            [$receiverSigPath, $receiverSigSha] = self::persistSignatureImage((string)$input['receiver_sig_b64'], 'receiver');
        }

        return Connection::transaction(function (PDO $tx) use (
            $assetId, $handoverType, $receiverName, $qty, $method, $clientTxUuid,
            $biometricKeyId, $biometricSigB64, $payloadSha256,
            $senderSigPath, $receiverSigPath, $senderSigSha, $receiverSigSha,
            $input, $actor
        ): array {
            $yearId = OperationalYearService::assertYearWritable(
                $tx,
                !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null
            );

            $aStmt = $tx->prepare('SELECT * FROM qa_assets WHERE id = :id LIMIT 1 FOR UPDATE');
            $aStmt->execute([':id' => $assetId]);
            $asset = $aStmt->fetch();
            if (!$asset) {
                throw new DomainException('Asset not found.');
            }

            $variantId = !empty($input['variant_id']) ? (int)$input['variant_id'] : null;
            $delta = in_array($handoverType, ['issue'], true) ? -$qty : ($handoverType === 'transfer' ? 0 : $qty);
            $newQty = (int)$asset['quantity'] + $delta;
            if ($newQty < 0) {
                throw new DomainException('Insufficient asset quantity available for handover.');
            }

            if ($variantId !== null && $delta !== 0) {
                $vStmt = $tx->prepare('SELECT quantity FROM qa_asset_variants WHERE id = :vid AND asset_id = :aid LIMIT 1 FOR UPDATE');
                $vStmt->execute([':vid' => $variantId, ':aid' => $assetId]);
                $var = $vStmt->fetch();
                if (!$var || ((int)$var['quantity'] + $delta) < 0) {
                    throw new DomainException('Insufficient variant quantity for handover.');
                }
                $tx->prepare('UPDATE qa_asset_variants SET quantity = quantity + :d WHERE id = :vid')
                    ->execute([':d' => $delta, ':vid' => $variantId]);
            }

            $newStatus = $asset['status'];
            $newCustodian = $asset['custodian_user_id'];
            if ($asset['tracking_mode'] === 'fixed') {
                if ($handoverType === 'issue' || $handoverType === 'transfer') {
                    $newStatus = 'assigned';
                    $newCustodian = !empty($input['receiver_user_id']) ? (int)$input['receiver_user_id'] : $newCustodian;
                } else {
                    $newStatus = 'in_stock';
                    $newCustodian = null;
                }
            } else {
                $newStatus = AssetService::deriveStockStatus((string)$asset['tracking_mode'], $newQty, (int)$asset['min_quantity'], (string)$asset['status']);
            }

            $tx->prepare('UPDATE qa_assets SET quantity = :q, status = :st, custodian_user_id = :cust WHERE id = :id')
                ->execute([':q' => $newQty, ':st' => $newStatus, ':cust' => $newCustodian, ':id' => $assetId]);

            $hndNum = AssetService::nextSequenceCode($tx, 'HND', 'qa_asset_handovers', 'handover_number');
            $hStmt = $tx->prepare(
                'INSERT INTO qa_asset_handovers (
                    handover_number, operational_year_id, asset_request_id, asset_id, variant_id,
                    handover_type, sender_user_id, receiver_user_id, member_cache_id, receiver_display_name,
                    quantity, condition_at_handover, expected_return_date,
                    sender_sig_path, receiver_sig_path, sender_sig_sha256, receiver_sig_sha256,
                    signed_by_operator_for_recipient, biometric_key_id, biometric_sig_b64, payload_sha256,
                    verification_method, client_tx_uuid, device_tx_at, notes
                 ) VALUES (
                    :hnum, :yid, :req_id, :aid, :vid,
                    :htype, :sender, :receiver, :mcid, :rname,
                    :qty, :cond, :ret_date,
                    :ssig_p, :rsig_p, :ssig_h, :rsig_h,
                    :op_signed, :bkey_id, :bsig, :psha,
                    :vmethod, :cuuid, :dev_at, :notes
                 )'
            );
            $condRaw = (string)($input['condition_at_handover'] ?? 'good');
            $condVal = in_array($condRaw, ['new', 'good', 'fair', 'poor', 'damaged'], true) ? $condRaw : 'good';
            $mcId = !empty($input['member_cache_id']) ? (int)$input['member_cache_id'] : null;
            $extMemId = trim((string)($input['receiver_member_external_id'] ?? $input['external_member_id'] ?? ''));
            if ($mcId === null && $extMemId !== '') {
                $mLookup = $tx->prepare('SELECT id FROM qa_member_sync_cache WHERE external_member_id = :ext LIMIT 1');
                $mLookup->execute([':ext' => $extMemId]);
                $resolvedMc = (int)($mLookup->fetchColumn() ?: 0);
                if ($resolvedMc > 0) {
                    $mcId = $resolvedMc;
                }
            }

            $hStmt->execute([
                ':hnum' => $hndNum,
                ':yid' => !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null,
                ':req_id' => !empty($input['asset_request_id']) ? (int)$input['asset_request_id'] : null,
                ':aid' => $assetId,
                ':vid' => $variantId,
                ':htype' => $handoverType,
                ':sender' => (int)$actor['uid'],
                ':receiver' => !empty($input['receiver_user_id']) ? (int)$input['receiver_user_id'] : null,
                ':mcid' => $mcId,
                ':rname' => substr($receiverName, 0, 150),
                ':qty' => $qty,
                ':cond' => $condVal,
                ':ret_date' => !empty($input['expected_return_date']) ? (string)$input['expected_return_date'] : null,
                ':ssig_p' => $senderSigPath,
                ':rsig_p' => $receiverSigPath,
                ':ssig_h' => $senderSigSha,
                ':rsig_h' => $receiverSigSha,
                ':op_signed' => !empty($input['signed_by_operator_for_recipient']) ? 1 : 0,
                ':bkey_id' => $biometricKeyId,
                ':bsig' => $biometricSigB64,
                ':psha' => $payloadSha256,
                ':vmethod' => $method,
                ':cuuid' => $clientTxUuid,
                ':dev_at' => !empty($input['device_tx_at']) ? (string)$input['device_tx_at'] : null,
                ':notes' => isset($input['notes']) ? substr((string)$input['notes'], 0, 500) : null,
            ]);
            $handoverId = (int)$tx->lastInsertId();

            if ($biometricKeyId !== null) {
                $tx->prepare('UPDATE qa_device_keys SET last_used_at = NOW() WHERE id = :id')
                    ->execute([':id' => $biometricKeyId]);
            }

            if ($delta !== 0) {
                $tx->prepare(
                    "INSERT INTO qa_asset_movements (
                        asset_id, variant_id, operational_year_id, movement_type, quantity_delta,
                        balance_after, reference_type, reference_id, reason, performed_by, device_uuid, movement_date
                     ) VALUES (
                        :aid, :vid, :yid, :mtype, :delta,
                        :bal, 'handover', :ref_id, :reason, :by, :dev, :mdate
                     )"
                )->execute([
                    ':aid' => $assetId,
                    ':vid' => $variantId,
                    ':yid' => $yearId,
                    ':mtype' => $delta < 0 ? 'issue' : 'return',
                    ':delta' => $delta,
                    ':bal' => $newQty,
                    ':ref_id' => $handoverId,
                    ':reason' => "Handover {$hndNum} ({$handoverType}) -> {$receiverName}",
                    ':by' => (int)$actor['uid'],
                    ':dev' => $actor['device_uuid'] ?? null,
                    ':mdate' => gmdate('Y-m-d'),
                ]);
            }

            if (!empty($input['asset_request_id']) && $handoverType === 'issue') {
                $tx->prepare("UPDATE qa_asset_requests SET status = 'FULFILLED' WHERE id = :rid AND status = 'APPROVED'")
                    ->execute([':rid' => (int)$input['asset_request_id']]);
            }

            if (in_array($newStatus, ['low_stock', 'out_of_stock'], true)) {
                AssetService::emitLowStockAlert($tx, $assetId, (string)$asset['asset_code'], (string)$asset['name_en'], $newQty, (int)$asset['min_quantity']);
            }

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'device_uuid' => $actor['device_uuid'] ?? null,
                'device_key_id' => $biometricKeyId,
                'action' => 'asset.handover_' . $handoverType,
                'entity_type' => 'asset_handover',
                'entity_id' => (string)$handoverId,
                'evidence_sha256' => $payloadSha256 ?? $receiverSigSha ?? $senderSigSha,
                'after' => ['handover_number' => $hndNum, 'asset_id' => $assetId, 'quantity' => $qty, 'method' => $method],
            ]);

            $res = $tx->prepare('SELECT * FROM qa_asset_handovers WHERE id = :id LIMIT 1');
            $res->execute([':id' => $handoverId]);
            return $res->fetch() ?: ['id' => $handoverId, 'handover_number' => $hndNum];
        });
    }

    /**
     * Save drawn signature PNG outside web root if configured, returning [relative_path, sha256].
     *
     * @return array{0:string,1:string}
     */
    private static function persistSignatureImage(string $base64, string $roleLabel): array
    {
        $clean = preg_replace('#^data:image/\w+;base64,#i', '', trim($base64)) ?? '';
        $bin = base64_decode($clean, true);
        if ($bin === false || $bin === '' || strlen($bin) > 524288) {
            throw new DomainException('Invalid or oversized signature payload (max 512 KB).');
        }
        $imgInfo = @getimagesizefromstring($bin);
        $allowedImgTypes = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
        if ($imgInfo === false || !in_array($imgInfo[2] ?? 0, $allowedImgTypes, true)) {
            throw new DomainException('Signature payload must be a valid PNG, JPEG, or WebP image.');
        }
        $sha = hash('sha256', $bin);
        $baseDir = rtrim((string)qa_config('storage_path', QA_ROOT . '/storage/vault'), '/') . '/signatures/' . gmdate('Y/m');
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
            throw new DomainException('Unable to initialize signature storage directory.');
        }
        $filename = sprintf('%s_%s_%s.png', $roleLabel, gmdate('YmdHis'), substr($sha, 0, 12));
        $fullPath = $baseDir . '/' . $filename;
        if (file_put_contents($fullPath, $bin) === false) {
            throw new DomainException('Failed to write signature image.');
        }
        return ['signatures/' . gmdate('Y/m') . '/' . $filename, $sha];
    }
}
