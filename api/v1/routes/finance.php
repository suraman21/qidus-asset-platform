<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;
use QidusAsset\Finance\BudgetAndLedgerService;
use QidusAsset\Finance\DocumentVaultService;
use QidusAsset\Finance\ProcurementService;
use QidusAsset\Support\IdempotencyService;
use QidusAsset\Support\Response;

/**
 * Phase 3 REST API v1 routes for Department 2:
 * Finance, Budgets, Reconciliations, Contributions, Procurement, and Document Vault.
 *
 * @param list<string> $segments
 */
function qaHandleFinanceRoute(string $method, array $segments): never
{
    $resource = $segments[0] ?? '';
    $sub = array_slice($segments, 1);

    match ($resource) {
        'fin-categories' => qaRouteFinCategories($method),
        'fin-accounts' => qaRouteFinAccounts($method),
        'budgets' => qaRouteBudgets($method, $sub),
        'budget-amendments' => qaRouteBudgetAmendments($method, $sub),
        'ledger' => qaRouteLedger($method, $sub),
        'reconciliations' => qaRouteReconciliations($method, $sub),
        'contributions' => qaRouteContributions($method, $sub),
        'suppliers' => qaRouteSuppliers($method),
        'procurement-requests' => qaRouteProcurementRequests($method, $sub),
        'purchase-orders' => qaRoutePurchaseOrders($method, $sub),
        'doc-folders' => qaRouteDocFolders($method),
        'documents' => qaRouteDocuments($method, $sub),
        'reports' => qaRouteFinanceReports($method, $sub),
        default => Response::err('Finance endpoint not found.', 404, 'ROUTE_NOT_FOUND'),
    };
}

function qaRouteFinCategories(string $method): never
{
    if ($method === 'GET') {
        qaRequireAuth('finance.read');
        $rows = Connection::get()->query('SELECT * FROM qa_fin_categories WHERE is_active = 1 ORDER BY type ASC, id ASC')->fetchAll();
        Response::ok(['categories' => $rows]);
    }
    Response::err('Method not allowed.', 405, 'METHOD_NOT_ALLOWED');
}

function qaRouteFinAccounts(string $method): never
{
    $pdo = Connection::get();
    if ($method === 'GET') {
        qaRequireAuth('finance.read');
        $rows = $pdo->query('SELECT * FROM qa_fin_accounts WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
        Response::ok(['accounts' => $rows]);
    }
    if ($method === 'POST') {
        $auth = qaRequireAuth('finance.budget_manage');
        $acc = BudgetAndLedgerService::createAccount($pdo, Response::jsonBody(), $auth);
        Response::ok(['account' => $acc], 201);
    }
    Response::err('Finance account endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteBudgets(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('finance.read');
        $rows = $pdo->query(
            'SELECT b.*, c.name_en AS category_name, d.name_en AS department_name
             FROM qa_fin_budgets b
             JOIN qa_fin_categories c ON c.id = b.category_id
             JOIN qa_departments d ON d.id = b.department_id
             ORDER BY b.id DESC LIMIT 200'
        )->fetchAll();
        Response::ok(['budgets' => $rows]);
    }
    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('finance.budget_manage');
        $b = BudgetAndLedgerService::createBudget($pdo, Response::jsonBody(), $auth);
        Response::ok(['budget' => $b], 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'approve') {
        $auth = qaRequireAuth('finance.budget_approve');
        $res = BudgetAndLedgerService::approveBudget($pdo, (int)$sub[0], $auth);
        Response::ok($res);
    }
    Response::err('Budget endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteBudgetAmendments(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('finance.read');
        $rows = $pdo->query('SELECT * FROM qa_fin_budget_amendments ORDER BY id DESC LIMIT 100')->fetchAll();
        Response::ok(['amendments' => $rows]);
    }
    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('finance.budget_manage');
        $res = BudgetAndLedgerService::requestAmendment($pdo, Response::jsonBody(), $auth);
        Response::ok(['amendment' => $res], 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'finance-decide') {
        $auth = qaRequireAuth('finance.budget_manage');
        $body = Response::jsonBody();
        $res = BudgetAndLedgerService::decideAmendment($pdo, (int)$sub[0], 'finance_head', (string)($body['decision'] ?? ''), (string)($body['note'] ?? ''), $auth);
        Response::ok($res);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'deputy-decide') {
        $auth = qaRequireAuth('finance.budget_approve');
        $body = Response::jsonBody();
        $res = BudgetAndLedgerService::decideAmendment($pdo, (int)$sub[0], 'deputy', (string)($body['decision'] ?? ''), (string)($body['note'] ?? ''), $auth);
        Response::ok($res);
    }
    Response::err('Budget amendment endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteLedger(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('finance.read');
        [$page, $limit, $offset] = Response::paginationParams();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM qa_fin_transactions')->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT t.*, a.account_code, c.name_en AS category_name
             FROM qa_fin_transactions t
             JOIN qa_fin_accounts a ON a.id = t.account_id
             LEFT JOIN qa_fin_categories c ON c.id = t.category_id
             ORDER BY t.id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        Response::paginated($stmt->fetchAll(), $total, $page, $limit);
    }
    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('finance.ledger_post');
        $body = Response::jsonBody();
        $idem = qaIdempotencyKey($body);
        if ($idem !== null) {
            IdempotencyService::execute($pdo, $idem, 'POST /ledger', (int)$auth['uid'], $body, static fn(): array => [
                'code' => 201,
                'body' => ['transaction' => BudgetAndLedgerService::postTransaction($pdo, $body, $auth)],
            ]);
        }
        $tx = BudgetAndLedgerService::postTransaction($pdo, $body, $auth);
        Response::ok(['transaction' => $tx], 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'reverse') {
        $auth = qaRequireAuth('finance.ledger_adjust');
        $body = Response::jsonBody();
        $res = BudgetAndLedgerService::reverseTransaction($pdo, (int)$sub[0], (string)($body['reason'] ?? ''), $auth);
        Response::ok($res, 201);
    }
    Response::err('Ledger endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteReconciliations(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('finance.reconcile');
        $rows = $pdo->query(
            'SELECT r.*, a.account_code, a.name_en AS account_name
             FROM qa_fin_bank_reconciliations r
             JOIN qa_fin_accounts a ON a.id = r.account_id
             ORDER BY r.id DESC LIMIT 50'
        )->fetchAll();
        Response::ok(['reconciliations' => $rows]);
    }
    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('finance.reconcile');
        $res = BudgetAndLedgerService::createReconciliation($pdo, Response::jsonBody(), $auth);
        Response::ok($res, 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'lines') {
        qaRequireAuth('finance.reconcile');
        $body = Response::jsonBody();
        $res = BudgetAndLedgerService::addStatementLines($pdo, (int)$sub[0], (array)($body['lines'] ?? []));
        Response::ok($res, 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'match') {
        $auth = qaRequireAuth('finance.reconcile');
        $body = Response::jsonBody();
        $res = BudgetAndLedgerService::matchStatementLine($pdo, (int)$sub[0], (int)($body['line_id'] ?? 0), (int)($body['tx_id'] ?? 0), $auth);
        Response::ok($res);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'finalize') {
        $auth = qaRequireAuth('finance.reconcile');
        $res = BudgetAndLedgerService::finalizeReconciliation($pdo, (int)$sub[0], $auth);
        Response::ok($res);
    }
    Response::err('Reconciliation endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteContributions(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('finance.contributions');
        $rows = $pdo->query(
            'SELECT c.*, m.external_member_id, m.name_en AS member_name
             FROM qa_fin_member_contributions c
             JOIN qa_member_sync_cache m ON m.id = c.member_cache_id
             ORDER BY c.id DESC LIMIT 200'
        )->fetchAll();
        Response::ok(['contributions' => $rows]);
    }
    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('finance.contributions');
        $res = BudgetAndLedgerService::recordMemberContribution($pdo, Response::jsonBody(), $auth);
        Response::ok($res, 201);
    }
    Response::err('Contributions endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

function qaRouteSuppliers(string $method): never
{
    $pdo = Connection::get();
    if ($method === 'GET') {
        qaRequireAuth('procurement.manage');
        $rows = $pdo->query('SELECT * FROM qa_suppliers WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
        Response::ok(['suppliers' => $rows]);
    }
    if ($method === 'POST') {
        $auth = qaRequireAuth('procurement.manage');
        $res = ProcurementService::createSupplier($pdo, Response::jsonBody(), $auth);
        Response::ok(['supplier' => $res], 201);
    }
    Response::err('Suppliers endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteProcurementRequests(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('finance.read');
        $rows = $pdo->query(
            'SELECT pr.*, u.full_name AS requester_name, d.name_en AS department_name
             FROM qa_procurement_requests pr
             JOIN qa_users u ON u.id = pr.requester_id
             JOIN qa_departments d ON d.id = pr.department_id
             ORDER BY pr.id DESC LIMIT 100'
        )->fetchAll();
        Response::ok(['procurement_requests' => $rows]);
    }
    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('procurement.request_submit');
        $res = ProcurementService::createPurchaseRequest($pdo, Response::jsonBody(), $auth);
        Response::ok(['procurement_request' => $res], 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0])) {
        $prId = (int)$sub[0];
        $body = Response::jsonBody();
        switch ($sub[1]) {
            case 'stock-check':
                $auth = qaRequireAuth('procurement.stock_check');
                Response::ok(ProcurementService::performStockCheck($pdo, $prId, (string)($body['note'] ?? ''), $auth));
            case 'budget-gate':
                $auth = qaRequireAuth('procurement.manage');
                $bid = !empty($body['budget_id']) ? (int)$body['budget_id'] : null;
                Response::ok(ProcurementService::runBudgetGateAndSubmit($pdo, $prId, $bid, $auth));
            case 'dept-decide':
                $auth = qaRequireAuth('asset.request_dept_head');
                Response::ok(ProcurementService::decidePrStage($pdo, $prId, 'dept_head', (string)($body['decision'] ?? ''), (string)($body['note'] ?? ''), $auth));
            case 'finance-decide':
                $auth = qaRequireAuth('finance.budget_manage');
                Response::ok(ProcurementService::decidePrStage($pdo, $prId, 'finance', (string)($body['decision'] ?? ''), (string)($body['note'] ?? ''), $auth));
            case 'deputy-decide':
                $auth = qaRequireAuth('procurement.approve_deputy');
                Response::ok(ProcurementService::decidePrStage($pdo, $prId, 'deputy', (string)($body['decision'] ?? ''), (string)($body['note'] ?? ''), $auth));
            case 'quotations':
                $auth = qaRequireAuth('procurement.manage');
                Response::ok(ProcurementService::addQuotation($pdo, $prId, $body, $auth), 201);
            case 'issue-po':
                $auth = qaRequireAuth('procurement.manage');
                Response::ok(ProcurementService::issuePurchaseOrder($pdo, $prId, $body, $auth), 201);
        }
    }
    Response::err('Procurement request endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRoutePurchaseOrders(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && $sub === []) {
        qaRequireAuth('finance.read');
        $rows = $pdo->query(
            'SELECT po.*, s.name AS supplier_name
             FROM qa_purchase_orders po
             JOIN qa_suppliers s ON s.id = po.supplier_id
             ORDER BY po.id DESC LIMIT 100'
        )->fetchAll();
        Response::ok(['purchase_orders' => $rows]);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'receive') {
        $auth = qaRequireAuth('procurement.receive_goods');
        Response::ok(ProcurementService::receiveGoods($pdo, (int)$sub[0], Response::jsonBody(), $auth), 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'pay') {
        $auth = qaRequireAuth('finance.ledger_post');
        Response::ok(ProcurementService::payPurchaseOrder($pdo, (int)$sub[0], Response::jsonBody(), $auth));
    }
    Response::err('Purchase order endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

function qaRouteDocFolders(string $method): never
{
    $pdo = Connection::get();
    if ($method === 'GET') {
        qaRequireAuth('document.read');
        $rows = $pdo->query('SELECT * FROM qa_doc_folders ORDER BY id ASC')->fetchAll();
        Response::ok(['folders' => $rows]);
    }
    if ($method === 'POST') {
        $auth = qaRequireAuth('document.upload');
        Response::ok(['folder' => DocumentVaultService::createFolder($pdo, Response::jsonBody(), $auth)], 201);
    }
    Response::err('Document folder endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteDocuments(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && $sub === []) {
        $auth = qaRequireAuth('document.read');
        $docs = DocumentVaultService::listDocuments($pdo, $_GET, $auth);
        Response::ok(['documents' => $docs]);
    }
    if ($method === 'POST' && $sub === []) {
        $auth = qaRequireAuth('document.upload');
        if (!empty($_FILES['file']['tmp_name'])) {
            $res = DocumentVaultService::uploadNewDocument($pdo, (string)$_FILES['file']['tmp_name'], (string)$_FILES['file']['name'], $_POST, $auth);
            Response::ok($res, 201);
        }
        $body = Response::jsonBody(15728640);
        if (empty($body['content_base64']) || empty($body['filename'])) {
            Response::err('Multipart file or content_base64 + filename required.', 422, 'VALIDATION_ERROR');
        }
        Response::ok(DocumentVaultService::uploadBase64Document($pdo, (string)$body['content_base64'], (string)$body['filename'], $body, $auth), 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'versions') {
        $auth = qaRequireAuth('document.upload');
        if (empty($_FILES['file']['tmp_name'])) {
            Response::err('Multipart file required for new version.', 422, 'VALIDATION_ERROR');
        }
        Response::ok(DocumentVaultService::addVersion($pdo, (int)$sub[0], (string)$_FILES['file']['tmp_name'], (string)$_FILES['file']['name'], (string)($_POST['change_note'] ?? ''), $auth), 201);
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'decide') {
        $auth = qaRequireAuth('document.approve');
        $body = Response::jsonBody();
        Response::ok(DocumentVaultService::decideDocument($pdo, (int)$sub[0], (string)($body['decision'] ?? ''), $auth));
    }
    if ($method === 'POST' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'archive') {
        $auth = qaRequireAuth('document.archive');
        $body = Response::jsonBody();
        $arch = array_key_exists('archive', $body) ? (bool)$body['archive'] : true;
        Response::ok(DocumentVaultService::toggleArchive($pdo, (int)$sub[0], $arch, $auth));
    }
    if ($method === 'GET' && count($sub) === 2 && ctype_digit($sub[0]) && $sub[1] === 'download') {
        $auth = qaRequireAuth('document.read');
        $ver = !empty($_GET['version']) ? (int)$_GET['version'] : null;
        $info = DocumentVaultService::resolveForDownload($pdo, (int)$sub[0], $auth, $ver);
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
    Response::err('Document endpoint not found.', 404, 'ROUTE_NOT_FOUND');
}

/**
 * @param list<string> $sub
 */
function qaRouteFinanceReports(string $method, array $sub): never
{
    $pdo = Connection::get();
    if ($method === 'GET' && ($sub[0] ?? '') === 'finance' && !empty($sub[1])) {
        $auth = qaRequireAuth('finance.read');
        $repKey = (string)$sub[1];
        $fmt = strtolower((string)($_GET['format'] ?? 'json'));
        if ($fmt === 'json') {
            Response::ok(BudgetAndLedgerService::generateReport($pdo, $repKey, $_GET));
        }
        $file = BudgetAndLedgerService::exportReport($pdo, $repKey, $fmt, $_GET, $auth);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: ' . $file['content_type']);
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        header('X-Content-Type-Options: nosniff');
        echo $file['body'];
        exit;
    }
    Response::err('Finance report route not found.', 404, 'ROUTE_NOT_FOUND');
}
