<?php
declare(strict_types=1);

namespace QidusAsset\Connector;

use PDO;

/**
 * SSMS Direct DB Connector: syncs read-only from colocated SSMS `students` & `users` tables
 * into `qa_member_sync_cache` using stable external IDs (`ssms_stu_{id}`, `ssms_usr_{id}`).
 */
final class SsmsDirectMemberConnector extends StandaloneMemberConnector
{
    public function mode(): string
    {
        return 'ssms_direct';
    }

    public function syncChanges(?string $cursor, int $limit = 200): array
    {
        $ssmsDb = (string)qa_config('ssms.db_name', '');
        if ($ssmsDb !== '' && preg_match('/^[A-Za-z0-9_]+$/', $ssmsDb)) {
            $this->pullFromSsmsSchema($ssmsDb);
        }
        return parent::syncChanges($cursor, $limit);
    }

    private function pullFromSsmsSchema(string $ssmsDb): void
    {
        $records = [];

        $checkStu = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl'
        );
        $checkStu->execute([':db' => $ssmsDb, ':tbl' => 'students']);
        if ((int)$checkStu->fetchColumn() > 0) {
            $sql = "SELECT id, full_name, section, status FROM `{$ssmsDb}`.`students` LIMIT 2000";
            foreach ($this->pdo->query($sql)->fetchAll() as $row) {
                $status = strtolower((string)($row['status'] ?? 'active'));
                $tomb = in_array($status, ['deleted', 'archived', 'tombstoned'], true);
                $records[] = [
                    'external_member_id' => 'ssms_stu_' . $row['id'],
                    'member_code' => 'STU-' . $row['id'],
                    'name_en' => (string)$row['full_name'],
                    'section_name' => $row['section'] ?? null,
                    'source_status' => $tomb ? 'archived' : $status,
                    'is_tombstoned' => $tomb,
                ];
            }
        }

        $checkUsr = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl'
        );
        $checkUsr->execute([':db' => $ssmsDb, ':tbl' => 'users']);
        if ((int)$checkUsr->fetchColumn() > 0) {
            $sql = "SELECT id, COALESCE(full_name, username) AS display_name, role, status FROM `{$ssmsDb}`.`users` LIMIT 1000";
            foreach ($this->pdo->query($sql)->fetchAll() as $row) {
                $status = strtolower((string)($row['status'] ?? 'active'));
                $tomb = in_array($status, ['deleted', 'archived', 'tombstoned'], true);
                $records[] = [
                    'external_member_id' => 'ssms_usr_' . $row['id'],
                    'member_code' => 'USR-' . $row['id'],
                    'name_en' => (string)$row['display_name'],
                    'department_name' => $row['role'] ?? null,
                    'source_status' => $tomb ? 'archived' : $status,
                    'is_tombstoned' => $tomb,
                ];
            }
        }

        if ($records !== []) {
            $this->upsertBatch($records, 'ssms_direct');
        }
    }
}
