'use strict';
/**
 * Tiny HTTP client for YOUR apps / cron jobs to talk to a Localia server
 * (works against both the Node and PHP servers).
 *
 *   const { LocaliaClient } = require('./lib/client');
 *   const localia = new LocaliaClient({ url: 'https://example.com/localia', apiKey: '...' });
 *   const id = await localia.enqueue({ prompt: 'Write a haiku about cron jobs' });
 *   const job = await localia.waitFor(id);   // job.status === 'done' -> job.response
 */
class LocaliaClient {
  constructor({ url, apiKey, timeoutMs = 30000 }) {
    this.url = url;
    this.apiKey = apiKey;
    this.timeoutMs = timeoutMs;
  }

  async call(action, payload = {}) {
    const res = await fetch(this.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + this.apiKey },
      body: JSON.stringify({ ...payload, action }),
      signal: AbortSignal.timeout(this.timeoutMs),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'invalid_response', status: res.status }));
    if (!data.ok) {
      const err = new Error('Localia ' + action + ' failed: ' + (data.message || data.error));
      err.code = data.error;
      err.status = res.status;
      throw err;
    }
    return data;
  }

  /** @returns {Promise<string>} job id */
  async enqueue(job) {
    return (await this.call('enqueue', { job })).id;
  }

  async get(id) {
    return (await this.call('get', { id })).job;
  }

  async cancel(id) {
    return (await this.call('cancel', { id })).cancelled;
  }

  async list(filter = {}) {
    return (await this.call('list', filter)).jobs;
  }

  async stats() {
    return this.call('stats');
  }

  /** Poll until the job is done/error/cancelled or the timeout passes. */
  async waitFor(id, { timeoutSeconds = 900, intervalSeconds = 5 } = {}) {
    const until = Date.now() + timeoutSeconds * 1000;
    for (;;) {
      const job = await this.get(id);
      if (['done', 'error', 'cancelled'].includes(job.status) || Date.now() >= until) return job;
      await new Promise((r) => setTimeout(r, intervalSeconds * 1000));
    }
  }
}

module.exports = { LocaliaClient };
