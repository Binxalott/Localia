#!/usr/bin/env node
'use strict';
/**
 * Localia server (Node 18+). Zero dependencies unless you use MySQL storage.
 *
 *   node server.js                 start the server
 *   node server.js --gen-secret    print a random secret for config.js
 */
const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const https = require('https');

if (process.argv.includes('--gen-secret')) {
  console.log(crypto.randomBytes(32).toString('hex'));
  process.exit(0);
}

const config = require('./config');
const { createStore } = require('./lib/stores');
const { Queue } = require('./lib/queue');
const { createHandler, VERSION } = require('./lib/http-handler');
const { isPlaceholder } = require('./lib/util');

async function main() {
  for (const [id, secret] of Object.entries(config.agents)) {
    if (isPlaceholder(secret)) console.warn(`[localia] WARNING: agent "${id}" has no secret set - it will be rejected until you set one.`);
  }
  if (!config.apiKeys.some((k) => !isPlaceholder(k))) {
    console.warn('[localia] WARNING: no API key set - the HTTP enqueue API is disabled until you set one.');
  }

  const store = await createStore(config.storage);
  const queue = new Queue(store, config);
  const handler = createHandler({ queue, config });

  const server = config.tls
    ? https.createServer({ key: fs.readFileSync(config.tls.key), cert: fs.readFileSync(config.tls.cert) }, handler)
    : http.createServer(handler);

  server.listen(config.port, config.host, () => {
    const scheme = config.tls ? 'https' : 'http';
    console.log(`[localia] v${VERSION} listening on ${scheme}://${config.host}:${config.port}${config.basePath} (storage: ${config.storage.driver})`);
  });

  const tick = () => queue.maintenance().catch((e) => console.error('[localia] maintenance failed:', e.message));
  tick();
  setInterval(tick, 60 * 1000).unref();
}

main().catch((e) => {
  console.error('[localia] failed to start:', e.message);
  process.exit(1);
});
