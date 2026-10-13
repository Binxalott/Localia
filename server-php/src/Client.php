<?php
declare(strict_types=1);

namespace Localia;

/**
 * HTTP client for apps on OTHER servers that want to queue jobs on a Localia server
 * (works against both the PHP and Node servers). On the same server, prefer localia_queue().
 *
 *   $localia = new \Localia\Client('https://example.com/localia/api.php', 'your-api-key');
 *   $id  = $localia->enqueue(['prompt' => 'Hello']);
 *   $job = $localia->waitFor($id);
 */
class Client
{
    private string $url;
    private string $apiKey;
    private int $timeout;

    public function __construct(string $url, string $apiKey, int $timeout = 30)
    {
        $this->url = $url;
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    public function call(string $action, array $payload = []): array
    {
        $payload['action'] = $action;
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$this->apiKey}",
            'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout'       => $this->timeout,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($this->url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException("Localia {$action} failed: could not reach {$this->url}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok'])) {
            $msg = is_array($data) ? ($data['message'] ?? $data['error'] ?? 'unknown error') : 'invalid response';
            throw new \RuntimeException("Localia {$action} failed: {$msg}");
        }
        return $data;
    }

    public function enqueue(array $job): string
    {
        return $this->call('enqueue', ['job' => $job])['id'];
    }

    public function get(string $id): array
    {
        return $this->call('get', ['id' => $id])['job'];
    }

    public function cancel(string $id): bool
    {
        return (bool) $this->call('cancel', ['id' => $id])['cancelled'];
    }

    public function list(array $filter = []): array
    {
        return $this->call('list', $filter)['jobs'];
    }

    public function stats(): array
    {
        return $this->call('stats');
    }

    public function waitFor(string $id, int $timeoutSeconds = 900, int $intervalSeconds = 5): array
    {
        $until = time() + $timeoutSeconds;
        while (true) {
            $job = $this->get($id);
            if (in_array($job['status'], Queue::FINAL, true) || time() >= $until) {
                return $job;
            }
            sleep($intervalSeconds);
        }
    }
}
