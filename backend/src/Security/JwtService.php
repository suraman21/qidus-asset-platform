<?php
declare(strict_types=1);

namespace QidusAsset\Security;

use InvalidArgumentException;

/**
 * HMAC-SHA256 JWT & Rotating Refresh Token Engine.
 * Mirrors SSMS `api/v1/core/auth.php` claim layout (`uid`, `usr`, `rol`, `nam`, `av`, `fid`, `rsid`).
 */
final class JwtService
{
    public static function secret(): string
    {
        $secret = (string)qa_config('jwt_secret', '');
        if ($secret === '' || str_starts_with($secret, 'CHANGE_ME_')) {
            if (qa_config('app_env') === 'production') {
                throw new InvalidArgumentException('Production jwt_secret must be configured outside web root.');
            }
            $secret = hash('sha256', QA_ROOT . '|dev_fallback_not_for_production');
        }
        return $secret;
    }

    public static function offlineLeaseSecret(): string
    {
        $secret = (string)qa_config('offline_lease_secret', '');
        if ($secret === '' || str_starts_with($secret, 'CHANGE_ME_')) {
            return hash_hmac('sha256', 'offline_lease', self::secret());
        }
        return $secret;
    }

    /**
     * @param array<string,mixed> $claims
     */
    public static function issueAccessToken(array $claims, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? (int)qa_config('access_token_ttl', 900);
        $now = time();
        $payload = [
            'uid' => (int)($claims['uid'] ?? 0),
            'usr' => (string)($claims['usr'] ?? ''),
            'rol' => (string)($claims['rol'] ?? 'requester'),
            'dept' => isset($claims['dept']) ? (int)$claims['dept'] : null,
            'nam' => (string)($claims['nam'] ?? ''),
            'av' => max(1, (int)($claims['av'] ?? 1)),
            'iat' => $now,
            'exp' => $now + $ttl,
            'typ' => 'access',
            'jti' => bin2hex(random_bytes(16)),
        ];
        if (!empty($claims['fid']) && !empty($claims['rsid'])) {
            $payload['fid'] = (string)$claims['fid'];
            $payload['rsid'] = (string)$claims['rsid'];
        }
        if (!empty($claims['imp_by'])) {
            $payload['imp_by'] = (int)$claims['imp_by'];
        }
        return self::signPayload($payload, self::secret());
    }

    /**
     * @param array<string,mixed> $scope
     */
    public static function issueOfflineLease(int $userId, string $deviceUuid, string $leaseJti, array $scope, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? (int)qa_config('offline_lease_ttl', 86400);
        $now = time();
        $payload = [
            'uid' => $userId,
            'dev' => $deviceUuid,
            'jti' => $leaseJti,
            'scope' => $scope,
            'iat' => $now,
            'exp' => $now + $ttl,
            'typ' => 'offline_lease',
        ];
        return self::signPayload($payload, self::offlineLeaseSecret());
    }

    /**
     * @return array{state:string,payload?:array<string,mixed>,token_type?:string}
     */
    public static function verify(string $token, ?string $secretOverride = null): array
    {
        if ($token === '' || strlen($token) > 8192 || !str_contains($token, '.')) {
            return ['state' => 'invalid'];
        }
        [$b64, $sig] = explode('.', $token, 2);
        if (!preg_match('/^[a-f0-9]{64}$/', $sig)) {
            return ['state' => 'invalid'];
        }
        $secret = $secretOverride ?? self::secret();
        if (!hash_equals(hash_hmac('sha256', $b64, $secret), $sig)) {
            return ['state' => 'invalid'];
        }
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            return ['state' => 'invalid'];
        }
        $payload = json_decode($raw, true);
        $now = time();
        if (!is_array($payload) || !isset($payload['uid'], $payload['exp'], $payload['iat'], $payload['typ'])
            || (int)$payload['uid'] <= 0 || (int)$payload['iat'] > ($now + 60)) {
            return ['state' => 'invalid'];
        }
        if ((int)$payload['exp'] < $now) {
            return ['state' => 'expired', 'token_type' => (string)$payload['typ']];
        }
        return ['state' => 'valid', 'payload' => $payload];
    }

    public static function extractBearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
        // Bearer header ONLY — never query string ?token=.
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function signPayload(array $payload, string $secret): string
    {
        $b64 = base64_encode((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $b64 . '.' . hash_hmac('sha256', $b64, $secret);
    }
}
