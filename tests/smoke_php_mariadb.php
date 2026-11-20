<?php
declare(strict_types=1);

/**
 * End-to-end PHP 8.2 + MariaDB integration smoke test executed in CI (Phases 1 & 2).
 */

require_once dirname(__DIR__) . '/backend/config/bootstrap.php';

use QidusAsset\Asset\AssetLifecycleService;
use QidusAsset\Asset\AssetService;
use QidusAsset\Asset\AssetWorkflowService;
use QidusAsset\Connector\ConnectorFactory;
use QidusAsset\Database\Connection;
use QidusAsset\Security\BiometricVerifier;
use QidusAsset\Security\JwtService;
use QidusAsset\Security\PasswordService;
use QidusAsset\Security\Rbac;

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

$yearCount = (int)$pdo->query("SELECT COUNT(*) FROM qa_operational_years WHERE status = 'active'")->fetchColumn();
assertTrue($yearCount >= 1, 'Expected active operational year');

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

// 5. Member Connector upsert & tombstone sync
$connector = ConnectorFactory::memberConnector($pdo);
$connector->upsertBatch([
    ['external_member_id' => 'ci_stu_1', 'name_en' => 'Abebe Kebede', 'section_name' => 'Grade 5', 'is_tombstoned' => false],
    ['external_member_id' => 'ci_stu_2', 'name_en' => 'Deleted Student', 'is_tombstoned' => true],
], 'standalone');
$search = $connector->search('Abebe');
assertTrue($search['total'] >= 1, 'Member search returns active record');
$sync = $connector->syncChanges(null, 50);
assertTrue(in_array('ci_stu_2', $sync['tombstones'], true), 'Tombstone propagated in incremental sync');

// 6. RBAC separation of duty check
$selfApprovalRejected = false;
try {
    Rbac::assertNotSelfApproval($officerId, $officerId, 'asset_officer');
} catch (\DomainException $e) {
    $selfApprovalRejected = true;
}
assertTrue($selfApprovalRejected, 'Self-approval blocked for non-admin');

// ============================================================================
// PHASE 2: Department 1 (Asset Dept) Full Integration Verification
// ============================================================================
$officerActor = ['uid' => $officerId, 'role' => 'asset_officer', 'department_id' => 1, 'device_uuid' => $deviceUuid];
$headActor = ['uid' => $headId, 'role' => 'asset_head', 'department_id' => 1, 'device_uuid' => null];
$deputyActor = ['uid' => $deputyId, 'role' => 'deputy_chairperson', 'department_id' => 1, 'device_uuid' => null];

$catId = (int)$pdo->query("SELECT id FROM qa_asset_categories WHERE code = 'UNIFORMS' LIMIT 1")->fetchColumn();
$locId = (int)$pdo->query("SELECT id FROM qa_asset_locations WHERE code = 'MAIN_STORE' LIMIT 1")->fetchColumn();
assertTrue($catId > 0 && $locId > 0, 'Seeded category and location present');

// 7. Create reusable asset with size variants + QR resolution + QR reprint
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

$reprint = AssetService::reprintQrToken($pdo, $assetId, 'Damaged label', $officerActor);
$newQr = (string)$reprint['qr_token'];
assertTrue($newQr !== $oldQr, 'QR reprint produces new token');
assertTrue(AssetService::resolveByQrToken($pdo, $oldQr) === null, 'Revoked QR token no longer resolves');
assertTrue(AssetService::resolveByQrToken($pdo, $newQr)['id'] === $assetId, 'New QR token resolves asset');

// 8. Stock movement & negative balance guard
$mov = AssetService::recordMovement($pdo, $assetId, [
    'movement_type' => 'outgoing',
    'quantity_delta' => -3,
    'reason' => 'Issued to rehearsal hall',
], $officerActor);
assertTrue($mov['balance_after'] === 17, 'Stock decremented to 17');

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

// 9. 2-Step Asset Request + Self-Approval Guard + Keystore-Signed Handover + Idempotency Replay
$req = AssetWorkflowService::createRequest($pdo, [
    'asset_id' => $assetId,
    'requested_item_name' => 'Choir Tunic Set',
    'quantity' => 2,
    'reason' => 'Sunday liturgy choir',
], $officerActor);
$reqId = (int)$req['id'];

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
    'receiver_display_name' => 'Abebe Kebede',
    'quantity' => 2,
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

// 10. Multi-station event + offline batch sync + conflict flagging + reconciliation
$ev = AssetLifecycleService::createEvent($pdo, [
    'name_en' => 'Meskel Distribution',
    'stations' => [['station_name' => 'North Gate', 'operator_user_id' => $officerId]],
], $headActor);
$evId = (int)$ev['id'];
$stId = (int)$pdo->query("SELECT id FROM qa_event_stations WHERE event_id = {$evId} LIMIT 1")->fetchColumn();
AssetLifecycleService::allocateStationStock($pdo, $evId, $stId, $assetId, 1);

$memberId = (int)$pdo->query("SELECT id FROM qa_member_sync_cache WHERE external_member_id = 'ci_stu_1' LIMIT 1")->fetchColumn();
$batchRes = AssetLifecycleService::syncEventBatch($pdo, $evId, [
    ['client_tx_uuid' => AssetService::generateUuidV4(), 'station_id' => $stId, 'member_cache_id' => $memberId, 'asset_id' => $assetId, 'tx_type' => 'issue', 'quantity' => 2],
], $officerActor);
assertTrue($batchRes['accepted'] === 1 && $batchRes['conflicts'] === 1, 'Over-allocated offline event tx flagged as conflict instead of lost');
$rec = AssetLifecycleService::reconcileEvent($pdo, $evId);
assertTrue($rec['conflicts_needing_review'] === 1, 'Event reconciliation reports 1 conflict');

// 11. Maintenance lifecycle, inspection scan variance, and controlled disposal
$ticket = AssetLifecycleService::createMaintenanceTicket($pdo, [
    'asset_id' => $assetId,
    'issue_description' => 'Hem stitching repair',
    'status' => 'in_progress',
], $officerActor);
AssetLifecycleService::updateMaintenanceTicket($pdo, (int)$ticket['id'], ['status' => 'completed'], $officerActor);
$postMaintStatus = $pdo->query("SELECT status FROM qa_assets WHERE id = {$assetId}")->fetchColumn();
assertTrue($postMaintStatus === 'in_stock', 'Asset restored to in_stock after maintenance');

$insp = AssetLifecycleService::createInspection($pdo, ['title' => 'Annual Count'], $headActor);
$scanRes = AssetLifecycleService::recordInspectionScans($pdo, (int)$insp['id'], [
    ['asset_id' => $assetId, 'outcome' => 'inspected', 'counted_qty' => 14],
], $headActor);
assertTrue($scanRes['recorded_scans'] === 1, 'Inspection scan recorded');

$disp = AssetLifecycleService::requestDisposal($pdo, [
    'asset_id' => $assetId,
    'reason_type' => 'retired',
    'reason_detail' => 'End of life uniform batch',
], $officerActor);
$dispDecision = AssetLifecycleService::decideDisposal($pdo, (int)$disp['id'], 'approve', $deputyActor);
assertTrue($dispDecision['status'] === 'COMPLETED', 'Disposal completed by deputy chairperson');
$finalAssetStatus = $pdo->query("SELECT status FROM qa_assets WHERE id = {$assetId}")->fetchColumn();
assertTrue($finalAssetStatus === 'retired', 'Disposed asset transitioned to retired');

echo "ALL PHP + MARIADB PHASE 1 & PHASE 2 SMOKE CHECKS PASSED.\n";
