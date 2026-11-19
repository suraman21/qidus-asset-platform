<?php
declare(strict_types=1);

use QidusAsset\Database\Connection;

$pdo = Connection::get();

$budgetTotal = (float)$pdo->query("SELECT COALESCE(SUM(approved_amount + amended_amount), 0) FROM qa_fin_budgets")->fetchColumn();
$incomeTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM qa_fin_transactions WHERE type = 'income' AND status = 'confirmed'")->fetchColumn();
$expenseTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM qa_fin_transactions WHERE type = 'expense' AND status = 'confirmed'")->fetchColumn();
$openPrCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_procurement_requests WHERE status NOT IN ('RECEIVED','CLOSED','REJECTED')")->fetchColumn();
$docCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_documents WHERE is_archived = 0")->fetchColumn();
$unmatchedBank = (int)$pdo->query("SELECT COUNT(*) FROM qa_fin_bank_statement_lines WHERE match_status = 'unmatched'")->fetchColumn();

$recentLedger = $pdo->query(
    "SELECT id, tx_number, entry_kind, type, amount, description, transaction_date
     FROM qa_fin_transactions ORDER BY id DESC LIMIT 8"
)->fetchAll();

qaRenderShell('Finance, Procurement & Document Vault', 'finance', $user, static function () use (
    $budgetTotal, $incomeTotal, $expenseTotal, $openPrCount, $docCount, $unmatchedBank, $recentLedger
): void {
    ?>
    <section class="qa-kpi-grid" aria-label="Finance Department KPIs">
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Allocated Budget</div>
        <div class="qa-kpi-value tabular-nums"><?= number_format($budgetTotal, 2) ?></div>
        <div class="qa-kpi-meta">ETB across active lines</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Recorded Income</div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-success);"><?= number_format($incomeTotal, 2) ?></div>
        <div class="qa-kpi-meta">Immutable ledger credits</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Recorded Expenses</div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-warning);"><?= number_format($expenseTotal, 2) ?></div>
        <div class="qa-kpi-meta">Vouched disbursements</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Open Purchase Requests</div>
        <div class="qa-kpi-value tabular-nums" style="color:var(--accent-primary);"><?= $openPrCount ?></div>
        <div class="qa-kpi-meta">Stock &amp; budget gated</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Vault Documents</div>
        <div class="qa-kpi-value tabular-nums"><?= $docCount ?></div>
        <div class="qa-kpi-meta">SHA-256 indexed archive</div>
      </div>
      <div class="qa-kpi-card">
        <div class="qa-kpi-label">Unmatched Bank Lines</div>
        <div class="qa-kpi-value tabular-nums"><?= $unmatchedBank ?></div>
        <div class="qa-kpi-meta">Pending reconciliation</div>
      </div>
    </section>

    <section class="qa-card">
      <div class="qa-card-header">
        <h2 class="qa-card-title">Immutable Financial Ledger (No Hard Deletes)</h2>
        <span class="qa-badge warning">Dept 2 Schema Active</span>
      </div>
      <?php if ($recentLedger === []): ?>
        <p style="color:var(--text-secondary);margin:0;">Ledger initialized and protected by append-only reversal policy. Finance, Procurement, and Document Vault workflows unlock in Phase 3.</p>
      <?php else: ?>
        <div class="qa-table-wrap">
          <table class="qa-table">
            <thead><tr><th>Tx #</th><th>Kind</th><th>Type</th><th>Amount (ETB)</th><th>Date</th><th>Description</th></tr></thead>
            <tbody>
              <?php foreach ($recentLedger as $row): ?>
                <tr>
                  <td><code><?= e($row['tx_number']) ?></code></td>
                  <td><span class="qa-badge"><?= e($row['entry_kind']) ?></span></td>
                  <td><span class="qa-badge"><?= e($row['type']) ?></span></td>
                  <td class="tabular-nums"><?= number_format((float)$row['amount'], 2) ?></td>
                  <td class="tabular-nums"><?= e($row['transaction_date']) ?></td>
                  <td><?= e($row['description']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php
});
