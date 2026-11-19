<?php
declare(strict_types=1);

namespace QidusAsset\Support;

final class Response
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }
        try {
            self::$requestId = 'req_' . bin2hex(random_bytes(12));
        } catch (\Throwable $e) {
            self::$requestId = 'req_' . substr(hash('sha256', uniqid('', true)), 0, 24);
        }
        return self::$requestId;
    }

    /**
     * @param mixed $data
     */
    public static function ok($data = null, int $statusCode = 200): never
    {
        $payload = ['status' => 'success'];
        if ($data !== null) {
            if (is_array($data) && isset($data['message'])) {
                $payload['message'] = $data['message'];
                unset($data['message']);
                if ($data !== []) {
                    $payload['data'] = $data;
                }
            } else {
                $payload['data'] = $data;
            }
        }
        self::sendJson($payload, $statusCode);
    }

    /**
     * @param array<string,mixed> $extra
     */
    public static function err(string $message, int $statusCode = 400, string $code = 'REQUEST_ERROR', array $extra = []): never
    {
        $payload = array_merge([
            'status' => 'error',
            'code' => $code,
            'message' => $message,
        ], $extra);
        self::sendJson($payload, $statusCode);
    }

    /**
     * @param list<mixed> $items
     */
    public static function paginated(array $items, int $total, int $page, int $limit): never
    {
        $safeLimit = max(1, $limit);
        self::ok([
            'items' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $safeLimit,
                'pages' => (int)ceil($total / $safeLimit),
                'has_more' => ($page * $safeLimit) < $total,
            ],
        ]);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public static function paginationParams(int $maxLimit = 100): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min($maxLimit, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        return [$page, $limit, $offset];
    }

    /**
     * Read bounded JSON or POST body.
     *
     * @return array<string,mixed>
     */
    public static function jsonBody(int $maxBytes = 1048576): array
    {
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (is_string($raw) && strlen($raw) > $maxBytes) {
            self::err('Request body exceeds allowed size.', 413, 'PAYLOAD_TOO_LARGE');
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return is_array($_POST) ? $_POST : [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function sendJson(array $payload, int $statusCode = 200): never
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Request-Id: ' . self::requestId());
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            if (!headers_sent()) {
                http_response_code(500);
            }
            $json = '{"status":"error","code":"ENCODE_ERROR","message":"Failed to encode response."}';
        }
        echo $json;
        exit;
    }
}
