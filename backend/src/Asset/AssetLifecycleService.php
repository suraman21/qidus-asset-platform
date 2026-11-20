<?php
declare(strict_types=1);

namespace QidusAsset\Asset;

use DomainException;
use PDO;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\Rbac;

final class AssetLifecycleService
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createEvent(PDO $pdo, array $input, array $actor): array
    {
        $nameEn = trim((string)($input['name_en'] ?? ''));
        $eventDate = trim((string)($input['event_date'] ?? gmdate('Y-m-d')));
        if ($nameEn === '') {
            throw new DomainException('name_en is required for event creation.');
        }

        return Connection::transaction(function (PDO $tx) use ($input, $actor, $nameEn, $eventDate): array {
            $code = AssetService::nextSequenceCode($tx, 'EVT', 'qa_events', 'event_code');
            $stmt = $tx->prepare(
                "INSERT INTO qa_events (
                    event_code, template_id, operational_year_id, name_en, name_am,
                    event_date, expected_return_date, status, created_by
                 ) VALUES (
                    :code, :tpl, :yid, :ne, :na,
                    :edate, :rdate, :st, :by
                 )"
            );
            $status = in_array(($input['status'] ?? 'prepared'), ['draft', 'prepared', 'active'], true)
                ? (string)$input['status'] : 'prepared';

            $stmt->execute([
                ':code' => $code,
                ':tpl' => !empty($input['template_id']) ? (int)$input['template_id'] : null,
                ':yid' => !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null,
                ':ne' => $nameEn,
                ':na' => $input['name_am'] ?? null,
                ':edate' => $eventDate,
                ':rdate' => !empty($input['expected_return_date']) ? (string)$input['expected_return_date'] : null,
                ':st' => $status,
                ':by' => (int)$actor['uid'],
            ]);
            $eventId = (int)$tx->lastInsertId();

            // Optionally register default station if provided
            if (!empty($input['stations']) && is_array($input['stations'])) {
                foreach ($input['stations'] as $st) {
                    if (!is_array($st) || empty($st['station_name'])) {
                        continue;
                    }
                    self::insertStation($tx, $eventId, $st);
                }
            }

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'event.create',
                'entity_type' => 'event',
                'entity_id' => (string)$eventId,
                'after' => ['event_code' => $code, 'name_en' => $nameEn],
            ]);

            return ['id' => $eventId, 'event_code' => $code, 'status' => $status];
        });
    }

    /**
     * @param array<string,mixed> $stationInput
     * @return array<string,mixed>
     */
    public static function insertStation(PDO $pdo, int $eventId, array $stationInput): array
    {
        $name = trim((string)($stationInput['station_name'] ?? ''));
        if ($name === '') {
            throw new DomainException('station_name is required.');
        }
        $code = trim((string)($stationInput['station_code'] ?? ('ST-' . strtoupper(bin2hex(random_bytes(3))))));
        $stmt = $pdo->prepare(
            'INSERT INTO qa_event_stations (event_id, station_code, station_name, operator_user_id, device_uuid)
             VALUES (:eid, :code, :name, :op, :dev)'
        );
        $stmt->execute([
            ':eid' => $eventId,
            ':code' => substr($code, 0, 32),
            ':name' => substr($name, 0, 120),
            ':op' => !empty($stationInput['operator_user_id']) ? (int)$stationInput['operator_user_id'] : null,
            ':dev' => $stationInput['device_uuid'] ?? null,
        ]);
        return ['id' => (int)$pdo->lastInsertId(), 'station_code' => $code, 'station_name' => $name];
    }

    /**
     * Partition stock into a station allocation to prevent multi-device over-issue.
     *
     * @return array<string,mixed>
     */
    public static function allocateStationStock(PDO $pdo, int $eventId, int $stationId, int $assetId, int $qty, ?int $variantId = null): array
    {
        if ($qty <= 0) {
            throw new DomainException('allocated_qty must be positive.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO qa_event_allocations (event_id, station_id, asset_id, variant_id, allocated_qty)
             VALUES (:eid, :sid, :aid, :vid, :qty)'
        );
        $stmt->execute([
            ':eid' => $eventId,
            ':sid' => $stationId,
            ':aid' => $assetId,
            ':vid' => $variantId,
            ':qty' => $qty,
        ]);
        return ['id' => (int)$pdo->lastInsertId(), 'allocated_qty' => $qty];
    }

    /**
     * Download offline-ready event package (event metadata, stations, allocations, eligible participants).
     *
     * @return array<string,mixed>
     */
    public static function getEventPackage(PDO $pdo, int $eventId): array
    {
        $ev = $pdo->prepare('SELECT * FROM qa_events WHERE id = :id LIMIT 1');
        $ev->execute([':id' => $eventId]);
        $event = $ev->fetch();
        if (!$event) {
            throw new DomainException('Event not found.');
        }

        $stations = $pdo->prepare('SELECT * FROM qa_event_stations WHERE event_id = :eid AND is_active = 1 ORDER BY id ASC');
        $stations->execute([':eid' => $eventId]);

        $allocs = $pdo->prepare(
            'SELECT al.*, a.asset_code, a.name_en, a.name_am, a.qr_token
             FROM qa_event_allocations al
             JOIN qa_assets a ON a.id = al.asset_id
             WHERE al.event_id = :eid
             ORDER BY al.id ASC'
        );
        $allocs->execute([':eid' => $eventId]);

        $parts = $pdo->prepare(
            'SELECT p.*, m.external_member_id, m.member_code, m.name_en, m.name_am, m.section_name
             FROM qa_event_participants p
             JOIN qa_member_sync_cache m ON m.id = p.member_cache_id
             WHERE p.event_id = :eid AND m.is_tombstoned = 0'
        );
        $parts->execute([':eid' => $eventId]);

        return [
            'event' => $event,
            'stations' => $stations->fetchAll(),
            'allocations' => $allocs->fetchAll(),
            'participants' => $parts->fetchAll(),
        ];
    }

    /**
     * Batch-sync offline rapid issue/return transactions from mobile station.
     * Never discards offline events; flags over-allocated transactions as `needs_review`.
     *
     * @param list<array<string,mixed>> $transactions
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function syncEventBatch(PDO $pdo, int $eventId, array $transactions, array $actor): array
    {
        $accepted = 0;
        $replayed = 0;
        $conflicts = 0;
        $results = [];

        foreach ($transactions as $txItem) {
            if (!is_array($txItem)) {
                continue;
            }
            $clientUuid = trim((string)($txItem['client_tx_uuid'] ?? ''));
            $stationId = (int)($txItem['station_id'] ?? 0);
            $memberCacheId = (int)($txItem['member_cache_id'] ?? 0);
            $assetId = (int)($txItem['asset_id'] ?? 0);
            $txType = (string)($txItem['tx_type'] ?? 'issue');
            $qty = max(1, (int)($txItem['quantity'] ?? 1));

            if ($clientUuid === '' || $stationId <= 0 || $memberCacheId <= 0 || $assetId <= 0) {
                throw new DomainException('Each event transaction requires client_tx_uuid, station_id, member_cache_id, and asset_id.');
            }
            if (!in_array($txType, ['issue', 'return', 'partial_return', 'damaged', 'missing'], true)) {
                throw new DomainException('Invalid event tx_type.');
            }

            $outcome = Connection::transaction(function (PDO $db) use (
                $eventId, $stationId, $memberCacheId, $assetId, $txType, $qty, $clientUuid, $txItem, $actor
            ): array {
                $dup = $db->prepare('SELECT id, conflict_status FROM qa_event_transactions WHERE client_tx_uuid = :u LIMIT 1');
                $dup->execute([':u' => $clientUuid]);
                $existing = $dup->fetch();
                if ($existing) {
                    return ['status' => 'replayed', 'id' => (int)$existing['id'], 'conflict_status' => $existing['conflict_status']];
                }

                $conflictStatus = 'clean';
                $allocStmt = $db->prepare(
                    'SELECT id, allocated_qty, issued_qty, returned_qty
                     FROM qa_event_allocations
                     WHERE event_id = :eid AND station_id = :sid AND asset_id = :aid
                     LIMIT 1 FOR UPDATE'
                );
                $allocStmt->execute([':eid' => $eventId, ':sid' => $stationId, ':aid' => $assetId]);
                $alloc = $allocStmt->fetch();

                if ($alloc) {
                    if ($txType === 'issue') {
                        if (((int)$alloc['issued_qty'] + $qty) > (int)$alloc['allocated_qty']) {
                            $conflictStatus = 'needs_review';
                        }
                        $db->prepare('UPDATE qa_event_allocations SET issued_qty = issued_qty + :q WHERE id = :id')
                            ->execute([':q' => $qty, ':id' => (int)$alloc['id']]);
                    } elseif (in_array($txType, ['return', 'partial_return'], true)) {
                        $db->prepare('UPDATE qa_event_allocations SET returned_qty = returned_qty + :q WHERE id = :id')
                            ->execute([':q' => $qty, ':id' => (int)$alloc['id']]);
                    }
                }

                $ins = $db->prepare(
                    'INSERT INTO qa_event_transactions (
                        client_tx_uuid, event_id, station_id, member_cache_id, asset_id, variant_id,
                        tx_type, quantity, condition_note, operator_user_id, verification_method,
                        biometric_sig_b64, conflict_status, device_tx_at
                     ) VALUES (
                        :cuuid, :eid, :sid, :mcid, :aid, :vid,
                        :txtype, :qty, :cnote, :op, :vmethod,
                        :bsig, :cstatus, :dev_at
                     )'
                );
                $vMethod = in_array(($txItem['verification_method'] ?? 'operator_confirm'), ['drawn_signature', 'biometric_keystore', 'pin_fallback', 'operator_confirm'], true)
                    ? (string)$txItem['verification_method'] : 'operator_confirm';
                $ins->execute([
                    ':cuuid' => $clientUuid,
                    ':eid' => $eventId,
                    ':sid' => $stationId,
                    ':mcid' => $memberCacheId,
                    ':aid' => $assetId,
                    ':vid' => !empty($txItem['variant_id']) ? (int)$txItem['variant_id'] : null,
                    ':txtype' => $txType,
                    ':qty' => $qty,
                    ':cnote' => isset($txItem['condition_note']) ? substr((string)$txItem['condition_note'], 0, 255) : null,
                    ':op' => (int)$actor['uid'],
                    ':vmethod' => $vMethod,
                    ':bsig' => $txItem['biometric_sig_b64'] ?? null,
                    ':cstatus' => $conflictStatus,
                    ':dev_at' => !empty($txItem['device_tx_at']) ? (string)$txItem['device_tx_at'] : gmdate('Y-m-d H:i:s'),
                ]);

                return ['status' => 'accepted', 'id' => (int)$db->lastInsertId(), 'conflict_status' => $conflictStatus];
            });

            if ($outcome['status'] === 'replayed') {
                $replayed++;
            } else {
                $accepted++;
                if ($outcome['conflict_status'] === 'needs_review') {
                    $conflicts++;
                }
            }
            $results[] = array_merge(['client_tx_uuid' => $clientUuid], $outcome);
        }

        return [
            'event_id' => $eventId,
            'accepted' => $accepted,
            'replayed' => $replayed,
            'conflicts' => $conflicts,
            'results' => $results,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function reconcileEvent(PDO $pdo, int $eventId): array
    {
        $stmt = $pdo->prepare(
            'SELECT al.station_id, st.station_name, al.asset_id, a.asset_code, a.name_en,
                    al.allocated_qty, al.issued_qty, al.returned_qty,
                    (al.issued_qty - al.returned_qty) AS outstanding_qty
             FROM qa_event_allocations al
             JOIN qa_event_stations st ON st.id = al.station_id
             JOIN qa_assets a ON a.id = al.asset_id
             WHERE al.event_id = :eid
             ORDER BY al.station_id ASC, al.id ASC'
        );
        $stmt->execute([':eid' => $eventId]);
        $rows = $stmt->fetchAll();

        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM qa_event_transactions WHERE event_id = :eid AND conflict_status = 'needs_review'");
        $cStmt->execute([':eid' => $eventId]);
        $conflictCount = (int)$cStmt->fetchColumn();

        return [
            'event_id' => $eventId,
            'station_summary' => $rows,
            'conflicts_needing_review' => $conflictCount,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createMaintenanceTicket(PDO $pdo, array $input, array $actor): array
    {
        $assetId = (int)($input['asset_id'] ?? 0);
        $issue = trim((string)($input['issue_description'] ?? ''));
        if ($assetId <= 0 || $issue === '') {
            throw new DomainException('asset_id and issue_description are required.');
        }

        return Connection::transaction(function (PDO $tx) use ($assetId, $issue, $input, $actor): array {
            $tNum = AssetService::nextSequenceCode($tx, 'MNT', 'qa_asset_maintenance', 'ticket_number');
            $mType = in_array(($input['maintenance_type'] ?? 'corrective'), ['preventive', 'corrective'], true)
                ? (string)$input['maintenance_type'] : 'corrective';
            $priority = in_array(($input['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], true)
                ? (string)$input['priority'] : 'normal';
            $status = in_array(($input['status'] ?? 'scheduled'), ['scheduled', 'in_progress'], true)
                ? (string)$input['status'] : 'scheduled';

            $tx->prepare(
                'INSERT INTO qa_asset_maintenance (
                    ticket_number, asset_id, operational_year_id, maintenance_type, priority,
                    issue_description, provider_name, scheduled_date, status, created_by
                 ) VALUES (
                    :tnum, :aid, :yid, :mtype, :prio,
                    :descr, :prov, :sdate, :st, :by
                 )'
            )->execute([
                ':tnum' => $tNum,
                ':aid' => $assetId,
                ':yid' => !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null,
                ':mtype' => $mType,
                ':prio' => $priority,
                ':descr' => $issue,
                ':prov' => $input['provider_name'] ?? null,
                ':sdate' => !empty($input['scheduled_date']) ? (string)$input['scheduled_date'] : gmdate('Y-m-d'),
                ':st' => $status,
                ':by' => (int)$actor['uid'],
            ]);
            $id = (int)$tx->lastInsertId();

            if ($status === 'in_progress') {
                $tx->prepare("UPDATE qa_assets SET status = 'maintenance', condition_status = 'under_repair' WHERE id = :aid")
                    ->execute([':aid' => $assetId]);
            }

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'maintenance.create',
                'entity_type' => 'maintenance',
                'entity_id' => (string)$id,
                'after' => ['ticket_number' => $tNum, 'asset_id' => $assetId, 'status' => $status],
            ]);

            return ['id' => $id, 'ticket_number' => $tNum, 'status' => $status];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function updateMaintenanceTicket(PDO $pdo, int $ticketId, array $input, array $actor): array
    {
        $newStatus = (string)($input['status'] ?? '');
        if (!in_array($newStatus, ['scheduled', 'in_progress', 'completed', 'cancelled'], true)) {
            throw new DomainException('Invalid maintenance status.');
        }

        return Connection::transaction(function (PDO $tx) use ($ticketId, $newStatus, $input, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_asset_maintenance WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $ticketId]);
            $ticket = $stmt->fetch();
            if (!$ticket) {
                throw new DomainException('Maintenance ticket not found.');
            }

            $tx->prepare(
                'UPDATE qa_asset_maintenance
                 SET status = :st,
                     work_performed = COALESCE(:work, work_performed),
                     parts_cost = COALESCE(:pcost, parts_cost),
                     service_cost = COALESCE(:scost, service_cost),
                     started_at = CASE WHEN :st_start = \'in_progress\' AND started_at IS NULL THEN NOW() ELSE started_at END,
                     completed_at = CASE WHEN :st_done = \'completed\' THEN NOW() ELSE completed_at END
                 WHERE id = :id'
            )->execute([
                ':st' => $newStatus,
                ':work' => $input['work_performed'] ?? null,
                ':pcost' => isset($input['parts_cost']) ? (float)$input['parts_cost'] : null,
                ':scost' => isset($input['service_cost']) ? (float)$input['service_cost'] : null,
                ':st_start' => $newStatus,
                ':st_done' => $newStatus,
                ':id' => $ticketId,
            ]);

            if ($newStatus === 'in_progress') {
                $tx->prepare("UPDATE qa_assets SET status = 'maintenance', condition_status = 'under_repair' WHERE id = :aid")
                    ->execute([':aid' => (int)$ticket['asset_id']]);
            } elseif ($newStatus === 'completed') {
                $cond = in_array(($input['post_condition'] ?? 'good'), ['new', 'good', 'fair', 'poor', 'damaged'], true)
                    ? (string)$input['post_condition'] : 'good';
                $tx->prepare("UPDATE qa_assets SET status = 'in_stock', condition_status = :cond WHERE id = :aid")
                    ->execute([':cond' => $cond, ':aid' => (int)$ticket['asset_id']]);
            }

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'maintenance.update',
                'entity_type' => 'maintenance',
                'entity_id' => (string)$ticketId,
                'before' => ['status' => $ticket['status']],
                'after' => ['status' => $newStatus],
            ]);

            return ['id' => $ticketId, 'status' => $newStatus];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createInspection(PDO $pdo, array $input, array $actor): array
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new DomainException('title is required for an inspection campaign.');
        }

        $code = AssetService::nextSequenceCode($pdo, 'INSP', 'qa_asset_inspections', 'campaign_code');
        $pdo->prepare(
            "INSERT INTO qa_asset_inspections (
                campaign_code, operational_year_id, title, location_id, category_id, lead_inspector_id, status, started_at
             ) VALUES (:code, :yid, :title, :loc, :cat, :lead, 'in_progress', NOW())"
        )->execute([
            ':code' => $code,
            ':yid' => !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null,
            ':title' => $title,
            ':loc' => !empty($input['location_id']) ? (int)$input['location_id'] : null,
            ':cat' => !empty($input['category_id']) ? (int)$input['category_id'] : null,
            ':lead' => (int)$actor['uid'],
        ]);
        $id = (int)$pdo->lastInsertId();

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'action' => 'inspection.create',
            'entity_type' => 'inspection',
            'entity_id' => (string)$id,
            'after' => ['campaign_code' => $code, 'title' => $title],
        ]);

        return ['id' => $id, 'campaign_code' => $code, 'status' => 'in_progress'];
    }

    /**
     * @param list<array<string,mixed>> $scans
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function recordInspectionScans(PDO $pdo, int $inspectionId, array $scans, array $actor): array
    {
        $allowedOutcomes = ['inspected', 'not_found', 'relocated', 'damaged', 'duplicated', 'unregistered'];
        $recorded = 0;

        $ins = $pdo->prepare(
            'INSERT INTO qa_asset_inspection_items (
                inspection_id, asset_id, scanned_qr_token, outcome, system_qty, counted_qty,
                variance_qty, note, inspector_user_id, scanned_at
             ) VALUES (
                :iid, :aid, :qr, :out, :sqty, :cqty,
                :vqty, :note, :by, :sat
             )'
        );

        foreach ($scans as $scan) {
            if (!is_array($scan)) {
                continue;
            }
            $outcome = (string)($scan['outcome'] ?? 'inspected');
            if (!in_array($outcome, $allowedOutcomes, true)) {
                throw new DomainException("Invalid inspection outcome: {$outcome}");
            }
            $assetId = !empty($scan['asset_id']) ? (int)$scan['asset_id'] : null;
            $sysQty = (int)($scan['system_qty'] ?? 0);
            if ($assetId !== null && $sysQty === 0) {
                $qStmt = $pdo->prepare('SELECT quantity FROM qa_assets WHERE id = :id LIMIT 1');
                $qStmt->execute([':id' => $assetId]);
                $sysQty = (int)($qStmt->fetchColumn() ?: 0);
            }
            $cntQty = (int)($scan['counted_qty'] ?? $sysQty);
            $ins->execute([
                ':iid' => $inspectionId,
                ':aid' => $assetId,
                ':qr' => $scan['scanned_qr_token'] ?? null,
                ':out' => $outcome,
                ':sqty' => $sysQty,
                ':cqty' => $cntQty,
                ':vqty' => $cntQty - $sysQty,
                ':note' => isset($scan['note']) ? substr((string)$scan['note'], 0, 500) : null,
                ':by' => (int)$actor['uid'],
                ':sat' => !empty($scan['scanned_at']) ? (string)$scan['scanned_at'] : gmdate('Y-m-d H:i:s'),
            ]);
            $recorded++;
        }

        return ['inspection_id' => $inspectionId, 'recorded_scans' => $recorded];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function requestDisposal(PDO $pdo, array $input, array $actor): array
    {
        $assetId = (int)($input['asset_id'] ?? 0);
        $reasonType = (string)($input['reason_type'] ?? '');
        $reasonDetail = trim((string)($input['reason_detail'] ?? ''));
        $allowed = ['lost', 'stolen', 'destroyed', 'damaged_beyond_repair', 'sold', 'donated', 'retired'];

        if ($assetId <= 0 || !in_array($reasonType, $allowed, true) || $reasonDetail === '') {
            throw new DomainException('asset_id, valid reason_type, and reason_detail are required.');
        }

        $num = AssetService::nextSequenceCode($pdo, 'DSP', 'qa_asset_disposals', 'disposal_number');
        $pdo->prepare(
            "INSERT INTO qa_asset_disposals (
                disposal_number, asset_id, operational_year_id, reason_type, reason_detail,
                proceeds_amount, recipient_name, status, requested_by
             ) VALUES (:num, :aid, :yid, :rtype, :rdet, :proc, :rec, 'PENDING_APPROVAL', :by)"
        )->execute([
            ':num' => $num,
            ':aid' => $assetId,
            ':yid' => !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null,
            ':rtype' => $reasonType,
            ':rdet' => $reasonDetail,
            ':proc' => isset($input['proceeds_amount']) && $input['proceeds_amount'] !== '' ? (float)$input['proceeds_amount'] : null,
            ':rec' => $input['recipient_name'] ?? null,
            ':by' => (int)$actor['uid'],
        ]);
        $id = (int)$pdo->lastInsertId();

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'action' => 'disposal.request',
            'entity_type' => 'disposal',
            'entity_id' => (string)$id,
            'after' => ['disposal_number' => $num, 'asset_id' => $assetId, 'reason_type' => $reasonType],
        ]);

        return ['id' => $id, 'disposal_number' => $num, 'status' => 'PENDING_APPROVAL'];
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function decideDisposal(PDO $pdo, int $disposalId, string $decision, array $actor): array
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new DomainException('decision must be approve or reject.');
        }

        return Connection::transaction(function (PDO $tx) use ($disposalId, $decision, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_asset_disposals WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $disposalId]);
            $disp = $stmt->fetch();
            if (!$disp) {
                throw new DomainException('Disposal record not found.');
            }
            if ($disp['status'] !== 'PENDING_APPROVAL') {
                throw new DomainException('Disposal record is not pending approval.');
            }

            Rbac::assertNotSelfApproval((int)$actor['uid'], (int)$disp['requested_by'], (string)$actor['role']);

            $nextStatus = $decision === 'approve' ? 'COMPLETED' : 'REJECTED';
            $tx->prepare(
                'UPDATE qa_asset_disposals
                 SET status = :st, approved_by = :by, approved_at = NOW(), disposal_date = CURDATE()
                 WHERE id = :id'
            )->execute([':st' => $nextStatus, ':by' => (int)$actor['uid'], ':id' => $disposalId]);

            if ($nextStatus === 'COMPLETED') {
                $aStmt = $tx->prepare('SELECT quantity FROM qa_assets WHERE id = :aid LIMIT 1 FOR UPDATE');
                $aStmt->execute([':aid' => (int)$disp['asset_id']]);
                $qty = (int)($aStmt->fetchColumn() ?: 0);

                $tx->prepare("UPDATE qa_assets SET status = 'retired', condition_status = 'disposed', quantity = 0 WHERE id = :aid")
                    ->execute([':aid' => (int)$disp['asset_id']]);

                if ($qty > 0) {
                    $tx->prepare(
                        "INSERT INTO qa_asset_movements (
                            asset_id, movement_type, quantity_delta, balance_after,
                            reference_type, reference_id, reason, performed_by, movement_date
                         ) VALUES (
                            :aid, 'disposal', :delta, 0,
                            'disposal', :rid, :rsn, :by, CURDATE()
                         )"
                    )->execute([
                        ':aid' => (int)$disp['asset_id'],
                        ':delta' => -$qty,
                        ':rid' => $disposalId,
                        ':rsn' => 'Approved disposal ' . $disp['disposal_number'] . ' (' . $disp['reason_type'] . ')',
                        ':by' => (int)$actor['uid'],
                    ]);
                }
            }

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'disposal.' . $decision,
                'entity_type' => 'disposal',
                'entity_id' => (string)$disposalId,
                'before' => ['status' => $disp['status']],
                'after' => ['status' => $nextStatus],
            ]);

            return ['id' => $disposalId, 'status' => $nextStatus];
        });
    }
}
