# Localia protocol v1

A single HTTPS endpoint (e.g. `/localia/api.php` or `/localia`) serves two kinds of callers:

- **Agents** (the home PC): HMAC-signed `POST` requests. A request counts as an agent request when it carries an `X-Localia-Signature` header.
- **Producers** (your apps): API key in `Authorization: Bearer <key>` or `X-Localia-Key: <key>`.

An unauthenticated `GET` with no `action` returns `{"ok":true,"service":"localia","version":"1.0.0","server_time":…}`, which works as a health check.

Every response is JSON. Errors look like `{"ok":false,"error":"<code>","message":"…","server_time":<unix>}`.

## Agent requests

Headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Localia-Agent` | agent ID, e.g. `home-pc` |
| `X-Localia-Timestamp` | Unix seconds |
| `X-Localia-Nonce` | 16–64 chars `[A-Za-z0-9_-]`, unique per request (32 hex chars recommended) |
| `X-Localia-Signature` | `hex(HMAC_SHA256(secret, "v1\n" + timestamp + "\n" + nonce + "\n" + rawBody))` |

The server checks:
- the agent ID is known and its secret is configured
- `|now - timestamp| <= timestamp_tolerance` (300s); otherwise `clock_skew`, and the error includes `server_time` so the agent can correct for it
- the signature matches (constant-time compare)
- the nonce hasn't been used before by this agent within the tolerance window

Successful agent responses carry
`X-Localia-Server-Signature: hex(HMAC_SHA256(secret, "v1-resp\n" + requestNonce + "\n" + rawResponseBody))`,
which the agent should verify.

### `ping`

```json
{"action":"ping","agent":{"version":"1.0.0","provider":"ollama","model":"llama3.1:8b"}}
```
→ `{"ok":true,"pending":2,"server_time":…}`

### `poll`

```json
{"action":"poll","agent":{…}}
```
→
```json
{"ok":true,"pending":1,"server_time":…,
 "job":{"id":"job_…","lease_token":"…","lease_until":1791853422,
        "prompt":"…","system":null,"messages":null,"model":null,
        "options":{"temperature":0.2},"attempt":1}}
```
`job` is `null` when nothing is waiting. `pending` is how many more jobs could be picked up right now.

Claimable jobs are those that are `queued` with `not_before <= now`, plus `leased` jobs whose lease has expired and that still have attempts left. They are handed out by `priority DESC, id ASC`, and job IDs sort by creation time.

### `result`

```json
{"action":"result","job_id":"job_…","lease_token":"…",
 "status":"done","response":"…","model":"llama3.1:8b","duration_ms":5321,
 "usage":{"prompt_tokens":120,"completion_tokens":300}}
```
or, on failure, `"status":"error","error":"message"`.

Web research jobs (below) also report `"sources":[{"url":"https://…","title":"…"}]` and add `web_searches` and `web_fetches` to `usage`. Servers keep `sources` in the job's `result`.

→ `{"ok":true,"accepted":true,"requeued":false,"pending":0,"server_time":…}`

- `lease_token` must match the job's current lease (`409 lease_mismatch`).
- An `error` result re-queues the job after `retry_delay_seconds` if attempts remain (`requeued: true`); otherwise the job becomes `error`.
- If `pending > 0`, the agent should poll again right away instead of waiting for its interval.

## Web research jobs (agent 1.1+)

A job can ask the agent to research the web before answering by putting a `research` object in `options`:

```json
"options": {
  "format": { …JSON schema for the answer… },
  "num_ctx": 16384,
  "research": { "max_searches": 3, "max_fetches": 3, "page_chars": 6000, "cite_field": "source_urls" }
}
```

The agent then:

1. asks the LLM for up to `max_searches` search queries,
2. runs them with the search provider chosen in its settings (DuckDuckGo, SearXNG, Brave Search API or Ollama web search), through the local helper in `serve.py` / `serve.js`,
3. asks the LLM to pick up to `max_fetches` results and fetches those pages (first `page_chars` characters of their text),
4. sends the job's messages plus the retrieved pages to the LLM, with `format` as the structured-output format,
5. if any object in the answer has a `cite_field` array citing no retrieved page, sends it back once for correction.

Every page shown to the LLM (search results and fetched pages, including redirect targets) is returned in `sources`, so the producer can reject citations of anything else. The agent reports `"research": true` in its `agent` info when it can take these jobs; jobs it can't research fail with an error.

The helper only answers requests addressed to `localhost:<port>` that carry a per-run token only the agent page can read, fetches only `http(s)`, refuses private, loopback and link-local addresses (except the SearXNG URL the user configured), re-checks each redirect (max 5) and caps responses at 3 MB.

Agents also report `models` (the runtime's model list) and `search_provider` in their `agent` info.

## Producer requests

`POST` a JSON body with `action`, or `GET ?action=…&…`.

| action | body | response |
|---|---|---|
| `enqueue` | `{"job":{…}}` (or job fields at the top level) | `201 {"ok":true,"id":"…","job":{…}}` |
| `get` | `{"id":"…"}` | `{"ok":true,"job":{…}}` |
| `cancel` | `{"id":"…"}` | `{"ok":true,"cancelled":true}` (only `queued`/`leased` jobs) |
| `list` | `{"status":"done","limit":50,"since":<unix>}` | `{"ok":true,"jobs":[…]}` newest first |
| `stats` | — | `{"ok":true,"counts":{…},"available":n,"agents":[{"agent_id","last_seen","seconds_since_seen","info"}]}` |

Job object fields: `id, status, priority, prompt, system, messages, model, options, meta, callback_url, attempts, max_attempts, not_before, agent_id, response, error, result{model,duration_ms,usage,agent}, created_at, leased_at, completed_at`.

## Callbacks

When a job reaches `done` or a final `error`, and it has a `callback_url`, the server POSTs:

```
Content-Type: application/json
X-Localia-Timestamp: <unix>
X-Localia-Signature: hex(HMAC_SHA256(callback_secret, "v1-cb\n" + timestamp + "\n" + rawBody))   (if callback_secret is set)

{"event":"job.finished","job":{…}}
```

Delivery is best-effort (one attempt, 15s timeout). Use `get`/`list` as the source of truth.

## Storage schema

See `server-php/schema/mysql.sql`. All times are Unix seconds stored in `BIGINT` columns. JSON fields (`messages`, `options`, `meta`, `result_meta`) are stored as text.
