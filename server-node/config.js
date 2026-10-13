'use strict';
/**
 * Localia server configuration (Node).
 *
 * Every value can be overridden with an environment variable, so you can keep
 * secrets out of this file. Generate secrets with:  node server.js --gen-secret
 */
const path = require('path');

const env = process.env;

module.exports = {
  // Where the HTTP server listens. Keep 127.0.0.1 when running behind nginx /
  // Apache / Caddy (recommended, they handle HTTPS). Use 0.0.0.0 to listen publicly.
  host: env.LOCALIA_HOST || '127.0.0.1',
  port: Number(env.LOCALIA_PORT || 8790),

  // URL path the API is served on. The desktop agent's "Path" setting must match.
  basePath: env.LOCALIA_BASE_PATH || '/localia',

  // Optional built-in HTTPS (only if you are NOT using a reverse proxy).
  // tls: { key: '/etc/letsencrypt/live/example.com/privkey.pem', cert: '/etc/letsencrypt/live/example.com/fullchain.pem' },
  tls: null,

  // Desktop agents allowed to pull work: { agentId: sharedSecret }.
  // The agent ID + secret must be entered identically in the desktop app's settings.
  agents: {
    'home-pc': env.LOCALIA_AGENT_SECRET || 'CHANGE_ME_AGENT_SECRET',
  },

  // API keys for *your* apps / cron jobs that enqueue jobs over HTTP
  // (sent as "Authorization: Bearer <key>" or "X-Localia-Key: <key>").
  apiKeys: [
    env.LOCALIA_API_KEY || 'CHANGE_ME_API_KEY',
  ],

  // If set, job completion callbacks (callback_url) are signed with this secret.
  callbackSecret: env.LOCALIA_CALLBACK_SECRET || '',

  // CORS: the desktop agent runs in a browser, so the server must allow its origin.
  // Requests are authenticated with HMAC signatures (not cookies), so '*' is fine.
  allowedOrigins: ['*'],

  leaseSeconds: 900,        // how long an agent may hold a job before it is retried
  maxAttempts: 3,           // default attempts per job before it is marked as error
  retryDelaySeconds: 30,    // wait before re-offering a job the agent reported as failed
  timestampTolerance: 300,  // max clock difference (seconds) between agent and server
  maxBodyBytes: 4 * 1024 * 1024,
  retentionDays: 30,        // finished jobs older than this are deleted (0 = keep forever)

  storage: {
    // 'json'  - zero-dependency file store. Fine for a single server process.
    // 'mysql' - requires `npm install mysql2`. Same schema as the PHP server, so
    //           both servers can share one database.
    driver: env.LOCALIA_DB_DRIVER || 'json',

    json: {
      file: env.LOCALIA_JSON_FILE || path.join(__dirname, 'data', 'localia.json'),
    },

    mysql: {
      host: env.LOCALIA_DB_HOST || '127.0.0.1',
      port: Number(env.LOCALIA_DB_PORT || 3306),
      user: env.LOCALIA_DB_USER || 'localia',
      password: env.LOCALIA_DB_PASS || 'CHANGE_ME_DB_PASSWORD',
      database: env.LOCALIA_DB_NAME || 'localia',
      tablePrefix: env.LOCALIA_DB_PREFIX || 'localia_',
      connectionLimit: 5,
    },
  },
};
