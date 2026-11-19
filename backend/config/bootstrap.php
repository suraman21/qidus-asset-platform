<?php
/**
 * QIDUS ASSET — Core Runtime Bootstrap
 *
 * Loads out-of-web-root `.qa_env.php`, registers PSR-4 autoloader (falls back to
 * lightweight internal autoloader if Composer vendor/ is not installed yet),
 * enforces strict timezone & security defaults.
 */
declare(strict_types=1);

if (defined('QA_BOOTSTRAPPED')) {
    return;
}
define('QA_BOOTSTRAPPED', true);
define('QA_ROOT', dirname(__DIR__, 2));

// 1. Load Composer autoloader if present; otherwise lightweight PSR-4 loader.
$composerAutoload = QA_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'QidusAsset\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = QA_ROOT . '/backend/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// 2. Load environment configuration (prefers out-of-web-root `.qa_env.php`).
$envCandidates = [
    dirname(QA_ROOT) . '/.qa_env.php',
    dirname(QA_ROOT, 2) . '/.qa_env.php',
    QA_ROOT . '/.qa_env.php',
];

$config = null;
foreach ($envCandidates as $candidate) {
    if (is_file($candidate)) {
        $loaded = require $candidate;
        if (is_array($loaded)) {
            $config = $loaded;
            break;
        }
    }
}

if ($config === null) {
    $config = require __DIR__ . '/env.example.php';
}

// Allow environment variable overrides for CI / containerized tests.
if (getenv('QA_DB_HOST') !== false) {
    $config['db']['host'] = getenv('QA_DB_HOST');
}
if (getenv('QA_DB_PORT') !== false) {
    $config['db']['port'] = (int)getenv('QA_DB_PORT');
}
if (getenv('QA_DB_NAME') !== false) {
    $config['db']['name'] = getenv('QA_DB_NAME');
}
if (getenv('QA_DB_USER') !== false) {
    $config['db']['user'] = getenv('QA_DB_USER');
}
if (getenv('QA_DB_PASS') !== false) {
    $config['db']['pass'] = getenv('QA_DB_PASS');
}
if (getenv('QA_JWT_SECRET') !== false) {
    $config['jwt_secret'] = getenv('QA_JWT_SECRET');
}
if (getenv('QA_OFFLINE_LEASE_SECRET') !== false) {
    $config['offline_lease_secret'] = getenv('QA_OFFLINE_LEASE_SECRET');
}
if (getenv('QA_INTEGRATION_MODE') !== false) {
    $config['integration_mode'] = getenv('QA_INTEGRATION_MODE');
}

date_default_timezone_set((string)($config['timezone'] ?? 'Africa/Addis_Ababa'));

$GLOBALS['QA_CONFIG'] = $config;

if (!function_exists('qa_config')) {
    /**
     * Read nested config key using dot notation, e.g. qa_config('db.host').
     *
     * @param mixed $default
     * @return mixed
     */
    function qa_config(string $key, $default = null)
    {
        $cursor = $GLOBALS['QA_CONFIG'] ?? [];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }
}
