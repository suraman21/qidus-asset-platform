<?php
declare(strict_types=1);

namespace QidusAsset\Connector;

use PDO;

/**
 * Standalone mode: reads and increments directly over `qa_member_sync_cache`.
 */
class StandaloneMemberConnector implements MemberConnectorInterface
{
    public function __construct(protected PDO $pdo)
    {
    }

    public function mode(): string
    {
        return 'standalone';
    }

    public function findByExternalId(string $externalMemberId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, source_system, external_member_id, member_code, qr_token_ref,
                    name_en, name_am, gender, department_name, class_name, section_name,
                    group_names, photo_url, source_status, is_tombstoned,
                    last_sync_revision, synced_at, updated_at
             FROM qa_member_sync_cache
             WHERE external_member_id = :ext
             LIMIT 1'
        );
        $stmt->execute([':ext' => $externalMemberId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function search(string $query, int $page = 1, int $limit = 20, bool $includeInactive = false): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $where = ['1=1'];
        $params = [];

        if (!$includeInactive) {
            $where[] = 'is_tombstoned = 0';
            $where[] = "source_status = 'active'";
        }
        $q = trim($query);
        if ($q !== '') {
            $where[] = '(external_member_id LIKE :q1 OR member_code LIKE :q2 OR name_en LIKE :q3 OR name_am LIKE :q4 OR section_name LIKE :q5 OR department_name LIKE :q6)';
            $like = '%' . $q . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
            $params[':q5'] = $like;
            $params[':q6'] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM qa_member_sync_cache WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT id, source_system, external_member_id, member_code, qr_token_ref,
                    name_en, name_am, gender, department_name, class_name, section_name,
                    group_names, photo_url, source_status, is_tombstoned,
                    last_sync_revision, synced_at, updated_at
             FROM qa_member_sync_cache
             WHERE {$whereSql}
             ORDER BY name_en ASC, id ASC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
        ];
    }

    public function syncChanges(?string $cursor, int $limit = 200): array
    {
        $safeLimit = min(500, max(1, $limit));
        $sinceTs = $this->parseTimestampCursor($cursor);

        $stmt = $this->pdo->prepare(
            'SELECT id, source_system, external_member_id, member_code, qr_token_ref,
                    name_en, name_am, gender, department_name, class_name, section_name,
                    group_names, photo_url, source_status, is_tombstoned,
                    last_sync_revision, synced_at, updated_at
             FROM qa_member_sync_cache
             WHERE updated_at >= :since
             ORDER BY updated_at ASC, id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':since', $sinceTs, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $safeLimit + 1, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $safeLimit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        $tombstones = [];
        $nextCursor = $sinceTs;
        foreach ($rows as $row) {
            $nextCursor = (string)$row['updated_at'];
            if ((int)$row['is_tombstoned'] === 1 || $row['source_status'] === 'archived') {
                $tombstones[] = (string)$row['external_member_id'];
            } else {
                $items[] = $row;
            }
        }

        return [
            'items' => $items,
            'tombstones' => $tombstones,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Upsert normalized member records into `qa_member_sync_cache`.
     *
     * @param list<array<string,mixed>> $records
     */
    public function upsertBatch(array $records, string $sourceSystem): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO qa_member_sync_cache (
                source_system, external_member_id, member_code, qr_token_ref,
                name_en, name_am, gender, department_name, class_name, section_name,
                group_names, photo_url, source_status, is_tombstoned, last_sync_revision, synced_at
            ) VALUES (
                :src, :ext, :code, :qr,
                :name_en, :name_am, :gender, :dept, :cls, :sec,
                :grp, :photo, :status, :tomb, :rev, NOW()
            )
            ON DUPLICATE KEY UPDATE
                member_code = VALUES(member_code),
                qr_token_ref = VALUES(qr_token_ref),
                name_en = VALUES(name_en),
                name_am = VALUES(name_am),
                gender = VALUES(gender),
                department_name = VALUES(department_name),
                class_name = VALUES(class_name),
                section_name = VALUES(section_name),
                group_names = VALUES(group_names),
                photo_url = VALUES(photo_url),
                source_status = VALUES(source_status),
                is_tombstoned = VALUES(is_tombstoned),
                last_sync_revision = VALUES(last_sync_revision),
                synced_at = NOW()'
        );

        $count = 0;
        foreach ($records as $r) {
            $ext = trim((string)($r['external_member_id'] ?? ''));
            if ($ext === '') {
                continue;
            }
            $isTomb = !empty($r['is_tombstoned']) ? 1 : 0;
            $rawStatus = strtolower((string)($r['source_status'] ?? $r['status'] ?? 'active'));
            $allowedStatus = ['active', 'inactive', 'archived', 'waiting', 'warning'];
            $status = $isTomb ? 'archived' : (in_array($rawStatus, $allowedStatus, true) ? $rawStatus : 'active');
            $gender = isset($r['gender']) && in_array(strtolower((string)$r['gender']), ['male', 'female', 'other'], true)
                ? strtolower((string)$r['gender']) : null;

            $stmt->execute([
                ':src' => $sourceSystem,
                ':ext' => $ext,
                ':code' => (string)($r['member_code'] ?? $ext),
                ':qr' => $r['qr_token_ref'] ?? null,
                ':name_en' => (string)($r['name_en'] ?? $r['full_name'] ?? $ext),
                ':name_am' => $r['name_am'] ?? $r['amharic_name'] ?? null,
                ':gender' => $gender,
                ':dept' => $r['department_name'] ?? null,
                ':cls' => $r['class_name'] ?? null,
                ':sec' => $r['section_name'] ?? $r['section_or_kifil'] ?? null,
                ':grp' => $r['group_names'] ?? null,
                ':photo' => $r['photo_url'] ?? null,
                ':status' => $status,
                ':tomb' => $isTomb,
                ':rev' => (int)($r['last_sync_revision'] ?? 0),
            ]);
            $count++;
        }
        return $count;
    }

    protected function parseTimestampCursor(?string $cursor): string
    {
        if ($cursor === null || trim($cursor) === '') {
            return '1970-01-01 00:00:00';
        }
        $ts = strtotime($cursor);
        return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : '1970-01-01 00:00:00';
    }
}
