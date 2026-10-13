'use strict';
/**
 * Agent authentication (see docs/PROTOCOL.md).
 *
 * Request signature:  hex(HMAC_SHA256(secret, "v1\n" + timestamp + "\n" + nonce + "\n" + rawBody))
 * Response signature: hex(HMAC_SHA256(secret, "v1-resp\n" + requestNonce + "\n" + rawResponseBody))
 */
const { HttpError, now, hmacHex, safeEqual, isPlaceholder } = require('./util');

function header(headers, name) {
  const v = headers[name.toLowerCase()];
  return Array.isArray(v) ? v[0] : v;
}

async function verifyAgent(headers, rawBody, config, store) {
  const agentId = header(headers, 'x-localia-agent');
  const ts = header(headers, 'x-localia-timestamp');
  const nonce = header(headers, 'x-localia-nonce');
  const signature = header(headers, 'x-localia-signature');

  if (!agentId || !ts || !nonce || !signature) throw new HttpError(401, 'missing_auth_headers');
  const secret = Object.prototype.hasOwnProperty.call(config.agents, agentId) ? config.agents[agentId] : null;
  if (!secret) throw new HttpError(401, 'unknown_agent');
  if (isPlaceholder(secret) || String(secret).length < 16) {
    throw new HttpError(403, 'agent_secret_not_configured', 'Set a real secret (16+ chars) for this agent in the server config');
  }
  if (!/^\d{9,12}$/.test(ts)) throw new HttpError(401, 'bad_timestamp');
  if (!/^[A-Za-z0-9_-]{16,64}$/.test(nonce)) throw new HttpError(401, 'bad_nonce');

  const t = now();
  if (Math.abs(t - Number(ts)) > config.timestampTolerance) {
    throw new HttpError(401, 'clock_skew', 'Agent clock differs from server clock by more than ' + config.timestampTolerance + 's');
  }

  const expected = hmacHex(secret, 'v1\n' + ts + '\n' + nonce + '\n' + rawBody);
  if (!safeEqual(expected, String(signature).toLowerCase())) throw new HttpError(401, 'bad_signature');

  // Only record the nonce after the signature checks out, so garbage can't fill the table.
  const fresh = await store.useNonce(agentId, nonce, Number(ts), t, config.timestampTolerance * 2 + 60);
  if (!fresh) throw new HttpError(401, 'replayed_nonce');

  return { agentId, secret, nonce };
}

function signResponse(secret, nonce, rawResponse) {
  return hmacHex(secret, 'v1-resp\n' + nonce + '\n' + rawResponse);
}

function verifyApiKey(headers, config) {
  let key = header(headers, 'x-localia-key');
  const authz = header(headers, 'authorization');
  if (!key && authz && /^Bearer\s+/i.test(authz)) key = authz.replace(/^Bearer\s+/i, '').trim();
  if (!key) throw new HttpError(401, 'missing_api_key');
  const ok = (config.apiKeys || []).some((k) => !isPlaceholder(k) && String(k).length >= 16 && safeEqual(k, key));
  if (!ok) throw new HttpError(401, 'bad_api_key');
}

module.exports = { verifyAgent, signResponse, verifyApiKey };
