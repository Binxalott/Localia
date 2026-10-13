'use strict';
/**
 * Request handler for the Localia endpoint. Works with plain `http.createServer`
 * and can be mounted in Express/Fastify-style apps (mount it BEFORE any body parser):
 *
 *   app.all('/localia', localiaHandler);
 */
const { HttpError, now } = require('./util');
const auth = require('./auth');
const { deliverCallback } = require('./queue');

const VERSION = '1.0.0';

function readBody(req, limit) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) {
        reject(new HttpError(413, 'body_too_large'));
        req.destroy();
      } else chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

function createHandler({ queue, config }) {
  const origins = config.allowedOrigins || ['*'];

  function cors(req, res) {
    const origin = req.headers.origin;
    if (origins.includes('*')) res.setHeader('Access-Control-Allow-Origin', '*');
    else if (origin && origins.includes(origin)) {
      res.setHeader('Access-Control-Allow-Origin', origin);
      res.setHeader('Vary', 'Origin');
    }
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Localia-Key, X-Localia-Agent, X-Localia-Timestamp, X-Localia-Nonce, X-Localia-Signature');
    res.setHeader('Access-Control-Expose-Headers', 'X-Localia-Server-Signature');
    res.setHeader('Access-Control-Max-Age', '600');
  }

  function send(res, status, obj, extraHeaders = {}) {
    const raw = typeof obj === 'string' ? obj : JSON.stringify(obj);
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...extraHeaders });
    res.end(raw);
  }

  async function agentAction(req, res, raw) {
    const { agentId, secret, nonce } = await auth.verifyAgent(req.headers, raw, config, queue.store);
    let body;
    try { body = JSON.parse(raw); } catch { throw new HttpError(400, 'invalid_json'); }

    let out;
    switch (body.action) {
      case 'ping':
        await queue.touchAgent(agentId, body.agent);
        out = { ok: true, pending: await queue.pending() };
        break;
      case 'poll': {
        await queue.touchAgent(agentId, body.agent);
        const job = await queue.claim(agentId);
        out = { ok: true, job, pending: await queue.pending() };
        break;
      }
      case 'result': {
        const r = await queue.complete(agentId, body);
        if (r.final) deliverCallback(r.final, config.callbackSecret);
        out = { ok: true, accepted: true, requeued: !!r.requeued, pending: await queue.pending() };
        break;
      }
      default:
        throw new HttpError(400, 'unknown_action');
    }
    out.server_time = now();
    const rawOut = JSON.stringify(out);
    send(res, 200, rawOut, { 'X-Localia-Server-Signature': auth.signResponse(secret, nonce, rawOut) });
  }

  async function producerAction(req, res, body) {
    auth.verifyApiKey(req.headers, config);
    switch (body.action) {
      case 'enqueue': {
        const id = await queue.enqueue(body.job || body);
        return send(res, 201, { ok: true, id, job: await queue.get(id) });
      }
      case 'get': {
        const job = await queue.get(body.id);
        if (!job) throw new HttpError(404, 'job_not_found');
        return send(res, 200, { ok: true, job });
      }
      case 'cancel':
        return send(res, 200, { ok: true, cancelled: await queue.cancel(body.id) });
      case 'list':
        return send(res, 200, { ok: true, jobs: await queue.list(body) });
      case 'stats':
        return send(res, 200, { ok: true, ...(await queue.stats()) });
      default:
        throw new HttpError(400, 'unknown_action');
    }
  }

  return async function localiaHandler(req, res) {
    try {
      const url = new URL(req.url, 'http://localhost');
      const p = url.pathname.replace(/\/+$/, '') || '/';
      if (p !== config.basePath.replace(/\/+$/, '') && p !== '/') throw new HttpError(404, 'not_found');
      cors(req, res);

      if (req.method === 'OPTIONS') { res.writeHead(204); return res.end(); }

      if (req.method === 'GET') {
        const action = url.searchParams.get('action');
        if (!action) return send(res, 200, { ok: true, service: 'localia', version: VERSION, server_time: now() });
        return await producerAction(req, res, Object.fromEntries(url.searchParams));
      }

      if (req.method !== 'POST') throw new HttpError(405, 'method_not_allowed');
      const raw = await readBody(req, config.maxBodyBytes);

      if (req.headers['x-localia-signature']) return await agentAction(req, res, raw);

      let body;
      try { body = JSON.parse(raw || '{}'); } catch { throw new HttpError(400, 'invalid_json'); }
      return await producerAction(req, res, body);
    } catch (e) {
      const status = e instanceof HttpError ? e.status : 500;
      if (status === 500) console.error('[localia]', e);
      if (!res.headersSent) {
        send(res, status, { ok: false, error: e.code || 'server_error', message: status === 500 ? 'Internal error' : e.message, server_time: now() });
      }
    }
  };
}

module.exports = { createHandler, VERSION };
