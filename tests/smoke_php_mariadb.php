<?php
declare(strict_types=1);

/**
 * End-to-end PHP 8.2 + MariaDB integration smoke test executed in CI.
 */

require_once dirname(__DIR__) . '/backend/config/bootstrap.php';

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
$adminUser = 'ci_sysadmin_' . bin2hex(random_bytes(3));
$officerUser = 'ci_officer_' . bin2hex(random_bytes(3));
$passHash = PasswordService::hash('TestPass#2026!');

$ins = $pdo->prepare(
    "INSERT INTO qa_users (username, full_name, qa_role, department_id, password_hash, is_active)
     VALUES (:u, :n, :r, :d, :p, 1)"
);
$ins->execute([':u' => $adminUser, ':n' => 'CI Admin', ':r' => 'system_admin', ':d' => 1, ':p' => $passHash]);
$adminId = (int)$pdo->lastInsertId();

$ins->execute([':u' => $officerUser, ':n' => 'CI Officer', ':r' => 'asset_officer', ':d' => 1, ':p' => $passHash]);
$officerId = (int)$pdo->lastInsertId();

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

// 4. OpenSSL Biometric Keystore signature test (EC P-256)
$keyRes = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
assertTrue($keyRes !== false, 'OpenSSL EC P-256 key generation');
$pubDetails = openssl_pkey_get_details($keyRes);
$pubPem = $pubDetails['key'];
$canonical = json_encode(['asset_id' => 12, 'member_id' => 'ssms_stu_101', 'nonce' => 'n1'], JSON_UNESCAPED_SLASHES);
openssl_sign((string)$canonical, $sigRaw, $keyRes, OPENSSL_ALGO_SHA256);
assertTrue(BiometricVerifier::verifySignature($pubPem, (string)$canonical, base64_encode($sigRaw)), 'Biometric Keystore EC signature verified');

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

echo "ALL PHP + MARIADB PHASE 1 SMOKE CHECKS PASSED.\n";
