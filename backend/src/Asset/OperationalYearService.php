<?php
declare(strict_types=1);

namespace QidusAsset\Asset;

use DomainException;
use PDO;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;

/**
 * Year-Based Operational Lifecycle (FR-17):
 * - Active year default
 * - Read-only lock on closed years
 * - Year-end close with clean carry-forward (without duplicating asset IDs)
 * - Audited authorized reopen
 */
final class OperationalYearService
{
    public static function getActiveYear(PDO $pdo): ?array
    {
        $row = $pdo->query(
            "SELECT * FROM qa_operational_years WHERE status IN ('active','reopened') ORDER BY ec_year DESC, id DESC LIMIT 1"
        )->fetch();
        return $row ?: null;
    }

    public static function assertYearWritable(PDO $pdo, ?int $yearId): ?int
    {
        if ($yearId === null || $yearId <= 0) {
            $active = self::getActiveYear($pdo);
            return $active ? (int)$active['id'] : null;
        }
        $stmt = $pdo->prepare('SELECT id, status FROM qa_operational_years WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $yearId]);
        $yr = $stmt->fetch();
        if (!$yr) {
            throw new DomainException('Operational year not found.');
        }
        if ($yr['status'] === 'closed') {
            throw new DomainException('Historical operational year is closed and read-only. Reopen it with authorization before posting changes.');
        }
        return (int)$yr['id'];
    }

    /**
     * Close an active/reopened year and optionally create/target the next E.C. year,
     * carrying forward opening stock balances without duplicating asset identities.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function closeYear(PDO $pdo, int $yearId, ?int $nextEcYear, array $actor): array
    {
        return Connection::transaction(function (PDO $tx) use ($yearId, $nextEcYear, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_operational_years WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $yearId]);
            $yr = $stmt->fetch();
            if (!$yr) {
                throw new DomainException('Operational year not found.');
            }
            if ($yr['status'] === 'closed') {
                throw new DomainException('Operational year is already closed.');
            }

            $tx->prepare(
                "UPDATE qa_operational_years SET status = 'closed', closed_by = :by, closed_at = NOW() WHERE id = :id"
            )->execute([':by' => (int)$actor['uid'], ':id' => $yearId]);

            $targetEc = $nextEcYear ?? ((int)$yr['ec_year'] + 1);
            $nextStmt = $tx->prepare('SELECT * FROM qa_operational_years WHERE ec_year = :ey LIMIT 1');
            $nextStmt->execute([':ey' => $targetEc]);
            $nextYear = $nextStmt->fetch();

            if (!$nextYear) {
                $nextStart = date('Y-m-d', strtotime((string)$yr['end_date'] . ' +1 day'));
                $nextEnd = date('Y-m-d', strtotime($nextStart . ' +364 days'));
                $tx->prepare(
                    "INSERT INTO qa_operational_years (ec_year, label_en, label_am, start_date, end_date, status)
                     VALUES (:ey, :le, :la, :sd, :ed, 'active')"
                )->execute([
                    ':ey' => $targetEc,
                    ':le' => "{$targetEc} E.C.",
                    ':la' => "{$targetEc} ዓ.ም.",
                    ':sd' => $nextStart,
                    ':ed' => $nextEnd,
                ]);
                $nextYearId = (int)$tx->lastInsertId();
            } else {
                $nextYearId = (int)$nextYear['id'];
            }

            // Carry forward pending requests and open maintenance tasks to the new active year
            $carriedReqs = $tx->prepare(
                "UPDATE qa_asset_requests
                 SET operational_year_id = :nyid
                 WHERE operational_year_id = :oyid
                   AND status IN ('PENDING_DEPARTMENT_HEAD','PENDING_DEPUTY_CHAIRPERSON','RETURNED_FOR_CORRECTION','APPROVED')"
            );
            $carriedReqs->execute([':nyid' => $nextYearId, ':oyid' => $yearId]);

            $carriedMaint = $tx->prepare(
                "UPDATE qa_asset_maintenance
                 SET operational_year_id = :nyid
                 WHERE operational_year_id = :oyid
                   AND status IN ('scheduled','in_progress')"
            );
            $carriedMaint->execute([':nyid' => $nextYearId, ':oyid' => $yearId]);

            $activeBalances = (int)$tx->query("SELECT COUNT(*) FROM qa_assets WHERE status NOT IN ('retired','archived')")->fetchColumn();

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'year.close_and_carry_forward',
                'entity_type' => 'operational_year',
                'entity_id' => (string)$yearId,
                'before' => ['status' => $yr['status']],
                'after' => [
                    'closed_year_id' => $yearId,
                    'next_year_id' => $nextYearId,
                    'carried_requests' => $carriedReqs->rowCount(),
                    'carried_maintenance' => $carriedMaint->rowCount(),
                    'active_assets_preserved' => $activeBalances,
                ],
            ]);

            return [
                'closed_year_id' => $yearId,
                'next_year_id' => $nextYearId,
                'next_ec_year' => $targetEc,
                'carried_requests' => $carriedReqs->rowCount(),
                'carried_maintenance' => $carriedMaint->rowCount(),
                'active_assets_preserved' => $activeBalances,
            ];
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function reopenYear(PDO $pdo, int $yearId, string $reason, array $actor): array
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw new DomainException('A non-empty reason is required to reopen a closed operational year.');
        }

        return Connection::transaction(function (PDO $tx) use ($yearId, $cleanReason, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_operational_years WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $yearId]);
            $yr = $stmt->fetch();
            if (!$yr) {
                throw new DomainException('Operational year not found.');
            }
            if ($yr['status'] !== 'closed') {
                throw new DomainException('Only a closed operational year can be reopened.');
            }

            $tx->prepare(
                "UPDATE qa_operational_years
                 SET status = 'reopened', reopened_by = :by, reopened_at = NOW(), reopen_reason = :rsn
                 WHERE id = :id"
            )->execute([':by' => (int)$actor['uid'], ':rsn' => substr($cleanReason, 0, 500), ':id' => $yearId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'action' => 'year.reopen',
                'entity_type' => 'operational_year',
                'entity_id' => (string)$yearId,
                'before' => ['status' => 'closed'],
                'after' => ['status' => 'reopened', 'reason' => $cleanReason],
            ]);

            return ['id' => $yearId, 'status' => 'reopened', 'reopen_reason' => $cleanReason];
        });
    }
}
