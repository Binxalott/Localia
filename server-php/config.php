<?php
/**
 * Localia server configuration (PHP).
 *
 * Generate secrets with:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 * Values can also come from environment variables (getenv), so you can keep secrets out of this file.
 */

$env = function (string $key, $default) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};

return [
    // Desktop agents allowed to pull work: agentId => shared secret.
    // The agent ID + secret must be entered identically in the desktop app's settings.
    'agents' => [
        'home-pc' => $env('LOCALIA_AGENT_SECRET', 'CHANGE_ME_AGENT_SECRET'),
    ],

    // API keys for apps/cron jobs that enqueue over HTTP ("Authorization: Bearer <key>").
    // Scripts on this same server can skip HTTP and use localia_queue() directly (see examples/).
    'api_keys' => [
        $env('LOCALIA_API_KEY', 'CHANGE_ME_API_KEY'),
    ],

    // If set, job completion callbacks (callback_url) are signed with this secret.
    'callback_secret' => $env('LOCALIA_CALLBACK_SECRET', ''),

    // CORS: the desktop agent runs in a browser, so the server must allow its origin.
    // Requests are authenticated with HMAC signatures (not cookies), so '*' is fine.
    'allowed_origins' => ['*'],

    'lease_seconds'       => 900,   // how long an agent may hold a job before it is retried
    'max_attempts'        => 3,     // default attempts per job before it is marked as error
    'retry_delay_seconds' => 30,    // wait before re-offering a job the agent reported as failed
    'timestamp_tolerance' => 300,   // max clock difference (seconds) between agent and server
    'max_body_bytes'      => 4 * 1024 * 1024,
    'retention_days'      => 30,    // finished jobs older than this are deleted (0 = keep forever)

    'db' => [
        // 'sqlite' works out of the box (needs pdo_sqlite). Switch to 'mysql' for production.
        'driver' => $env('LOCALIA_DB_DRIVER', 'sqlite'),

        'sqlite' => [
            // Keep this OUTSIDE your web root if you can. data/.htaccess blocks web access on Apache.
            'path' => $env('LOCALIA_SQLITE_PATH', __DIR__ . '/data/localia.sqlite'),
        ],

        'mysql' => [
            'host'     => $env('LOCALIA_DB_HOST', '127.0.0.1'),
            'port'     => (int) $env('LOCALIA_DB_PORT', 3306),
            'database' => $env('LOCALIA_DB_NAME', 'localia'),
            'username' => $env('LOCALIA_DB_USER', 'localia'),
            'password' => $env('LOCALIA_DB_PASS', 'CHANGE_ME_DB_PASSWORD'),
            'charset'  => 'utf8mb4',
        ],

        'table_prefix' => $env('LOCALIA_DB_PREFIX', 'localia_'),
    ],
];
