<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/backend/config/bootstrap.php';
require_once __DIR__ . '/core/auth_guard.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/core.php';
require_once __DIR__ . '/routes/assets.php';

use QidusAsset\Support\EthiopianCalendar;
use QidusAsset\Support\Response;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$route = trim((string)($_GET['_route'] ?? $_GET['route'] ?? ''), '/');
if ($route === '') {
    $uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if (preg_match('#/api/v1/?(.*)$#', $uriPath, $m)) {
        $route = trim($m[1], '/');
    }
}

$segments = $route === '' ? [] : explode('/', $route);
if (($segments[0] ?? '') === 'qa') {
    array_shift($segments);
    $route = implode('/', $segments);
}

try {
    if ($route === 'ping' && $method === 'GET') {
        Response::ok([
            'app' => qa_config('app_name'),
            'version' => '1.0.0-phase2',
            'server_time_utc' => gmdate('c'),
            'ethiopian_today' => EthiopianCalendar::today((string)qa_config('timezone')),
            'integration_mode' => qa_config('integration_mode', 'standalone'),
        ]);
    }

    if (($segments[0] ?? '') === 'auth') {
        qaHandleAuthRoute($method, array_slice($segments, 1));
    }

    $corePrefixes = ['users', 'departments', 'years', 'devices', 'offline-lease', 'members', 'notifications', 'audit-logs', 'system'];
    if (in_array($segments[0] ?? '', $corePrefixes, true)) {
        qaHandleCoreRoute($method, $segments);
    }

    $assetPrefixes = ['assets', 'asset-categories', 'asset-locations', 'attachments', 'asset-requests', 'handovers', 'event-templates', 'events', 'maintenance', 'inspections', 'disposals', 'reports'];
    if (in_array($segments[0] ?? '', $assetPrefixes, true)) {
        qaHandleAssetRoute($method, $segments);
    }

    Response::err('Endpoint not found.', 404, 'ROUTE_NOT_FOUND');
} catch (\DomainException $e) {
    Response::err($e->getMessage(), 422, 'DOMAIN_RULE_VIOLATION');
} catch (\Throwable $e) {
    $isDebug = (string)qa_config('app_env') !== 'production';
    Response::err(
        $isDebug ? ('Server error: ' . $e->getMessage()) : 'Internal server error.',
        500,
        'INTERNAL_ERROR'
    );
}
