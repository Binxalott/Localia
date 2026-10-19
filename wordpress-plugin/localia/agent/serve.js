#!/usr/bin/env node
// Serve the Localia agent on http://localhost:8787 (Node alternative to serve.py).
// Usage: node serve.js [port]
//
// Also runs the web-research helper (same rules as serve.py): the page asks it to fetch
// search results and pages, because browsers can't read other sites (CORS). It only answers
// requests addressed to localhost on this port, needs a per-run token only this page can read,
// refuses private/local addresses unless the page allows them for your own search server,
// fetches only http(s), re-checks every redirect (max 5) and caps response sizes.
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const dns = require('dns').promises;
const net = require('net');
const crypto = require('crypto');
const { exec } = require('child_process');

const PORT = Number(process.argv[2] || 8787);
const ROOT = __dirname;
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' };
const HELPER_VERSION = 1;
const TOKEN = crypto.randomBytes(24).toString('hex');
const ALLOWED_HOSTS = new Set([`localhost:${PORT}`, `127.0.0.1:${PORT}`]);
const FORWARD_HEADERS = new Set(['accept', 'accept-language', 'authorization', 'x-subscription-token', 'content-type']);
const USER_AGENT = 'Mozilla/5.0 (compatible; LocaliaAgent/1.1; +https://github.com/Binxalott/Localia)';
const MAX_BYTES = 3 * 1024 * 1024;
const TIMEOUT_MS = 20000;

class FetchError extends Error {
  constructor(code, message) { super(message); this.code = code; }
}

function isPrivateIp(ip) {
  if (net.isIPv4(ip)) {
    const [a, b] = ip.split('.').map(Number);
    return a === 0 || a === 10 || a === 127 || a >= 224 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31)
      || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127) || (a === 192 && b === 0) || (a === 198 && (b === 18 || b === 19));
  }
  const v6 = ip.toLowerCase().split('%')[0];
  const mapped = /^::ffff:(\d+\.\d+\.\d+\.\d+)$/.exec(v6);
  if (mapped) return isPrivateIp(mapped[1]);
  return v6 === '::' || v6 === '::1' || /^f[cd]/.test(v6) || /^fe[89ab]/.test(v6) || /^ff/.test(v6) || v6.startsWith('64:ff9b:') || v6.startsWith('2001:db8:');
}

async function checkUrl(url, allowPrivate) {
  let u;
  try { u = new URL(url); } catch (e) { throw new FetchError('bad_url', 'Invalid URL.'); }
  if (u.protocol !== 'http:' && u.protocol !== 'https:') throw new FetchError('bad_url', 'Only http and https URLs can be fetched.');
  if (allowPrivate) return;
  const host = u.hostname.replace(/^\[|\]$/g, '');
  let addrs;
  try { addrs = net.isIP(host) ? [{ address: host }] : await dns.lookup(host, { all: true }); } catch (e) { throw new FetchError('dns_failed', `Could not resolve ${host}.`); }
  if (addrs.some((a) => isPrivateIp(a.address))) throw new FetchError('private_address', `${host} points to a private or local address; refusing to fetch it.`);
}

async function readCapped(res) {
  const chunks = [];
  let size = 0;
  for await (const chunk of res.body || []) {
    chunks.push(chunk);
    size += chunk.length;
    if (size >= MAX_BYTES) break;
  }
  return Buffer.concat(chunks).subarray(0, MAX_BYTES);
}

async function helperFetch(req) {
  let url = String(req.url || '');
  let method = req.method === 'POST' ? 'POST' : 'GET';
  const allowPrivate = !!req.allow_private;
  const headers = { 'User-Agent': USER_AGENT };
  for (const [k, v] of Object.entries(req.headers && typeof req.headers === 'object' ? req.headers : {})) {
    if (typeof v === 'string' && FORWARD_HEADERS.has(k.toLowerCase())) headers[k] = v;
  }
  let body = method === 'POST' && typeof req.body === 'string' ? req.body : undefined;

  for (let hop = 0; hop < 6; hop++) {
    await checkUrl(url, allowPrivate);
    let res;
    try {
      res = await fetch(url, { method, headers, body, redirect: 'manual', signal: AbortSignal.timeout(TIMEOUT_MS) });
    } catch (e) {
      throw new FetchError('fetch_failed', `Could not fetch ${url}: ${e.cause ? e.cause.message : e.message}`);
    }
    const location = res.headers.get('location');
    if ([301, 302, 303, 307, 308].includes(res.status) && location) {
      url = new URL(location, url).toString();
      if (res.status === 303 || ([301, 302].includes(res.status) && method === 'POST')) { method = 'GET'; body = undefined; }
      continue;
    }
    const contentType = res.headers.get('content-type') || '';
    const mime = contentType.split(';')[0].trim().toLowerCase();
    const texty = mime.startsWith('text/') || ['application/json', 'application/xhtml+xml', 'application/xml', ''].includes(mime);
    const raw = await readCapped(res);
    const charset = (/charset=([\w-]+)/i.exec(contentType) || [])[1] || 'utf-8';
    let text = '';
    if (texty) {
      try { text = new TextDecoder(charset).decode(raw); } catch (e) { text = new TextDecoder('utf-8').decode(raw); }
    }
    return { ok: true, status: res.status, url, content_type: contentType, body: text };
  }
  throw new FetchError('too_many_redirects', 'Too many redirects.');
}

function sendJson(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' });
  res.end(body);
}

function handleHelperFetch(req, res) {
  const given = Buffer.from(String(req.headers['x-localia-helper'] || ''));
  const expected = Buffer.from(TOKEN);
  if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) {
    return sendJson(res, 403, { ok: false, error: 'bad_token', message: 'Helper token missing or wrong; reload the page.' });
  }
  let raw = '';
  req.on('data', (c) => { raw += c; if (raw.length > 1024 * 1024) req.destroy(); });
  req.on('end', async () => {
    let body;
    try { body = JSON.parse(raw || '{}'); } catch (e) { return sendJson(res, 400, { ok: false, error: 'bad_request', message: 'Invalid JSON.' }); }
    try {
      sendJson(res, 200, await helperFetch(body || {}));
    } catch (e) {
      sendJson(res, 200, { ok: false, error: e.code || 'fetch_failed', message: String(e.message).slice(0, 300) });
    }
  });
}

const server = http.createServer((req, res) => {
  if (!ALLOWED_HOSTS.has(String(req.headers.host || '').toLowerCase())) { res.writeHead(403); return res.end(); }
  const pathname = new URL(req.url, 'http://x').pathname;
  if (req.method === 'POST') {
    if (pathname === '/helper/fetch') return handleHelperFetch(req, res);
    res.writeHead(403); return res.end();
  }
  if (pathname === '/helper/info') return sendJson(res, 200, { ok: true, helper: HELPER_VERSION, token: TOKEN });

  let p = decodeURIComponent(pathname);
  if (p.endsWith('/')) p += 'index.html';
  const file = path.normalize(path.join(ROOT, p));
  if (!file.startsWith(ROOT + path.sep)) { res.writeHead(403); return res.end(); }
  fs.readFile(file, (err, data) => {
    if (err) { res.writeHead(404); return res.end('Not found'); }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-cache' });
    res.end(data);
  });
});

const url = `http://localhost:${PORT}/`;
const open = () => {
  const cmd = process.platform === 'win32' ? `start "" "${url}"` : process.platform === 'darwin' ? `open "${url}"` : `xdg-open "${url}"`;
  exec(cmd, () => {});
};
server.on('error', (e) => {
  if (e.code === 'EADDRINUSE') { console.log(`Port ${PORT} is busy; assuming Localia is already served there.`); open(); }
  else { console.error(e.message); process.exit(1); }
});
server.listen(PORT, '127.0.0.1', () => {
  console.log(`Localia agent: ${url}  (only reachable from this computer)`);
  console.log('Leave this window open. Press Ctrl+C to stop.');
  if (!process.argv.includes('--no-open')) open();
});
