#!/usr/bin/env node
'use strict';
/**
 * Example cron job: queue a prompt for your home PC and wait for the answer.
 *
 *   LOCALIA_URL=https://example.com/localia LOCALIA_API_KEY=... node examples/cron-enqueue.js
 *
 * crontab:  0 6 * * *  cd /var/www/localia/server-node && node examples/cron-enqueue.js >> /var/log/localia-cron.log 2>&1
 */
const { LocaliaClient } = require('../lib/client');

async function main() {
  const localia = new LocaliaClient({
    url: process.env.LOCALIA_URL || 'http://127.0.0.1:8790/localia',
    apiKey: process.env.LOCALIA_API_KEY || 'CHANGE_ME_API_KEY',
  });

  const id = await localia.enqueue({
    system: 'You write short, upbeat blog intros.',
    prompt: 'Write a 3 sentence intro for a post titled "Why I self-host my AI".',
    options: { temperature: 0.7, max_tokens: 300 },
    meta: { post_id: 123 },                // anything you want back later
    // callback_url: 'https://example.com/localia-callback',  // optional webhook instead of waiting
  });
  console.log('queued', id);

  // Option A: wait right here (fine for cron). Option B: store the id and check later with localia.get(id).
  const job = await localia.waitFor(id, { timeoutSeconds: 1800, intervalSeconds: 10 });
  if (job.status === 'done') {
    console.log('response:\n' + job.response);
    // ...save job.response to your database, publish it, etc.
  } else {
    console.error('job not finished:', job.status, job.error || '');
    process.exitCode = 1;
  }
}

main().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
