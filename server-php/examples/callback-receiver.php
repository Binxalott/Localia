<?php
/**
 * Example webhook endpoint for jobs created with a 'callback_url'.
 * Verifies the signature using the same value as 'callback_secret' in config.php.
 * Copy this into your own site; server-php/.htaccess blocks every PHP file in this folder except api.php.
 */
$secret = getenv('LOCALIA_CALLBACK_SECRET') ?: 'CHANGE_ME_CALLBACK_SECRET';

$raw = file_get_contents('php://input');
$ts = $_SERVER['HTTP_X_LOCALIA_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_LOCALIA_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', "v1-cb\n{$ts}\n{$raw}", $secret);

if (abs(time() - (int) $ts) > 300 || !hash_equals($expected, $sig)) {
    http_response_code(401);
    exit;
}

$job = json_decode($raw, true)['job'];
// $job['id'], $job['status'], $job['response'], $job['meta'] ...
error_log("Localia job {$job['id']} finished: {$job['status']}");
http_response_code(204);
