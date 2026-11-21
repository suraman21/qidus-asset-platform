<?php
declare(strict_types=1);

namespace QidusAsset\Governance;

use DomainException;
use PDO;
use QidusAsset\Asset\AssetService;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;

/**
 * Phase 4: Cross-Department Executive Dashboard Metrics, Internal Audit Findings Lifecycle,
 * and Filterable Audit Trail Explorer / CSV Export.
 */
final class ExecutiveAndAuditService
{
    /**
     * @return array<string,mixed>
     */
    public static function getExecutiveSummary(PDO $pdo): array
    {
        $assetValuation = (float)$pdo->query(
            "SELECT COALESCE(SUM(quantity * COALESCE(unit_cost, 0)), 0) FROM qa_assets WHERE status <> 'archived'"
        )->fetchColumn();
        $totalAssets = (int)$pdo->query("SELECT COUNT(*) FROM qa_assets WHERE status <> 'archived'")->fetchColumn();
        $lowStock = (int)$pdo->query("SELECT COUNT(*) FROM qa_assets WHERE quantity <= min_quantity AND status <> 'archived'")->fetchColumn();
        $pendingAssetReq = (int)$pdo->query(
            "SELECT COUNT(*) FROM qa_asset_requests WHERE status IN ('PENDING_DEPARTMENT_HEAD','PENDING_DEPUTY_CHAIRPERSON')"
        )->fetchColumn();
        $pendingDisposals = (int)$pdo->query("SELECT COUNT(*) FROM qa_asset_disposals WHERE status = 'PENDING_APPROVAL'")->fetchColumn();

        $budgetAlloc = (float)$pdo->query("SELECT COALESCE(SUM(approved_amount + amended_amount), 0) FROM qa_fin_budgets WHERE status IN ('approved','locked')")->fetchColumn();
        $budgetCommitted = (float)$pdo->query("SELECT COALESCE(SUM(committed_amount), 0) FROM qa_fin_budgets")->fetchColumn();
        $budgetSpent = (float)$pdo->query("SELECT COALESCE(SUM(spent_amount), 0) FROM qa_fin_budgets")->fetchColumn();
        $netCash = (float)$pdo->query('SELECT COALESCE(SUM(current_balance), 0) FROM qa_fin_accounts WHERE is_active = 1')->fetchColumn();
        $incomeYtd = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM qa_fin_transactions WHERE type = 'income' AND status = 'confirmed'")->fetchColumn();
        $expenseYtd = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM qa_fin_transactions WHERE type = 'expense' AND status = 'confirmed'")->fetchColumn();
        $pendingPr = (int)$pdo->query(
            "SELECT COUNT(*) FROM qa_procurement_requests WHERE status IN ('STOCK_CHECK_PENDING','PENDING_DEPARTMENT_HEAD','PENDING_FINANCE_REVIEW','PENDING_DEPUTY_CHAIRPERSON')"
        )->fetchColumn();
        $unmatchedBank = (int)$pdo->query("SELECT COUNT(*) FROM qa_fin_bank_statement_lines WHERE match_status = 'unmatched'")->fetchColumn();

        $openFindings = (int)$pdo->query("SELECT COUNT(*) FROM qa_audit_findings WHERE status IN ('open','in_progress')")->fetchColumn();
        $criticalFindings = (int)$pdo->query("SELECT COUNT(*) FROM qa_audit_findings WHERE severity IN ('high','critical') AND status <> 'verified'")->fetchColumn();
        $activeDevices = (int)$pdo->query("SELECT COUNT(*) FROM qa_device_keys WHERE status = 'active'")->fetchColumn();
        $pendingDevices = (int)$pdo->query("SELECT COUNT(*) FROM qa_device_keys WHERE status = 'pending'")->fetchColumn();
        $activeMembers = (int)$pdo->query('SELECT COUNT(*) FROM qa_member_sync_cache WHERE is_tombstoned = 0')->fetchColumn();

        return [
            'asset_dept' => [
                'total_assets' => $totalAssets,
                'total_valuation_etb' => round($assetValuation, 2),
                'low_stock_alerts' => $lowStock,
                'pending_requests' => $pendingAssetReq,
                'pending_disposals' => $pendingDisposals,
            ],
            'finance_dept' => [
                'budget_allocated_etb' => round($budgetAlloc, 2),
                'budget_committed_etb' => round($budgetCommitted, 2),
                'budget_spent_etb' => round($budgetSpent, 2),
                'budget_utilization_pct' => $budgetAlloc > 0 ? round((($budgetCommitted + $budgetSpent) / $budgetAlloc) * 100, 1) : 0.0,
                'net_cash_balance_etb' => round($netCash, 2),
                'income_ytd_etb' => round($incomeYtd, 2),
                'expense_ytd_etb' => round($expenseYtd, 2),
                'pending_procurement_requests' => $pendingPr,
                'unmatched_bank_lines' => $unmatchedBank,
            ],
            'governance' => [
                'open_findings' => $openFindings,
                'high_critical_findings' => $criticalFindings,
                'active_devices' => $activeDevices,
                'pending_devices' => $pendingDevices,
                'active_members' => $activeMembers,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function listFindings(PDO $pdo, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'f.status = :st';
            $params[':st'] = (string)$filters['status'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'f.severity = :sev';
            $params[':sev'] = (string)$filters['severity'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 'f.department_id = :did';
            $params[':did'] = (int)$filters['department_id'];
        }

        $sql = 'SELECT f.*, u.full_name AS auditor_name, d.name_en AS department_name
             FROM qa_audit_findings f
             JOIN qa_users u ON u.id = f.auditor_user_id
             LEFT JOIN qa_departments d ON d.id = f.department_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY f.id DESC LIMIT 100';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createFinding(PDO $pdo, array $input, array $actor): array
    {
        $title = trim((string)($input['title'] ?? ''));
        $desc = trim((string)($input['description'] ?? ''));
        if ($title === '' || $desc === '') {
            throw new DomainException('title and description are required for an audit finding.');
        }

        $sevRaw = (string)($input['severity'] ?? 'medium');
        $sev = in_array($sevRaw, ['info', 'low', 'medium', 'high', 'critical'], true) ? $sevRaw : 'medium';

        return Connection::transaction(function (PDO $tx) use ($input, $actor, $title, $desc, $sev): array {
            $code = AssetService::nextSequenceCode($tx, 'FND', 'qa_audit_findings', 'finding_code');
            $tx->prepare(
                "INSERT INTO qa_audit_findings (
                    finding_code, auditor_user_id, department_id, severity, title, description,
                    recommendation, linked_entity_type, linked_entity_id, assigned_to_user_id, due_date, status
                 ) VALUES (:code, :uid, :did, :sev, :ttl, :descr, :rec, :let, :leid, :ass, :due, 'open')"
            )->execute([
                ':code' => $code,
                ':uid' => (int)$actor['uid'],
                ':did' => !empty($input['department_id']) ? (int)$input['department_id'] : null,
                ':sev' => $sev,
                ':ttl' => substr($title, 0, 200),
                ':descr' => $desc,
                ':rec' => $input['recommendation'] ?? null,
                ':let' => $input['linked_entity_type'] ?? null,
                ':leid' => isset($input['linked_entity_id']) ? (string)$input['linked_entity_id'] : null,
                ':ass' => !empty($input['assigned_to_user_id']) ? (int)$input['assigned_to_user_id'] : null,
                ':due' => !empty($input['due_date']) ? (string)$input['due_date'] : null,
            ]);
            $id = (int)$tx->lastInsertId();

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => !empty($input['department_id']) ? (int)$input['department_id'] : null,
                'action' => 'audit_finding.create',
                'entity_type' => 'audit_finding',
                'entity_id' => (string)$id,
                'after' => ['finding_code' => $code, 'severity' => $sev],
            ]);

            return ['id' => $id, 'finding_code' => $code, 'severity' => $sev, 'status' => 'open'];
        });
    }

    /**
     * Management response to an open/in-progress finding.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function respondToFinding(PDO $pdo, int $findingId, array $input, array $actor): array
    {
        $response = trim((string)($input['management_response'] ?? ''));
        $nextStatusRaw = (string)($input['status'] ?? 'resolved');
        $nextStatus = in_array($nextStatusRaw, ['in_progress', 'resolved'], true) ? $nextStatusRaw : 'resolved';
        if ($response === '') {
            throw new DomainException('management_response is required.');
        }

        return Connection::transaction(function (PDO $tx) use ($findingId, $response, $nextStatus, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_audit_findings WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $findingId]);
            $f = $stmt->fetch();
            if (!$f) {
                throw new DomainException('Audit finding not found.');
            }
            if ($f['status'] === 'verified') {
                throw new DomainException('Verified audit findings cannot be modified.');
            }

            $tx->prepare(
                "UPDATE qa_audit_findings
                 SET management_response = :resp,
                     status = :st,
                     resolved_at = CASE WHEN :st_res = 'resolved' THEN NOW() ELSE resolved_at END
                 WHERE id = :id"
            )->execute([
                ':resp' => $response,
                ':st' => $nextStatus,
                ':st_res' => $nextStatus,
                ':id' => $findingId,
            ]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => $f['department_id'],
                'action' => 'audit_finding.respond',
                'entity_type' => 'audit_finding',
                'entity_id' => (string)$findingId,
                'after' => ['status' => $nextStatus],
            ]);

            return ['id' => $findingId, 'status' => $nextStatus];
        });
    }

    /**
     * Independent auditor verification of a resolved finding.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function verifyFinding(PDO $pdo, int $findingId, array $actor): array
    {
        return Connection::transaction(function (PDO $tx) use ($findingId, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_audit_findings WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $findingId]);
            $f = $stmt->fetch();
            if (!$f) {
                throw new DomainException('Audit finding not found.');
            }
            if ($f['status'] !== 'resolved') {
                throw new DomainException('Only resolved findings can be verified by the auditor.');
            }

            $tx->prepare("UPDATE qa_audit_findings SET status = 'verified', verified_by = :by, verified_at = NOW() WHERE id = :id")
                ->execute([':by' => (int)$actor['uid'], ':id' => $findingId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => $f['department_id'],
                'action' => 'audit_finding.verify',
                'entity_type' => 'audit_finding',
                'entity_id' => (string)$findingId,
                'after' => ['status' => 'verified'],
            ]);

            return ['id' => $findingId, 'status' => 'verified'];
        });
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function searchAuditLogs(PDO $pdo, array $filters = [], int $limit = 100): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['action'])) {
            $where[] = 'action LIKE :act';
            $params[':act'] = '%' . trim((string)$filters['action']) . '%';
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = :et';
            $params[':et'] = trim((string)$filters['entity_type']);
        }
        if (!empty($filters['outcome'])) {
            $where[] = 'outcome = :out';
            $params[':out'] = trim((string)$filters['outcome']);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'server_at >= :df';
            $params[':df'] = (string)$filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'server_at <= :dt';
            $params[':dt'] = (string)$filters['date_to'] . ' 23:59:59';
        }

        $safeLim = max(1, min(1000, $limit));
        $sql = 'SELECT id, actor_user_id, actor_role, impersonated_by_user_id, department_id,
                    device_uuid, action, entity_type, entity_id, outcome, ip_address, server_at
             FROM qa_audit_logs
             WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $safeLim;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $actor
     * @return array{filename:string,body:string}
     */
    public static function exportAuditLogsCsv(PDO $pdo, array $filters, array $actor): array
    {
        $rows = self::searchAuditLogs($pdo, $filters, 1000);
        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'action' => 'audit_logs.export_csv',
            'entity_type' => 'audit_logs',
            'after' => ['row_count' => count($rows)],
        ]);

        $fh = fopen('php://temp', 'w+');
        if ($fh === false) {
            throw new DomainException('Failed to create CSV buffer.');
        }
        fwrite($fh, "\xEF\xBB\xBF");
        $cols = ['id', 'actor_user_id', 'actor_role', 'impersonated_by_user_id', 'department_id', 'device_uuid', 'action', 'entity_type', 'entity_id', 'outcome', 'ip_address', 'server_at'];
        fputcsv($fh, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $val = (string)($r[$c] ?? '');
                if ($val !== '' && !is_numeric($val) && preg_match('/^[=+\-@\t\r]/', $val) === 1) {
                    $val = "'" . $val;
                }
                $line[] = $val;
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = (string)stream_get_contents($fh);
        fclose($fh);
        return ['filename' => 'qidus_audit_trail_' . gmdate('Ymd_His') . '.csv', 'body' => $csv];
    }
}
