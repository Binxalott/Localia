'use strict';
const crypto = require('crypto');

class HttpError extends Error {
  constructor(status, code, message) {
    super(message || code);
    this.status = status;
    this.code = code;
  }
}

const now = () => Math.floor(Date.now() / 1000);

// Time-sortable job id: "job_" + 9 base36 chars of ms timestamp + 12 random hex chars.
function newJobId() {
  return 'job_' + Date.now().toString(36).padStart(9, '0') + crypto.randomBytes(6).toString('hex');
}

const randomHex = (bytes) => crypto.randomBytes(bytes).toString('hex');

function hmacHex(secret, message) {
  return crypto.createHmac('sha256', secret).update(message, 'utf8').digest('hex');
}

function safeEqual(a, b) {
  const ba = Buffer.from(String(a));
  const bb = Buffer.from(String(b));
  return ba.length === bb.length && crypto.timingSafeEqual(ba, bb);
}

const isPlaceholder = (v) => !v || String(v).startsWith('CHANGE_ME');

module.exports = { HttpError, now, newJobId, randomHex, hmacHex, safeEqual, isPlaceholder };
