<?php
declare(strict_types=1);

namespace Localia;

/**
 * Agent authentication (see docs/PROTOCOL.md).
 *
 * Request signature:  hex(HMAC_SHA256(secret, "v1\n" . timestamp . "\n" . nonce . "\n" . rawBody))
 * Response signature: hex(HMAC_SHA256(secret, "v1-resp\n" . requestNonce . "\n" . rawResponseBody))
 */
class Auth
{
    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (strcasecmp($name, 'Authorization') === 0 && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return null;
    }

    private static function isPlaceholder($v): bool
    {
        return !$v || strncmp((string) $v, 'CHANGE_ME', 9) === 0;
    }

    /** @return array{agentId:string, secret:string, nonce:string} */
    public static function verifyAgent(string $rawBody, array $config, Queue $queue): array
    {
        $agentId = self::header('X-Localia-Agent');
        $ts = self::header('X-Localia-Timestamp');
        $nonce = self::header('X-Localia-Nonce');
        $signature = self::header('X-Localia-Signature');

        if (!$agentId || !$ts || !$nonce || !$signature) {
            throw new HttpError(401, 'missing_auth_headers');
        }
        $secret = $config['agents'][$agentId] ?? null;
        if ($secret === null) {
            throw new HttpError(401, 'unknown_agent');
        }
        if (self::isPlaceholder($secret) || strlen((string) $secret) < 16) {
            throw new HttpError(403, 'agent_secret_not_configured', 'Set a real secret (16+ chars) for this agent in the server config');
        }
        if (!preg_match('/^\d{9,12}$/', $ts)) {
            throw new HttpError(401, 'bad_timestamp');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) {
            throw new HttpError(401, 'bad_nonce');
        }
        $tolerance = (int) $config['timestamp_tolerance'];
        if (abs(time() - (int) $ts) > $tolerance) {
            throw new HttpError(401, 'clock_skew', "Agent clock differs from server clock by more than {$tolerance}s");
        }
        $expected = hash_hmac('sha256', "v1\n{$ts}\n{$nonce}\n{$rawBody}", (string) $secret);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new HttpError(401, 'bad_signature');
        }
        if (!$queue->useNonce($agentId, $nonce, (int) $ts, $tolerance * 2 + 60)) {
            throw new HttpError(401, 'replayed_nonce');
        }
        return ['agentId' => $agentId, 'secret' => (string) $secret, 'nonce' => $nonce];
    }

    public static function signResponse(string $secret, string $nonce, string $rawResponse): string
    {
        return hash_hmac('sha256', "v1-resp\n{$nonce}\n{$rawResponse}", $secret);
    }

    public static function verifyApiKey(array $config): void
    {
        $key = self::header('X-Localia-Key');
        $authz = self::header('Authorization');
        if (!$key && $authz && preg_match('/^Bearer\s+(.+)$/i', $authz, $m)) {
            $key = trim($m[1]);
        }
        if (!$key) {
            throw new HttpError(401, 'missing_api_key');
        }
        foreach ($config['api_keys'] ?? [] as $k) {
            if (!self::isPlaceholder($k) && strlen((string) $k) >= 16 && hash_equals((string) $k, $key)) {
                return;
            }
        }
        throw new HttpError(401, 'bad_api_key');
    }
}
