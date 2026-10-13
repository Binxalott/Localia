'use strict';
/**
 * Library entry point, for embedding Localia in your own Node app.
 *
 *   const localia = require('./server-node');
 *   const queue = await localia.createQueue();            // uses config.js
 *   app.all('/localia', localia.createHandler({ queue, config: localia.config }));
 *   const id = await queue.enqueue({ prompt: 'Hello' });
 */
const config = require('./config');
const { createStore } = require('./lib/stores');
const { Queue } = require('./lib/queue');
const { createHandler } = require('./lib/http-handler');
const { LocaliaClient } = require('./lib/client');

async function createQueue(cfg = config) {
  return new Queue(await createStore(cfg.storage), cfg);
}

module.exports = { config, createQueue, createHandler, LocaliaClient, Queue };
