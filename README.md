# Localia

**Use the LLM running on your home PC from your web server, without exposing anything at home.**

Localia is a "reverse API". Your server (for example a DigitalOcean droplet) keeps a queue of prompts. A small web page open in a browser on your home PC checks that queue on a schedule. It sends each prompt to the LLM running locally (Ollama, LM Studio, llama.cpp…) and posts the answer back to the server.

```
  your website / cron job                   your home PC (any OS, in a browser tab)
 ┌──────────────────────────┐              ┌──────────────────────────────────────┐
 │ enqueue("write a post…") │              │  Localia Agent page                  │
 │          │               │   1. poll    │   every N seconds, inside your       │
 │          ▼               │ ◄─────────── │   schedule, HMAC-signed              │
 │   Localia queue (DB)     │   2. job     │                                      │
 │          │               │ ───────────► │   3. prompt ──► Ollama / LM Studio   │
 │          ▼               │   4. result  │      answer ◄──                      │
 │ job.response / webhook   │ ◄─────────── │   (more queued? go again at once)    │
 └──────────────────────────┘              └──────────────────────────────────────┘
```

The home PC always makes the **outgoing** request, so your router, NAT, firewall and changing residential IP don't matter. No port forwarding, no tunnels, and nothing at home listens on the internet.

It's built for low-volume work like nightly cron jobs and scheduled content generation, not high-traffic, real-time use.

| Part | What it is | Runs on |
|---|---|---|
| [`client/`](client) | The desktop agent: a static web page (HTML/JS, no build step) | Any desktop browser on Windows, macOS or Linux |
| [`wordpress-plugin/localia/`](wordpress-plugin/localia) | **WordPress plugin**: tasks, a daily scheduler, an editor button, and a one-click download of a pre-configured agent | WordPress 6.2+ / PHP 7.4+ |
| [`server-php/`](server-php) | Queue + endpoint for PHP 7.4+/8.x, using **PDO** (SQLite out of the box, MySQL/MariaDB with placeholders ready) | Any PHP host |
| [`server-node/`](server-node) | The same thing for Node 18+, zero dependencies (JSON file store; MySQL via `mysql2`) | Any Node host |
| [`tools/agent-sim.js`](tools/agent-sim.js) | Command-line fake agent for testing a server install | Anywhere with Node |

Both servers speak the same protocol and use the same MySQL schema, so pick whichever fits your stack.

---

## Quick start

### 1. Make two secrets

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"     # or:  node server-node/server.js --gen-secret
```

Make one for the **agent secret** (shared by the server and the home PC) and one for the **API key** (used by your apps to enqueue jobs over HTTP). You can also press **Generate** on the agent's settings page and copy that value into the server config.

### 2a. Server: PHP

1. Upload `server-php/` to your site, e.g. as `/var/www/html/localia/`.
2. Edit `config.php`: set the agent secret under `agents`, an API key under `api_keys`, and the `db` settings.
   - `sqlite` works out of the box (needs `pdo_sqlite`). The database file goes in `data/`, which `data/.htaccess` blocks from the web. On nginx, block it yourself (below) or move the path outside the web root.
   - For MySQL, set `'driver' => 'mysql'` and fill in the `mysql` block. Tables are created automatically, or use `schema/mysql.sql`.
3. Your endpoint is `https://example.com/localia/api.php`. Open it in a browser and you should see `{"ok":true,"service":"localia",...}`.

nginx users: add `location ~ ^/localia/(data|src|schema|examples)/ { deny all; }`.

### 2b. Server: Node

```bash
cd server-node
LOCALIA_AGENT_SECRET=... LOCALIA_API_KEY=... node server.js
# MySQL instead of the JSON file:  npm install mysql2  and set LOCALIA_DB_DRIVER=mysql + LOCALIA_DB_* (see config.js)
```

It listens on `127.0.0.1:8790` at path `/localia`. Put it behind your existing HTTPS reverse proxy:

```nginx
location /localia {
    proxy_pass http://127.0.0.1:8790;
    proxy_set_header Host $host;
    proxy_read_timeout 60s;
}
```

Keep it running with systemd, pm2, or similar. You can also embed it in an Express app: `app.all('/localia', localia.createHandler({ queue, config }))` (see `server-node/index.js`).

Test either server from anywhere:

```bash
node tools/agent-sim.js --url https://example.com/localia/api.php --agent home-pc --secret YOUR_AGENT_SECRET ping
```

### 3. Home PC: LLM + agent

1. Install a local LLM runtime:
   - **Ollama** ([ollama.com](https://ollama.com)): `ollama pull llama3.1:8b` (or any model your hardware can run).
   - **LM Studio**: start the server in the Developer tab and switch on **Enable CORS**.
   - **llama.cpp / vLLM / LocalAI / Jan**: anything with an OpenAI-compatible `/v1/chat/completions` works.
2. Copy the `client/` folder to the PC and run the start script for your OS:
   - Windows: `start-windows.bat`
   - macOS: `start-macos.command`
   - Linux: `./start-linux.sh`

   This serves the page at **http://localhost:8787** using Python or Node, whichever is installed, and opens your browser. Using `localhost` matters: Ollama accepts browser requests from `localhost` pages by default. If you open `index.html` directly, the server connection still works, but you must set `OLLAMA_ORIGINS=*` for Ollama (see the Help tab).
3. On the **Settings** tab, fill in:
   - **Server**: protocol, address (domain or IP), port (blank = 443/80), and path (`/localia/api.php` for PHP, `/localia` for Node).
   - **Security**: agent ID (`home-pc`) and the shared secret.
   - **Local LLM**: runtime, URL, model (press **Load** to list them), temperature, max tokens, timeout.
   - **Instructions**: the LLM's role/system prompt, and how it combines with a system prompt a job sends.
   - **Checking interval**, back-to-back queue draining, auto-start, and keeping the screen awake.
   - **Schedule**: any time, or only in windows you choose (days of the week, start and end times, overnight windows, optional date ranges).

   Use **Test LLM** and **Test server connection**, then **Save settings**. Settings are stored in the browser's local storage for that page address, so keep using the same URL and port.
4. Press **Start agent** on the Dashboard and leave the tab open.

### 4. Send work from your server

**PHP on the same server** talks to the queue directly, no HTTP needed:

```php
require '/var/www/html/localia/bootstrap.php';

$id = localia_queue()->enqueue([
    'prompt'  => 'Write a 3 sentence intro for a post titled "Why I self-host my AI".',
    'system'  => 'You write short, upbeat blog intros.',       // optional
    'options' => ['temperature' => 0.7, 'max_tokens' => 400],  // optional
    'meta'    => ['post_id' => 123],                           // optional, yours, never sent to the PC
]);

$job = localia_queue()->waitFor($id, 1800);   // CLI/cron only — or check later with ->get($id)
if ($job['status'] === 'done') echo $job['response'];
```

**From any language, over HTTP**, with the API key:

```bash
curl -X POST https://example.com/localia/api.php \
  -H "Authorization: Bearer YOUR_API_KEY" -H "Content-Type: application/json" \
  -d '{"action":"enqueue","job":{"prompt":"Say hello"}}'
# → {"ok":true,"id":"job_0mv5yq715ce16d56dfb03",...}

curl -X POST https://example.com/localia/api.php \
  -H "Authorization: Bearer YOUR_API_KEY" -d '{"action":"get","id":"job_0mv5yq715ce16d56dfb03"}'
# → {"ok":true,"job":{"status":"done","response":"Hello!",...}}
```

Ready-made helpers:
- **Node**: `LocaliaClient` in `server-node/lib/client.js`
- **PHP on another server**: `\Localia\Client` in `server-php/src/Client.php`

See the `examples/` folders for cron scripts, an enqueue-now/collect-later pattern, and webhook receivers.

---

## WordPress plugin

If your site runs on WordPress, you don't need either server. Install the plugin instead:

1. Run `./tools/build-wp-plugin.sh` to build `dist/localia.zip`, then upload it in **Plugins → Add New → Upload Plugin** and activate it.
2. Go to **Localia → Settings** and click **Download agent for this site**. Unzip it on your home PC and run its start script. The connection details are already filled in.
3. Create **Tasks** (write new posts, or rewrite existing ones) with times of day, or use the **Localia – your AI** panel in the post editor.

See [`wordpress-plugin/localia/readme.txt`](wordpress-plugin/localia/readme.txt) for details and [`docs/wordpress-plugin-plan.md`](docs/wordpress-plugin-plan.md) for the free/Pro plan.

## Job options

| Field | Description |
|---|---|
| `prompt` | The user message. Required unless `messages` is given. |
| `messages` | Optional chat history: `[{"role":"system"\|"user"\|"assistant","content":"…"}]`. If you also send `prompt`, it is added as the last user message. |
| `system` | Job-specific system prompt. How it combines with the agent's own instructions is set on the agent: append (default), override, or ignore. |
| `model` | Ask for a specific local model. Used only if the agent allows it; otherwise the agent's own model is used. |
| `options` | `temperature`, `max_tokens`, `top_p`, `seed`, `stop`, `num_ctx`, and `format` (`"json"` or a JSON Schema object). Used only if the agent allows it. |
| `meta` | Any JSON you want to get back with the job. Never sent to the agent. |
| `priority` | Integer; higher runs first (default 0). |
| `not_before` | Unix time or date string; the job stays hidden until then. |
| `max_attempts` | Retries if the agent reports an error or never returns (default 3). |
| `callback_url` | When the job finishes, the server POSTs `{"event":"job.finished","job":{…}}` here, signed with `callback_secret`. |

Job statuses: `queued` → `leased` (the PC is working on it) → `done` / `error` / `cancelled`.

Producer actions (HTTP `POST` JSON with `action`, or `GET ?action=`): `enqueue`, `get`, `cancel`, `list` (`status`, `limit`, `since`), `stats` (queue counts, plus when each agent was last seen).

## Security

- Every agent request is signed with **HMAC-SHA256** over a timestamp, a one-time nonce, and the exact body. The server rejects unknown agents, bad signatures, timestamps more than 5 minutes off, and reused nonces (replay protection). The secret never travels over the network.
- The server signs its replies too (bound to the request's nonce), and the agent rejects replies that don't verify. Someone impersonating your server can't feed prompts to your PC.
- Jobs are **leased** with a random token. Only the agent holding the lease can submit the result, and a lease that runs out puts the job back in the queue.
- Your apps use a separate **API key**. The agent's secret can't create jobs, and the API key can't fetch them.
- Placeholder secrets (`CHANGE_ME…`) and secrets shorter than 16 characters are refused.
- **Use HTTPS.** Signatures stop tampering and replay, but over plain HTTP anyone in between can *read* your prompts and responses. A free Let's Encrypt certificate needs a domain name; a bare IP usually can't get a trusted certificate.
- **Prompt guard on the PC.** Before any job reaches the local LLM, the agent checks it for attempts to override instructions, requests for files, keys or wallets, commands to run, and attempts to send data elsewhere. An optional second layer asks the local model itself to classify each job as SAFE or UNSAFE. Blocked jobs never reach the LLM, are reported to the server as `rejected` (never retried), and by default pause the agent until you look. This is a safety net: the local model only turns text into text and can't open files or run programs, but if your server is ever compromised it stops obvious abuse early. You can also lock the agent to one instruction language (English, Spanish, French, German, Italian, Portuguese or Dutch) so that jobs written in any other language are blocked, which closes the "write the attack in another language" gap. Configure it under **Settings → Prompt guard**.
- The agent stores its settings, including the secret, in the browser's local storage on that PC. Treat the PC (and exported settings files) accordingly.

## Running it reliably

- Browsers slow down timers in background tabs. The agent runs its timers in a Web Worker and chains jobs as soon as each finishes, but a pinned tab or its own window is the most reliable setup.
- Turn off PC sleep, or tick **Keep the screen awake**. Tick **Start automatically** and add the start script to your OS's startup items for a hands-off setup.
- If the PC is off, jobs simply wait in the queue. A job whose lease runs out (default 15 minutes) is retried, up to `max_attempts`.
- Finished jobs are deleted after `retention_days` (default 30).

## Protocol

See [docs/PROTOCOL.md](docs/PROTOCOL.md) if you want to write your own agent or server in another language.

## License

MIT
