<?php
/**
 * QIDUS ASSET — Environment Configuration Template
 *
 * Copy to `.qa_env.php` ONE directory ABOVE the web root in production
 * (never commit real secrets into git).
 */

return [
    'app_env' => 'development', // production | staging | development
    'app_name' => 'QIDUS ASSET',
    'school_name_en' => 'Sunday School',
    'school_name_am' => 'ሰንበት ትምህርት ቤት',
    'timezone' => 'Africa/Addis_Ababa',

    // Deployment mode: standalone | ssms_direct | ssms_http
    'integration_mode' => 'standalone',

    // Database (PDO MySQL/MariaDB)
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'qidus_asset',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    // Security secrets (MUST be 32+ random bytes in production)
    'jwt_secret' => 'CHANGE_ME_TO_64_HEX_CHARS_OUTSIDE_WEB_ROOT',
    'offline_lease_secret' => 'CHANGE_ME_TO_64_HEX_CHARS_OUTSIDE_WEB_ROOT',

    // Token lifetimes (seconds)
    'access_token_ttl' => 900,        // 15 minutes
    'refresh_token_ttl' => 7776000,   // 90 days
    'offline_lease_ttl' => 86400,     // 24 hours default

    // Private storage OUTSIDE public_html in production
    'storage_path' => __DIR__ . '/../../storage/vault',

    // SSMS HTTP Connector settings (when integration_mode = 'ssms_http')
    'ssms' => [
        'base_url' => '',
        'service_token' => '',
        'timeout_seconds' => 10,
    ],

    // Optional CORS allow-list (empty = same-origin / native mobile)
    'cors_allowed_origins' => [],
];
