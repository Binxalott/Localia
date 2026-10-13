#!/usr/bin/env node
// Serve the Localia agent on http://localhost:8787 (Node alternative to serve.py).
// Usage: node serve.js [port]
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const { exec } = require('child_process');

const PORT = Number(process.argv[2] || 8787);
const ROOT = __dirname;
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' };

const server = http.createServer((req, res) => {
  let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
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
