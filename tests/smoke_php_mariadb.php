<?php
declare(strict_types=1);

/**
 * End-to-end PHP 8.2 + MariaDB integration smoke test executed in CI (Phases 1 & 2 / Step A).
 */

require_once dirname(__DIR__) . '/backend/config/bootstrap.php';

use QidusAsset\Asset\AssetAttachmentService;
use QidusAsset\Asset\AssetLifecycleService;
use QidusAsset\Asset\AssetReportService;
use QidusAsset\Asset\AssetService;
use QidusAsset\Asset\AssetWorkflowService;
use QidusAsset\Asset\OperationalYearService;
use QidusAsset\Connector\ConnectorFactory;
use QidusAsset\Database\Connection;
use QidusAsset\Security\BiometricVerifier;
use QidusAsset\Security\JwtService;
use QidusAsset\Security\PasswordService;
use QidusAsset\Security\Rbac;
use QidusAsset\Support\QrLabelService;

$pdo = Connection::get();

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

// 1. Verify tables & seed data
$deptCount = (int)$pdo->query('SELECT COUNT(*) FROM qa_departments')->fetchColumn();
assertTrue($deptCount >= 2, 'Expected at least 2 seeded departments');

$activeYear = OperationalYearService::getActiveYear($pdo);
assertTrue((int)$activeYear['id'] > 0 && $activeYear['status'] === 'active', 'Expected active operational year');

// 2. Create temporary test users & verify Password + JWT + Offline Lease + Connector
$sfx = bin2hex(random_bytes(3));
$adminUser = 'ci_sysadmin_' . $sfx;
$officerUser = 'ci_officer_' . $sfx;
$headUser = 'ci_head_' . $sfx;
$deputyUser = 'ci_deputy_' . $sfx;
$passHash = PasswordService::hash('TestPass#2026!');

$ins = $pdo->prepare(
    "INSERT INTO qa_users (username, full_name, qa_role, department_id, password_hash, is_active)
     VALUES (:u, :n, :r, :d, :p, 1)"
);
$ins->execute([':u' => $adminUser, ':n' => 'CI Admin', ':r' => 'system_admin', ':d' => 1, ':p' => $passHash]);
$adminId = (int)$pdo->lastInsertId();

$ins->execute([':u' => $officerUser, ':n' => 'CI Officer', ':r' => 'asset_officer', ':d' => 1, ':p' => $passHash]);
$officerId = (int)$pdo->lastInsertId();

$ins->execute([':u' => $headUser, ':n' => 'CI Asset Head', ':r' => 'asset_head', ':d' => 1, ':p' => $passHash]);
$headId = (int)$pdo->lastInsertId();

$ins->execute([':u' => $deputyUser, ':n' => 'CI Deputy', ':r' => 'deputy_chairperson', ':d' => 1, ':p' => $passHash]);
$deputyId = (int)$pdo->lastInsertId();

assertTrue(PasswordService::verify('TestPass#2026!', $passHash), 'Password verification works');

// 3. JWT & Offline Lease
$jwt = JwtService::issueAccessToken([
    'uid' => $officerId,
    'usr' => $officerUser,
    'rol' => 'asset_officer',
    'nam' => 'CI Officer',
    'av' => 1,
]);
$verified = JwtService::verify($jwt);
assertTrue($verified['state'] === 'valid' && (int)$verified['payload']['uid'] === $officerId, 'JWT sign & verify works');

$lease = JwtService::issueOfflineLease($officerId, 'dev-ci-001', hash('sha256', random_bytes(16)), [
    'permissions' => Rbac::offlineCapabilitiesForRole('asset_officer'),
]);
$verifiedLease = JwtService::verify($lease, JwtService::offlineLeaseSecret());
assertTrue($verifiedLease['state'] === 'valid' && $verifiedLease['payload']['typ'] === 'offline_lease', 'Offline lease verify works');

// 4. OpenSSL Biometric Keystore signature test (EC P-256) + register device key
$keyRes = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
assertTrue($keyRes !== false, 'OpenSSL EC P-256 key generation');
$pubDetails = openssl_pkey_get_details($keyRes);
$pubPem = $pubDetails['key'];
$canonical = json_encode(['asset_id' => 12, 'member_id' => 'ssms_stu_101', 'nonce' => 'n1'], JSON_UNESCAPED_SLASHES);
openssl_sign((string)$canonical, $sigRaw, $keyRes, OPENSSL_ALGO_SHA256);
assertTrue(BiometricVerifier::verifySignature($pubPem, (string)$canonical, base64_encode($sigRaw)), 'Biometric Keystore EC signature verified');

$deviceUuid = 'dev-ci-' . $sfx;
$pdo->prepare(
    "INSERT INTO qa_device_keys (user_id, device_uuid, device_label, key_algorithm, public_key_pem, biometric_bound, status, approved_by, approved_at)
     VALUES (:u, :d, 'CI Pixel', 'EC_P256', :pk, 1, 'active', :ab, NOW())"
)->execute([':u' => $officerId, ':d' => $deviceUuid, ':pk' => $pubPem, ':ab' => $adminId]);

// 5. Member Connector upsert & tombstone sync + Member Sizing Profile
$connector = ConnectorFactory::memberConnector($pdo);
$connector->upsertBatch([
    ['external_member_id' => 'ci_stu_1_' . $sfx, 'name_en' => 'Abebe Kebede', 'section_name' => 'Grade 5', 'is_tombstoned' => false],
    ['external_member_id' => 'ci_stu_2_' . $sfx, 'name_en' => 'Deleted Student', 'is_tombstoned' => true],
], 'standalone');
$extStu1 = 'ci_stu_1_' . $sfx;
$extStu2 = 'ci_stu_2_' . $sfx;
$search = $connector->search('Abebe');
assertTrue($search['total'] >= 1, 'Member search returns active record');
$sync = $connector->syncChanges(null, 50);
assertTrue(in_array($extStu2, $sync['tombstones'], true), 'Tombstone propagated in incremental sync');

$adminActor = ['uid' => $adminId, 'role' => 'system_admin', 'department_id' => 1, 'device_uuid' => null];
$officerActor = ['uid' => $officerId, 'role' => 'asset_officer', 'department_id' => 1, 'device_uuid' => $deviceUuid];
$headActor = ['uid' => $headId, 'role' => 'asset_head', 'department_id' => 1, 'device_uuid' => null];
$deputyActor = ['uid' => $deputyId, 'role' => 'deputy_chairperson', 'department_id' => 1, 'device_uuid' => null];

$prof = AssetService::upsertMemberAssetProfile($pdo, $extStu1, ['clothing_size' => 'M', 'shoe_size' => '41'], $officerActor);
assertTrue(($prof['member']['clothing_size'] ?? null) === 'M', 'Member clothing size profile saved');

// 6. RBAC separation of duty check
$selfApprovalRejected = false;
try {
    Rbac::assertNotSelfApproval($officerId, $officerId, 'asset_officer');
} catch (\DomainException $e) {
    $selfApprovalRejected = true;
}
assertTrue($selfApprovalRejected, 'Self-approval blocked for non-admin');

// ============================================================================
// PHASE 2 / STEP A: Complete Department 1 (Asset Dept) Verification
// ============================================================================
$newCat = AssetService::createCategory($pdo, [
    'code' => 'CAT_' . strtoupper($sfx),
    'name_en' => 'Liturgical Robes',
    'tracking_mode' => 'reusable_member',
    'default_min_qty' => 2,
], $headActor);
$newLoc = AssetService::createLocation($pdo, [
    'code' => 'LOC_' . strtoupper($sfx),
    'name_en' => 'Choir Annex',
], $headActor);
$catId = (int)$newCat['id'];
$locId = (int)$newLoc['id'];
assertTrue($catId > 0 && $locId > 0, 'Category and location created');

// 7. Create reusable asset + PATCH updateAsset + QR SVG + QR Sheet + QR reprint
$uniform = AssetService::createAsset($pdo, [
    'name_en' => 'Choir Tunic Set',
    'category_id' => $catId,
    'location_id' => $locId,
    'quantity' => 20,
    'min_quantity' => 5,
    'variants' => [
        ['size_label' => 'M', 'quantity' => 12, 'min_quantity' => 3],
        ['size_label' => 'L', 'quantity' => 8, 'min_quantity' => 2],
    ],
], $officerActor);
$assetId = (int)$uniform['id'];
$oldQr = (string)$uniform['qr_token'];
assertTrue(count($uniform['variants']) === 2, 'Asset created with 2 size variants');
assertTrue(AssetService::resolveByQrToken($pdo, $oldQr)['id'] === $assetId, 'Initial QR resolves asset');

$updated = AssetService::updateAsset($pdo, $assetId, ['name_am' => 'የመዘምራን ልብስ', 'min_quantity' => 16], $officerActor);
assertTrue((int)$updated['min_quantity'] === 16, 'Asset metadata updated via updateAsset');

$svg = QrLabelService::renderQrSvg((string)$updated['qr_token']);
assertTrue(str_contains($svg, '<svg'), 'QR SVG rendered');
$sheetHtml = QrLabelService::renderLabelSheetHtml([$updated], 'CI Sunday School');
assertTrue(str_contains($sheetHtml, (string)$updated['asset_code']), 'Printable A4 QR label sheet rendered');

$reprint = AssetService::reprintQrToken($pdo, $assetId, 'Damaged label', $officerActor);
$newQr = (string)$reprint['qr_token'];
assertTrue($newQr !== $oldQr, 'QR reprint produces new token');
assertTrue(AssetService::resolveByQrToken($pdo, $oldQr) === null, 'Revoked QR token no longer resolves');
assertTrue(AssetService::resolveByQrToken($pdo, $newQr)['id'] === $assetId, 'New QR token resolves asset');

// 8. Stock movement -> triggers low_stock notification + negative balance guard
$mov = AssetService::recordMovement($pdo, $assetId, [
    'movement_type' => 'outgoing',
    'quantity_delta' => -5,
    'reason' => 'Issued to rehearsal hall',
], $officerActor);
assertTrue($mov['balance_after'] === 15, 'Stock decremented to 15 (<= min_quantity 16)');
$lowAlerts = (int)$pdo->query("SELECT COUNT(*) FROM qa_notifications WHERE type = 'warning' AND entity_type = 'asset' AND entity_id = {$assetId}")->fetchColumn();
assertTrue($lowAlerts >= 1, 'Low-stock notification emitted automatically');

$negBlocked = false;
try {
    AssetService::recordMovement($pdo, $assetId, [
        'movement_type' => 'outgoing',
        'quantity_delta' => -50,
        'reason' => 'Over-draw attempt',
    ], $officerActor);
} catch (\DomainException $e) {
    $negBlocked = true;
}
assertTrue($negBlocked, 'Negative stock balance blocked');

// 9. Private Attachment upload + magic-byte check + audited download
$tmpPng = tempnam(sys_get_temp_dir(), 'qapng_');
file_put_contents((string)$tmpPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
$att = AssetAttachmentService::storeUploadedFile($pdo, (string)$tmpPng, 'photo.png', [
    'linked_entity_type' => 'asset',
    'linked_entity_id' => $assetId,
], $officerActor);
assertTrue((int)$att['document_id'] > 0 && $att['mime_type'] === 'image/png', 'Asset attachment uploaded via magic-byte validation');
$dl = AssetAttachmentService::resolveForDownload($pdo, (int)$att['document_id'], $officerActor);
assertTrue($dl['mime_type'] === 'image/png' && file_exists($dl['full_path']), 'Audited attachment download resolves file');
@unlink((string)$tmpPng);

$fakePng = tempnam(sys_get_temp_dir(), 'qabad_');
file_put_contents((string)$fakePng, '<?php echo "hacked";');
$badBlocked = false;
try {
    AssetAttachmentService::storeUploadedFile($pdo, (string)$fakePng, 'shell.png', [
        'linked_entity_type' => 'asset',
        'linked_entity_id' => $assetId,
    ], $officerActor);
} catch (\DomainException $e) {
    $badBlocked = true;
}
assertTrue($badBlocked, 'Disguised PHP file rejected by finfo MIME magic-byte check');
@unlink((string)$fakePng);

// 10. 2-Step Asset Request + Return for Correction + Resubmit + Self-Approval Guard + Keystore-Signed Handover + Member Summary
$req = AssetWorkflowService::createRequest($pdo, [
    'asset_id' => $assetId,
    'requested_item_name' => 'Choir Tunic Set',
    'quantity' => 2,
    'reason' => 'Sunday liturgy choir',
], $officerActor);
$reqId = (int)$req['id'];

$ret = AssetWorkflowService::departmentHeadDecision($pdo, $reqId, 'return', 'Add event date', $headActor);
assertTrue($ret['status'] === 'RETURNED_FOR_CORRECTION', 'Request returned for correction');
$resub = AssetWorkflowService::resubmitRequest($pdo, $reqId, ['reason' => 'Sunday Meskel Liturgy'], $officerActor);
assertTrue($resub['status'] === 'PENDING_DEPARTMENT_HEAD', 'Request resubmitted cleanly');

$reqSelfBlocked = false;
try {
    AssetWorkflowService::departmentHeadDecision($pdo, $reqId, 'approve', 'self', $officerActor);
} catch (\DomainException $e) {
    $reqSelfBlocked = true;
}
assertTrue($reqSelfBlocked, 'Requester self-approval blocked');

$step1 = AssetWorkflowService::departmentHeadDecision($pdo, $reqId, 'approve', 'Approved by head', $headActor);
assertTrue($step1['status'] === 'PENDING_DEPUTY_CHAIRPERSON', 'Step 1 moves request to Deputy Chairperson');
$step2 = AssetWorkflowService::deputyDecision($pdo, $reqId, 'approve', 'Approved by deputy', $deputyActor);
assertTrue($step2['status'] === 'APPROVED', 'Step 2 approves request');

$handoverPayload = json_encode(['asset_id' => $assetId, 'qty' => 2, 'req_id' => $reqId], JSON_UNESCAPED_SLASHES);
openssl_sign((string)$handoverPayload, $hndSigRaw, $keyRes, OPENSSL_ALGO_SHA256);
$txUuid = AssetService::generateUuidV4();

$hnd = AssetWorkflowService::createHandover($pdo, [
    'asset_id' => $assetId,
    'asset_request_id' => $reqId,
    'handover_type' => 'issue',
    'receiver_member_external_id' => $extStu1,
    'receiver_display_name' => 'Abebe Kebede',
    'quantity' => 2,
    'expected_return_date' => '2020-01-01',
    'verification_method' => 'biometric_keystore',
    'device_uuid' => $deviceUuid,
    'canonical_payload' => $handoverPayload,
    'biometric_sig_b64' => base64_encode($hndSigRaw),
    'client_tx_uuid' => $txUuid,
], $officerActor);
assertTrue(!empty($hnd['handover_number']), 'Biometric Keystore handover created');

$hndReplay = AssetWorkflowService::createHandover($pdo, [
    'asset_id' => $assetId,
    'handover_type' => 'issue',
    'receiver_display_name' => 'Abebe Kebede',
    'quantity' => 2,
    'verification_method' => 'biometric_keystore',
    'device_uuid' => $deviceUuid,
    'canonical_payload' => $handoverPayload,
    'biometric_sig_b64' => base64_encode($hndSigRaw),
    'client_tx_uuid' => $txUuid,
], $officerActor);
assertTrue(!empty($hndReplay['idempotent_replay']) && $hndReplay['id'] === $hnd['id'], 'Duplicate client_tx_uuid replays cleanly without double-deducting');

$reqStatusAfter = $pdo->query("SELECT status FROM qa_asset_requests WHERE id = {$reqId}")->fetchColumn();
assertTrue($reqStatusAfter === 'FULFILLED', 'Linked request marked FULFILLED after handover');

$memSummary = AssetService::getMemberAssetSummary($pdo, $extStu1);
assertTrue(count($memSummary['active_holdings']) >= 1, 'Member active holdings tracked');
$overdueList = AssetService::listOverdueHandovers($pdo);
assertTrue(count($overdueList) >= 1, 'Overdue handover detected');

// 11. Event Template + Participant Filter + Conflict Resolution + Event Close
$tpl = AssetLifecycleService::createEventTemplate($pdo, [
    'name_en' => 'Choir Uniform Kit',
    'default_items' => [['asset_id' => $assetId, 'qty' => 1]],
], $headActor);
assertTrue((int)$tpl['id'] > 0, 'Event template created');

$ev = AssetLifecycleService::createEvent($pdo, [
    'name_en' => 'Meskel Distribution',
    'template_id' => (int)$tpl['id'],
    'stations' => [['station_name' => 'North Gate', 'operator_user_id' => $officerId]],
], $headActor);
$evId = (int)$ev['id'];
$stId = (int)$pdo->query("SELECT id FROM qa_event_stations WHERE event_id = {$evId} LIMIT 1")->fetchColumn();
AssetLifecycleService::allocateStationStock($pdo, $evId, $stId, $assetId, 1);
$prep = AssetLifecycleService::prepareEventParticipants($pdo, $evId, ['section_name' => 'Grade 5']);
assertTrue($prep['participants_prepared'] >= 1, 'Event participants prepared via filter');

$memberId = (int)$pdo->query("SELECT id FROM qa_member_sync_cache WHERE external_member_id = '{$extStu1}' LIMIT 1")->fetchColumn();
$batchRes = AssetLifecycleService::syncEventBatch($pdo, $evId, [
    ['client_tx_uuid' => AssetService::generateUuidV4(), 'station_id' => $stId, 'member_cache_id' => $memberId, 'asset_id' => $assetId, 'tx_type' => 'issue', 'quantity' => 2],
], $officerActor);
assertTrue($batchRes['accepted'] === 1 && $batchRes['conflicts'] === 1, 'Over-allocated offline event tx flagged as conflict instead of lost');

$txConflictId = (int)$pdo->query("SELECT id FROM qa_event_transactions WHERE event_id = {$evId} AND conflict_status = 'needs_review' LIMIT 1")->fetchColumn();
AssetLifecycleService::resolveEventConflict($pdo, $evId, $txConflictId, 'Approved overage by Head', $headActor);
$closedEv = AssetLifecycleService::closeEvent($pdo, $evId, $headActor);
assertTrue($closedEv['status'] === 'closed', 'Event closed after resolving conflicts');

// 12. Maintenance, Inspection Close + Findings, Disposal Open-Issue Guard + Override
$ticket = AssetLifecycleService::createMaintenanceTicket($pdo, [
    'asset_id' => $assetId,
    'issue_description' => 'Hem stitching repair',
    'status' => 'in_progress',
], $officerActor);
AssetLifecycleService::updateMaintenanceTicket($pdo, (int)$ticket['id'], ['status' => 'completed'], $officerActor);
$postMaintStatus = $pdo->query("SELECT status FROM qa_assets WHERE id = {$assetId}")->fetchColumn();
assertTrue(in_array($postMaintStatus, ['in_stock', 'low_stock'], true), 'Asset restored from maintenance');

$insp = AssetLifecycleService::createInspection($pdo, ['title' => 'Annual Count'], $headActor);
AssetLifecycleService::recordInspectionScans($pdo, (int)$insp['id'], [
    ['asset_id' => $assetId, 'outcome' => 'damaged', 'counted_qty' => 12, 'notes' => '1 damaged tunic'],
], $headActor);
$finding = AssetLifecycleService::createFindingFromInspection($pdo, (int)$insp['id'], [
    'title' => 'Tunic count variance',
    'description' => '1 tunic missing from shelf count',
    'severity' => 'medium',
], $headActor);
assertTrue((int)$finding['id'] > 0, 'Audit finding created from inspection');
$closedInsp = AssetLifecycleService::closeInspection($pdo, (int)$insp['id'], $headActor);
assertTrue($closedInsp['status'] === 'closed', 'Inspection closed cleanly');

// Disposal blocked because member still has active open handover (FR-10 guardrail)
$dispBlocked = false;
try {
    AssetLifecycleService::requestDisposal($pdo, [
        'asset_id' => $assetId,
        'reason_type' => 'retired',
        'reason_detail' => 'Attempt without override',
    ], $officerActor);
} catch (\DomainException $e) {
    $dispBlocked = true;
}
assertTrue($dispBlocked, 'Disposal blocked when asset has open handover');

// Deputy overrides open handover with mandatory reason
$disp = AssetLifecycleService::requestDisposal($pdo, [
    'asset_id' => $assetId,
    'reason_type' => 'retired',
    'reason_detail' => 'End of life uniform batch',
    'override_open_issues' => true,
    'override_reason' => 'Confirmed write-off after audit',
], $deputyActor);
$dispDecision = AssetLifecycleService::decideDisposal($pdo, (int)$disp['id'], 'approve', $headActor);
assertTrue($dispDecision['status'] === 'COMPLETED', 'Disposal completed with authorized override');
$finalAssetStatus = $pdo->query("SELECT status FROM qa_assets WHERE id = {$assetId}")->fetchColumn();
assertTrue($finalAssetStatus === 'retired', 'Disposed asset transitioned to retired');

// 13. Bilingual Asset Reports + CSV / HTML / PDF / XLSX Exports
$rep = AssetReportService::generate($pdo, 'inventory_register');
assertTrue(count($rep['rows']) >= 1, 'Inventory register report generated');
foreach (['csv', 'html', 'pdf', 'xlsx'] as $fmt) {
    $exp = AssetReportService::export($pdo, 'inventory_register', $fmt, [], $headActor);
    assertTrue(strlen($exp['body']) > 20, "Report exported cleanly as {$fmt}");
}

// 14. Operational Year-End Close + Carry-Forward + Reopen
$closedYear = OperationalYearService::closeYear($pdo, (int)$activeYear['id'], null, $adminActor);
assertTrue((int)$closedYear['next_year_id'] > 0, 'Year closed and carried forward to next E.C. year');
$reopened = OperationalYearService::reopenYear($pdo, (int)$activeYear['id'], 'CI verification reopen', $adminActor);
assertTrue($reopened['status'] === 'reopened', 'Year reopened with audit reason');
$pdo->prepare("UPDATE qa_operational_years SET status = 'active' WHERE id = :id")->execute([':id' => (int)$activeYear['id']]);
$pdo->prepare("DELETE FROM qa_operational_years WHERE id = :id")->execute([':id' => (int)$closedYear['next_year_id']]);

// 15. Real HTTP Route Dispatch Test via built-in PHP server
$port = 18089;
$docRoot = dirname(__DIR__);
$router = $docRoot . '/api/v1/index.php';
$cmd = sprintf('php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($docRoot), escapeshellarg($router));
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
assertTrue(is_resource($proc), 'Started PHP built-in test server');
usleep(400000);

$adminJwt = JwtService::issueAccessToken([
    'uid' => $adminId,
    'usr' => $adminUser,
    'rol' => 'system_admin',
    'nam' => 'CI Admin',
    'av' => 1,
]);
$ctxAdminGet = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$adminJwt}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 5,
    ],
]);
$healthBody = @file_get_contents("http://127.0.0.1:{$port}/api/v1/system/health", false, $ctxAdminGet);
$healthJson = is_string($healthBody) ? json_decode($healthBody, true) : null;
assertTrue(is_array($healthJson) && !empty($healthJson['ok']) && !empty($healthJson['data']['db_connected']), 'HTTP GET /api/v1/system/health succeeded');

$ctxGet = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$jwt}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 5,
    ],
]);
$assetsBody = @file_get_contents("http://127.0.0.1:{$port}/api/v1/assets", false, $ctxGet);
$assetsJson = is_string($assetsBody) ? json_decode($assetsBody, true) : null;
assertTrue(is_array($assetsJson) && !empty($assetsJson['ok']), 'HTTP GET /api/v1/assets succeeded');

$repBody = @file_get_contents("http://127.0.0.1:{$port}/api/v1/reports/assets/inventory_register", false, $ctxGet);
$repJson = is_string($repBody) ? json_decode($repBody, true) : null;
assertTrue(is_array($repJson) && !empty($repJson['ok']), 'HTTP GET /api/v1/reports/assets/inventory_register succeeded');

proc_terminate($proc);
proc_close($proc);

echo "ALL PHP + MARIADB + HTTP STEP A SMOKE CHECKS PASSED.\n";
