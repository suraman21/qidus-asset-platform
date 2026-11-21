<?php
declare(strict_types=1);

namespace QidusAsset\Asset;

use DomainException;
use PDO;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Support\EthiopianCalendar;

final class AssetService
{
    public static function nextSequenceCode(PDO $pdo, string $prefix, string $table, string $column): string
    {
        $ecYear = EthiopianCalendar::today((string)qa_config('timezone'))['year'];
        $like = sprintf('%s-%d-%%', $prefix, $ecYear);
        $stmt = $pdo->prepare(
            "SELECT {$column} FROM {$table} WHERE {$column} LIKE :pat ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':pat' => $like]);
        $last = $stmt->fetchColumn();
        $nextNum = 1;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
            $nextNum = ((int)$m[1]) + 1;
        }
        return sprintf('%s-%d-%05d', $prefix, $ecYear, $nextNum);
    }

    public static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createAsset(PDO $pdo, array $input, array $actor): array
    {
        $nameEn = trim((string)($input['name_en'] ?? ''));
        $categoryId = (int)($input['category_id'] ?? 0);
        if ($nameEn === '' || $categoryId <= 0) {
            throw new DomainException('name_en and category_id are required.');
        }

        $catStmt = $pdo->prepare('SELECT id, tracking_mode FROM qa_asset_categories WHERE id = :id AND is_active = 1 LIMIT 1');
        $catStmt->execute([':id' => $categoryId]);
        $category = $catStmt->fetch();
        if (!$category) {
            throw new DomainException('Invalid or inactive asset category.');
        }

        $trackingMode = (string)($input['tracking_mode'] ?? $category['tracking_mode']);
        if (!in_array($trackingMode, ['fixed', 'consumable', 'reusable_member'], true)) {
            throw new DomainException('Invalid tracking_mode.');
        }

        $qty = max(0, (int)($input['quantity'] ?? ($trackingMode === 'fixed' ? 1 : 0)));
        $minQty = max(0, (int)($input['min_quantity'] ?? 0));
        $status = self::deriveStockStatus($trackingMode, $qty, $minQty, (string)($input['status'] ?? 'in_stock'));

        return Connection::transaction(function (PDO $tx) use ($input, $actor, $nameEn, $categoryId, $trackingMode, $qty, $minQty, $status): array {
            $prefix = (string)qa_config('asset_code_prefix', 'AST');
            $assetCode = trim((string)($input['asset_code'] ?? ''));
            if ($assetCode === '') {
                $assetCode = self::nextSequenceCode($tx, $prefix, 'qa_assets', 'asset_code');
            }
            $assetUuid = self::generateUuidV4();
            $qrToken = 'QA-' . bin2hex(random_bytes(16));

            $stmt = $tx->prepare(
                'INSERT INTO qa_assets (
                    asset_uuid, asset_code, qr_token, name_en, name_am, category_id, tracking_mode,
                    description, serial_number, manufacturer, model, custom_fields_json,
                    unit, quantity, reserved_qty, min_quantity, location_id, department_id,
                    custodian_user_id, acquisition_source, acquisition_date, unit_cost, currency,
                    condition_status, status, created_by
                 ) VALUES (
                    :uuid, :code, :qr, :name_en, :name_am, :cat_id, :mode,
                    :descr, :sn, :mfg, :model, :cf,
                    :unit, :qty, 0, :min_qty, :loc_id, :dept_id,
                    :cust_id, :acq_src, :acq_date, :cost, :curr,
                    :cond, :status, :created_by
                 )'
            );

            $customFields = isset($input['custom_fields']) && is_array($input['custom_fields'])
                ? json_encode($input['custom_fields'], JSON_UNESCAPED_UNICODE)
                : null;

            $acqSrcRaw = (string)($input['acquisition_source'] ?? 'purchase');
            $condRaw = (string)($input['condition_status'] ?? 'good');

            $stmt->execute([
                ':uuid' => $assetUuid,
                ':code' => $assetCode,
                ':qr' => $qrToken,
                ':name_en' => $nameEn,
                ':name_am' => $input['name_am'] ?? null,
                ':cat_id' => $categoryId,
                ':mode' => $trackingMode,
                ':descr' => $input['description'] ?? null,
                ':sn' => $input['serial_number'] ?? null,
                ':mfg' => $input['manufacturer'] ?? null,
                ':model' => $input['model'] ?? null,
                ':cf' => $customFields,
                ':unit' => (string)($input['unit'] ?? 'piece'),
                ':qty' => $qty,
                ':min_qty' => $minQty,
                ':loc_id' => !empty($input['location_id']) ? (int)$input['location_id'] : null,
                ':dept_id' => !empty($input['department_id']) ? (int)$input['department_id'] : ($actor['department_id'] ?? null),
                ':cust_id' => !empty($input['custodian_user_id']) ? (int)$input['custodian_user_id'] : null,
                ':acq_src' => in_array($acqSrcRaw, ['purchase', 'donation', 'transfer', 'opening'], true) ? $acqSrcRaw : 'purchase',
                ':acq_date' => !empty($input['acquisition_date']) ? (string)$input['acquisition_date'] : gmdate('Y-m-d'),
                ':cost' => isset($input['unit_cost']) && $input['unit_cost'] !== '' ? (float)$input['unit_cost'] : null,
                ':curr' => (string)($input['currency'] ?? 'ETB'),
                ':cond' => in_array($condRaw, ['new', 'good', 'fair', 'poor', 'damaged', 'under_repair', 'disposed'], true) ? $condRaw : 'good',
                ':status' => $status,
                ':created_by' => (int)$actor['uid'],
            ]);
            $assetId = (int)$tx->lastInsertId();

            $tx->prepare(
                "INSERT INTO qa_asset_qr_history (asset_id, qr_token, status, issued_by)
                 VALUES (:aid, :qr, 'active', :by)"
            )->execute([':aid' => $assetId, ':qr' => $qrToken, ':by' => (int)$actor['uid']]);

            if (!empty($input['variants']) && is_array($input['variants'])) {
                $vStmt = $tx->prepare(
                    'INSERT INTO qa_asset_variants (asset_id, variant_code, size_label, color_or_style, location_id, quantity, min_quantity)
                     VALUES (:aid, :vc, :sz, :col, :loc, :q, :mq)'
                );
                foreach ($input['variants'] as $v) {
                    if (!is_array($v) || empty($v['size_label'])) {
                        continue;
                    }
                    $vCode = trim((string)($v['variant_code'] ?? ($assetCode . '-' . strtoupper((string)$v['size_label']))));
                    $vStmt->execute([
                        ':aid' => $assetId,
                        ':vc' => substr($vCode, 0, 48),
                        ':sz' => substr((string)$v['size_label'], 0, 32),
                        ':col' => $v['color_or_style'] ?? null,
                        ':loc' => !empty($v['location_id']) ? (int)$v['location_id'] : (!empty($input['location_id']) ? (int)$input['location_id'] : null),
                        ':q' => max(0, (int)($v['quantity'] ?? 0)),
                        ':mq' => max(0, (int)($v['min_quantity'] ?? 0)),
                    ]);
                }
            }

            if ($qty > 0) {
                $tx->prepare(
                    "INSERT INTO qa_asset_movements (
                        asset_id, movement_type, quantity_delta, balance_after, to_location_id,
                        reference_type, reference_id, reason, performed_by, device_uuid, movement_date
                     ) VALUES (
                        :aid, 'incoming', :delta, :bal, :loc,
                        'asset_registration', :ref_id, :reason, :by, :dev, :mdate
                     )"
                )->execute([
                    ':aid' => $assetId,
                    ':delta' => $qty,
                    ':bal' => $qty,
                    ':loc' => !empty($input['location_id']) ? (int)$input['location_id'] : null,
                    ':ref_id' => $assetId,
                    ':reason' => 'Initial asset registration',
                    ':by' => (int)$actor['uid'],
                    ':dev' => $actor['device_uuid'] ?? null,
                    ':mdate' => gmdate('Y-m-d'),
                ]);
            }

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'impersonated_by_user_id' => $actor['impersonated_by'] ?? null,
                'department_id' => $actor['department_id'] ?? null,
                'device_uuid' => $actor['device_uuid'] ?? null,
                'action' => 'asset.create',
                'entity_type' => 'asset',
                'entity_id' => (string)$assetId,
                'after' => ['asset_code' => $assetCode, 'name_en' => $nameEn, 'quantity' => $qty],
            ]);

            return self::getById($tx, $assetId) ?? ['id' => $assetId, 'asset_code' => $assetCode, 'qr_token' => $qrToken];
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getById(PDO $pdo, int $assetId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT a.*, c.code AS category_code, c.name_en AS category_name_en,
                    l.code AS location_code, l.name_en AS location_name_en
             FROM qa_assets a
             JOIN qa_asset_categories c ON c.id = a.category_id
             LEFT JOIN qa_asset_locations l ON l.id = a.location_id
             WHERE a.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $assetId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $vStmt = $pdo->prepare('SELECT * FROM qa_asset_variants WHERE asset_id = :id ORDER BY id ASC');
        $vStmt->execute([':id' => $assetId]);
        $row['variants'] = $vStmt->fetchAll();
        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function resolveByQrToken(PDO $pdo, string $qrToken): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT a.id FROM qa_assets a WHERE a.qr_token = :qr1
             UNION
             SELECT h.asset_id AS id FROM qa_asset_qr_history h WHERE h.qr_token = :qr2 AND h.status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([':qr1' => $qrToken, ':qr2' => $qrToken]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            return null;
        }
        return self::getById($pdo, (int)$id);
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function reprintQrToken(PDO $pdo, int $assetId, string $reason, array $actor): array
    {
        return Connection::transaction(function (PDO $tx) use ($assetId, $reason, $actor): array {
            $asset = self::getById($tx, $assetId);
            if (!$asset) {
                throw new DomainException('Asset not found.');
            }
            $newQr = 'QA-' . bin2hex(random_bytes(16));
            $tx->prepare(
                "UPDATE qa_asset_qr_history
                 SET status = 'revoked', revoked_by = :by, revoked_reason = :rsn, revoked_at = NOW()
                 WHERE asset_id = :aid AND status = 'active'"
            )->execute([':by' => (int)$actor['uid'], ':rsn' => $reason !== '' ? $reason : 'Reprint requested', ':aid' => $assetId]);

            $tx->prepare(
                "INSERT INTO qa_asset_qr_history (asset_id, qr_token, status, issued_by)
                 VALUES (:aid, :qr, 'active', :by)"
            )->execute([':aid' => $assetId, ':qr' => $newQr, ':by' => (int)$actor['uid']]);

            $tx->prepare('UPDATE qa_assets SET qr_token = :qr WHERE id = :aid')
                ->execute([':qr' => $newQr, ':aid' => $assetId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'asset.qr_reprint',
                'entity_type' => 'asset',
                'entity_id' => (string)$assetId,
                'before' => ['qr_token' => $asset['qr_token']],
                'after' => ['qr_token' => $newQr, 'reason' => $reason],
            ]);

            return ['asset_id' => $assetId, 'qr_token' => $newQr];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function recordMovement(PDO $pdo, int $assetId, array $input, array $actor): array
    {
        $movementType = (string)($input['movement_type'] ?? '');
        $allowedTypes = ['incoming', 'outgoing', 'transfer', 'issue', 'return', 'adjustment', 'maintenance_out', 'maintenance_in', 'disposal'];
        if (!in_array($movementType, $allowedTypes, true)) {
            throw new DomainException('Invalid movement_type.');
        }

        $qtyDelta = (int)($input['quantity_delta'] ?? 0);
        if ($qtyDelta === 0) {
            throw new DomainException('quantity_delta must be non-zero.');
        }
        $reason = trim((string)($input['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('reason is required for every asset movement.');
        }

        $variantId = !empty($input['variant_id']) ? (int)$input['variant_id'] : null;

        return Connection::transaction(function (PDO $tx) use ($assetId, $movementType, $qtyDelta, $reason, $variantId, $input, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_assets WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $assetId]);
            $asset = $stmt->fetch();
            if (!$asset) {
                throw new DomainException('Asset not found.');
            }

            $newAssetQty = (int)$asset['quantity'] + $qtyDelta;
            if ($newAssetQty < 0) {
                throw new DomainException('Insufficient asset quantity; stock balance cannot go negative.');
            }

            if ($variantId !== null) {
                $vStmt = $tx->prepare('SELECT * FROM qa_asset_variants WHERE id = :vid AND asset_id = :aid LIMIT 1 FOR UPDATE');
                $vStmt->execute([':vid' => $variantId, ':aid' => $assetId]);
                $variant = $vStmt->fetch();
                if (!$variant) {
                    throw new DomainException('Asset variant not found.');
                }
                $newVarQty = (int)$variant['quantity'] + $qtyDelta;
                if ($newVarQty < 0) {
                    throw new DomainException('Insufficient variant stock quantity.');
                }
                $tx->prepare('UPDATE qa_asset_variants SET quantity = :q WHERE id = :vid')
                    ->execute([':q' => $newVarQty, ':vid' => $variantId]);
            }

            $newLocationId = !empty($input['to_location_id']) ? (int)$input['to_location_id'] : $asset['location_id'];
            $newStatus = self::deriveStockStatus(
                (string)$asset['tracking_mode'],
                $newAssetQty,
                (int)$asset['min_quantity'],
                (string)$asset['status']
            );
            if ($movementType === 'maintenance_out') {
                $newStatus = 'maintenance';
            } elseif ($movementType === 'maintenance_in' && $newAssetQty > 0) {
                $newStatus = 'in_stock';
            } elseif ($movementType === 'disposal') {
                $newStatus = 'retired';
            }

            $tx->prepare('UPDATE qa_assets SET quantity = :q, location_id = :loc, status = :st WHERE id = :id')
                ->execute([':q' => $newAssetQty, ':loc' => $newLocationId, ':st' => $newStatus, ':id' => $assetId]);

            $movStmt = $tx->prepare(
                'INSERT INTO qa_asset_movements (
                    asset_id, variant_id, operational_year_id, movement_type, quantity_delta,
                    balance_after, from_location_id, to_location_id, reference_type, reference_id,
                    reason, performed_by, device_uuid, movement_date
                 ) VALUES (
                    :aid, :vid, :yid, :mtype, :delta,
                    :bal, :from_loc, :to_loc, :rtype, :rid,
                    :reason, :by, :dev, :mdate
                 )'
            );
            $movStmt->execute([
                ':aid' => $assetId,
                ':vid' => $variantId,
                ':yid' => !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null,
                ':mtype' => $movementType,
                ':delta' => $qtyDelta,
                ':bal' => $newAssetQty,
                ':from_loc' => $asset['location_id'],
                ':to_loc' => $newLocationId,
                ':rtype' => $input['reference_type'] ?? 'manual_movement',
                ':rid' => !empty($input['reference_id']) ? (int)$input['reference_id'] : null,
                ':reason' => $reason,
                ':by' => (int)$actor['uid'],
                ':dev' => $actor['device_uuid'] ?? null,
                ':mdate' => !empty($input['movement_date']) ? (string)$input['movement_date'] : gmdate('Y-m-d'),
            ]);
            $movementId = (int)$tx->lastInsertId();

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'device_uuid' => $actor['device_uuid'] ?? null,
                'action' => 'asset.movement_' . $movementType,
                'entity_type' => 'asset',
                'entity_id' => (string)$assetId,
                'before' => ['quantity' => (int)$asset['quantity'], 'status' => $asset['status']],
                'after' => ['quantity' => $newAssetQty, 'status' => $newStatus, 'movement_id' => $movementId],
            ]);

            if (in_array($newStatus, ['low_stock', 'out_of_stock'], true)) {
                self::emitLowStockAlert($tx, $assetId, (string)$asset['asset_code'], (string)$asset['name_en'], $newAssetQty, (int)$asset['min_quantity']);
            }

            return [
                'movement_id' => $movementId,
                'asset_id' => $assetId,
                'balance_after' => $newAssetQty,
                'status' => $newStatus,
            ];
        });
    }

    /**
     * Update non-balance asset metadata (`PATCH /assets/{id}`) while preserving history.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function updateAsset(PDO $pdo, int $assetId, array $input, array $actor): array
    {
        return Connection::transaction(function (PDO $tx) use ($assetId, $input, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_assets WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $assetId]);
            $before = $stmt->fetch();
            if (!$before) {
                throw new DomainException('Asset not found.');
            }

            $nameEn = array_key_exists('name_en', $input) ? trim((string)$input['name_en']) : (string)$before['name_en'];
            if ($nameEn === '') {
                throw new DomainException('name_en cannot be empty.');
            }
            $condRaw = array_key_exists('condition_status', $input) ? (string)$input['condition_status'] : (string)$before['condition_status'];
            $cond = in_array($condRaw, ['new', 'good', 'fair', 'poor', 'damaged', 'under_repair', 'disposed'], true)
                ? $condRaw : (string)$before['condition_status'];

            $customFields = isset($input['custom_fields']) && is_array($input['custom_fields'])
                ? json_encode($input['custom_fields'], JSON_UNESCAPED_UNICODE)
                : $before['custom_fields_json'];

            $tx->prepare(
                'UPDATE qa_assets
                 SET name_en = :ne,
                     name_am = :na,
                     description = :descr,
                     serial_number = :sn,
                     manufacturer = :mfg,
                     model = :mdl,
                     custom_fields_json = :cf,
                     min_quantity = :mq,
                     location_id = :loc,
                     department_id = :dept,
                     unit_cost = :ucost,
                     condition_status = :cond
                 WHERE id = :id'
            )->execute([
                ':ne' => $nameEn,
                ':na' => array_key_exists('name_am', $input) ? $input['name_am'] : $before['name_am'],
                ':descr' => array_key_exists('description', $input) ? $input['description'] : $before['description'],
                ':sn' => array_key_exists('serial_number', $input) ? $input['serial_number'] : $before['serial_number'],
                ':mfg' => array_key_exists('manufacturer', $input) ? $input['manufacturer'] : $before['manufacturer'],
                ':mdl' => array_key_exists('model', $input) ? $input['model'] : $before['model'],
                ':cf' => $customFields,
                ':mq' => array_key_exists('min_quantity', $input) ? max(0, (int)$input['min_quantity']) : (int)$before['min_quantity'],
                ':loc' => array_key_exists('location_id', $input) ? ($input['location_id'] ? (int)$input['location_id'] : null) : $before['location_id'],
                ':dept' => array_key_exists('department_id', $input) ? ($input['department_id'] ? (int)$input['department_id'] : null) : $before['department_id'],
                ':ucost' => array_key_exists('unit_cost', $input) ? ($input['unit_cost'] !== '' ? (float)$input['unit_cost'] : null) : $before['unit_cost'],
                ':cond' => $cond,
                ':id' => $assetId,
            ]);

            $after = self::getById($tx, $assetId);
            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'asset.update',
                'entity_type' => 'asset',
                'entity_id' => (string)$assetId,
                'before' => ['name_en' => $before['name_en'], 'condition_status' => $before['condition_status'], 'location_id' => $before['location_id']],
                'after' => ['name_en' => $nameEn, 'condition_status' => $cond, 'location_id' => $after['location_id'] ?? null],
            ]);

            return $after ?? ['id' => $assetId];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createCategory(PDO $pdo, array $input, array $actor): array
    {
        $code = strtoupper(trim((string)($input['code'] ?? '')));
        $nameEn = trim((string)($input['name_en'] ?? ''));
        $nameAm = trim((string)($input['name_am'] ?? $nameEn));
        $modeRaw = (string)($input['tracking_mode'] ?? 'fixed');
        $mode = in_array($modeRaw, ['fixed', 'consumable', 'reusable_member'], true) ? $modeRaw : 'fixed';
        if ($code === '' || $nameEn === '') {
            throw new DomainException('code and name_en are required for asset category.');
        }

        $pdo->prepare(
            'INSERT INTO qa_asset_categories (parent_id, code, name_en, name_am, tracking_mode, qr_required, requires_return, field_schema_json)
             VALUES (:pid, :code, :ne, :na, :tm, :qr, :rr, :fs)'
        )->execute([
            ':pid' => !empty($input['parent_id']) ? (int)$input['parent_id'] : null,
            ':code' => substr($code, 0, 32),
            ':ne' => $nameEn,
            ':na' => $nameAm,
            ':tm' => $mode,
            ':qr' => isset($input['qr_required']) ? (!empty($input['qr_required']) ? 1 : 0) : ($mode === 'fixed' ? 1 : 0),
            ':rr' => isset($input['requires_return']) ? (!empty($input['requires_return']) ? 1 : 0) : ($mode !== 'consumable' ? 1 : 0),
            ':fs' => isset($input['field_schema']) && is_array($input['field_schema']) ? json_encode($input['field_schema'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'action' => 'asset_category.create',
            'entity_type' => 'asset_category',
            'entity_id' => (string)$id,
            'after' => ['code' => $code, 'tracking_mode' => $mode],
        ]);
        return ['id' => $id, 'code' => $code, 'name_en' => $nameEn, 'tracking_mode' => $mode];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createLocation(PDO $pdo, array $input, array $actor): array
    {
        $code = strtoupper(trim((string)($input['code'] ?? '')));
        $nameEn = trim((string)($input['name_en'] ?? ''));
        $nameAm = trim((string)($input['name_am'] ?? $nameEn));
        if ($code === '' || $nameEn === '') {
            throw new DomainException('code and name_en are required for asset location.');
        }

        $pdo->prepare(
            'INSERT INTO qa_asset_locations (code, name_en, name_am, building, room, custodian_user_id)
             VALUES (:code, :ne, :na, :bld, :rm, :cust)'
        )->execute([
            ':code' => substr($code, 0, 32),
            ':ne' => $nameEn,
            ':na' => $nameAm,
            ':bld' => $input['building'] ?? null,
            ':rm' => $input['room'] ?? null,
            ':cust' => !empty($input['custodian_user_id']) ? (int)$input['custodian_user_id'] : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'action' => 'asset_location.create',
            'entity_type' => 'asset_location',
            'entity_id' => (string)$id,
            'after' => ['code' => $code, 'name_en' => $nameEn],
        ]);
        return ['id' => $id, 'code' => $code, 'name_en' => $nameEn];
    }

    /**
     * Member-level asset profile + clothing sizes + full issue/return history (FR-15).
     *
     * @return array<string,mixed>
     */
    public static function getMemberAssetSummary(PDO $pdo, string $externalMemberId): array
    {
        $mStmt = $pdo->prepare(
            'SELECT m.*, p.clothing_size, p.shoe_size, p.notes AS sizing_notes
             FROM qa_member_sync_cache m
             LEFT JOIN qa_member_asset_profiles p ON p.member_cache_id = m.id
             WHERE m.external_member_id = :ext
             LIMIT 1'
        );
        $mStmt->execute([':ext' => $externalMemberId]);
        $member = $mStmt->fetch();
        if (!$member) {
            throw new DomainException('Member not found in sync directory.');
        }
        $mcId = (int)$member['id'];

        $hStmt = $pdo->prepare(
            'SELECT h.handover_number, h.handover_type, h.quantity, h.condition_at_handover,
                    h.expected_return_date, h.verification_method, h.server_received_at,
                    a.id AS asset_id, a.asset_code, a.name_en AS asset_name
             FROM qa_asset_handovers h
             JOIN qa_assets a ON a.id = h.asset_id
             WHERE h.member_cache_id = :mcid
             ORDER BY h.id DESC LIMIT 200'
        );
        $hStmt->execute([':mcid' => $mcId]);
        $handovers = $hStmt->fetchAll();

        $evStmt = $pdo->prepare(
            'SELECT t.tx_type, t.quantity, t.condition_note, t.device_tx_at,
                    e.event_code, e.name_en AS event_name, a.asset_code, a.name_en AS asset_name
             FROM qa_event_transactions t
             JOIN qa_events e ON e.id = t.event_id
             JOIN qa_assets a ON a.id = t.asset_id
             WHERE t.member_cache_id = :mcid
             ORDER BY t.id DESC LIMIT 200'
        );
        $evStmt->execute([':mcid' => $mcId]);
        $eventTxs = $evStmt->fetchAll();

        return [
            'member' => $member,
            'handovers' => $handovers,
            'event_transactions' => $eventTxs,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function upsertMemberAssetProfile(PDO $pdo, string $externalMemberId, array $input, array $actor): array
    {
        $mStmt = $pdo->prepare('SELECT id FROM qa_member_sync_cache WHERE external_member_id = :ext LIMIT 1');
        $mStmt->execute([':ext' => $externalMemberId]);
        $mcId = (int)($mStmt->fetchColumn() ?: 0);
        if ($mcId <= 0) {
            throw new DomainException('Member not found in sync directory.');
        }

        $pdo->prepare(
            'INSERT INTO qa_member_asset_profiles (member_cache_id, clothing_size, shoe_size, notes, updated_by)
             VALUES (:mcid, :cs, :ss, :notes, :by)
             ON DUPLICATE KEY UPDATE
                clothing_size = VALUES(clothing_size),
                shoe_size = VALUES(shoe_size),
                notes = VALUES(notes),
                updated_by = VALUES(updated_by)'
        )->execute([
            ':mcid' => $mcId,
            ':cs' => isset($input['clothing_size']) ? substr(trim((string)$input['clothing_size']), 0, 24) : null,
            ':ss' => isset($input['shoe_size']) ? substr(trim((string)$input['shoe_size']), 0, 24) : null,
            ':notes' => isset($input['notes']) ? substr(trim((string)$input['notes']), 0, 255) : null,
            ':by' => (int)$actor['uid'],
        ]);

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'action' => 'member_profile.upsert',
            'entity_type' => 'member',
            'entity_id' => $externalMemberId,
            'after' => ['clothing_size' => $input['clothing_size'] ?? null, 'shoe_size' => $input['shoe_size'] ?? null],
        ]);

        return self::getMemberAssetSummary($pdo, $externalMemberId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listOverdueHandovers(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT h.id, h.handover_number, h.asset_id, a.asset_code, a.name_en AS asset_name,
                    h.receiver_display_name, h.quantity, h.expected_return_date, h.server_received_at
             FROM qa_asset_handovers h
             JOIN qa_assets a ON a.id = h.asset_id
             WHERE h.handover_type = 'issue'
               AND h.expected_return_date IS NOT NULL
               AND h.expected_return_date < CURDATE()
             ORDER BY h.expected_return_date ASC
             LIMIT 200"
        )->fetchAll();
    }

    public static function emitLowStockAlert(PDO $pdo, int $assetId, string $assetCode, string $assetName, int $qty, int $minQty): void
    {
        $heads = $pdo->query("SELECT id FROM qa_users WHERE qa_role IN ('asset_head','asset_admin') AND is_active = 1")->fetchAll();
        if ($heads === []) {
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO qa_notifications (recipient_user_id, title, message, type, entity_type, entity_id)
             VALUES (:uid, :title, :msg, 'warning', 'asset', :eid)"
        );
        foreach ($heads as $u) {
            $stmt->execute([
                ':uid' => (int)$u['id'],
                ':title' => "Low Stock Alert: {$assetCode}",
                ':msg' => "{$assetName} ({$assetCode}) stock is {$qty} (min threshold {$minQty}).",
                ':eid' => $assetId,
            ]);
        }
    }

    public static function deriveStockStatus(string $trackingMode, int $qty, int $minQty, string $currentStatus): string
    {
        if (in_array($currentStatus, ['maintenance', 'retired', 'archived'], true)) {
            return $currentStatus;
        }
        if ($trackingMode === 'fixed') {
            return $qty > 0 ? ($currentStatus === 'assigned' ? 'assigned' : 'in_stock') : 'assigned';
        }
        if ($qty <= 0) {
            return 'out_of_stock';
        }
        if ($minQty > 0 && $qty <= $minQty) {
            return 'low_stock';
        }
        return 'in_stock';
    }
}
