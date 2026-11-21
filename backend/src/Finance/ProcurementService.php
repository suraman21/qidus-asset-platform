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

/**
 * Department 2B: Suppliers, Stock-Check & Budget-Gated Purchase Requests,
 * 3-Stage Approvals, Competitive Quotations, Purchase Orders, Goods Receipts (GRN -> Asset Stock),
 * and PO Payment Posting.
 */
final class ProcurementService
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createSupplier(PDO $pdo, array $input, array $actor): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new DomainException('Supplier name is required.');
        }

        $pdo->prepare(
            'INSERT INTO qa_suppliers (name, contact_person, phone, email, tin_number, address, category, bank_details)
             VALUES (:nm, :cp, :ph, :em, :tin, :addr, :cat, :bank)'
        )->execute([
            ':nm' => substr($name, 0, 160),
            ':cp' => $input['contact_person'] ?? null,
            ':ph' => $input['phone'] ?? null,
            ':em' => $input['email'] ?? null,
            ':tin' => $input['tin_number'] ?? null,
            ':addr' => $input['address'] ?? null,
            ':cat' => $input['category'] ?? null,
            ':bank' => $input['bank_details'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'department_id' => 2,
            'action' => 'supplier.create',
            'entity_type' => 'supplier',
            'entity_id' => (string)$id,
            'after' => ['name' => $name],
        ]);
        return ['id' => $id, 'name' => $name];
    }

    /**
     * Create Purchase Request + line items; enters `STOCK_CHECK_PENDING`.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function createPurchaseRequest(PDO $pdo, array $input, array $actor): array
    {
        $title = trim((string)($input['title'] ?? ''));
        $justification = trim((string)($input['justification'] ?? ''));
        $items = $input['items'] ?? [];
        if ($title === '' || $justification === '' || !is_array($items) || $items === []) {
            throw new DomainException('title, justification, and at least one item are required.');
        }

        return Connection::transaction(function (PDO $tx) use ($input, $actor, $title, $justification, $items): array {
            $yearId = OperationalYearService::assertYearWritable(
                $tx,
                !empty($input['operational_year_id']) ? (int)$input['operational_year_id'] : null
            );
            $prNum = AssetService::nextSequenceCode($tx, 'PR', 'qa_procurement_requests', 'pr_number');

            $estTotal = 0.0;
            $normalizedItems = [];
            foreach ($items as $it) {
                if (!is_array($it) || empty($it['item_name'])) {
                    continue;
                }
                $qty = max(1, (int)($it['requested_qty'] ?? 1));
                $unitPrice = max(0.0, round((float)($it['estimated_unit_price'] ?? 0), 2));
                $estTotal += ($qty * $unitPrice);
                $typeRaw = (string)($it['item_type'] ?? 'fixed_asset');
                $itemType = in_array($typeRaw, ['fixed_asset', 'consumable', 'service'], true) ? $typeRaw : 'fixed_asset';
                $normalizedItems[] = [
                    'asset_id' => !empty($it['asset_id']) ? (int)$it['asset_id'] : null,
                    'item_name' => substr(trim((string)$it['item_name']), 0, 180),
                    'specification' => $it['specification'] ?? null,
                    'item_type' => $itemType,
                    'requested_qty' => $qty,
                    'estimated_unit_price' => $unitPrice,
                    'unit' => (string)($it['unit'] ?? 'piece'),
                ];
            }
            if ($normalizedItems === []) {
                throw new DomainException('At least one valid procurement item is required.');
            }

            $deptId = !empty($input['department_id']) ? (int)$input['department_id'] : (int)($actor['department_id'] ?? 1);
            $budgetId = !empty($input['budget_id']) ? (int)$input['budget_id'] : null;

            $tx->prepare(
                "INSERT INTO qa_procurement_requests (
                    pr_number, operational_year_id, requester_id, department_id, budget_id,
                    title, justification, needed_by_date, estimated_total, status
                 ) VALUES (:num, :yid, :req, :did, :bid, :ttl, :just, :need, :est, 'STOCK_CHECK_PENDING')"
            )->execute([
                ':num' => $prNum,
                ':yid' => $yearId,
                ':req' => (int)$actor['uid'],
                ':did' => $deptId,
                ':bid' => $budgetId,
                ':ttl' => substr($title, 0, 180),
                ':just' => $justification,
                ':need' => !empty($input['needed_by_date']) ? (string)$input['needed_by_date'] : null,
                ':est' => round($estTotal, 2),
            ]);
            $prId = (int)$tx->lastInsertId();

            $iStmt = $tx->prepare(
                'INSERT INTO qa_procurement_items (
                    procurement_request_id, asset_id, item_name, specification, item_type,
                    requested_qty, available_stock_qty, qty_to_buy, estimated_unit_price, unit
                 ) VALUES (:pr, :aid, :nm, :spec, :tp, :rq, 0, :qb, :up, :un)'
            );
            foreach ($normalizedItems as $ni) {
                $iStmt->execute([
                    ':pr' => $prId,
                    ':aid' => $ni['asset_id'],
                    ':nm' => $ni['item_name'],
                    ':spec' => $ni['specification'],
                    ':tp' => $ni['item_type'],
                    ':rq' => $ni['requested_qty'],
                    ':qb' => $ni['requested_qty'],
                    ':up' => $ni['estimated_unit_price'],
                    ':un' => $ni['unit'],
                ]);
            }

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => $deptId,
                'action' => 'procurement_pr.create',
                'entity_type' => 'procurement_request',
                'entity_id' => (string)$prId,
                'after' => ['pr_number' => $prNum, 'estimated_total' => $estTotal],
            ]);

            return ['id' => $prId, 'pr_number' => $prNum, 'status' => 'STOCK_CHECK_PENDING', 'estimated_total' => round($estTotal, 2)];
        });
    }

    /**
     * Mandatory Stock Check Gate before Procurement (FR-03 / PRD §9):
     * Inspects Asset Dept stock for linked items and computes qty_to_buy.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function performStockCheck(PDO $pdo, int $prId, string $note, array $actor): array
    {
        return Connection::transaction(function (PDO $tx) use ($prId, $note, $actor): array {
            $prStmt = $tx->prepare('SELECT * FROM qa_procurement_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $prStmt->execute([':id' => $prId]);
            $pr = $prStmt->fetch();
            if (!$pr) {
                throw new DomainException('Purchase request not found.');
            }
            if (!in_array($pr['status'], ['DRAFT', 'STOCK_CHECK_PENDING'], true)) {
                throw new DomainException('Purchase request is not pending stock check.');
            }

            $itStmt = $tx->prepare('SELECT * FROM qa_procurement_items WHERE procurement_request_id = :pr ORDER BY id ASC');
            $itStmt->execute([':pr' => $prId]);
            $items = $itStmt->fetchAll();

            $allFulfillable = true;
            $anyInStock = false;
            $updatedEstTotal = 0.0;

            foreach ($items as $it) {
                $avail = 0;
                if (!empty($it['asset_id'])) {
                    $aStmt = $tx->prepare("SELECT quantity FROM qa_assets WHERE id = :aid AND status <> 'archived' LIMIT 1");
                    $aStmt->execute([':aid' => (int)$it['asset_id']]);
                    $avail = max(0, (int)($aStmt->fetchColumn() ?: 0));
                }
                $reqQty = (int)$it['requested_qty'];
                $toBuy = max(0, $reqQty - $avail);
                if ($avail > 0) {
                    $anyInStock = true;
                }
                if ($toBuy > 0) {
                    $allFulfillable = false;
                }
                $updatedEstTotal += ($toBuy * (float)$it['estimated_unit_price']);

                $tx->prepare('UPDATE qa_procurement_items SET available_stock_qty = :av, qty_to_buy = :qb WHERE id = :id')
                    ->execute([':av' => $avail, ':qb' => $toBuy, ':id' => (int)$it['id']]);
            }

            $stockStatus = $allFulfillable
                ? 'IN_STOCK_FULFILLABLE'
                : ($anyInStock ? 'PARTIAL_STOCK' : 'NOT_IN_STOCK');

            $tx->prepare(
                'UPDATE qa_procurement_requests
                 SET stock_check_status = :scs, stock_checked_by = :by, stock_checked_at = NOW(),
                     stock_check_note = :nt, estimated_total = :est
                 WHERE id = :id'
            )->execute([
                ':scs' => $stockStatus,
                ':by' => (int)$actor['uid'],
                ':nt' => substr(trim($note), 0, 500),
                ':est' => round($updatedEstTotal, 2),
                ':id' => $prId,
            ]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => (int)$pr['department_id'],
                'action' => 'procurement_pr.stock_check',
                'entity_type' => 'procurement_request',
                'entity_id' => (string)$prId,
                'after' => ['stock_check_status' => $stockStatus, 'estimated_total' => round($updatedEstTotal, 2)],
            ]);

            return [
                'id' => $prId,
                'stock_check_status' => $stockStatus,
                'estimated_total' => round($updatedEstTotal, 2),
            ];
        });
    }

    /**
     * Budget Gate + Submit into 3-Stage Approval Chain.
     * Blocks `IN_STOCK_FULFILLABLE` requests unless explicit override, and blocks insufficient budget.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function runBudgetGateAndSubmit(PDO $pdo, int $prId, ?int $budgetId, array $actor): array
    {
        return Connection::transaction(function (PDO $tx) use ($prId, $budgetId, $actor): array {
            $prStmt = $tx->prepare('SELECT * FROM qa_procurement_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $prStmt->execute([':id' => $prId]);
            $pr = $prStmt->fetch();
            if (!$pr) {
                throw new DomainException('Purchase request not found.');
            }
            if ($pr['stock_check_status'] === 'PENDING') {
                throw new DomainException('Asset Dept stock check must be completed before budget gate.');
            }
            if ($pr['stock_check_status'] === 'IN_STOCK_FULFILLABLE') {
                $tx->prepare("UPDATE qa_procurement_requests SET status = 'REJECTED', decision_note = 'Fulfillable from existing store inventory' WHERE id = :id")
                    ->execute([':id' => $prId]);
                throw new DomainException('Requested items are already available in Asset Dept stock; fulfill via store handover instead of purchasing.');
            }

            $targetBudgetId = $budgetId ?? (!empty($pr['budget_id']) ? (int)$pr['budget_id'] : 0);
            if ($targetBudgetId <= 0) {
                throw new DomainException('budget_id is required to pass budget gate.');
            }

            $bStmt = $tx->prepare('SELECT * FROM qa_fin_budgets WHERE id = :bid LIMIT 1 FOR UPDATE');
            $bStmt->execute([':bid' => $targetBudgetId]);
            $budget = $bStmt->fetch();
            if (!$budget || $budget['status'] !== 'approved') {
                throw new DomainException('Selected budget line must exist and be approved.');
            }

            $remaining = (float)$budget['approved_amount'] + (float)$budget['amended_amount']
                - (float)$budget['committed_amount'] - (float)$budget['spent_amount'];

            if ($remaining < (float)$pr['estimated_total']) {
                $tx->prepare(
                    "UPDATE qa_procurement_requests
                     SET budget_id = :bid, budget_check_status = 'BLOCKED_INSUFFICIENT',
                         budget_checked_at = NOW(), status = 'BUDGET_BLOCKED'
                     WHERE id = :id"
                )->execute([':bid' => $targetBudgetId, ':id' => $prId]);

                return ['id' => $prId, 'budget_check_status' => 'BLOCKED_INSUFFICIENT', 'status' => 'BUDGET_BLOCKED', 'remaining_budget' => round($remaining, 2)];
            }

            $tx->prepare(
                "UPDATE qa_procurement_requests
                 SET budget_id = :bid, budget_check_status = 'PASSED',
                     budget_checked_at = NOW(), status = 'PENDING_DEPARTMENT_HEAD'
                 WHERE id = :id"
            )->execute([':bid' => $targetBudgetId, ':id' => $prId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'procurement_pr.budget_passed',
                'entity_type' => 'procurement_request',
                'entity_id' => (string)$prId,
                'after' => ['budget_id' => $targetBudgetId, 'status' => 'PENDING_DEPARTMENT_HEAD'],
            ]);

            return ['id' => $prId, 'budget_check_status' => 'PASSED', 'status' => 'PENDING_DEPARTMENT_HEAD'];
        });
    }

    /**
     * 3-Stage PR Approval: dept_head -> finance_review -> deputy.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function decidePrStage(PDO $pdo, int $prId, string $stage, string $decision, string $note, array $actor): array
    {
        if (!in_array($stage, ['dept_head', 'finance', 'deputy'], true) || !in_array($decision, ['approve', 'reject'], true)) {
            throw new DomainException('Invalid procurement approval stage or decision.');
        }

        return Connection::transaction(function (PDO $tx) use ($prId, $stage, $decision, $note, $actor): array {
            $stmt = $tx->prepare('SELECT * FROM qa_procurement_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $prId]);
            $pr = $stmt->fetch();
            if (!$pr) {
                throw new DomainException('Purchase request not found.');
            }
            Rbac::assertNotSelfApproval((int)$actor['uid'], (int)$pr['requester_id'], (string)$actor['role']);

            $expected = match ($stage) {
                'dept_head' => 'PENDING_DEPARTMENT_HEAD',
                'finance' => 'PENDING_FINANCE_REVIEW',
                'deputy' => 'PENDING_DEPUTY_CHAIRPERSON',
            };
            if ($pr['status'] !== $expected) {
                throw new DomainException("Purchase request is not at {$expected} stage.");
            }

            $next = $decision === 'reject'
                ? 'REJECTED'
                : match ($stage) {
                    'dept_head' => 'PENDING_FINANCE_REVIEW',
                    'finance' => 'PENDING_DEPUTY_CHAIRPERSON',
                    'deputy' => 'APPROVED',
                };

            $colBy = match ($stage) {
                'dept_head' => 'dept_head_id',
                'finance' => 'finance_reviewer_id',
                'deputy' => 'deputy_id',
            };
            $colAt = match ($stage) {
                'dept_head' => 'dept_head_at',
                'finance' => 'finance_reviewed_at',
                'deputy' => 'deputy_at',
            };

            $tx->prepare(
                "UPDATE qa_procurement_requests
                 SET status = :st, {$colBy} = :by, {$colAt} = NOW(), decision_note = :nt
                 WHERE id = :id"
            )->execute([':st' => $next, ':by' => (int)$actor['uid'], ':nt' => substr(trim($note), 0, 500), ':id' => $prId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => "procurement_pr.{$stage}_{$decision}",
                'entity_type' => 'procurement_request',
                'entity_id' => (string)$prId,
                'before' => ['status' => $expected],
                'after' => ['status' => $next],
            ]);

            return ['id' => $prId, 'status' => $next];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function addQuotation(PDO $pdo, int $prId, array $input, array $actor): array
    {
        $supplierId = (int)($input['supplier_id'] ?? 0);
        $quotedTotal = round((float)($input['quoted_total'] ?? 0), 2);
        if ($supplierId <= 0 || $quotedTotal <= 0) {
            throw new DomainException('supplier_id and positive quoted_total are required.');
        }

        $pdo->prepare(
            'INSERT INTO qa_procurement_quotations (
                procurement_request_id, supplier_id, quote_number, quoted_total,
                delivery_days, warranty_terms, payment_terms, document_id, entered_by
             ) VALUES (:pr, :sup, :qn, :tot, :dd, :wt, :pt, :doc, :by)'
        )->execute([
            ':pr' => $prId,
            ':sup' => $supplierId,
            ':qn' => $input['quote_number'] ?? null,
            ':tot' => $quotedTotal,
            ':dd' => !empty($input['delivery_days']) ? (int)$input['delivery_days'] : null,
            ':wt' => $input['warranty_terms'] ?? null,
            ':pt' => $input['payment_terms'] ?? null,
            ':doc' => !empty($input['document_id']) ? (int)$input['document_id'] : null,
            ':by' => (int)$actor['uid'],
        ]);
        $qId = (int)$pdo->lastInsertId();

        return ['id' => $qId, 'procurement_request_id' => $prId, 'quoted_total' => $quotedTotal];
    }

    /**
     * Select winning quotation, commit budget, and issue Purchase Order.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function issuePurchaseOrder(PDO $pdo, int $prId, array $input, array $actor): array
    {
        $quotationId = (int)($input['quotation_id'] ?? 0);
        $reason = trim((string)($input['selection_reason'] ?? 'Best evaluated quotation'));
        if ($quotationId <= 0) {
            throw new DomainException('quotation_id is required to issue a Purchase Order.');
        }

        return Connection::transaction(function (PDO $tx) use ($prId, $quotationId, $reason, $input, $actor): array {
            $prStmt = $tx->prepare('SELECT * FROM qa_procurement_requests WHERE id = :id LIMIT 1 FOR UPDATE');
            $prStmt->execute([':id' => $prId]);
            $pr = $prStmt->fetch();
            if (!$pr || $pr['status'] !== 'APPROVED') {
                throw new DomainException('Purchase request must be APPROVED before issuing a Purchase Order.');
            }
            if (empty($pr['budget_id'])) {
                throw new DomainException('Approved PR has no budget line assigned.');
            }

            $qStmt = $tx->prepare('SELECT * FROM qa_procurement_quotations WHERE id = :qid AND procurement_request_id = :pr LIMIT 1 FOR UPDATE');
            $qStmt->execute([':qid' => $quotationId, ':pr' => $prId]);
            $quote = $qStmt->fetch();
            if (!$quote) {
                throw new DomainException('Quotation not found for this purchase request.');
            }

            $poTotal = (float)$quote['quoted_total'];
            $budgetId = (int)$pr['budget_id'];
            $bStmt = $tx->prepare('SELECT * FROM qa_fin_budgets WHERE id = :bid LIMIT 1 FOR UPDATE');
            $bStmt->execute([':bid' => $budgetId]);
            $budget = $bStmt->fetch();
            $remaining = (float)$budget['approved_amount'] + (float)$budget['amended_amount']
                - (float)$budget['committed_amount'] - (float)$budget['spent_amount'];
            if ($remaining < $poTotal) {
                throw new DomainException('Selected quotation exceeds remaining available budget.');
            }

            $tx->prepare('UPDATE qa_procurement_quotations SET is_selected = 0 WHERE procurement_request_id = :pr')
                ->execute([':pr' => $prId]);
            $tx->prepare('UPDATE qa_procurement_quotations SET is_selected = 1, selection_reason = :rsn WHERE id = :qid')
                ->execute([':rsn' => substr($reason, 0, 500), ':qid' => $quotationId]);

            $tx->prepare('UPDATE qa_fin_budgets SET committed_amount = committed_amount + :amt WHERE id = :bid')
                ->execute([':amt' => $poTotal, ':bid' => $budgetId]);

            $poNum = AssetService::nextSequenceCode($tx, 'PO', 'qa_purchase_orders', 'po_number');
            $tx->prepare(
                "INSERT INTO qa_purchase_orders (
                    po_number, procurement_request_id, supplier_id, selected_quotation_id, budget_id,
                    total_amount, expected_delivery_date, status, issued_by, issued_at
                 ) VALUES (:num, :pr, :sup, :qid, :bid, :tot, :edd, 'ISSUED', :by, NOW())"
            )->execute([
                ':num' => $poNum,
                ':pr' => $prId,
                ':sup' => (int)$quote['supplier_id'],
                ':qid' => $quotationId,
                ':bid' => $budgetId,
                ':tot' => $poTotal,
                ':edd' => !empty($input['expected_delivery_date']) ? (string)$input['expected_delivery_date'] : null,
                ':by' => (int)$actor['uid'],
            ]);
            $poId = (int)$tx->lastInsertId();

            $tx->prepare("UPDATE qa_procurement_requests SET status = 'ORDERED' WHERE id = :id")
                ->execute([':id' => $prId]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'procurement_po.issue',
                'entity_type' => 'purchase_order',
                'entity_id' => (string)$poId,
                'after' => ['po_number' => $poNum, 'total_amount' => $poTotal],
            ]);

            return ['id' => $poId, 'po_number' => $poNum, 'total_amount' => $poTotal, 'status' => 'ISSUED'];
        });
    }

    /**
     * Receive Goods (GRN) and automatically hand off accepted items into Asset Dept stock!
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function receiveGoods(PDO $pdo, int $poId, array $input, array $actor): array
    {
        $receivedLines = $input['items'] ?? [];
        $partial = !empty($input['partial']);

        return Connection::transaction(function (PDO $tx) use ($poId, $receivedLines, $partial, $input, $actor): array {
            $poStmt = $tx->prepare('SELECT * FROM qa_purchase_orders WHERE id = :id LIMIT 1 FOR UPDATE');
            $poStmt->execute([':id' => $poId]);
            $po = $poStmt->fetch();
            if (!$po || !in_array($po['status'], ['ISSUED', 'PARTIALLY_RECEIVED'], true)) {
                throw new DomainException('Purchase order is not open for receiving.');
            }

            $prItemsStmt = $tx->prepare('SELECT * FROM qa_procurement_items WHERE procurement_request_id = :pr ORDER BY id ASC');
            $prItemsStmt->execute([':pr' => (int)$po['procurement_request_id']]);
            $prItems = $prItemsStmt->fetchAll();
            if ($prItems === []) {
                throw new DomainException('No procurement items found for this PO.');
            }

            $grnNum = AssetService::nextSequenceCode($tx, 'GRN', 'qa_goods_receipts', 'grn_number');
            $inspStatus = $partial ? 'PARTIAL' : 'ACCEPTED';
            $tx->prepare(
                'INSERT INTO qa_goods_receipts (
                    grn_number, po_id, received_by, inspected_by, received_at,
                    inspection_status, notes, asset_handoff_completed
                 ) VALUES (:num, :poid, :rby, :iby, NOW(), :st, :nt, 1)'
            )->execute([
                ':num' => $grnNum,
                ':poid' => $poId,
                ':rby' => (int)$actor['uid'],
                ':iby' => (int)$actor['uid'],
                ':st' => $inspStatus,
                ':nt' => $input['notes'] ?? null,
            ]);
            $grnId = (int)$tx->lastInsertId();

            $lineMap = [];
            if (is_array($receivedLines)) {
                foreach ($receivedLines as $rl) {
                    if (is_array($rl) && !empty($rl['procurement_item_id'])) {
                        $lineMap[(int)$rl['procurement_item_id']] = $rl;
                    }
                }
            }

            $handoffAssets = [];
            $grniStmt = $tx->prepare(
                'INSERT INTO qa_goods_receipt_items (
                    grn_id, procurement_item_id, ordered_qty, received_qty, accepted_qty, rejected_qty, condition_note, created_asset_id
                 ) VALUES (:gid, :piid, :oq, :rq, :aq, :rej, :cn, :caid)'
            );

            foreach ($prItems as $pi) {
                $piId = (int)$pi['id'];
                $ordered = max(1, (int)$pi['qty_to_buy']);
                $override = $lineMap[$piId] ?? null;
                $recQty = $override ? max(0, (int)($override['received_qty'] ?? $ordered)) : $ordered;
                $accQty = $override ? max(0, min($recQty, (int)($override['accepted_qty'] ?? $recQty))) : $recQty;
                $rejQty = max(0, $recQty - $accQty);
                $createdAssetId = null;

                if ($accQty > 0 && $pi['item_type'] !== 'service') {
                    if (!empty($pi['asset_id'])) {
                        AssetService::recordMovement($tx, (int)$pi['asset_id'], [
                            'movement_type' => 'incoming',
                            'quantity_delta' => $accQty,
                            'reference_type' => 'goods_receipt',
                            'reference_id' => $grnId,
                            'reason' => "GRN {$grnNum} for PO {$po['po_number']}",
                        ], $actor);
                        $createdAssetId = (int)$pi['asset_id'];
                    } elseif (!empty($input['default_asset_category_id'])) {
                        $newAsset = AssetService::createAsset($tx, [
                            'name_en' => (string)$pi['item_name'],
                            'category_id' => (int)$input['default_asset_category_id'],
                            'location_id' => !empty($input['default_location_id']) ? (int)$input['default_location_id'] : null,
                            'quantity' => $accQty,
                            'unit' => (string)$pi['unit'],
                            'unit_cost' => (float)$pi['estimated_unit_price'],
                            'acquisition_source' => 'purchase',
                        ], $actor);
                        $createdAssetId = (int)$newAsset['id'];
                    }
                    if ($createdAssetId !== null) {
                        $handoffAssets[] = $createdAssetId;
                    }
                }

                $grniStmt->execute([
                    ':gid' => $grnId,
                    ':piid' => $piId,
                    ':oq' => $ordered,
                    ':rq' => $recQty,
                    ':aq' => $accQty,
                    ':rej' => $rejQty,
                    ':cn' => $override['condition_note'] ?? null,
                    ':caid' => $createdAssetId,
                ]);
            }

            $nextPoStatus = $partial ? 'PARTIALLY_RECEIVED' : 'RECEIVED';
            $nextPrStatus = $partial ? 'PARTIALLY_RECEIVED' : 'RECEIVED';
            $tx->prepare('UPDATE qa_purchase_orders SET status = :st WHERE id = :id')
                ->execute([':st' => $nextPoStatus, ':id' => $poId]);
            $tx->prepare('UPDATE qa_procurement_requests SET status = :st WHERE id = :id')
                ->execute([':st' => $nextPrStatus, ':id' => (int)$po['procurement_request_id']]);

            AuditLogger::log($tx, [
                'actor_user_id' => (int)$actor['uid'],
                'actor_role' => (string)$actor['role'],
                'department_id' => 2,
                'action' => 'procurement_grn.receive',
                'entity_type' => 'goods_receipt',
                'entity_id' => (string)$grnId,
                'after' => ['grn_number' => $grnNum, 'po_status' => $nextPoStatus, 'handoff_assets' => $handoffAssets],
            ]);

            return ['grn_id' => $grnId, 'grn_number' => $grnNum, 'po_status' => $nextPoStatus, 'handoff_asset_ids' => $handoffAssets];
        });
    }

    /**
     * Pay a Purchase Order: posts immutable expense ledger entry, converts committed -> spent budget, marks PO PAID.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function payPurchaseOrder(PDO $pdo, int $poId, array $input, array $actor): array
    {
        $accountId = (int)($input['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new DomainException('account_id is required to pay a Purchase Order.');
        }

        return Connection::transaction(function (PDO $tx) use ($poId, $accountId, $input, $actor): array {
            $poStmt = $tx->prepare('SELECT * FROM qa_purchase_orders WHERE id = :id LIMIT 1 FOR UPDATE');
            $poStmt->execute([':id' => $poId]);
            $po = $poStmt->fetch();
            if (!$po || !in_array($po['status'], ['RECEIVED', 'PARTIALLY_RECEIVED', 'ISSUED'], true)) {
                throw new DomainException('Purchase order cannot be paid in its current state.');
            }

            $catId = $tx->query("SELECT id FROM qa_fin_categories WHERE code = 'EXP_ASSET' LIMIT 1")->fetchColumn();
            $txRes = BudgetAndLedgerService::postTransaction($tx, [
                'type' => 'expense',
                'account_id' => $accountId,
                'category_id' => $catId ? (int)$catId : null,
                'budget_id' => (int)$po['budget_id'],
                'procurement_po_id' => $poId,
                'consume_committed' => true,
                'amount' => (float)$po['total_amount'],
                'payment_method' => $input['payment_method'] ?? 'bank_transfer',
                'voucher_number' => $input['voucher_number'] ?? $po['po_number'],
                'description' => "Payment for Purchase Order {$po['po_number']}",
            ], $actor);

            $tx->prepare("UPDATE qa_purchase_orders SET status = 'PAID' WHERE id = :id")->execute([':id' => $poId]);
            $tx->prepare("UPDATE qa_procurement_requests SET status = 'CLOSED' WHERE id = :id")->execute([':id' => (int)$po['procurement_request_id']]);

            return ['po_id' => $poId, 'po_status' => 'PAID', 'tx_number' => $txRes['tx_number']];
        });
    }
}
