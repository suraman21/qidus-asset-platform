<?php
declare(strict_types=1);

namespace QidusAsset\Finance;

use DomainException;
use PDO;
use QidusAsset\Asset\AssetService;
use QidusAsset\Asset\OperationalYearService;
use QidusAsset\Database\Connection;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\EthiopianCalendar;

/**
 * Department 2A: Budgets, Amendments, Append-Only Financial Ledger,
 * Bank/Petty Cash Reconciliation, Member Contributions, and Finance Reports.
 */
final class BudgetAndLedgerService
{
    public const SUPPORTED_REPORTS = [
        'budget_vs_actual',
        'income_expense_ledger',
        'account_balances',
        'member_contributions',
        'procurement_pipeline',
    ];

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createAccount(PDO $pdo, array $input, array $actor): array
    {
        $code = strtoupper(trim((string)($input['account_code'] ?? '')));
        $nameEn = trim((string)($input['name_en'] ?? ''));
        $typeRaw = (string)($input['account_type'] ?? 'bank');
        $type = in_array($typeRaw, ['bank', 'petty_cash', 'mobile_money'], true) ? $typeRaw : 'bank';
        $opening = round((float)($input['opening_balance'] ?? 0), 2);

        if ($code === '' || $nameEn === '') {
            throw new DomainException('account_code and name_en are required.');
        }
        if ($opening < 0) {
            throw new DomainException('opening_balance cannot be negative.');
        }

        $pdo->prepare(
            'INSERT INTO qa_fin_accounts (
                account_code, name_en, name_am, account_type, bank_name, branch_name,
                account_number_masked, custodian_user_id, opening_balance, current_balance
             ) VALUES (:code, :ne, :na, :atype, :bank, :branch, :mask, :cust, :ob, :cb)'
        )->execute([
            ':code' => substr($code, 0, 32),
            ':ne' => $nameEn,
            ':na' => $input['name_am'] ?? null,
            ':atype' => $type,
            ':bank' => $input['bank_name'] ?? null,
            ':branch' => $input['branch_name'] ?? null,
            ':mask' => $input['account_number_masked'] ?? null,
            ':cust' => !empty($input['custodian_user_id']) ? (int)$input['custodian_user_id'] : null,
            ':ob' => $opening,
            ':cb' => $opening,
        ]);
        $id = (int)$pdo->lastInsertId();

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'department_id' => 2,
            'action' => 'finance_account.create',
            'entity_type' => 'fin_account',
            'entity_id' => (string)$id,
            'after' => ['account_code' => $code, 'type' => $type, 'opening_balance' => $opening],
        ]);

        return ['id' => $id, 'account_code' => $code, 'name_en' => $nameEn, 'current_balance' => $opening];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createBudget(PDO $pdo, array $input, array $actor): array
    {
        $catId = (int)($input['category_id'] ?? 0);
        $deptId = !empty($input['department_id']) ? (int)$input['department_id'] : 2;
        $lineCode = strtoupper(trim((string)($input['line_code'] ?? '')));
        $titleEn = trim((string)($input['title_en'] ?? ''));
        $amount = round((float)($input['approved_amount'] ?? 0), 2);

        if ($catId <= 0 || $lineCode === '' || $titleEn === '' || $amount <= 0) {
            throw new DomainException('category_id, line_code, title_en, and positive approved_amount are required.');
        }

        return Connection::transaction(function (PDO $tx) use ($input, $actor, $catId, $deptId, $lineCode, $titleEn, $amount): array {
            $yearId = OperationalYearService::assertYearWritable(
                $tx,
                !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null
            );

            $tx->prepare(
                "INSERT INTO qa_fin_budgets (
                    operational_year_id, department_id, category_id, line_code,
                    title_en, title_am, approved_amount, status, created_by
                 ) VALUES (:yid, :did, :cid, :lc, :te, :ta, :amt, 'draft', :by)"
            )->execute([
                ':yid' => $yearId,
                ':did' => $deptId,
                ':cid' => $catId,
                ':lc' => substr($lineCode, 0, 40),
                ':te' => $titleEn,
                ':ta' => $input['title_am'] ?? null,
                ':amt' => $amount,
                ':by' => (int)$actor['uid'],
            ]);
            $id = (int)$tx->lastInsertId();

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => $deptId,
                'action' => 'finance_budget.create',
                'entity_type' => 'fin_budget',
                'entity_id' => (string)$id,
                'after' => ['line_code' => $lineCode, 'approved_amount' => $amount],
            ]);

            return ['id' => $id, 'line_code' => $lineCode, 'approved_amount' => $amount, 'status' => 'draft'];
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function approveBudget(PDO $pdo, int $budgetId, array $actor): array
    {
        return Connection::transaction(function (PDO $tx) use ($budgetId, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_fin_budgets WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $budgetId]);
            $b = $stmt->fetch();
            if (!$b) {
                throw new DomainException('Budget line not found.');
            }
            if ($b['status'] !== 'draft') {
                throw new DomainException('Only draft budget lines can be approved.');
            }
            Rbac::assertNotSelfApproval((int)$actor['uid'], (int)$b['created_by'], (string)$actor['role']);

            $tx->prepare("UPDATE qa_fin_budgets SET status = 'approved', approved_by = :by, approved_at = NOW() WHERE id = :id")
                ->execute([':by' => (int)$actor['uid'], ':id' => $budgetId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => (int)$b['department_id'],
                'action' => 'finance_budget.approve',
                'entity_type' => 'fin_budget',
                'entity_id' => (string)$budgetId,
                'before' => ['status' => 'draft'],
                'after' => ['status' => 'approved'],
            ]);

            return ['id' => $budgetId, 'status' => 'approved'];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function requestAmendment(PDO $pdo, array $input, array $actor): array
    {
        $type = (string)($input['amendment_type'] ?? 'increase');
        if (!in_array($type, ['increase', 'decrease', 'reallocation'], true)) {
            throw new DomainException('amendment_type must be increase, decrease, or reallocation.');
        }
        $toId = (int)($input['to_budget_id'] ?? 0);
        $fromId = !empty($input['from_budget_id']) ? (int)$input['from_budget_id'] : null;
        $amount = round((float)($input['amount'] ?? 0), 2);
        $reason = trim((string)($input['reason'] ?? ''));

        if ($toId <= 0 || $amount <= 0 || $reason === '') {
            throw new DomainException('to_budget_id, positive amount, and reason are required.');
        }
        if ($type === 'reallocation' && ($fromId === null || $fromId === $toId)) {
            throw new DomainException('reallocation requires a distinct from_budget_id.');
        }

        return Connection::transaction(function (PDO $tx) use ($type, $toId, $fromId, $amount, $reason, $actor): array {
            $num = AssetService::nextSequenceCode($tx, 'BAMD', 'qa_fin_budget_amendments', 'amendment_number');
            $tx->prepare(
                "INSERT INTO qa_fin_budget_amendments (
                    amendment_number, amendment_type, from_budget_id, to_budget_id,
                    amount, reason, status, requested_by
                 ) VALUES (:num, :tp, :fid, :tid, :amt, :rsn, 'PENDING_FINANCE_HEAD', :by)"
            )->execute([
                ':num' => $num,
                ':tp' => $type,
                ':fid' => $fromId,
                ':tid' => $toId,
                ':amt' => $amount,
                ':rsn' => $reason,
                ':by' => (int)$actor['uid'],
            ]);
            $id = (int)$tx->lastInsertId();

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'finance_amendment.request',
                'entity_type' => 'fin_budget_amendment',
                'entity_id' => (string)$id,
                'after' => ['amendment_number' => $num, 'type' => $type, 'amount' => $amount],
            ]);

            return ['id' => $id, 'amendment_number' => $num, 'status' => 'PENDING_FINANCE_HEAD'];
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function decideAmendment(PDO $pdo, int $amendmentId, string $stage, string $decision, string $note, array $actor): array
    {
        if (!in_array($stage, ['finance_head', 'deputy'], true) || !in_array($decision, ['approve', 'reject'], true)) {
            throw new DomainException('Invalid amendment stage or decision.');
        }

        return Connection::transaction(function (PDO $tx) use ($amendmentId, $stage, $decision, $note, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_fin_budget_amendments WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $amendmentId]);
            $amd = $stmt->fetch();
            if (!$amd) {
                throw new DomainException('Budget amendment not found.');
            }
            Rbac::assertNotSelfApproval((int)$actor['uid'], (int)$amd['requested_by'], (string)$actor['role']);

            if ($stage === 'finance_head') {
                if ($amd['status'] !== 'PENDING_FINANCE_HEAD') {
                    throw new DomainException('Amendment is not awaiting finance head review.');
                }
                $next = $decision === 'approve' ? 'PENDING_DEPUTY_CHAIRPERSON' : 'REJECTED';
                $tx->prepare(
                    'UPDATE qa_fin_budget_amendments
                     SET status = :st, finance_head_id = :by, finance_head_at = NOW(), decision_note = :nt
                     WHERE id = :id'
                )->execute([':st' => $next, ':by' => (int)$actor['uid'], ':nt' => substr(trim($note), 0, 500), ':id' => $amendmentId]);
                return ['id' => $amendmentId, 'status' => $next];
            }

            if ($amd['status'] !== 'PENDING_DEPUTY_CHAIRPERSON') {
                throw new DomainException('Amendment is not awaiting deputy chairperson approval.');
            }
            $next = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
            if ($next === 'APPROVED') {
                $amt = (float)$amd['amount'];
                if ($amd['amendment_type'] === 'increase') {
                    $tx->prepare('UPDATE qa_fin_budgets SET amended_amount = amended_amount + :amt WHERE id = :id')
                        ->execute([':amt' => $amt, ':id' => (int)$amd['to_budget_id']]);
                } elseif ($amd['amendment_type'] === 'decrease') {
                    $bStmt = $tx->prepare('SELECT approved_amount, amended_amount, committed_amount, spent_amount FROM qa_fin_budgets WHERE id = :id LIMIT 1 FOR UPDATE');
                    $bStmt->execute([':id' => (int)$amd['to_budget_id']]);
                    $b = $bStmt->fetch();
                    $avail = (float)$b['approved_amount'] + (float)$b['amended_amount'] - (float)$b['committed_amount'] - (float)$b['spent_amount'];
                    if ($avail < $amt) {
                        throw new DomainException('Cannot decrease budget below committed/spent amounts.');
                    }
                    $tx->prepare('UPDATE qa_fin_budgets SET amended_amount = amended_amount - :amt WHERE id = :id')
                        ->execute([':amt' => $amt, ':id' => (int)$amd['to_budget_id']]);
                } elseif ($amd['amendment_type'] === 'reallocation' && !empty($amd['from_budget_id'])) {
                    $fStmt = $tx->prepare('SELECT approved_amount, amended_amount, committed_amount, spent_amount FROM qa_fin_budgets WHERE id = :id LIMIT 1 FOR UPDATE');
                    $fStmt->execute([':id' => (int)$amd['from_budget_id']]);
                    $fb = $fStmt->fetch();
                    $avail = (float)$fb['approved_amount'] + (float)$fb['amended_amount'] - (float)$fb['committed_amount'] - (float)$fb['spent_amount'];
                    if ($avail < $amt) {
                        throw new DomainException('Insufficient available balance in source budget for reallocation.');
                    }
                    $tx->prepare('UPDATE qa_fin_budgets SET amended_amount = amended_amount - :amt WHERE id = :id')
                        ->execute([':amt' => $amt, ':id' => (int)$amd['from_budget_id']]);
                    $tx->prepare('UPDATE qa_fin_budgets SET amended_amount = amended_amount + :amt WHERE id = :id')
                        ->execute([':amt' => $amt, ':id' => (int)$amd['to_budget_id']]);
                }
            }

            $tx->prepare(
                'UPDATE qa_fin_budget_amendments
                 SET status = :st, deputy_id = :by, deputy_at = NOW(), decision_note = :nt
                 WHERE id = :id'
            )->execute([':st' => $next, ':by' => (int)$actor['uid'], ':nt' => substr(trim($note), 0, 500), ':id' => $amendmentId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'finance_amendment.deputy_' . $decision,
                'entity_type' => 'fin_budget_amendment',
                'entity_id' => (string)$amendmentId,
                'after' => ['status' => $next],
            ]);

            return ['id' => $amendmentId, 'status' => $next];
        });
    }

    /**
     * Post an immutable income, expense, or internal transfer ledger transaction.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function postTransaction(PDO $pdo, array $input, array $actor): array
    {
        $type = (string)($input['type'] ?? '');
        if (!in_array($type, ['income', 'expense', 'transfer'], true)) {
            throw new DomainException('Transaction type must be income, expense, or transfer.');
        }
        $accountId = (int)($input['account_id'] ?? 0);
        $counterAccId = !empty($input['counter_account_id']) ? (int)$input['counter_account_id'] : null;
        $budgetId = !empty($input['budget_id']) ? (int)$input['budget_id'] : null;
        $catId = !empty($input['category_id']) ? (int)$input['category_id'] : null;
        $amount = round((float)($input['amount'] ?? 0), 2);
        $descr = trim((string)($input['description'] ?? ''));
        $txDate = !empty($input['transaction_date']) ? (string)$input['transaction_date'] : gmdate('Y-m-d');

        if ($accountId <= 0 || $amount <= 0 || $descr === '') {
            throw new DomainException('account_id, positive amount, and description are required.');
        }
        if ($type === 'transfer' && ($counterAccId === null || $counterAccId === $accountId)) {
            throw new DomainException('transfer requires a distinct counter_account_id.');
        }

        $ec = EthiopianCalendar::fromGregorian($txDate);
        $pmRaw = (string)($input['payment_method'] ?? 'cash');
        $pm = in_array($pmRaw, ['cash', 'bank_transfer', 'mobile_money', 'check', 'other'], true) ? $pmRaw : 'cash';
        $kind = $type === 'transfer' ? 'TRANSFER' : 'STANDARD';

        return Connection::transaction(function (PDO $tx) use (
            $type, $accountId, $counterAccId, $budgetId, $catId, $amount, $descr, $txDate, $ec, $pm, $kind, $input, $actor
        ): array {
            $yearId = OperationalYearService::assertYearWritable(
                $tx,
                !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null
            );

            $accStmt = $tx->prepare('SELECT * FROM qa_fin_accounts WHERE id = :id AND is_active = 1 LIMIT 1 FOR UPDATE');
            $accStmt->execute([':id' => $accountId]);
            $acc = $accStmt->fetch();
            if (!$acc) {
                throw new DomainException('Active finance account not found.');
            }

            if ($type === 'income') {
                $tx->prepare('UPDATE qa_fin_accounts SET current_balance = current_balance + :amt WHERE id = :id')
                    ->execute([':amt' => $amount, ':id' => $accountId]);
            } elseif ($type === 'expense') {
                if ((float)$acc['current_balance'] < $amount) {
                    throw new DomainException('Insufficient balance in selected account.');
                }
                if ($budgetId !== null) {
                    $bStmt = $tx->prepare('SELECT * FROM qa_fin_budgets WHERE id = :id LIMIT 1 FOR UPDATE');
                    $bStmt->execute([':id' => $budgetId]);
                    $b = $bStmt->fetch();
                    if (!$b || !in_array($b['status'], ['approved', 'draft'], true)) {
                        throw new DomainException('Selected budget line is invalid or locked.');
                    }
                    if ($b['status'] !== 'approved') {
                        throw new DomainException('Budget line must be approved before spending.');
                    }
                    $allowCommittedSpend = !empty($input['consume_committed']);
                    $remaining = (float)$b['approved_amount'] + (float)$b['amended_amount'] - (float)$b['spent_amount']
                        - ($allowCommittedSpend ? 0.0 : (float)$b['committed_amount']);
                    if ($remaining < $amount) {
                        throw new DomainException('Expense exceeds remaining approved budget balance.');
                    }
                    if ($allowCommittedSpend) {
                        $tx->prepare(
                            'UPDATE qa_fin_budgets
                             SET spent_amount = spent_amount + :amt1,
                                 committed_amount = GREATEST(0, committed_amount - :amt2)
                             WHERE id = :id'
                        )->execute([':amt1' => $amount, ':amt2' => $amount, ':id' => $budgetId]);
                    } else {
                        $tx->prepare('UPDATE qa_fin_budgets SET spent_amount = spent_amount + :amt WHERE id = :id')
                            ->execute([':amt' => $amount, ':id' => $budgetId]);
                    }
                }
                $tx->prepare('UPDATE qa_fin_accounts SET current_balance = current_balance - :amt WHERE id = :id')
                    ->execute([':amt' => $amount, ':id' => $accountId]);
            } elseif ($type === 'transfer' && $counterAccId !== null) {
                if ((float)$acc['current_balance'] < $amount) {
                    throw new DomainException('Insufficient balance in source account for transfer.');
                }
                $cStmt = $tx->prepare('SELECT id FROM qa_fin_accounts WHERE id = :id AND is_active = 1 LIMIT 1 FOR UPDATE');
                $cStmt->execute([':id' => $counterAccId]);
                if (!$cStmt->fetchColumn()) {
                    throw new DomainException('Destination counter account not found.');
                }
                $tx->prepare('UPDATE qa_fin_accounts SET current_balance = current_balance - :amt WHERE id = :id')
                    ->execute([':amt' => $amount, ':id' => $accountId]);
                $tx->prepare('UPDATE qa_fin_accounts SET current_balance = current_balance + :amt WHERE id = :id')
                    ->execute([':amt' => $amount, ':id' => $counterAccId]);
            }

            $txNum = AssetService::nextSequenceCode($tx, 'FTX', 'qa_fin_transactions', 'tx_number');
            $tx->prepare(
                "INSERT INTO qa_fin_transactions (
                    tx_number, entry_kind, type, operational_year_id, department_id, category_id,
                    budget_id, account_id, counter_account_id, member_cache_id, procurement_po_id,
                    amount, payment_method, voucher_number, payer_payee_name, description,
                    transaction_date, ec_year, ec_month, ec_day, receipt_document_id,
                    status, recorded_by
                 ) VALUES (
                    :num, :kind, :tp, :yid, :did, :cid,
                    :bid, :aid, :caid, :mcid, :poid,
                    :amt, :pm, :vnum, :pp, :descr,
                    :tdate, :ey, :em, :ed, :rdoc,
                    'confirmed', :by
                 )"
            )->execute([
                ':num' => $txNum,
                ':kind' => $kind,
                ':tp' => $type,
                ':yid' => $yearId,
                ':did' => !empty($input['department_id']) ? (int)$input['department_id'] : 2,
                ':cid' => $catId,
                ':bid' => $budgetId,
                ':aid' => $accountId,
                ':caid' => $counterAccId,
                ':mcid' => !empty($input['member_cache_id']) ? (int)$input['member_cache_id'] : null,
                ':poid' => !empty($input['procurement_po_id']) ? (int)$input['procurement_po_id'] : null,
                ':amt' => $amount,
                ':pm' => $pm,
                ':vnum' => $input['voucher_number'] ?? null,
                ':pp' => $input['payer_payee_name'] ?? null,
                ':descr' => substr($descr, 0, 500),
                ':tdate' => $txDate,
                ':ey' => $ec['year'],
                ':em' => $ec['month'],
                ':ed' => $ec['day'],
                ':rdoc' => !empty($input['receipt_document_id']) ? (int)$input['receipt_document_id'] : null,
                ':by' => (int)$actor['uid'],
            ]);
            $txId = (int)$tx->lastInsertId();

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'finance_ledger.post_' . $type,
                'entity_type' => 'fin_transaction',
                'entity_id' => (string)$txId,
                'after' => ['tx_number' => $txNum, 'type' => $type, 'amount' => $amount, 'account_id' => $accountId],
            ]);

            $row = $tx->prepare('SELECT * FROM qa_fin_transactions WHERE id = :id LIMIT 1');
            $row->execute([':id' => $txId]);
            return $row->fetch() ?: ['id' => $txId, 'tx_number' => $txNum];
        });
    }

    /**
     * Append-only reversal entry: never deletes executed rows; emits REVERSAL record & restores balances.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function reverseTransaction(PDO $pdo, int $origTxId, string $reason, array $actor): array
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw new DomainException('Reversal reason is required.');
        }

        return Connection::transaction(function (PDO $tx) use ($origTxId, $cleanReason, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_fin_transactions WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $origTxId]);
            $orig = $stmt->fetch();
            if (!$orig) {
                throw new DomainException('Original transaction not found.');
            }
            if ($orig['status'] === 'reversed' || $orig['entry_kind'] === 'REVERSAL') {
                throw new DomainException('Transaction is already reversed.');
            }
            OperationalYearService::assertYearWritable($tx, (int)$orig['operational_year_id']);

            $amt = (float)$orig['amount'];
            $accId = (int)$orig['account_id'];
            if ($orig['type'] === 'income') {
                $aStmt = $tx->prepare('SELECT current_balance FROM qa_fin_accounts WHERE id = :id LIMIT 1 FOR UPDATE');
                $aStmt->execute([':id' => $accId]);
                if ((float)$aStmt->fetchColumn() < $amt) {
                    throw new DomainException('Account balance too low to reverse this income entry.');
                }
                $tx->prepare('UPDATE qa_fin_accounts SET current_balance = current_balance - :amt WHERE id = :id')
                    ->execute([':amt' => $amt, ':id' => $accId]);
            } elseif ($orig['type'] === 'expense') {
                $tx->prepare('UPDATE qa_fin_accounts SET current_balance = current_balance + :amt WHERE id = :id')
                    ->execute([':amt' => $amt, ':id' => $accId]);
                if (!empty($orig['budget_id'])) {
                    $tx->prepare('UPDATE qa_fin_budgets SET spent_amount = GREATEST(0, spent_amount - :amt) WHERE id = :bid')
                        ->execute([':amt' => $amt, ':bid' => (int)$orig['budget_id']]);
                }
            } elseif ($orig['type'] === 'transfer' && !empty($orig['counter_account_id'])) {
                $tx->prepare('UPDATE qa_fin_accounts SET current_balance = current_balance + :amt WHERE id = :id')
                    ->execute([':amt' => $amt, ':id' => $accId]);
                $tx->prepare('UPDATE qa_fin_accounts SET current_balance = current_balance - :amt WHERE id = :id')
                    ->execute([':amt' => $amt, ':id' => (int)$orig['counter_account_id']]);
            }

            $tx->prepare("UPDATE qa_fin_transactions SET status = 'reversed' WHERE id = :id")
                ->execute([':id' => $origTxId]);

            $revNum = AssetService::nextSequenceCode($tx, 'REV', 'qa_fin_transactions', 'tx_number');
            $ec = EthiopianCalendar::today((string)qa_config('timezone'));
            $tx->prepare(
                "INSERT INTO qa_fin_transactions (
                    tx_number, entry_kind, reverses_tx_id, type, operational_year_id, department_id,
                    category_id, budget_id, account_id, counter_account_id, amount, payment_method,
                    description, transaction_date, ec_year, ec_month, ec_day, status, recorded_by
                 ) VALUES (
                    :num, 'REVERSAL', :rev, :tp, :yid, :did,
                    :cid, :bid, :aid, :caid, :amt, :pm,
                    :descr, CURDATE(), :ey, :em, :ed, 'confirmed', :by
                 )"
            )->execute([
                ':num' => $revNum,
                ':rev' => $origTxId,
                ':tp' => $orig['type'],
                ':yid' => (int)$orig['operational_year_id'],
                ':did' => $orig['department_id'],
                ':cid' => $orig['category_id'],
                ':bid' => $orig['budget_id'],
                ':aid' => $accId,
                ':caid' => $orig['counter_account_id'],
                ':amt' => $amt,
                ':pm' => $orig['payment_method'],
                ':descr' => substr('REVERSAL of ' . $orig['tx_number'] . ': ' . $cleanReason, 0, 500),
                ':ey' => $ec['year'],
                ':em' => $ec['month'],
                ':ed' => $ec['day'],
                ':by' => (int)$actor['uid'],
            ]);
            $revId = (int)$tx->lastInsertId();

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'finance_ledger.reverse',
                'entity_type' => 'fin_transaction',
                'entity_id' => (string)$revId,
                'before' => ['original_tx_id' => $origTxId, 'status' => 'confirmed'],
                'after' => ['reversal_tx_number' => $revNum, 'reason' => $cleanReason],
            ]);

            return ['reversal_id' => $revId, 'tx_number' => $revNum, 'reverses_tx_id' => $origTxId];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createReconciliation(PDO $pdo, array $input, array $actor): array
    {
        $accId = (int)($input['account_id'] ?? 0);
        $pStart = (string)($input['period_start'] ?? '');
        $pEnd = (string)($input['period_end'] ?? '');
        $stmtBal = round((float)($input['statement_ending_balance'] ?? 0), 2);
        if ($accId <= 0 || $pStart === '' || $pEnd === '') {
            throw new DomainException('account_id, period_start, and period_end are required.');
        }

        $aStmt = $pdo->prepare('SELECT current_balance FROM qa_fin_accounts WHERE id = :id LIMIT 1');
        $aStmt->execute([':id' => $accId]);
        $ledgerBal = $aStmt->fetchColumn();
        if ($ledgerBal === false) {
            throw new DomainException('Account not found.');
        }
        $diff = round($stmtBal - (float)$ledgerBal, 2);

        $pdo->prepare(
            "INSERT INTO qa_fin_bank_reconciliations (
                account_id, period_start, period_end, statement_ending_balance,
                ledger_ending_balance, difference, status, reconciled_by, notes
             ) VALUES (:aid, :ps, :pe, :sb, :lb, :diff, 'draft', :by, :nt)"
        )->execute([
            ':aid' => $accId,
            ':ps' => $pStart,
            ':pe' => $pEnd,
            ':sb' => $stmtBal,
            ':lb' => (float)$ledgerBal,
            ':diff' => $diff,
            ':by' => (int)$actor['uid'],
            ':nt' => $input['notes'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'department_id' => 2,
            'action' => 'finance_reconciliation.create',
            'entity_type' => 'fin_reconciliation',
            'entity_id' => (string)$id,
            'after' => ['difference' => $diff],
        ]);

        return ['id' => $id, 'ledger_ending_balance' => (float)$ledgerBal, 'difference' => $diff, 'status' => 'draft'];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    public static function addStatementLines(PDO $pdo, int $recId, array $lines): array
    {
        $ins = $pdo->prepare(
            "INSERT INTO qa_fin_bank_statement_lines (reconciliation_id, line_date, description, reference_no, debit, credit, match_status)
             VALUES (:rid, :ld, :descr, :ref, :deb, :cred, 'unmatched')"
        );
        $added = 0;
        foreach ($lines as $ln) {
            if (!is_array($ln) || empty($ln['description'])) {
                continue;
            }
            $ins->execute([
                ':rid' => $recId,
                ':ld' => !empty($ln['line_date']) ? (string)$ln['line_date'] : gmdate('Y-m-d'),
                ':descr' => substr((string)$ln['description'], 0, 300),
                ':ref' => $ln['reference_no'] ?? null,
                ':deb' => round((float)($ln['debit'] ?? 0), 2),
                ':cred' => round((float)($ln['credit'] ?? 0), 2),
            ]);
            $added++;
        }
        return ['reconciliation_id' => $recId, 'added_lines' => $added];
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function matchStatementLine(PDO $pdo, int $recId, int $lineId, int $txId, array $actor): array
    {
        $pdo->prepare(
            "UPDATE qa_fin_bank_statement_lines
             SET matched_tx_id = :txid, match_status = 'matched'
             WHERE id = :lid AND reconciliation_id = :rid"
        )->execute([':txid' => $txId, ':lid' => $lineId, ':rid' => $recId]);

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'department_id' => 2,
            'action' => 'finance_reconciliation.match_line',
            'entity_type' => 'fin_reconciliation',
            'entity_id' => (string)$recId,
            'after' => ['line_id' => $lineId, 'matched_tx_id' => $txId],
        ]);
        return ['reconciliation_id' => $recId, 'line_id' => $lineId, 'matched_tx_id' => $txId, 'match_status' => 'matched'];
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function finalizeReconciliation(PDO $pdo, int $recId, array $actor): array
    {
        $unmatched = (int)$pdo->query("SELECT COUNT(*) FROM qa_fin_bank_statement_lines WHERE reconciliation_id = {$recId} AND match_status = 'unmatched'")->fetchColumn();
        if ($unmatched > 0) {
            throw new DomainException("Cannot finalize reconciliation with {$unmatched} unmatched statement line(s).");
        }
        $pdo->prepare("UPDATE qa_fin_bank_reconciliations SET status = 'reconciled', approved_by = :by WHERE id = :id")
            ->execute([':by' => (int)$actor['uid'], ':id' => $recId]);
        return ['id' => $recId, 'status' => 'reconciled'];
    }

    /**
     * Record monthly member contribution and post corresponding income transaction.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function recordMemberContribution(PDO $pdo, array $input, array $actor): array
    {
        $extId = trim((string)($input['external_member_id'] ?? ''));
        $accountId = (int)($input['account_id'] ?? 0);
        $ecYear = !empty($input['ec_year']) ? (int)$input['ec_year'] : EthiopianCalendar::today((string)qa_config('timezone'))['year'];
        $ecMonth = (int)($input['ec_month'] ?? 0);
        $expected = round((float)($input['expected_amount'] ?? 0), 2);
        $paid = round((float)($input['paid_amount'] ?? $expected), 2);

        if ($extId === '' || $accountId <= 0 || $ecMonth < 1 || $ecMonth > 13 || $paid <= 0) {
            throw new DomainException('external_member_id, account_id, ec_month (1-13), and positive paid_amount are required.');
        }

        $mStmt = $pdo->prepare('SELECT id, name_en FROM qa_member_sync_cache WHERE external_member_id = :eid AND is_tombstoned = 0 LIMIT 1');
        $mStmt->execute([':eid' => $extId]);
        $member = $mStmt->fetch();
        if (!$member) {
            throw new DomainException('Active member not found in connector cache.');
        }

        return Connection::transaction(function (PDO $tx) use ($member, $accountId, $ecYear, $ecMonth, $expected, $paid, $input, $actor): array {
            $catId = $tx->query("SELECT id FROM qa_fin_categories WHERE code = 'INC_CONTRIB' LIMIT 1")->fetchColumn();
            $txRes = self::postTransaction($tx, [
                'type' => 'income',
                'account_id' => $accountId,
                'category_id' => $catId ? (int)$catId : null,
                'member_cache_id' => (int)$member['id'],
                'amount' => $paid,
                'payment_method' => $input['payment_method'] ?? 'cash',
                'payer_payee_name' => (string)$member['name_en'],
                'description' => "Monthly contribution {$ecYear}/{$ecMonth} — {$member['name_en']}",
            ], $actor);

            $expAmt = max($expected, $paid);
            $status = $paid >= $expAmt ? 'paid' : 'partial';
            $receiptNo = (string)($input['receipt_number'] ?? $txRes['tx_number']);

            $tx->prepare(
                'INSERT INTO qa_fin_member_contributions (
                    member_cache_id, plan_id, ec_year, ec_month, expected_amount, paid_amount,
                    status, paid_date, receipt_number, transaction_id, recorded_by
                 ) VALUES (
                    :mcid, :pid, :ey, :em, :exp, :paid,
                    :st, CURDATE(), :rec, :txid, :by
                 )
                 ON DUPLICATE KEY UPDATE
                    paid_amount = paid_amount + VALUES(paid_amount),
                    status = IF(paid_amount + VALUES(paid_amount) >= expected_amount, \'paid\', \'partial\'),
                    paid_date = CURDATE(),
                    receipt_number = VALUES(receipt_number),
                    transaction_id = VALUES(transaction_id)'
            )->execute([
                ':mcid' => (int)$member['id'],
                ':pid' => !empty($input['plan_id']) ? (int)$input['plan_id'] : null,
                ':ey' => $ecYear,
                ':em' => $ecMonth,
                ':exp' => $expAmt,
                ':paid' => $paid,
                ':st' => $status,
                ':rec' => $receiptNo,
                ':txid' => (int)$txRes['id'],
                ':by' => (int)$actor['uid'],
            ]);

            return [
                'member_name' => $member['name_en'],
                'ec_year' => $ecYear,
                'ec_month' => $ecMonth,
                'paid_amount' => $paid,
                'status' => $status,
                'tx_number' => $txRes['tx_number'],
            ];
        });
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function generateReport(PDO $pdo, string $reportKey, array $filters = []): array
    {
        if (!in_array($reportKey, self::SUPPORTED_REPORTS, true)) {
            throw new DomainException("Unsupported finance report key: {$reportKey}");
        }

        $yearId = !empty($filters['operational_year_id']) ? (int)$filters['operational_year_id'] : null;
        $columns = [];
        $rows = [];
        $title = '';

        switch ($reportKey) {
            case 'budget_vs_actual':
                $title = 'Budget vs. Committed & Actual Spend Report';
                $where = $yearId ? 'WHERE b.operational_year_id = :yid' : '';
                $stmt = $pdo->prepare(
                    "SELECT b.line_code, b.title_en, c.name_en AS category, b.approved_amount, b.amended_amount,
                            b.committed_amount, b.spent_amount,
                            (b.approved_amount + b.amended_amount - b.committed_amount - b.spent_amount) AS remaining_amount,
                            b.status
                     FROM qa_fin_budgets b
                     JOIN qa_fin_categories c ON c.id = b.category_id
                     {$where} ORDER BY b.id DESC LIMIT 500"
                );
                $stmt->execute($yearId ? [':yid' => $yearId] : []);
                $rows = $stmt->fetchAll();
                $columns = ['line_code', 'title_en', 'category', 'approved_amount', 'amended_amount', 'committed_amount', 'spent_amount', 'remaining_amount', 'status'];
                break;

            case 'income_expense_ledger':
                $title = 'Immutable Income & Expense Ledger Report';
                $rows = $pdo->query(
                    'SELECT t.tx_number, t.entry_kind, t.type, t.amount, a.account_code, c.name_en AS category,
                            t.payment_method, t.transaction_date, t.status, t.description
                     FROM qa_fin_transactions t
                     JOIN qa_fin_accounts a ON a.id = t.account_id
                     LEFT JOIN qa_fin_categories c ON c.id = t.category_id
                     ORDER BY t.id DESC LIMIT 1000'
                )->fetchAll();
                $columns = ['tx_number', 'entry_kind', 'type', 'amount', 'account_code', 'category', 'payment_method', 'transaction_date', 'status', 'description'];
                break;

            case 'account_balances':
                $title = 'Cash & Bank Account Balances';
                $rows = $pdo->query(
                    'SELECT account_code, name_en, account_type, bank_name, opening_balance, current_balance, is_active
                     FROM qa_fin_accounts ORDER BY id ASC'
                )->fetchAll();
                $columns = ['account_code', 'name_en', 'account_type', 'bank_name', 'opening_balance', 'current_balance', 'is_active'];
                break;

            case 'member_contributions':
                $title = 'Monthly Member Contribution Collection Report';
                $rows = $pdo->query(
                    'SELECT m.external_member_id, m.name_en, c.ec_year, c.ec_month, c.expected_amount, c.paid_amount, c.status, c.paid_date, c.receipt_number
                     FROM qa_fin_member_contributions c
                     JOIN qa_member_sync_cache m ON m.id = c.member_cache_id
                     ORDER BY c.ec_year DESC, c.ec_month DESC, m.name_en ASC LIMIT 1000'
                )->fetchAll();
                $columns = ['external_member_id', 'name_en', 'ec_year', 'ec_month', 'expected_amount', 'paid_amount', 'status', 'paid_date', 'receipt_number'];
                break;

            case 'procurement_pipeline':
                $title = 'Procurement & Purchase Order Pipeline';
                $rows = $pdo->query(
                    'SELECT p.pr_number, p.title, p.estimated_total, p.stock_check_status, p.budget_check_status, p.status,
                            po.po_number, po.total_amount AS po_amount, po.status AS po_status
                     FROM qa_procurement_requests p
                     LEFT JOIN qa_purchase_orders po ON po.procurement_request_id = p.id
                     ORDER BY p.id DESC LIMIT 500'
                )->fetchAll();
                $columns = ['pr_number', 'title', 'estimated_total', 'stock_check_status', 'budget_check_status', 'status', 'po_number', 'po_amount', 'po_status'];
                break;
        }

        return [
            'report_key' => $reportKey,
            'title' => $title,
            'generated_at_utc' => gmdate('c'),
            'ethiopian_date' => EthiopianCalendar::today((string)qa_config('timezone'))['formatted_en'],
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $actor
     * @return array{content_type:string,filename:string,body:string}
     */
    public static function exportReport(PDO $pdo, string $reportKey, string $format, array $filters, array $actor): array
    {
        $report = self::generateReport($pdo, $reportKey, $filters);
        $format = strtolower($format);
        if (!in_array($format, ['csv', 'xlsx', 'html', 'pdf'], true)) {
            throw new DomainException('Supported export formats: csv, xlsx, html, pdf.');
        }

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'department_id' => 2,
            'action' => 'finance_report.export_' . $format,
            'entity_type' => 'finance_report',
            'entity_id' => $reportKey,
            'after' => ['row_count' => count($report['rows'])],
        ]);

        $baseName = 'qidus_fin_' . $reportKey . '_' . gmdate('Ymd_His');

        if ($format === 'csv') {
            $fh = fopen('php://temp', 'w+');
            if ($fh === false) {
                throw new DomainException('Unable to create CSV stream.');
            }
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $report['columns']);
            foreach ($report['rows'] as $row) {
                $line = [];
                foreach ($report['columns'] as $col) {
                    $val = (string)($row[$col] ?? '');
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
            return ['content_type' => 'text/csv; charset=utf-8', 'filename' => $baseName . '.csv', 'body' => $csv];
        }

        if ($format === 'xlsx' && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray($report['columns'], null, 'A1');
            $rIdx = 2;
            foreach ($report['rows'] as $row) {
                $line = [];
                foreach ($report['columns'] as $col) {
                    $line[] = $row[$col] ?? '';
                }
                $sheet->fromArray($line, null, 'A' . $rIdx);
                $rIdx++;
            }
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            return [
                'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'filename' => $baseName . '.xlsx',
                'body' => (string)ob_get_clean(),
            ];
        }

        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $school = (string)qa_config('school_name_en', 'QIDUS ASSET');
        $th = implode('', array_map(static fn(string $c): string => '<th>' . $esc($c) . '</th>', $report['columns']));
        $trs = '';
        foreach ($report['rows'] as $row) {
            $tds = '';
            foreach ($report['columns'] as $col) {
                $tds .= '<td>' . $esc((string)($row[$col] ?? '')) . '</td>';
            }
            $trs .= '<tr>' . $tds . '</tr>';
        }
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $esc($report['title']) . '</title>'
            . '<style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#0f172a;margin:20px;}'
            . 'table{width:100%;border-collapse:collapse;}th,td{border:1px solid #cbd5e1;padding:5px 7px;text-align:left;}'
            . 'th{background:#f1f5f9;}</style></head><body>'
            . '<h1>' . $esc($school) . ' — ' . $esc($report['title']) . '</h1>'
            . '<p>Generated: ' . $esc($report['generated_at_utc']) . ' · Ethiopian Date: ' . $esc($report['ethiopian_date']) . '</p>'
            . '<table><thead><tr>' . $th . '</tr></thead><tbody>' . $trs . '</tbody></table></body></html>';

        if ($format === 'pdf' && class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            return ['content_type' => 'application/pdf', 'filename' => $baseName . '.pdf', 'body' => $dompdf->output()];
        }

        return ['content_type' => 'text/html; charset=utf-8', 'filename' => $baseName . '.html', 'body' => $html];
    }
}
