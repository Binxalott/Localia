#!/usr/bin/env node
'use strict';
/**
 * Example webhook receiver for jobs created with a callback_url.
 * Verifies the X-Localia-Signature header using config.callbackSecret.
 */
const crypto = require('crypto');
const http = require('http');

const SECRET = process.env.LOCALIA_CALLBACK_SECRET || 'CHANGE_ME_CALLBACK_SECRET';

http.createServer((req, res) => {
  let raw = '';
  req.on('data', (c) => { raw += c; });
  req.on('end', () => {
    const ts = req.headers['x-localia-timestamp'] || '';
    const sig = req.headers['x-localia-signature'] || '';
    const expected = crypto.createHmac('sha256', SECRET).update('v1-cb\n' + ts + '\n' + raw).digest('hex');
    const fresh = Math.abs(Date.now() / 1000 - Number(ts)) < 300;
    if (!fresh || sig.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) {
      res.writeHead(401).end();
      return;
    }
    const { job } = JSON.parse(raw);
    console.log(`job ${job.id} finished with status ${job.status}`, job.meta);
    console.log(job.response);
    res.writeHead(204).end();
  });
}).listen(8791, '127.0.0.1', () => console.log('callback receiver on http://127.0.0.1:8791'));
