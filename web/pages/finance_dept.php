<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Finance\BudgetAndLedgerService;
use QidusAsset\Finance\DocumentVaultService;
use QidusAsset\Finance\ProcurementService;
use QidusAsset\Security\Rbac;

$pdo = Connection::get();
$role = (string)$user['role'];
$tab = (string)($_GET['tab'] ?? 'ledger');
if (!in_array($tab, ['ledger', 'budgets', 'reconcile', 'procurement', 'documents'], true)) {
    $tab = 'ledger';
}

// Document Download & Finance Report Export GET handlers
if (!empty($_GET['download_doc']) && ctype_digit((string)$_GET['download_doc'])) {
    if (!Rbac::can($role, 'document.read')) {
        http_response_code(403);
        exit('Permission denied.');
    }
    $ver = !empty($_GET['version']) ? (int)$_GET['version'] : null;
    $info = DocumentVaultService::resolveForDownload($pdo, (int)$_GET['download_doc'], $user, $ver);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($info['original_filename'])) ?: 'document';
    header('Content-Type: ' . $info['mime_type']);
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($info['full_path']);
    exit;
}

if (!empty($_GET['export_fin_report'])) {
    if (!Rbac::can($role, 'finance.read')) {
        http_response_code(403);
        exit('Permission denied.');
    }
    $repKey = (string)$_GET['export_fin_report'];
    $fmt = (string)($_GET['format'] ?? 'csv');
    $file = BudgetAndLedgerService::exportReport($pdo, $repKey, $fmt, [], $user);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: ' . $file['content_type']);
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    header('X-Content-Type-Options: nosniff');
    echo $file['body'];
    exit;
}

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qaVerifyCsrf($_POST['csrf'] ?? null)) {
        $flashErr = 'Invalid CSRF token. Please refresh and retry.';
    } else {
        $act = (string)($_POST['fin_action'] ?? '');
        try {
            if ($act === 'create_account') {
                if (!Rbac::can($role, 'finance.budget_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $acc = BudgetAndLedgerService::createAccount($pdo, $_POST, $user);
                $flashOk = 'Created account ' . (string)$acc['account_code'] . '.';
            } elseif ($act === 'post_tx') {
                if (!Rbac::can($role, 'finance.ledger_post')) {
                    throw new \DomainException('Permission denied.');
                }
                $tx = BudgetAndLedgerService::postTransaction($pdo, $_POST, $user);
                $flashOk = 'Posted ledger transaction ' . (string)$tx['tx_number'] . '.';
            } elseif ($act === 'reverse_tx') {
                if (!Rbac::can($role, 'finance.ledger_adjust')) {
                    throw new \DomainException('Permission denied.');
                }
                $rev = BudgetAndLedgerService::reverseTransaction($pdo, (int)($_POST['tx_id'] ?? 0), (string)($_POST['reason'] ?? ''), $user);
                $flashOk = 'Posted append-only reversal ' . (string)$rev['tx_number'] . '.';
            } elseif ($act === 'create_budget') {
                if (!Rbac::can($role, 'finance.budget_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $b = BudgetAndLedgerService::createBudget($pdo, $_POST, $user);
                $flashOk = 'Created draft budget line ' . (string)$b['line_code'] . '.';
            } elseif ($act === 'approve_budget') {
                if (!Rbac::can($role, 'finance.budget_approve')) {
                    throw new \DomainException('Permission denied.');
                }
                BudgetAndLedgerService::approveBudget($pdo, (int)($_POST['budget_id'] ?? 0), $user);
                $flashOk = 'Budget line approved.';
            } elseif ($act === 'request_amendment') {
                if (!Rbac::can($role, 'finance.budget_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $amd = BudgetAndLedgerService::requestAmendment($pdo, $_POST, $user);
                $flashOk = 'Submitted amendment ' . (string)$amd['amendment_number'] . '.';
            } elseif ($act === 'decide_amendment') {
                $stage = (string)($_POST['stage'] ?? '');
                if ($stage === 'finance_head' && !Rbac::can($role, 'finance.budget_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                if ($stage === 'deputy' && !Rbac::can($role, 'finance.budget_approve')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = BudgetAndLedgerService::decideAmendment($pdo, (int)($_POST['amendment_id'] ?? 0), $stage, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''), $user);
                $flashOk = 'Amendment updated to ' . (string)$res['status'] . '.';
            } elseif ($act === 'create_reconciliation') {
                if (!Rbac::can($role, 'finance.reconcile')) {
                    throw new \DomainException('Permission denied.');
                }
                $rec = BudgetAndLedgerService::createReconciliation($pdo, $_POST, $user);
                $flashOk = 'Created reconciliation #' . (int)$rec['id'] . ' (Diff: ' . number_format((float)$rec['difference'], 2) . ' ETB).';
            } elseif ($act === 'add_stmt_line') {
                if (!Rbac::can($role, 'finance.reconcile')) {
                    throw new \DomainException('Permission denied.');
                }
                BudgetAndLedgerService::addStatementLines($pdo, (int)($_POST['reconciliation_id'] ?? 0), [[
                    'line_date' => $_POST['line_date'] ?? gmdate('Y-m-d'),
                    'description' => $_POST['description'] ?? '',
                    'debit' => $_POST['debit'] ?? 0,
                    'credit' => $_POST['credit'] ?? 0,
                ]]);
                $flashOk = 'Added bank statement line.';
            } elseif ($act === 'match_stmt_line') {
                if (!Rbac::can($role, 'finance.reconcile')) {
                    throw new \DomainException('Permission denied.');
                }
                BudgetAndLedgerService::matchStatementLine($pdo, (int)($_POST['reconciliation_id'] ?? 0), (int)($_POST['line_id'] ?? 0), (int)($_POST['tx_id'] ?? 0), $user);
                $flashOk = 'Matched statement line to ledger transaction.';
            } elseif ($act === 'finalize_rec') {
                if (!Rbac::can($role, 'finance.reconcile')) {
                    throw new \DomainException('Permission denied.');
                }
                BudgetAndLedgerService::finalizeReconciliation($pdo, (int)($_POST['reconciliation_id'] ?? 0), $user);
                $flashOk = 'Reconciliation finalized.';
            } elseif ($act === 'record_contribution') {
                if (!Rbac::can($role, 'finance.contributions')) {
                    throw new \DomainException('Permission denied.');
                }
                $c = BudgetAndLedgerService::recordMemberContribution($pdo, $_POST, $user);
                $flashOk = 'Recorded contribution for ' . (string)$c['member_name'] . ' (' . (string)$c['tx_number'] . ').';
            } elseif ($act === 'create_supplier') {
                if (!Rbac::can($role, 'procurement.manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $sup = ProcurementService::createSupplier($pdo, $_POST, $user);
                $flashOk = 'Added supplier ' . (string)$sup['name'] . '.';
            } elseif ($act === 'create_pr') {
                $pr = ProcurementService::createPurchaseRequest($pdo, [
                    'title' => $_POST['title'] ?? '',
                    'justification' => $_POST['justification'] ?? '',
                    'budget_id' => $_POST['budget_id'] ?? null,
                    'items' => [[
                        'asset_id' => $_POST['asset_id'] ?? null,
                        'item_name' => $_POST['item_name'] ?? '',
                        'item_type' => $_POST['item_type'] ?? 'fixed_asset',
                        'requested_qty' => $_POST['requested_qty'] ?? 1,
                        'estimated_unit_price' => $_POST['estimated_unit_price'] ?? 0,
                    ]],
                ], $user);
                $flashOk = 'Created Purchase Request ' . (string)$pr['pr_number'] . '.';
            } elseif ($act === 'stock_check_pr') {
                if (!Rbac::can($role, 'procurement.stock_check')) {
                    throw new \DomainException('Permission denied: procurement.stock_check required.');
                }
                $res = ProcurementService::performStockCheck($pdo, (int)($_POST['pr_id'] ?? 0), (string)($_POST['note'] ?? ''), $user);
                $flashOk = 'Stock check completed: ' . (string)$res['stock_check_status'];
            } elseif ($act === 'budget_gate_pr') {
                if (!Rbac::can($role, 'procurement.manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = ProcurementService::runBudgetGateAndSubmit($pdo, (int)($_POST['pr_id'] ?? 0), !empty($_POST['budget_id']) ? (int)$_POST['budget_id'] : null, $user);
                $flashOk = 'Budget gate result: ' . (string)$res['status'];
            } elseif ($act === 'decide_pr') {
                $stage = (string)($_POST['stage'] ?? '');
                if ($stage === 'dept_head' && !Rbac::can($role, 'asset.request_dept_head')) {
                    throw new \DomainException('Permission denied.');
                }
                if ($stage === 'finance' && !Rbac::can($role, 'finance.budget_manage')) {
                    throw new \DomainException('Permission denied.');
                }
                if ($stage === 'deputy' && !Rbac::can($role, 'procurement.approve_deputy')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = ProcurementService::decidePrStage($pdo, (int)($_POST['pr_id'] ?? 0), $stage, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''), $user);
                $flashOk = 'PR updated to ' . (string)$res['status'] . '.';
            } elseif ($act === 'add_quotation') {
                if (!Rbac::can($role, 'procurement.manage')) {
                    throw new \DomainException('Permission denied.');
                }
                ProcurementService::addQuotation($pdo, (int)($_POST['pr_id'] ?? 0), $_POST, $user);
                $flashOk = 'Added supplier quotation.';
            } elseif ($act === 'issue_po') {
                if (!Rbac::can($role, 'procurement.manage')) {
                    throw new \DomainException('Permission denied.');
                }
                $po = ProcurementService::issuePurchaseOrder($pdo, (int)($_POST['pr_id'] ?? 0), $_POST, $user);
                $flashOk = 'Issued Purchase Order ' . (string)$po['po_number'] . '.';
            } elseif ($act === 'receive_po') {
                if (!Rbac::can($role, 'procurement.receive_goods')) {
                    throw new \DomainException('Permission denied.');
                }
                $grn = ProcurementService::receiveGoods($pdo, (int)($_POST['po_id'] ?? 0), $_POST, $user);
                $flashOk = 'Goods Receipt ' . (string)$grn['grn_number'] . ' completed (' . (string)$grn['po_status'] . ').';
            } elseif ($act === 'pay_po') {
                if (!Rbac::can($role, 'finance.ledger_post')) {
                    throw new \DomainException('Permission denied.');
                }
                $pay = ProcurementService::payPurchaseOrder($pdo, (int)($_POST['po_id'] ?? 0), $_POST, $user);
                $flashOk = 'Paid PO via ledger tx ' . (string)$pay['tx_number'] . '.';
            } elseif ($act === 'create_folder') {
                if (!Rbac::can($role, 'document.upload')) {
                    throw new \DomainException('Permission denied.');
                }
                $fld = DocumentVaultService::createFolder($pdo, $_POST, $user);
                $flashOk = 'Created folder ' . (string)$fld['name_en'] . '.';
            } elseif ($act === 'upload_doc') {
                if (!Rbac::can($role, 'document.upload')) {
                    throw new \DomainException('Permission denied.');
                }
                if (empty($_FILES['doc_file']['tmp_name'])) {
                    throw new \DomainException('Choose a JPG, PNG, WEBP, or PDF file.');
                }
                $doc = DocumentVaultService::uploadNewDocument($pdo, (string)$_FILES['doc_file']['tmp_name'], (string)$_FILES['doc_file']['name'], $_POST, $user);
                $flashOk = 'Uploaded document ' . (string)$doc['doc_number'] . '.';
            } elseif ($act === 'add_doc_version') {
                if (!Rbac::can($role, 'document.upload')) {
                    throw new \DomainException('Permission denied.');
                }
                if (empty($_FILES['ver_file']['tmp_name'])) {
                    throw new \DomainException('Choose a version file.');
                }
                $ver = DocumentVaultService::addVersion($pdo, (int)($_POST['document_id'] ?? 0), (string)$_FILES['ver_file']['tmp_name'], (string)$_FILES['ver_file']['name'], (string)($_POST['change_note'] ?? ''), $user);
                $flashOk = 'Uploaded version v' . (int)$ver['version_number'] . '.';
            } elseif ($act === 'decide_doc') {
                if (!Rbac::can($role, 'document.approve')) {
                    throw new \DomainException('Permission denied.');
                }
                $res = DocumentVaultService::decideDocument($pdo, (int)($_POST['document_id'] ?? 0), (string)($_POST['decision'] ?? ''), $user);
                $flashOk = 'Document updated: ' . (string)$res['approval_status'];
            }
        } catch (\Throwable $ex) {
            $flashErr = $ex->getMessage();
        }
    }
}

$budgetTotal = (float)$pdo->query("SELECT COALESCE(SUM(approved_amount + amended_amount), 0) FROM qa_fin_budgets")->fetchColumn();
$incomeTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM qa_fin_transactions WHERE type = 'income' AND status = 'confirmed'")->fetchColumn();
$expenseTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM qa_fin_transactions WHERE type = 'expense' AND status = 'confirmed'")->fetchColumn();
$openPrCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_procurement_requests WHERE status NOT IN ('RECEIVED','CLOSED','REJECTED')")->fetchColumn();
$docCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_documents WHERE is_archived = 0")->fetchColumn();
$unmatchedBank = (int)$pdo->query("SELECT COUNT(*) FROM qa_fin_bank_statement_lines WHERE match_status = 'unmatched'")->fetchColumn();

$accounts = $pdo->query("SELECT * FROM qa_fin_accounts WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$finCategories = $pdo->query("SELECT * FROM qa_fin_categories WHERE is_active = 1 ORDER BY type ASC, id ASC")->fetchAll();
$budgets = $pdo->query(
    "SELECT b.*, c.name_en AS category_name FROM qa_fin_budgets b JOIN qa_fin_categories c ON c.id = b.category_id ORDER BY b.id DESC LIMIT 40"
)->fetchAll();
$amendments = $pdo->query("SELECT * FROM qa_fin_budget_amendments ORDER BY id DESC LIMIT 20")->fetchAll();
$recentLedger = $pdo->query(
    "SELECT t.*, a.account_code FROM qa_fin_transactions t JOIN qa_fin_accounts a ON a.id = t.account_id ORDER BY t.id DESC LIMIT 30"
)->fetchAll();
$reconciliations = $pdo->query(
    "SELECT r.*, a.account_code FROM qa_fin_bank_reconciliations r JOIN qa_fin_accounts a ON a.id = r.account_id ORDER BY r.id DESC LIMIT 15"
)->fetchAll();
$stmtLines = $pdo->query("SELECT * FROM qa_fin_bank_statement_lines ORDER BY id DESC LIMIT 20")->fetchAll();
$contributions = $pdo->query(
    "SELECT c.*, m.name_en AS member_name FROM qa_fin_member_contributions c JOIN qa_member_sync_cache m ON m.id = c.member_cache_id ORDER BY c.id DESC LIMIT 20"
)->fetchAll();
$members = $pdo->query("SELECT external_member_id, name_en FROM qa_member_sync_cache WHERE is_tombstoned = 0 ORDER BY name_en ASC LIMIT 50")->fetchAll();

$suppliers = $pdo->query("SELECT * FROM qa_suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$prs = $pdo->query("SELECT pr.*, u.full_name AS requester_name FROM qa_procurement_requests pr JOIN qa_users u ON u.id = pr.requester_id ORDER BY pr.id DESC LIMIT 20")->fetchAll();
$quotations = $pdo->query("SELECT q.*, s.name AS supplier_name, pr.pr_number FROM qa_procurement_quotations q JOIN qa_suppliers s ON s.id = q.supplier_id JOIN qa_procurement_requests pr ON pr.id = q.procurement_request_id ORDER BY q.id DESC LIMIT 20")->fetchAll();
$pos = $pdo->query("SELECT po.*, s.name AS supplier_name FROM qa_purchase_orders po JOIN qa_suppliers s ON s.id = po.supplier_id ORDER BY po.id DESC LIMIT 20")->fetchAll();
$assetCats = $pdo->query("SELECT id, name_en FROM qa_asset_categories WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$assets = $pdo->query("SELECT id, asset_code, name_en FROM qa_assets WHERE status <> 'archived' ORDER BY id DESC LIMIT 40")->fetchAll();

$folders = $pdo->query("SELECT * FROM qa_doc_folders ORDER BY id ASC")->fetchAll();
$docs = Rbac::can($role, 'document.read') ? DocumentVaultService::listDocuments($pdo, ['q' => $_GET['doc_q'] ?? ''], $user) : [];
$selFinRep = (string)($_GET['fin_report_key'] ?? 'budget_vs_actual');
if (!in_array($selFinRep, BudgetAndLedgerService::SUPPORTED_REPORTS, true)) {
    $selFinRep = 'budget_vs_actual';
}
$finRepData = $tab === 'documents' && Rbac::can($role, 'finance.read') ? BudgetAndLedgerService::generateReport($pdo, $selFinRep) : null;

qaRenderShell(qaT('page.finance'), 'finance', $user, static function () use (
    $role, $tab, $flashOk, $flashErr,
    $budgetTotal, $incomeTotal, $expenseTotal, $openPrCount, $docCount, $unmatchedBank,
    $accounts, $finCategories, $budgets, $amendments, $recentLedger, $reconciliations, $stmtLines,
    $contributions, $members, $suppliers, $prs, $quotations, $pos, $assetCats, $assets,
    $folders, $docs, $selFinRep, $finRepData
): void {
    ?>
    <?php if ($flashOk !== ''): ?>
      <div class="qa-card" style="border-color:rgba(16,185,129,0.5);margin-bottom:16px;color:var(--accent-success);"><strong>Success:</strong> <?= e($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="qa-card" style="border-color:rgba(239,68,68,0.5);margin-bottom:16px;color:var(--accent-danger);"><strong>Error:</strong> <?= e($flashErr) ?></div>
    <?php endif; ?>

    <section class="qa-kpi-grid" aria-label="Finance Department KPIs">
      <div class="qa-kpi-card"><div class="qa-kpi-label">Allocated Budget</div><div class="qa-kpi-value tabular-nums"><?= number_format($budgetTotal, 2) ?></div><div class="qa-kpi-meta">ETB across active lines</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Recorded Income</div><div class="qa-kpi-value tabular-nums" style="color:var(--accent-success);"><?= number_format($incomeTotal, 2) ?></div><div class="qa-kpi-meta">Immutable ledger credits</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Recorded Expenses</div><div class="qa-kpi-value tabular-nums" style="color:var(--accent-warning);"><?= number_format($expenseTotal, 2) ?></div><div class="qa-kpi-meta">Vouched disbursements</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Open Purchase Requests</div><div class="qa-kpi-value tabular-nums" style="color:var(--accent-primary);"><?= $openPrCount ?></div><div class="qa-kpi-meta">Stock &amp; budget gated</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Vault Documents</div><div class="qa-kpi-value tabular-nums"><?= $docCount ?></div><div class="qa-kpi-meta">SHA-256 indexed archive</div></div>
      <div class="qa-kpi-card"><div class="qa-kpi-label">Unmatched Bank Lines</div><div class="qa-kpi-value tabular-nums"><?= $unmatchedBank ?></div><div class="qa-kpi-meta">Pending reconciliation</div></div>
    </section>

    <nav style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;" aria-label="Finance Department Tabs">
      <?php foreach ([
          'ledger' => 'Ledger & Accounts',
          'budgets' => 'Budgets & Reallocations',
          'reconcile' => 'Reconciliation & Contributions',
          'procurement' => 'Procurement & GRN',
          'documents' => 'Document Vault & Reports',
      ] as $k => $lbl): ?>
        <a href="index.php?page=finance&amp;tab=<?= e($k) ?>" class="qa-btn <?= $tab === $k ? 'qa-btn-primary' : '' ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'ledger'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'finance.budget_manage')): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Cash / Bank Account</h2><span class="qa-badge">Treasury</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="create_account">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 2fr;gap:8px;">
              <input name="account_code" class="qa-input" placeholder="Code (CBE_MAIN)" required>
              <input name="name_en" class="qa-input" placeholder="Account Name" required>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <select name="account_type" class="qa-select"><option value="bank">Bank</option><option value="petty_cash">Petty Cash</option><option value="mobile_money">Mobile Money</option></select>
              <input name="opening_balance" type="number" step="0.01" min="0" value="0" class="qa-input" placeholder="Opening ETB">
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Create Account</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if (Rbac::can($role, 'finance.ledger_post') && $accounts !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Post Financial Entry</h2><span class="qa-badge success">Append-Only</span></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="post_tx">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <select name="type" class="qa-select"><option value="income">Income</option><option value="expense">Expense</option><option value="transfer">Transfer</option></select>
              <select name="account_id" class="qa-select"><?php foreach ($accounts as $ac): ?><option value="<?= (int)$ac['id'] ?>"><?= e($ac['account_code']) ?> (<?= number_format((float)$ac['current_balance'], 2) ?>)</option><?php endforeach; ?></select>
              <input name="amount" type="number" step="0.01" min="0.01" class="qa-input" placeholder="Amount ETB" required>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <select name="category_id" class="qa-select"><option value="">— Category —</option><?php foreach ($finCategories as $fc): ?><option value="<?= (int)$fc['id'] ?>"><?= e($fc['name_en']) ?> (<?= e($fc['type']) ?>)</option><?php endforeach; ?></select>
              <select name="budget_id" class="qa-select"><option value="">— Budget Line —</option><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['line_code']) ?></option><?php endforeach; ?></select>
              <select name="counter_account_id" class="qa-select"><option value="">— Counter Acc (Transfer) —</option><?php foreach ($accounts as $ac): ?><option value="<?= (int)$ac['id'] ?>"><?= e($ac['account_code']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="qa-field"><input name="description" class="qa-input" placeholder="Voucher / transaction description" required></div>
            <button type="submit" class="qa-btn qa-btn-primary">Post Ledger Transaction</button>
          </form>
        </div>
        <?php endif; ?>
      </section>

      <section class="qa-card" style="margin-top:16px;">
        <div class="qa-card-header"><h2 class="qa-card-title">Immutable Financial Ledger (No Hard Deletes)</h2><span class="qa-badge warning">Reversal Only</span></div>
        <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Tx #</th><th>Kind</th><th>Type</th><th>Account</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($recentLedger as $row): ?>
            <tr>
              <td><code><?= e($row['tx_number']) ?></code></td><td><span class="qa-badge"><?= e($row['entry_kind']) ?></span></td><td><?= e($row['type']) ?></td><td><?= e($row['account_code']) ?></td>
              <td class="tabular-nums"><?= number_format((float)$row['amount'], 2) ?></td><td><span class="qa-badge <?= $row['status'] === 'confirmed' ? 'success' : 'warning' ?>"><?= e($row['status']) ?></span></td>
              <td>
                <?php if ($row['status'] === 'confirmed' && $row['entry_kind'] !== 'REVERSAL' && Rbac::can($role, 'finance.ledger_adjust')): ?>
                  <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="reverse_tx"><input type="hidden" name="tx_id" value="<?= (int)$row['id'] ?>"><input name="reason" class="qa-input" placeholder="Reversal reason" required style="width:120px;padding:3px 6px;"><button type="submit" class="qa-btn" style="padding:4px 8px;">Reverse</button></form>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </section>

    <?php elseif ($tab === 'budgets'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'finance.budget_manage')): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Create Budget Line</h2></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="create_budget">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 2fr;gap:8px;">
              <input name="line_code" class="qa-input" placeholder="BL-ASSET-01" required>
              <input name="title_en" class="qa-input" placeholder="Budget Line Title" required>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <select name="category_id" class="qa-select"><?php foreach ($finCategories as $fc): if ($fc['type'] === 'expense'): ?><option value="<?= (int)$fc['id'] ?>"><?= e($fc['name_en']) ?></option><?php endif; endforeach; ?></select>
              <input name="approved_amount" type="number" step="0.01" min="1" class="qa-input" placeholder="Amount ETB" required>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Create Budget</button>
          </form>
        </div>

        <?php if ($budgets !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Request Budget Amendment / Reallocation</h2></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="request_amendment">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <select name="amendment_type" class="qa-select"><option value="increase">Increase</option><option value="decrease">Decrease</option><option value="reallocation">Reallocation</option></select>
              <select name="from_budget_id" class="qa-select"><option value="">From (Realloc)...</option><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['line_code']) ?></option><?php endforeach; ?></select>
              <select name="to_budget_id" class="qa-select"><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['line_code']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 2fr;gap:8px;">
              <input name="amount" type="number" step="0.01" min="1" class="qa-input" placeholder="Amount" required>
              <input name="reason" class="qa-input" placeholder="Justification" required>
            </div>
            <button type="submit" class="qa-btn">Submit Amendment</button>
          </form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="qa-panel-grid" style="margin-top:16px;">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Annual Budget Lines</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Line</th><th>Approved+Amd</th><th>Committed</th><th>Spent</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($budgets as $b): ?>
              <tr>
                <td><code><?= e($b['line_code']) ?></code> (<?= e($b['title_en']) ?>)</td>
                <td class="tabular-nums"><?= number_format((float)$b['approved_amount'] + (float)$b['amended_amount'], 2) ?></td>
                <td class="tabular-nums"><?= number_format((float)$b['committed_amount'], 2) ?></td>
                <td class="tabular-nums"><?= number_format((float)$b['spent_amount'], 2) ?></td>
                <td><span class="qa-badge"><?= e($b['status']) ?></span></td>
                <td>
                  <?php if ($b['status'] === 'draft' && Rbac::can($role, 'finance.budget_approve')): ?>
                    <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="approve_budget"><input type="hidden" name="budget_id" value="<?= (int)$b['id'] ?>"><button type="submit" class="qa-btn" style="padding:4px 8px;">Approve</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>

        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Budget Amendments Queue</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>#</th><th>Type</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($amendments as $am): ?>
              <tr>
                <td><code><?= e($am['amendment_number']) ?></code></td><td><?= e($am['amendment_type']) ?></td><td class="tabular-nums"><?= number_format((float)$am['amount'], 2) ?></td><td><span class="qa-badge"><?= e($am['status']) ?></span></td>
                <td>
                  <?php if ($am['status'] === 'PENDING_FINANCE_HEAD' && Rbac::can($role, 'finance.budget_manage')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="decide_amendment"><input type="hidden" name="stage" value="finance_head"><input type="hidden" name="amendment_id" value="<?= (int)$am['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button></form>
                  <?php elseif ($am['status'] === 'PENDING_DEPUTY_CHAIRPERSON' && Rbac::can($role, 'finance.budget_approve')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="decide_amendment"><input type="hidden" name="stage" value="deputy"><input type="hidden" name="amendment_id" value="<?= (int)$am['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><button name="decision" value="reject" class="qa-btn" style="padding:4px 8px;">Reject</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'reconcile'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'finance.reconcile') && $accounts !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Bank / Petty Cash Reconciliation</h2></div>
          <form method="post" style="margin-bottom:12px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="create_reconciliation">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <select name="account_id" class="qa-select"><?php foreach ($accounts as $ac): ?><option value="<?= (int)$ac['id'] ?>"><?= e($ac['account_code']) ?></option><?php endforeach; ?></select>
              <input name="statement_ending_balance" type="number" step="0.01" class="qa-input" placeholder="Statement Ending ETB" required>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <input name="period_start" type="date" value="<?= e(gmdate('Y-m-01')) ?>" class="qa-input" required>
              <input name="period_end" type="date" value="<?= e(gmdate('Y-m-d')) ?>" class="qa-input" required>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Start Reconciliation</button>
          </form>
          <?php if ($reconciliations !== []): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="add_stmt_line">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:8px;">
              <select name="reconciliation_id" class="qa-select"><?php foreach ($reconciliations as $rc): ?><option value="<?= (int)$rc['id'] ?>">#<?= (int)$rc['id'] ?> (<?= e($rc['account_code']) ?>)</option><?php endforeach; ?></select>
              <input name="description" class="qa-input" placeholder="Bank line description" required>
              <input name="credit" type="number" step="0.01" value="0" class="qa-input" placeholder="Credit">
            </div>
            <button type="submit" class="qa-btn">Add Statement Line</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (Rbac::can($role, 'finance.contributions') && $accounts !== [] && $members !== []): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Monthly Member Contributions</h2></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="record_contribution">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <select name="external_member_id" class="qa-select"><?php foreach ($members as $m): ?><option value="<?= e($m['external_member_id']) ?>"><?= e($m['name_en']) ?></option><?php endforeach; ?></select>
              <select name="account_id" class="qa-select"><?php foreach ($accounts as $ac): ?><option value="<?= (int)$ac['id'] ?>"><?= e($ac['account_code']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <input name="ec_month" type="number" min="1" max="13" value="3" class="qa-input" placeholder="EC Month">
              <input name="expected_amount" type="number" step="0.01" value="100" class="qa-input" placeholder="Expected">
              <input name="paid_amount" type="number" step="0.01" value="100" class="qa-input" placeholder="Paid">
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Record Contribution</button>
          </form>
        </div>
        <?php endif; ?>
      </section>

      <section class="qa-panel-grid" style="margin-top:16px;">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Statement Lines &amp; Matching</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Rec#</th><th>Description</th><th>Amt</th><th>Status</th><th>Match</th></tr></thead><tbody>
            <?php foreach ($stmtLines as $ln): ?>
              <tr>
                <td>#<?= (int)$ln['reconciliation_id'] ?></td><td><?= e($ln['description']) ?></td><td class="tabular-nums"><?= number_format((float)$ln['credit'] - (float)$ln['debit'], 2) ?></td><td><span class="qa-badge"><?= e($ln['match_status']) ?></span></td>
                <td>
                  <?php if ($ln['match_status'] === 'unmatched' && $recentLedger !== [] && Rbac::can($role, 'finance.reconcile')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="match_stmt_line"><input type="hidden" name="reconciliation_id" value="<?= (int)$ln['reconciliation_id'] ?>"><input type="hidden" name="line_id" value="<?= (int)$ln['id'] ?>"><select name="tx_id" class="qa-select" style="padding:3px 6px;"><?php foreach ($recentLedger as $rl): ?><option value="<?= (int)$rl['id'] ?>"><?= e($rl['tx_number']) ?></option><?php endforeach; ?></select><button type="submit" class="qa-btn" style="padding:4px 8px;">Match</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Recent Contributions</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Member</th><th>E.C.</th><th>Paid</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($contributions as $c): ?>
              <tr><td><?= e($c['member_name']) ?></td><td class="tabular-nums"><?= (int)$c['ec_year'] ?>/<?= (int)$c['ec_month'] ?></td><td class="tabular-nums"><?= number_format((float)$c['paid_amount'], 2) ?></td><td><span class="qa-badge success"><?= e($c['status']) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'procurement'): ?>
      <section class="qa-panel-grid">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Submit Purchase Request (Stock &amp; Budget Gated)</h2></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="create_pr">
            <div class="qa-field" style="display:grid;grid-template-columns:2fr 1fr;gap:8px;">
              <input name="title" class="qa-input" placeholder="PR Title" required>
              <select name="budget_id" class="qa-select"><option value="">— Budget —</option><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['line_code']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 2fr 1fr 1fr;gap:8px;">
              <select name="asset_id" class="qa-select"><option value="">New Item...</option><?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['asset_code']) ?> — <?= e($a['name_en']) ?></option><?php endforeach; ?></select>
              <input name="item_name" class="qa-input" placeholder="Item Name" required>
              <input name="requested_qty" type="number" min="1" value="1" class="qa-input" required>
              <input name="estimated_unit_price" type="number" step="0.01" min="0" value="100" class="qa-input" required>
            </div>
            <div class="qa-field"><input name="justification" class="qa-input" placeholder="Justification" required></div>
            <button type="submit" class="qa-btn qa-btn-primary">Create Purchase Request</button>
          </form>
        </div>

        <?php if (Rbac::can($role, 'procurement.manage')): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Suppliers &amp; Quotations</h2></div>
          <form method="post" style="margin-bottom:12px;">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="create_supplier">
            <div class="qa-field" style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;">
              <input name="name" class="qa-input" placeholder="Supplier Name" required>
              <input name="phone" class="qa-input" placeholder="Phone">
              <input name="tin_number" class="qa-input" placeholder="TIN">
            </div>
            <button type="submit" class="qa-btn">Add Supplier</button>
          </form>
          <?php if ($prs !== [] && $suppliers !== []): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="add_quotation">
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <select name="pr_id" class="qa-select"><?php foreach ($prs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['pr_number']) ?></option><?php endforeach; ?></select>
              <select name="supplier_id" class="qa-select"><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
              <input name="quoted_total" type="number" step="0.01" min="1" class="qa-input" placeholder="Quote Total ETB" required>
            </div>
            <button type="submit" class="qa-btn">Log Supplier Quotation</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </section>

      <section class="qa-panel-grid" style="margin-top:16px;">
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Purchase Requests Pipeline</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>PR #</th><th>Title</th><th>Stock/Budget</th><th>Status</th><th>Next Gate / Action</th></tr></thead><tbody>
            <?php foreach ($prs as $p): ?>
              <tr>
                <td><code><?= e($p['pr_number']) ?></code></td><td><?= e($p['title']) ?> (<?= number_format((float)$p['estimated_total'], 2) ?>)</td>
                <td><span class="qa-badge"><?= e($p['stock_check_status']) ?></span> <span class="qa-badge"><?= e($p['budget_check_status']) ?></span></td>
                <td><span class="qa-badge info"><?= e($p['status']) ?></span></td>
                <td>
                  <?php if ($p['stock_check_status'] === 'PENDING' && Rbac::can($role, 'procurement.stock_check')): ?>
                    <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="stock_check_pr"><input type="hidden" name="pr_id" value="<?= (int)$p['id'] ?>"><button type="submit" class="qa-btn" style="padding:4px 8px;">Run Stock Check</button></form>
                  <?php elseif ($p['status'] === 'STOCK_CHECK_PENDING' && $p['stock_check_status'] !== 'PENDING' && Rbac::can($role, 'procurement.manage')): ?>
                    <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="budget_gate_pr"><input type="hidden" name="pr_id" value="<?= (int)$p['id'] ?>"><button type="submit" class="qa-btn" style="padding:4px 8px;">Budget Gate</button></form>
                  <?php elseif ($p['status'] === 'PENDING_DEPARTMENT_HEAD' && Rbac::can($role, 'asset.request_dept_head')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="decide_pr"><input type="hidden" name="stage" value="dept_head"><input type="hidden" name="pr_id" value="<?= (int)$p['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Dept Approve</button></form>
                  <?php elseif ($p['status'] === 'PENDING_FINANCE_REVIEW' && Rbac::can($role, 'finance.budget_manage')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="decide_pr"><input type="hidden" name="stage" value="finance"><input type="hidden" name="pr_id" value="<?= (int)$p['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Finance Approve</button></form>
                  <?php elseif ($p['status'] === 'PENDING_DEPUTY_CHAIRPERSON' && Rbac::can($role, 'procurement.approve_deputy')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="decide_pr"><input type="hidden" name="stage" value="deputy"><input type="hidden" name="pr_id" value="<?= (int)$p['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Deputy Approve</button></form>
                  <?php elseif ($p['status'] === 'APPROVED' && Rbac::can($role, 'procurement.manage')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="issue_po"><input type="hidden" name="pr_id" value="<?= (int)$p['id'] ?>"><select name="quotation_id" class="qa-select" style="padding:3px 6px;"><?php foreach ($quotations as $q): if ((int)$q['procurement_request_id'] === (int)$p['id']): ?><option value="<?= (int)$q['id'] ?>"><?= e($q['supplier_name']) ?> (<?= number_format((float)$q['quoted_total'], 2) ?>)</option><?php endif; endforeach; ?></select><button type="submit" class="qa-btn qa-btn-primary" style="padding:4px 8px;">Issue PO</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>

        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Purchase Orders &amp; Goods Receipt (GRN)</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>PO #</th><th>Supplier</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($pos as $po): ?>
              <tr>
                <td><code><?= e($po['po_number']) ?></code></td><td><?= e($po['supplier_name']) ?></td><td class="tabular-nums"><?= number_format((float)$po['total_amount'], 2) ?></td><td><span class="qa-badge"><?= e($po['status']) ?></span></td>
                <td>
                  <?php if (in_array($po['status'], ['ISSUED', 'PARTIALLY_RECEIVED'], true) && Rbac::can($role, 'procurement.receive_goods')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="receive_po"><input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>"><select name="default_asset_category_id" class="qa-select" style="padding:3px 6px;"><?php foreach ($assetCats as $ac): ?><option value="<?= (int)$ac['id'] ?>"><?= e($ac['name_en']) ?></option><?php endforeach; ?></select><button type="submit" class="qa-btn" style="padding:4px 8px;">Receive GRN</button></form>
                  <?php elseif ($po['status'] === 'RECEIVED' && $accounts !== [] && Rbac::can($role, 'finance.ledger_post')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="pay_po"><input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>"><select name="account_id" class="qa-select" style="padding:3px 6px;"><?php foreach ($accounts as $ac): ?><option value="<?= (int)$ac['id'] ?>"><?= e($ac['account_code']) ?></option><?php endforeach; ?></select><button type="submit" class="qa-btn qa-btn-primary" style="padding:4px 8px;">Pay PO</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

    <?php elseif ($tab === 'documents'): ?>
      <section class="qa-panel-grid">
        <?php if (Rbac::can($role, 'document.upload')): ?>
        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Controlled Document Vault Upload</h2><span class="qa-badge info">SHA-256</span></div>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>">
            <input type="hidden" name="fin_action" value="upload_doc">
            <div class="qa-field" style="display:grid;grid-template-columns:2fr 1fr;gap:8px;">
              <input name="title_en" class="qa-input" placeholder="Document Title" required>
              <select name="doc_type" class="qa-select"><option value="invoice">Invoice</option><option value="receipt">Receipt</option><option value="contract">Contract</option><option value="decision">Decision</option><option value="letter">Letter</option><option value="audit_report">Audit Report</option></select>
            </div>
            <div class="qa-field" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <select name="confidentiality" class="qa-select"><option value="department">Department</option><option value="leadership_finance">Leadership &amp; Finance</option><option value="restricted">Restricted</option></select>
              <select name="folder_id" class="qa-select"><option value="">Root Vault</option><?php foreach ($folders as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e($f['name_en']) ?></option><?php endforeach; ?></select>
              <input type="file" name="doc_file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="qa-input" required>
            </div>
            <button type="submit" class="qa-btn qa-btn-primary">Upload to Document Vault</button>
          </form>
        </div>
        <?php endif; ?>

        <div class="qa-card">
          <div class="qa-card-header"><h2 class="qa-card-title">Document Vault Index</h2></div>
          <div class="qa-table-wrap"><table class="qa-table"><thead><tr><th>Doc #</th><th>Title</th><th>Scope</th><th>Ver/Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($docs as $d): ?>
              <tr>
                <td><a href="index.php?page=finance&amp;download_doc=<?= (int)$d['id'] ?>"><code><?= e($d['doc_number']) ?></code></a></td>
                <td><?= e($d['title_en']) ?></td><td><span class="qa-badge"><?= e($d['confidentiality']) ?></span></td>
                <td>v<?= (int)$d['current_version'] ?> · <?= e($d['approval_status']) ?></td>
                <td>
                  <?php if ($d['approval_status'] === 'PENDING_APPROVAL' && Rbac::can($role, 'document.approve')): ?>
                    <form method="post" style="display:inline-flex;gap:4px;"><input type="hidden" name="csrf" value="<?= e(qaCsrfToken()) ?>"><input type="hidden" name="fin_action" value="decide_doc"><input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>"><button name="decision" value="approve" class="qa-btn" style="padding:4px 8px;">Approve</button><button name="decision" value="return" class="qa-btn" style="padding:4px 8px;">Return</button></form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>

      <?php if ($finRepData !== null): ?>
      <section class="qa-card" style="margin-top:16px;">
        <div class="qa-card-header" style="flex-wrap:wrap;gap:10px;">
          <h2 class="qa-card-title"><?= e((string)$finRepData['title']) ?></h2>
          <form method="get" style="display:flex;gap:6px;">
            <input type="hidden" name="page" value="finance"><input type="hidden" name="tab" value="documents">
            <select name="fin_report_key" class="qa-select"><?php foreach (BudgetAndLedgerService::SUPPORTED_REPORTS as $rk): ?><option value="<?= e($rk) ?>" <?= $rk === $selFinRep ? 'selected' : '' ?>><?= e($rk) ?></option><?php endforeach; ?></select>
            <button type="submit" class="qa-btn">Run</button>
          </form>
          <div style="display:flex;gap:6px;">
            <a class="qa-btn qa-btn-primary" href="index.php?page=finance&amp;export_fin_report=<?= e($selFinRep) ?>&amp;format=csv">CSV</a>
            <a class="qa-btn" href="index.php?page=finance&amp;export_fin_report=<?= e($selFinRep) ?>&amp;format=xlsx">XLSX</a>
            <a class="qa-btn" target="_blank" href="index.php?page=finance&amp;export_fin_report=<?= e($selFinRep) ?>&amp;format=html">Printable</a>
          </div>
        </div>
        <div class="qa-table-wrap"><table class="qa-table">
          <thead><tr><?php foreach ($finRepData['columns'] as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
            <?php foreach ($finRepData['rows'] as $row): ?>
              <tr><?php foreach ($finRepData['columns'] as $col): ?><td><?= e((string)($row[$col] ?? '')) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </section>
      <?php endif; ?>
    <?php endif; ?>
    <?php
});
