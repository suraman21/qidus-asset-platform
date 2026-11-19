<?php
declare(strict_types=1);

namespace QidusAsset\Security;

/**
 * Verifies Android Keystore biometric-protected cryptographic signatures (EC P-256 / RSA-2048).
 * Raw fingerprint images or biometric templates are NEVER collected or accepted.
 */
final class BiometricVerifier
{
    /**
     * Canonical payload digest bound to transaction identity, actor, quantities, condition, and nonce.
     *
     * @param array<string,mixed> $challengeFields
     */
    public static function canonicalPayloadSha256(array $challengeFields): string
    {
        ksort($challengeFields);
        $canonical = json_encode($challengeFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', (string)$canonical);
    }

    public static function verifySignature(string $publicKeyPem, string $canonicalPayloadJson, string $signatureBase64, string $algorithm = 'EC_P256'): bool
    {
        if ($publicKeyPem === '' || $canonicalPayloadJson === '' || $signatureBase64 === '') {
            return false;
        }
        $sig = base64_decode($signatureBase64, true);
        if ($sig === false || $sig === '') {
            return false;
        }
        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if ($pubKey === false) {
            return false;
        }
        $algo = match ($algorithm) {
            'EC_P256', 'RSA_2048' => OPENSSL_ALGO_SHA256,
            default => OPENSSL_ALGO_SHA256,
        };
        $ok = openssl_verify($canonicalPayloadJson, $sig, $pubKey, $algo);
        return $ok === 1;
    }

    public static function isValidPublicKeyPem(string $pem): bool
    {
        if (!str_contains($pem, 'BEGIN PUBLIC KEY')) {
            return false;
        }
        return openssl_pkey_get_public($pem) !== false;
    }
}
