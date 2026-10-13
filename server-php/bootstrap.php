<?php
/**
 * Include this from your own scripts to use the queue directly (no HTTP needed):
 *
 *   require '/path/to/server-php/bootstrap.php';
 *   $id  = localia_queue()->enqueue(['prompt' => 'Hello']);
 *   $job = localia_queue()->waitFor($id, 600);
 */
declare(strict_types=1);

require_once __DIR__ . '/src/HttpError.php';
require_once __DIR__ . '/src/Queue.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Client.php';

function localia_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function localia_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $db = localia_config()['db'];
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if ($db['driver'] === 'mysql') {
        $m = $db['mysql'];
        $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset={$m['charset']}";
        $pdo = new PDO($dsn, $m['username'], $m['password'], $opts);
    } elseif ($db['driver'] === 'sqlite') {
        $path = $db['sqlite']['path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    } else {
        throw new RuntimeException('Unknown db driver: ' . $db['driver']);
    }
    return $pdo;
}

function localia_queue(): \Localia\Queue
{
    static $queue = null;
    if ($queue === null) {
        $config = localia_config();
        $queue = new \Localia\Queue(localia_pdo(), $config);
        $queue->ensureSchema();
    }
    return $queue;
}
