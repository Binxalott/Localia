'use strict';
const { HttpError, now, newJobId, randomHex, hmacHex } = require('./util');

const FINAL = new Set(['done', 'error', 'cancelled']);
const STATUSES = ['queued', 'leased', 'done', 'error', 'cancelled'];

function isObj(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function toUnix(v) {
  if (v === undefined || v === null || v === '') return 0;
  if (typeof v === 'number') return Math.floor(v > 1e11 ? v / 1000 : v);
  const t = Date.parse(v);
  if (Number.isNaN(t)) throw new HttpError(400, 'invalid_not_before');
  return Math.floor(t / 1000);
}

/** Validate producer input and build a job record. Shared by HTTP API and in-process use. */
function buildJob(input, config) {
  if (!isObj(input)) throw new HttpError(400, 'invalid_body');
  const prompt = input.prompt == null ? null : String(input.prompt);
  let messages = null;
  if (input.messages != null) {
    if (!Array.isArray(input.messages) || !input.messages.length) throw new HttpError(400, 'invalid_messages');
    messages = input.messages.map((m) => {
      if (!isObj(m) || !['system', 'user', 'assistant'].includes(m.role) || typeof m.content !== 'string') {
        throw new HttpError(400, 'invalid_messages', 'Each message needs role (system|user|assistant) and string content');
      }
      return { role: m.role, content: m.content };
    });
  }
  if (!messages && !(prompt && prompt.trim())) throw new HttpError(400, 'prompt_required', 'Provide "prompt" or "messages"');

  const options = input.options == null ? {} : input.options;
  if (!isObj(options)) throw new HttpError(400, 'invalid_options');
  const meta = input.meta == null ? null : input.meta;
  if (meta !== null && JSON.stringify(meta).length > 65535) throw new HttpError(400, 'meta_too_large');

  const callbackUrl = input.callback_url ? String(input.callback_url) : null;
  if (callbackUrl && !/^https?:\/\//i.test(callbackUrl)) throw new HttpError(400, 'invalid_callback_url');

  const priority = Math.max(-1000, Math.min(1000, parseInt(input.priority || 0, 10) || 0));
  const maxAttempts = Math.max(1, Math.min(10, parseInt(input.max_attempts || config.maxAttempts || 3, 10) || 3));

  return {
    id: newJobId(),
    status: 'queued',
    priority,
    prompt,
    system_prompt: input.system == null ? null : String(input.system),
    messages,
    model: input.model ? String(input.model).slice(0, 191) : null,
    options,
    meta,
    callback_url: callbackUrl,
    attempts: 0,
    max_attempts: maxAttempts,
    not_before: toUnix(input.not_before),
    agent_id: null,
    lease_token: null,
    lease_until: null,
    response: null,
    error: null,
    result_meta: null,
    created_at: now(),
    leased_at: null,
    completed_at: null,
  };
}

/** Shape returned to producers (your apps). */
function toPublic(job) {
  if (!job) return null;
  return {
    id: job.id,
    status: job.status,
    priority: job.priority,
    prompt: job.prompt,
    system: job.system_prompt,
    messages: job.messages,
    model: job.model,
    options: job.options,
    meta: job.meta,
    callback_url: job.callback_url,
    attempts: job.attempts,
    max_attempts: job.max_attempts,
    not_before: job.not_before,
    agent_id: job.agent_id,
    response: job.response,
    error: job.error,
    result: job.result_meta,
    created_at: job.created_at,
    leased_at: job.leased_at,
    completed_at: job.completed_at,
  };
}

/** Shape handed to the desktop agent. */
function toAgent(job) {
  return {
    id: job.id,
    lease_token: job.lease_token,
    lease_until: job.lease_until,
    prompt: job.prompt,
    system: job.system_prompt,
    messages: job.messages,
    model: job.model,
    options: job.options,
    attempt: job.attempts,
  };
}

class Queue {
  constructor(store, config) {
    this.store = store;
    this.config = config;
  }

  async enqueue(input) {
    const job = buildJob(input, this.config);
    await this.store.insertJob(job);
    return job.id;
  }

  async get(id) {
    return toPublic(await this.store.getJob(String(id)));
  }

  async cancel(id) {
    return this.store.cancelJob(String(id), now());
  }

  async list({ status, limit, since } = {}) {
    if (status && !STATUSES.includes(status)) throw new HttpError(400, 'invalid_status');
    const rows = await this.store.listJobs({
      status: status || null,
      limit: Math.max(1, Math.min(500, parseInt(limit || 50, 10) || 50)),
      since: parseInt(since || 0, 10) || 0,
    });
    return rows.map(toPublic);
  }

  async stats() {
    const t = now();
    return {
      counts: await this.store.countJobs(),
      available: await this.store.countAvailable(t),
      agents: (await this.store.listAgents()).map((a) => ({ ...a, seconds_since_seen: t - a.last_seen })),
      server_time: t,
    };
  }

  /** Wait (by polling storage) until a job is finished. Handy for in-process cron scripts. */
  async waitFor(id, timeoutSeconds = 600, intervalSeconds = 3) {
    const until = Date.now() + timeoutSeconds * 1000;
    for (;;) {
      const job = await this.get(id);
      if (!job || FINAL.has(job.status)) return job;
      if (Date.now() >= until) return job;
      await new Promise((r) => setTimeout(r, intervalSeconds * 1000));
    }
  }

  // ---- agent side ------------------------------------------------------

  async touchAgent(agentId, info) {
    await this.store.touchAgent(agentId, isObj(info) ? info : {}, now());
  }

  async pending() {
    return this.store.countAvailable(now());
  }

  async claim(agentId) {
    const t = now();
    const job = await this.store.claimJob(agentId, randomHex(16), t + this.config.leaseSeconds, t);
    return job ? toAgent(job) : null;
  }

  /**
   * Record an agent's result. Returns the updated job when it reached a final state
   * (so the caller can fire the callback), or { requeued: true } / null.
   */
  async complete(agentId, body) {
    if (!isObj(body) || typeof body.job_id !== 'string' || typeof body.lease_token !== 'string') {
      throw new HttpError(400, 'job_id_and_lease_token_required');
    }
    const job = await this.store.getJob(body.job_id);
    if (!job) throw new HttpError(404, 'job_not_found');
    if (job.agent_id !== agentId || job.lease_token !== body.lease_token) throw new HttpError(409, 'lease_mismatch');
    if (job.status === 'done' || job.status === 'cancelled') throw new HttpError(409, 'job_already_' + job.status);

    const t = now();
    const resultMeta = {
      model: body.model ? String(body.model) : null,
      duration_ms: Number.isFinite(body.duration_ms) ? body.duration_ms : null,
      usage: isObj(body.usage) ? body.usage : null,
      agent: agentId,
    };

    if (body.status === 'error') {
      const errorText = String(body.error || 'agent_error').slice(0, 10000);
      if (job.attempts < job.max_attempts) {
        await this.store.requeueJob(job.id, body.lease_token, t + this.config.retryDelaySeconds, errorText);
        return { requeued: true };
      }
      await this.store.finishJob(job.id, body.lease_token, { status: 'error', error: errorText, response: null, result_meta: resultMeta, completed_at: t });
    } else {
      if (typeof body.response !== 'string') throw new HttpError(400, 'response_required');
      await this.store.finishJob(job.id, body.lease_token, { status: 'done', error: null, response: body.response, result_meta: resultMeta, completed_at: t });
    }
    return { final: await this.store.getJob(job.id) };
  }

  async maintenance() {
    const t = now();
    const expired = await this.store.expireLeases(t);
    let purged = 0;
    if (this.config.retentionDays > 0) purged = await this.store.purge(t - this.config.retentionDays * 86400);
    return { expired, purged };
  }
}

/** POST the finished job to its callback_url (fire and forget). */
async function deliverCallback(job, secret) {
  if (!job || !job.callback_url) return;
  const body = JSON.stringify({ event: 'job.finished', job: toPublic(job) });
  const ts = String(now());
  const headers = { 'Content-Type': 'application/json', 'User-Agent': 'Localia/1.0', 'X-Localia-Timestamp': ts };
  if (secret) headers['X-Localia-Signature'] = hmacHex(secret, 'v1-cb\n' + ts + '\n' + body);
  try {
    await fetch(job.callback_url, { method: 'POST', headers, body, signal: AbortSignal.timeout(15000) });
  } catch (e) {
    console.error('[localia] callback failed for', job.id, e.message);
  }
}

module.exports = { Queue, buildJob, toPublic, toAgent, deliverCallback, FINAL };
