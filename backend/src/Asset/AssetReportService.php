<?php
declare(strict_types=1);

namespace QidusAsset\Asset;

use DomainException;
use PDO;
use QidusAsset\Security\AuditLogger;
use QidusAsset\Support\EthiopianCalendar;

/**
 * Asset Department Reporting & Bilingual Export Service (FR-12, PRD §11).
 */
final class AssetReportService
{
    public const SUPPORTED_REPORTS = [
        'inventory_register',
        'low_stock',
        'movements',
        'custody_overdue',
        'member_clothing',
        'maintenance',
        'inspections',
        'disposals',
        'event_reconciliation',
        'valuation',
    ];

    /**
     * @param array<string,mixed> $filters
     * @return array{report_key:string,title:string,generated_at_utc:string,ethiopian_date:string,columns:list<string>,rows:list<array<string,mixed>>,summary:array<string,mixed>}
     */
    public static function generate(PDO $pdo, string $reportKey, array $filters = []): array
    {
        if (!in_array($reportKey, self::SUPPORTED_REPORTS, true)) {
            throw new DomainException("Unsupported report key: {$reportKey}");
        }

        $yearId = !empty($filters['operational_year_id']) ? (int)$filters['operational_year_id'] : null;
        $catId = !empty($filters['category_id']) ? (int)$filters['category_id'] : null;
        $locId = !empty($filters['location_id']) ? (int)$filters['location_id'] : null;
        $dateFrom = !empty($filters['date_from']) ? (string)$filters['date_from'] : null;
        $dateTo = !empty($filters['date_to']) ? (string)$filters['date_to'] : null;

        $columns = [];
        $rows = [];
        $summary = [];
        $title = '';

        switch ($reportKey) {
            case 'inventory_register':
            case 'valuation':
                $title = $reportKey === 'valuation' ? 'Asset Valuation Report' : 'Asset Register Report';
                $where = ['1=1'];
                $params = [];
                if ($catId) {
                    $where[] = 'a.category_id = :cat';
                    $params[':cat'] = $catId;
                }
                if ($locId) {
                    $where[] = 'a.location_id = :loc';
                    $params[':loc'] = $locId;
                }
                $sql = 'SELECT a.asset_code, a.name_en, a.name_am, c.name_en AS category, a.tracking_mode,
                               a.quantity, a.unit, a.unit_cost, (a.quantity * COALESCE(a.unit_cost, 0)) AS total_value,
                               a.condition_status, a.status, l.name_en AS location
                        FROM qa_assets a
                        JOIN qa_asset_categories c ON c.id = a.category_id
                        LEFT JOIN qa_asset_locations l ON l.id = a.location_id
                        WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 1000';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                $columns = ['asset_code', 'name_en', 'name_am', 'category', 'tracking_mode', 'quantity', 'unit', 'unit_cost', 'total_value', 'condition_status', 'status', 'location'];
                $totalVal = array_reduce($rows, static fn(float $acc, array $r): float => $acc + (float)$r['total_value'], 0.0);
                $summary = ['total_items' => count($rows), 'total_valuation_etb' => round($totalVal, 2)];
                break;

            case 'low_stock':
                $title = 'Low Stock & Out-of-Stock Alert Report';
                $rows = $pdo->query(
                    "SELECT a.asset_code, a.name_en, c.name_en AS category, a.quantity, a.min_quantity, a.unit, a.status, l.name_en AS location
                     FROM qa_assets a
                     JOIN qa_asset_categories c ON c.id = a.category_id
                     LEFT JOIN qa_asset_locations l ON l.id = a.location_id
                     WHERE a.tracking_mode IN ('consumable','reusable_member')
                       AND a.quantity <= a.min_quantity AND a.status <> 'archived'
                     ORDER BY a.quantity ASC, a.id DESC LIMIT 1000"
                )->fetchAll();
                $columns = ['asset_code', 'name_en', 'category', 'quantity', 'min_quantity', 'unit', 'status', 'location'];
                $summary = ['low_stock_count' => count($rows)];
                break;

            case 'movements':
                $title = 'Asset & Stock Movement Ledger';
                $where = ['1=1'];
                $params = [];
                if ($yearId) {
                    $where[] = 'm.operational_year_id = :yid';
                    $params[':yid'] = $yearId;
                }
                if ($dateFrom) {
                    $where[] = 'm.movement_date >= :df';
                    $params[':df'] = $dateFrom;
                }
                if ($dateTo) {
                    $where[] = 'm.movement_date <= :dt';
                    $params[':dt'] = $dateTo;
                }
                $stmt = $pdo->prepare(
                    'SELECT m.id, a.asset_code, a.name_en, m.movement_type, m.quantity_delta, m.balance_after,
                            m.reason, m.movement_date, u.username AS performed_by
                     FROM qa_asset_movements m
                     JOIN qa_assets a ON a.id = m.asset_id
                     LEFT JOIN qa_users u ON u.id = m.performed_by
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT 1000'
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                $columns = ['id', 'asset_code', 'name_en', 'movement_type', 'quantity_delta', 'balance_after', 'reason', 'movement_date', 'performed_by'];
                $summary = ['movement_count' => count($rows)];
                break;

            case 'custody_overdue':
                $title = 'Active Custody & Overdue Returns Report';
                $rows = $pdo->query(
                    "SELECT h.handover_number, a.asset_code, a.name_en, h.receiver_display_name, h.quantity,
                            h.expected_return_date, h.verification_method, h.server_received_at
                     FROM qa_asset_handovers h
                     JOIN qa_assets a ON a.id = h.asset_id
                     WHERE h.handover_type = 'issue'
                       AND h.expected_return_date IS NOT NULL
                       AND h.expected_return_date < CURDATE()
                     ORDER BY h.expected_return_date ASC LIMIT 1000"
                )->fetchAll();
                $columns = ['handover_number', 'asset_code', 'name_en', 'receiver_display_name', 'quantity', 'expected_return_date', 'verification_method', 'server_received_at'];
                $summary = ['overdue_count' => count($rows)];
                break;

            case 'member_clothing':
                $title = 'Member Reusable Item & Clothing Issue Summary';
                $rows = $pdo->query(
                    "SELECT m.external_member_id, m.member_code, m.name_en AS member_name, m.section_name,
                            p.clothing_size, p.shoe_size,
                            COALESCE(SUM(CASE WHEN h.handover_type = 'issue' THEN h.quantity ELSE 0 END), 0) AS total_issued,
                            COALESCE(SUM(CASE WHEN h.handover_type IN ('return','partial_return') THEN h.quantity ELSE 0 END), 0) AS total_returned
                     FROM qa_member_sync_cache m
                     LEFT JOIN qa_member_asset_profiles p ON p.member_cache_id = m.id
                     LEFT JOIN qa_asset_handovers h ON h.member_cache_id = m.id
                     WHERE m.is_tombstoned = 0
                     GROUP BY m.id, m.external_member_id, m.member_code, m.name_en, m.section_name, p.clothing_size, p.shoe_size
                     HAVING total_issued > 0 OR p.clothing_size IS NOT NULL
                     ORDER BY m.name_en ASC LIMIT 1000"
                )->fetchAll();
                $columns = ['external_member_id', 'member_code', 'member_name', 'section_name', 'clothing_size', 'shoe_size', 'total_issued', 'total_returned'];
                $summary = ['members_tracked' => count($rows)];
                break;

            case 'maintenance':
                $title = 'Maintenance & Repair History Report';
                $rows = $pdo->query(
                    'SELECT m.ticket_number, a.asset_code, a.name_en, m.maintenance_type, m.priority,
                            m.status, m.scheduled_date, m.parts_cost, m.service_cost,
                            (m.parts_cost + m.service_cost) AS total_cost
                     FROM qa_asset_maintenance m
                     JOIN qa_assets a ON a.id = m.asset_id
                     ORDER BY m.id DESC LIMIT 1000'
                )->fetchAll();
                $columns = ['ticket_number', 'asset_code', 'name_en', 'maintenance_type', 'priority', 'status', 'scheduled_date', 'parts_cost', 'service_cost', 'total_cost'];
                $summary = ['ticket_count' => count($rows)];
                break;

            case 'inspections':
                $title = 'Physical Inspection & Discrepancy Report';
                $rows = $pdo->query(
                    'SELECT i.campaign_code, i.title, i.status, a.asset_code, it.outcome,
                            it.system_qty, it.counted_qty, it.variance_qty, it.scanned_at
                     FROM qa_asset_inspection_items it
                     JOIN qa_asset_inspections i ON i.id = it.inspection_id
                     LEFT JOIN qa_assets a ON a.id = it.asset_id
                     ORDER BY it.id DESC LIMIT 1000'
                )->fetchAll();
                $columns = ['campaign_code', 'title', 'status', 'asset_code', 'outcome', 'system_qty', 'counted_qty', 'variance_qty', 'scanned_at'];
                $summary = ['scanned_rows' => count($rows)];
                break;

            case 'disposals':
                $title = 'Asset Disposal & Retirement Report';
                $rows = $pdo->query(
                    'SELECT d.disposal_number, a.asset_code, a.name_en, d.reason_type, d.status,
                            d.proceeds_amount, d.recipient_name, d.disposal_date
                     FROM qa_asset_disposals d
                     JOIN qa_assets a ON a.id = d.asset_id
                     ORDER BY d.id DESC LIMIT 1000'
                )->fetchAll();
                $columns = ['disposal_number', 'asset_code', 'name_en', 'reason_type', 'status', 'proceeds_amount', 'recipient_name', 'disposal_date'];
                $summary = ['disposal_count' => count($rows)];
                break;

            case 'event_reconciliation':
                $title = 'Event Station Distribution & Reconciliation Report';
                $rows = $pdo->query(
                    'SELECT e.event_code, e.name_en AS event_name, e.status, st.station_name,
                            a.asset_code, al.allocated_qty, al.issued_qty, al.returned_qty,
                            (al.issued_qty - al.returned_qty) AS outstanding_qty
                     FROM qa_event_allocations al
                     JOIN qa_events e ON e.id = al.event_id
                     JOIN qa_event_stations st ON st.id = al.station_id
                     JOIN qa_assets a ON a.id = al.asset_id
                     ORDER BY e.id DESC, st.id ASC LIMIT 1000'
                )->fetchAll();
                $columns = ['event_code', 'event_name', 'status', 'station_name', 'asset_code', 'allocated_qty', 'issued_qty', 'returned_qty', 'outstanding_qty'];
                $summary = ['station_lines' => count($rows)];
                break;
        }

        $eth = EthiopianCalendar::today((string)qa_config('timezone'));

        return [
            'report_key' => $reportKey,
            'title' => $title,
            'generated_at_utc' => gmdate('c'),
            'ethiopian_date' => $eth['formatted_en'] . ' (' . $eth['formatted_am'] . ')',
            'columns' => $columns,
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * Render CSV / XLSX / Printable HTML export and log export in `qa_audit_logs`.
     *
     * @param array<string,mixed> $actor
     * @return array{content_type:string,filename:string,body:string}
     */
    public static function export(PDO $pdo, string $reportKey, string $format, array $filters, array $actor): array
    {
        $report = self::generate($pdo, $reportKey, $filters);
        $format = strtolower($format);
        if (!in_array($format, ['csv', 'xlsx', 'html', 'pdf'], true)) {
            throw new DomainException('Supported export formats: csv, xlsx, html, pdf.');
        }

        AuditLogger::log($pdo, [
            'actor_user_id' => (int)$actor['uid'],
            'actor_role' => (string)$actor['role'],
            'action' => 'report.export_' . $format,
            'entity_type' => 'asset_report',
            'entity_id' => $reportKey,
            'after' => ['row_count' => count($report['rows']), 'filters' => $filters],
        ]);

        $baseName = 'qidus_' . $reportKey . '_' . gmdate('Ymd_His');

        if ($format === 'csv') {
            $fh = fopen('php://temp', 'w+');
            if ($fh === false) {
                throw new DomainException('Unable to create CSV stream.');
            }
            // UTF-8 BOM so Excel opens Amharic text cleanly
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
            return [
                'content_type' => 'text/csv; charset=utf-8',
                'filename' => $baseName . '.csv',
                'body' => $csv,
            ];
        }

        if ($format === 'xlsx' && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray($report['columns'], null, 'A1');
            $rowIndex = 2;
            foreach ($report['rows'] as $row) {
                $line = [];
                foreach ($report['columns'] as $col) {
                    $line[] = $row[$col] ?? '';
                }
                $sheet->fromArray($line, null, 'A' . $rowIndex);
                $rowIndex++;
            }
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $bin = (string)ob_get_clean();
            return [
                'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'filename' => $baseName . '.xlsx',
                'body' => $bin,
            ];
        }

        // HTML (and Dompdf PDF if vendor/dompdf is installed)
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
            . 'h1{font-size:16px;margin:0 0 4px;}p{margin:0 0 12px;color:#475569;font-size:10px;}'
            . 'table{width:100%;border-collapse:collapse;}th,td{border:1px solid #cbd5e1;padding:5px 7px;text-align:left;}'
            . 'th{background:#f1f5f9;font-weight:700;}</style></head><body>'
            . '<h1>' . $esc($school) . ' — ' . $esc($report['title']) . '</h1>'
            . '<p>Generated: ' . $esc($report['generated_at_utc']) . ' · Ethiopian Date: ' . $esc($report['ethiopian_date']) . '</p>'
            . '<table><thead><tr>' . $th . '</tr></thead><tbody>' . $trs . '</tbody></table></body></html>';

        if ($format === 'pdf' && class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            return [
                'content_type' => 'application/pdf',
                'filename' => $baseName . '.pdf',
                'body' => $dompdf->output(),
            ];
        }

        return [
            'content_type' => 'text/html; charset=utf-8',
            'filename' => $baseName . '.html',
            'body' => $html,
        ];
    }
}
