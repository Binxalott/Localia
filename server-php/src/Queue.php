<?php
declare(strict_types=1);

namespace Localia;

use PDO;

/**
 * The job queue, backed by PDO (MySQL/MariaDB or SQLite).
 * Same table layout as the Node server's MySQL store, so both can share a database.
 */
class Queue
{
    public const FINAL = ['done', 'error', 'cancelled'];
    private const STATUSES = ['queued', 'leased', 'done', 'error', 'cancelled'];
    private const JSON_FIELDS = ['messages', 'options', 'meta', 'result_meta'];
    private const INT_FIELDS = ['priority', 'attempts', 'max_attempts', 'not_before', 'lease_until', 'created_at', 'leased_at', 'completed_at'];
    private const CLAIMABLE = "((status = 'queued' AND not_before <= :t1) OR (status = 'leased' AND lease_until < :t2 AND attempts < max_attempts))";

    private PDO $pdo;
    private array $config;
    private string $jobs;
    private string $agents;
    private string $nonces;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $p = $config['db']['table_prefix'] ?? 'localia_';
        $this->jobs = $p . 'jobs';
        $this->agents = $p . 'agents';
        $this->nonces = $p . 'nonces';
    }

    /** Prepare + execute, binding ints as PDO::PARAM_INT (SQLite compares a text parameter as larger than any number). */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue(is_int($k) ? $k + 1 : $k, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $st->execute();
        return $st;
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    public function ensureSchema(): void
    {
        if ($this->isMysql()) {
            $text = 'MEDIUMTEXT';
            $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        } else {
            $text = 'TEXT';
            $tail = '';
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->jobs} (
            id VARCHAR(40) NOT NULL PRIMARY KEY,
            status VARCHAR(16) NOT NULL,
            priority INT NOT NULL DEFAULT 0,
            prompt {$text} NULL,
            system_prompt {$text} NULL,
            messages {$text} NULL,
            model VARCHAR(191) NULL,
            options TEXT NULL,
            meta {$text} NULL,
            callback_url VARCHAR(1024) NULL,
            attempts INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 3,
            not_before BIGINT NOT NULL DEFAULT 0,
            agent_id VARCHAR(64) NULL,
            lease_token VARCHAR(64) NULL,
            lease_until BIGINT NULL,
            response {$text} NULL,
            error TEXT NULL,
            result_meta TEXT NULL,
            created_at BIGINT NOT NULL,
            leased_at BIGINT NULL,
            completed_at BIGINT NULL
        ){$tail}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->agents} (
            agent_id VARCHAR(64) NOT NULL PRIMARY KEY,
            last_seen BIGINT NOT NULL,
            info TEXT NULL
        ){$tail}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->nonces} (
            agent_id VARCHAR(64) NOT NULL,
            nonce VARCHAR(64) NOT NULL,
            ts BIGINT NOT NULL,
            PRIMARY KEY (agent_id, nonce)
        ){$tail}");
        if (!$this->isMysql()) {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$this->jobs}_status ON {$this->jobs} (status, priority, id)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$this->nonces}_ts ON {$this->nonces} (ts)");
        } else {
            $this->addMysqlIndex($this->jobs, 'idx_status_priority', '(status, priority, id)');
            $this->addMysqlIndex($this->nonces, 'idx_ts', '(ts)');
        }
    }

    private function addMysqlIndex(string $table, string $name, string $cols): void
    {
        $st = $this->run("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$name]);
        if (!$st->fetch()) {
            $this->pdo->exec("ALTER TABLE {$table} ADD INDEX {$name} {$cols}");
        }
    }

    // ------------------------------------------------------------------ producer API

    /**
     * Queue a job. Accepted keys: prompt, messages, system, model, options, meta,
     * priority, callback_url, not_before (unix time or date string), max_attempts.
     * Returns the job id.
     */
    public function enqueue(array $input): string
    {
        $prompt = isset($input['prompt']) ? (string) $input['prompt'] : null;
        $messages = null;
        if (isset($input['messages'])) {
            if (!is_array($input['messages']) || !$input['messages']) {
                throw new HttpError(400, 'invalid_messages');
            }
            $messages = [];
            foreach ($input['messages'] as $m) {
                if (!is_array($m) || !in_array($m['role'] ?? '', ['system', 'user', 'assistant'], true) || !is_string($m['content'] ?? null)) {
                    throw new HttpError(400, 'invalid_messages', 'Each message needs role (system|user|assistant) and string content');
                }
                $messages[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }
        if ($messages === null && ($prompt === null || trim($prompt) === '')) {
            throw new HttpError(400, 'prompt_required', 'Provide "prompt" or "messages"');
        }

        $options = $input['options'] ?? [];
        if (!is_array($options)) {
            throw new HttpError(400, 'invalid_options');
        }
        $meta = $input['meta'] ?? null;
        $metaJson = $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson !== null && strlen($metaJson) > 65535) {
            throw new HttpError(400, 'meta_too_large');
        }
        $callback = !empty($input['callback_url']) ? (string) $input['callback_url'] : null;
        if ($callback !== null && !preg_match('#^https?://#i', $callback)) {
            throw new HttpError(400, 'invalid_callback_url');
        }

        $notBefore = 0;
        if (!empty($input['not_before'])) {
            $nb = $input['not_before'];
            if (is_numeric($nb)) {
                $notBefore = (int) $nb;
                if ($notBefore > 100000000000) {
                    $notBefore = intdiv($notBefore, 1000);
                }
            } else {
                $notBefore = strtotime((string) $nb);
                if ($notBefore === false) {
                    throw new HttpError(400, 'invalid_not_before');
                }
            }
        }

        $id = self::newId();
        $row = [
            'id'            => $id,
            'status'        => 'queued',
            'priority'      => max(-1000, min(1000, (int) ($input['priority'] ?? 0))),
            'prompt'        => $prompt,
            'system_prompt' => isset($input['system']) ? (string) $input['system'] : null,
            'messages'      => $messages === null ? null : json_encode($messages, JSON_UNESCAPED_UNICODE),
            'model'         => !empty($input['model']) ? substr((string) $input['model'], 0, 191) : null,
            'options'       => json_encode((object) $options, JSON_UNESCAPED_UNICODE),
            'meta'          => $metaJson,
            'callback_url'  => $callback,
            'attempts'      => 0,
            'max_attempts'  => max(1, min(10, (int) ($input['max_attempts'] ?? $this->config['max_attempts'] ?? 3))),
            'not_before'    => $notBefore,
            'created_at'    => time(),
        ];
        $cols = array_keys($row);
        $st = $this->run("INSERT INTO {$this->jobs} (" . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')', $row);
        return $id;
    }

    /** Raw job row (JSON fields decoded) or null. */
    public function getRow(string $id): ?array
    {
        $st = $this->run("SELECT * FROM {$this->jobs} WHERE id = ?", [$id]);
        $row = $st->fetch();
        return $row ? self::decode($row) : null;
    }

    /** Job as returned to producers, or null. */
    public function get(string $id): ?array
    {
        $row = $this->getRow($id);
        return $row ? self::toPublic($row) : null;
    }

    public function cancel(string $id): bool
    {
        $st = $this->run("UPDATE {$this->jobs} SET status = 'cancelled', completed_at = ?, lease_until = NULL WHERE id = ? AND status IN ('queued','leased')", [time(), $id]);
        return $st->rowCount() === 1;
    }

    /** @param array $filter status, limit, since (unix time) */
    public function list(array $filter = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filter['status'])) {
            if (!in_array($filter['status'], self::STATUSES, true)) {
                throw new HttpError(400, 'invalid_status');
            }
            $where[] = 'status = ?';
            $params[] = $filter['status'];
        }
        if (!empty($filter['since'])) {
            $where[] = 'COALESCE(completed_at, created_at) >= ?';
            $params[] = (int) $filter['since'];
        }
        $limit = max(1, min(500, (int) ($filter['limit'] ?? 50)));
        $sql = "SELECT * FROM {$this->jobs}" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT {$limit}";
        $st = $this->run($sql, $params);
        return array_map(fn ($r) => self::toPublic(self::decode($r)), $st->fetchAll());
    }

    public function stats(): array
    {
        $counts = ['queued' => 0, 'leased' => 0, 'done' => 0, 'error' => 0, 'cancelled' => 0];
        foreach ($this->pdo->query("SELECT status, COUNT(*) AS n FROM {$this->jobs} GROUP BY status") as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $t = time();
        $agents = [];
        foreach ($this->pdo->query("SELECT * FROM {$this->agents}") as $r) {
            $agents[] = [
                'agent_id'           => $r['agent_id'],
                'last_seen'          => (int) $r['last_seen'],
                'seconds_since_seen' => $t - (int) $r['last_seen'],
                'info'               => $r['info'] ? json_decode($r['info'], true) : new \stdClass(),
            ];
        }
        return ['counts' => $counts, 'available' => $this->pending(), 'agents' => $agents, 'server_time' => $t];
    }

    /** Block (polling the DB) until the job finishes or the timeout passes. Meant for CLI/cron scripts. */
    public function waitFor(string $id, int $timeoutSeconds = 600, int $intervalSeconds = 3): ?array
    {
        $until = time() + $timeoutSeconds;
        while (true) {
            $job = $this->get($id);
            if ($job === null || in_array($job['status'], self::FINAL, true) || time() >= $until) {
                return $job;
            }
            sleep($intervalSeconds);
        }
    }

    // ------------------------------------------------------------------ agent API

    public function touchAgent(string $agentId, $info): void
    {
        $json = json_encode(is_array($info) ? $info : new \stdClass(), JSON_UNESCAPED_UNICODE);
        if ($this->isMysql()) {
            $sql = "INSERT INTO {$this->agents} (agent_id, last_seen, info) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), info = VALUES(info)";
        } else {
            $sql = "INSERT INTO {$this->agents} (agent_id, last_seen, info) VALUES (?, ?, ?) ON CONFLICT(agent_id) DO UPDATE SET last_seen = excluded.last_seen, info = excluded.info";
        }
        $this->run($sql, [$agentId, time(), $json]);
    }

    /** Number of jobs an agent could pick up right now. */
    public function pending(): int
    {
        $t = time();
        $st = $this->run("SELECT COUNT(*) FROM {$this->jobs} WHERE " . self::CLAIMABLE, ['t1' => $t, 't2' => $t]);
        return (int) $st->fetchColumn();
    }

    /** Atomically lease the next job to an agent. */
    public function claim(string $agentId): ?array
    {
        $t = time();
        $token = bin2hex(random_bytes(16));
        $leaseUntil = $t + (int) $this->config['lease_seconds'];
        for ($i = 0; $i < 5; $i++) {
            $st = $this->run("SELECT id FROM {$this->jobs} WHERE " . self::CLAIMABLE . ' ORDER BY priority DESC, id ASC LIMIT 1', ['t1' => $t, 't2' => $t]);
            $id = $st->fetchColumn();
            if ($id === false) {
                return null;
            }
            $up = $this->run("UPDATE {$this->jobs} SET status = 'leased', agent_id = :agent, lease_token = :token, lease_until = :until, leased_at = :now, attempts = attempts + 1 WHERE id = :id AND " . self::CLAIMABLE, ['agent' => $agentId, 'token' => $token, 'until' => $leaseUntil, 'now' => $t, 'id' => $id, 't1' => $t, 't2' => $t]);
            if ($up->rowCount() === 1) {
                return self::toAgent($this->getRow((string) $id));
            }
        }
        return null;
    }

    /**
     * Record an agent's result. Returns the finished job row when it reached a final
     * state (so the caller can fire the callback), or null when it was re-queued.
     */
    public function complete(string $agentId, array $body): ?array
    {
        if (!is_string($body['job_id'] ?? null) || !is_string($body['lease_token'] ?? null)) {
            throw new HttpError(400, 'job_id_and_lease_token_required');
        }
        $job = $this->getRow($body['job_id']);
        if ($job === null) {
            throw new HttpError(404, 'job_not_found');
        }
        if ($job['agent_id'] !== $agentId || !hash_equals((string) $job['lease_token'], $body['lease_token'])) {
            throw new HttpError(409, 'lease_mismatch');
        }
        if ($job['status'] === 'done' || $job['status'] === 'cancelled') {
            throw new HttpError(409, 'job_already_' . $job['status']);
        }

        $t = time();
        $resultMeta = json_encode([
            'model'       => isset($body['model']) ? (string) $body['model'] : null,
            'duration_ms' => is_numeric($body['duration_ms'] ?? null) ? $body['duration_ms'] + 0 : null,
            'usage'       => is_array($body['usage'] ?? null) ? $body['usage'] : null,
            'agent'       => $agentId,
        ], JSON_UNESCAPED_UNICODE);

        if (($body['status'] ?? 'done') === 'error') {
            $error = substr((string) ($body['error'] ?? 'agent_error'), 0, 10000);
            if ($job['attempts'] < $job['max_attempts']) {
                $st = $this->run("UPDATE {$this->jobs} SET status = 'queued', not_before = ?, error = ?, lease_until = NULL WHERE id = ? AND lease_token = ? AND status = 'leased'", [$t + (int) $this->config['retry_delay_seconds'], $error, $job['id'], $body['lease_token']]);
                return null;
            }
            $fields = ['error', $error, null];
        } else {
            if (!is_string($body['response'] ?? null)) {
                throw new HttpError(400, 'response_required');
            }
            $fields = ['done', null, $body['response']];
        }
        $st = $this->run("UPDATE {$this->jobs} SET status = ?, error = ?, response = ?, result_meta = ?, completed_at = ?, lease_until = NULL WHERE id = ? AND lease_token = ? AND status NOT IN ('done','cancelled')", [$fields[0], $fields[1], $fields[2], $resultMeta, $t, $job['id'], $body['lease_token']]);
        return $this->getRow($job['id']);
    }

    /** Record a nonce; false if it was already used (replay). */
    public function useNonce(string $agentId, string $nonce, int $ts, int $ttl): bool
    {
        $this->run("DELETE FROM {$this->nonces} WHERE ts < ?", [time() - $ttl]);
        $ignore = $this->isMysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $st = $this->run("{$ignore} INTO {$this->nonces} (agent_id, nonce, ts) VALUES (?, ?, ?)", [$agentId, $nonce, $ts]);
        return $st->rowCount() === 1;
    }

    /** Expire dead leases and purge old jobs. Called opportunistically by api.php (or from cron). */
    public function maintenance(): array
    {
        $t = time();
        $st = $this->run("UPDATE {$this->jobs} SET status = 'error', error = 'lease_expired', completed_at = ?, lease_until = NULL WHERE status = 'leased' AND lease_until < ? AND attempts >= max_attempts", [$t, $t]);
        $expired = $st->rowCount();
        $purged = 0;
        $days = (int) ($this->config['retention_days'] ?? 0);
        if ($days > 0) {
            $st = $this->run("DELETE FROM {$this->jobs} WHERE status IN ('done','error','cancelled') AND COALESCE(completed_at, created_at) < ?", [$t - $days * 86400]);
            $purged = $st->rowCount();
        }
        return ['expired' => $expired, 'purged' => $purged];
    }

    // ------------------------------------------------------------------ helpers

    public static function newId(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        return 'job_' . str_pad(base_convert((string) $ms, 10, 36), 9, '0', STR_PAD_LEFT) . bin2hex(random_bytes(6));
    }

    private static function decode(array $row): array
    {
        foreach (self::JSON_FIELDS as $f) {
            $row[$f] = $row[$f] === null ? null : json_decode($row[$f], true);
        }
        foreach (self::INT_FIELDS as $f) {
            if ($row[$f] !== null) {
                $row[$f] = (int) $row[$f];
            }
        }
        return $row;
    }

    private static function obj($v)
    {
        return (is_array($v) && !$v) ? new \stdClass() : $v;
    }

    public static function toPublic(array $job): array
    {
        return [
            'id'           => $job['id'],
            'status'       => $job['status'],
            'priority'     => $job['priority'],
            'prompt'       => $job['prompt'],
            'system'       => $job['system_prompt'],
            'messages'     => $job['messages'],
            'model'        => $job['model'],
            'options'      => self::obj($job['options'] ?? []),
            'meta'         => self::obj($job['meta']),
            'callback_url' => $job['callback_url'],
            'attempts'     => $job['attempts'],
            'max_attempts' => $job['max_attempts'],
            'not_before'   => $job['not_before'],
            'agent_id'     => $job['agent_id'],
            'response'     => $job['response'],
            'error'        => $job['error'],
            'result'       => $job['result_meta'],
            'created_at'   => $job['created_at'],
            'leased_at'    => $job['leased_at'],
            'completed_at' => $job['completed_at'],
        ];
    }

    public static function toAgent(array $job): array
    {
        return [
            'id'          => $job['id'],
            'lease_token' => $job['lease_token'],
            'lease_until' => $job['lease_until'],
            'prompt'      => $job['prompt'],
            'system'      => $job['system_prompt'],
            'messages'    => $job['messages'],
            'model'       => $job['model'],
            'options'     => self::obj($job['options'] ?? []),
            'attempt'     => $job['attempts'],
        ];
    }

    /** POST a finished job to its callback_url. */
    public static function deliverCallback(array $job, string $secret): void
    {
        if (empty($job['callback_url'])) {
            return;
        }
        $body = json_encode(['event' => 'job.finished', 'job' => self::toPublic($job)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $headers = ['Content-Type: application/json', 'User-Agent: Localia/1.0', 'X-Localia-Timestamp: ' . $ts];
        if ($secret !== '') {
            $headers[] = 'X-Localia-Signature: ' . hash_hmac('sha256', "v1-cb\n{$ts}\n{$body}", $secret);
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        if (@file_get_contents($job['callback_url'], false, $ctx) === false) {
            error_log('[localia] callback failed for ' . $job['id']);
        }
    }
}
