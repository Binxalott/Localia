<?php
/**
 * Localia endpoint (PHP 7.4+ / 8.x with PDO).
 * Point the desktop agent at this file, e.g. https://example.com/localia/api.php
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Localia\Auth;
use Localia\HttpError;
use Localia\Queue;

const LOCALIA_VERSION = '1.0.0';

function localia_send(int $status, $data, array $headers = []): void
{
    $raw = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $k => $v) {
        header("{$k}: {$v}");
    }
    echo $raw;
}

function localia_cors(array $config): void
{
    $origins = $config['allowed_origins'] ?? ['*'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array('*', $origins, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array($origin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Localia-Key, X-Localia-Agent, X-Localia-Timestamp, X-Localia-Nonce, X-Localia-Signature');
    header('Access-Control-Expose-Headers: X-Localia-Server-Signature');
    header('Access-Control-Max-Age: 600');
}

function localia_producer(array $body, array $config, Queue $queue): void
{
    Auth::verifyApiKey($config);
    switch ($body['action'] ?? '') {
        case 'enqueue':
            $job = isset($body['job']) && is_array($body['job']) ? $body['job'] : $body;
            $id = $queue->enqueue($job);
            localia_send(201, ['ok' => true, 'id' => $id, 'job' => $queue->get($id)]);
            return;
        case 'get':
            $job = $queue->get((string) ($body['id'] ?? ''));
            if ($job === null) {
                throw new HttpError(404, 'job_not_found');
            }
            localia_send(200, ['ok' => true, 'job' => $job]);
            return;
        case 'cancel':
            localia_send(200, ['ok' => true, 'cancelled' => $queue->cancel((string) ($body['id'] ?? ''))]);
            return;
        case 'list':
            localia_send(200, ['ok' => true, 'jobs' => $queue->list($body)]);
            return;
        case 'stats':
            localia_send(200, ['ok' => true] + $queue->stats());
            return;
        default:
            throw new HttpError(400, 'unknown_action');
    }
}

$config = localia_config();
$finishedJob = null;

try {
    localia_cors($config);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $queue = localia_queue();
    if (random_int(1, 20) === 1) {
        $queue->maintenance();
    }

    if ($method === 'GET') {
        if (empty($_GET['action'])) {
            localia_send(200, ['ok' => true, 'service' => 'localia', 'version' => LOCALIA_VERSION, 'server_time' => time()]);
        } else {
            localia_producer($_GET, $config, $queue);
        }
        exit;
    }
    if ($method !== 'POST') {
        throw new HttpError(405, 'method_not_allowed');
    }

    $raw = file_get_contents('php://input', false, null, 0, (int) $config['max_body_bytes'] + 1);
    if ($raw === false || strlen($raw) > (int) $config['max_body_bytes']) {
        throw new HttpError(413, 'body_too_large');
    }

    if (Auth::header('X-Localia-Signature') !== null) {
        // ---- desktop agent ----
        $auth = Auth::verifyAgent($raw, $config, $queue);
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new HttpError(400, 'invalid_json');
        }
        switch ($body['action'] ?? '') {
            case 'ping':
                $queue->touchAgent($auth['agentId'], $body['agent'] ?? null);
                $out = ['ok' => true, 'pending' => $queue->pending()];
                break;
            case 'poll':
                $queue->touchAgent($auth['agentId'], $body['agent'] ?? null);
                $job = $queue->claim($auth['agentId']);
                $out = ['ok' => true, 'job' => $job, 'pending' => $queue->pending()];
                break;
            case 'result':
                $finishedJob = $queue->complete($auth['agentId'], $body);
                $out = ['ok' => true, 'accepted' => true, 'requeued' => $finishedJob === null, 'pending' => $queue->pending()];
                break;
            default:
                throw new HttpError(400, 'unknown_action');
        }
        $out['server_time'] = time();
        $rawOut = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        localia_send(200, $rawOut, ['X-Localia-Server-Signature' => Auth::signResponse($auth['secret'], $auth['nonce'], $rawOut)]);
    } else {
        // ---- your apps ----
        $body = json_decode($raw === '' ? '{}' : $raw, true);
        if (!is_array($body)) {
            throw new HttpError(400, 'invalid_json');
        }
        localia_producer($body, $config, $queue);
    }
} catch (HttpError $e) {
    localia_send($e->status, ['ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage(), 'server_time' => time()]);
} catch (Throwable $e) {
    error_log('[localia] ' . $e);
    localia_send(500, ['ok' => false, 'error' => 'server_error', 'message' => 'Internal error', 'server_time' => time()]);
}

// Fire the completion webhook after the agent already has its answer.
if ($finishedJob !== null && !empty($finishedJob['callback_url'])) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    Queue::deliverCallback($finishedJob, (string) ($config['callback_secret'] ?? ''));
}
