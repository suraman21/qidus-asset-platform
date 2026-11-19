<?php
declare(strict_types=1);

namespace QidusAsset\Support;

use PDO;

/**
 * Prevents duplicate offline/mobile submissions on flaky connections.
 */
final class IdempotencyService
{
    public static function extractKey(): ?string
    {
        $key = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($key === '' || strlen($key) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return null;
        }
        return $key;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{hit:bool,conflict:bool,status_code?:int,response?:array<string,mixed>}
     */
    public static function check(PDO $pdo, int $userId, string $key, string $endpoint, array $body): array
    {
        $reqHash = hash('sha256', (string)json_encode($body, JSON_UNESCAPED_UNICODE));
        $stmt = $pdo->prepare(
            'SELECT request_hash, response_code, response_json
             FROM qa_api_idempotency
             WHERE user_id = :u AND idempotency_key = :k AND endpoint = :e
             LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':k' => $key, ':e' => $endpoint]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['hit' => false, 'conflict' => false];
        }
        if (!hash_equals((string)$row['request_hash'], $reqHash)) {
            return ['hit' => true, 'conflict' => true];
        }
        $decoded = json_decode((string)$row['response_json'], true);
        return [
            'hit' => true,
            'conflict' => false,
            'status_code' => (int)$row['response_code'],
            'response' => is_array($decoded) ? $decoded : ['status' => 'success'],
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $response
     */
    public static function record(PDO $pdo, int $userId, string $key, string $endpoint, array $body, int $statusCode, array $response): void
    {
        $reqHash = hash('sha256', (string)json_encode($body, JSON_UNESCAPED_UNICODE));
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO qa_api_idempotency (user_id, idempotency_key, endpoint, request_hash, response_code, response_json)
             VALUES (:u, :k, :e, :h, :c, :r)'
        );
        $stmt->execute([
            ':u' => $userId,
            ':k' => $key,
            ':e' => $endpoint,
            ':h' => $reqHash,
            ':c' => $statusCode,
            ':r' => (string)json_encode($response, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
