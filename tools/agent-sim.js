#!/usr/bin/env node
'use strict';
/**
 * Command-line stand-in for the desktop agent, for testing a server install
 * (Node or PHP) without a browser or an LLM. Node 18+.
 *
 *   node tools/agent-sim.js --url https://example.com/localia/api.php --agent home-pc --secret XXXX ping
 *   node tools/agent-sim.js ... poll          # take one job and answer it with an echo
 *   node tools/agent-sim.js ... drain         # answer every waiting job with an echo
 */
const crypto = require('crypto');

const args = process.argv.slice(2);
const opt = (name, def) => {
  const i = args.indexOf('--' + name);
  return i >= 0 ? args[i + 1] : def;
};
const url = opt('url', process.env.LOCALIA_URL);
const agentId = opt('agent', process.env.LOCALIA_AGENT || 'home-pc');
const secret = opt('secret', process.env.LOCALIA_AGENT_SECRET);
const command = args.filter((a, i) => !a.startsWith('--') && !(i > 0 && args[i - 1].startsWith('--')))[0] || 'ping';

if (!url || !secret) {
  console.error('Usage: agent-sim.js --url <endpoint> --agent <id> --secret <secret> [ping|poll|drain]');
  process.exit(2);
}

async function call(action, extra = {}) {
  const raw = JSON.stringify({ action, ...extra });
  const ts = String(Math.floor(Date.now() / 1000));
  const nonce = crypto.randomBytes(16).toString('hex');
  const sig = crypto.createHmac('sha256', secret).update(`v1\n${ts}\n${nonce}\n${raw}`).digest('hex');
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Localia-Agent': agentId, 'X-Localia-Timestamp': ts, 'X-Localia-Nonce': nonce, 'X-Localia-Signature': sig },
    body: raw,
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { throw new Error(`HTTP ${res.status}, non-JSON body: ${text.slice(0, 200)}`); }
  if (!data.ok) throw new Error(`HTTP ${res.status}: ${data.error} ${data.message || ''}`);
  const expected = crypto.createHmac('sha256', secret).update(`v1-resp\n${nonce}\n${text}`).digest('hex');
  if (res.headers.get('x-localia-server-signature') !== expected) throw new Error('server response signature invalid');
  return data;
}

async function handleOne() {
  const res = await call('poll', { agent: { version: 'sim', provider: 'echo', model: 'echo' } });
  if (!res.job) {
    console.log('no job waiting');
    return 0;
  }
  const job = res.job;
  console.log(`got ${job.id}: ${JSON.stringify(job.prompt || job.messages).slice(0, 120)}`);
  const out = await call('result', {
    job_id: job.id,
    lease_token: job.lease_token,
    status: 'done',
    response: `ECHO: ${job.prompt || ''}`,
    model: 'echo',
    duration_ms: 1,
  });
  console.log(`answered ${job.id}; ${out.pending} still waiting`);
  return out.pending;
}

(async () => {
  if (command === 'ping') {
    const r = await call('ping', { agent: { version: 'sim' } });
    console.log(`ok - authenticated, signature verified, ${r.pending} job(s) waiting, server time ${new Date(r.server_time * 1000).toISOString()}`);
  } else if (command === 'poll') {
    await handleOne();
  } else if (command === 'drain') {
    while ((await handleOne()) > 0);
  } else {
    throw new Error('unknown command ' + command);
  }
})().catch((e) => {
  console.error('error:', e.message);
  process.exit(1);
});
