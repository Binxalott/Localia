'use strict';
/**
 * Zero-dependency JSON file store. Everything lives in memory and is written to
 * disk atomically after each change. Only safe for ONE server process — use the
 * HTTP API (or MySQL) from other scripts.
 */
const fs = require('fs');
const path = require('path');

const clone = (v) => (v == null ? v : JSON.parse(JSON.stringify(v)));

function claimable(job, t) {
  return (job.status === 'queued' && job.not_before <= t)
    || (job.status === 'leased' && job.lease_until < t && job.attempts < job.max_attempts);
}

const order = (a, b) => (b.priority - a.priority) || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);

class JsonStore {
  constructor(opts) {
    this.file = opts.file;
    this.data = { jobs: {}, agents: {}, nonces: {} };
  }

  async init() {
    fs.mkdirSync(path.dirname(this.file), { recursive: true });
    if (fs.existsSync(this.file)) {
      const parsed = JSON.parse(fs.readFileSync(this.file, 'utf8'));
      this.data = { jobs: {}, agents: {}, nonces: {}, ...parsed };
    }
  }

  save() {
    const tmp = this.file + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(this.data));
    fs.renameSync(tmp, this.file);
  }

  async insertJob(job) {
    this.data.jobs[job.id] = clone(job);
    this.save();
  }

  async getJob(id) {
    return clone(this.data.jobs[id] || null);
  }

  async claimJob(agentId, leaseToken, leaseUntil, t) {
    const job = Object.values(this.data.jobs).filter((j) => claimable(j, t)).sort(order)[0];
    if (!job) return null;
    Object.assign(job, { status: 'leased', agent_id: agentId, lease_token: leaseToken, lease_until: leaseUntil, leased_at: t, attempts: job.attempts + 1 });
    this.save();
    return clone(job);
  }

  async finishJob(id, leaseToken, fields) {
    const job = this.data.jobs[id];
    if (!job || job.lease_token !== leaseToken || job.status === 'done' || job.status === 'cancelled') return false;
    Object.assign(job, fields, { lease_until: null });
    this.save();
    return true;
  }

  async requeueJob(id, leaseToken, notBefore, error) {
    const job = this.data.jobs[id];
    if (!job || job.lease_token !== leaseToken || job.status !== 'leased') return false;
    Object.assign(job, { status: 'queued', not_before: notBefore, error, lease_until: null });
    this.save();
    return true;
  }

  async cancelJob(id, t) {
    const job = this.data.jobs[id];
    if (!job || (job.status !== 'queued' && job.status !== 'leased')) return false;
    Object.assign(job, { status: 'cancelled', completed_at: t, lease_until: null });
    this.save();
    return true;
  }

  async listJobs({ status, limit, since }) {
    return Object.values(this.data.jobs)
      .filter((j) => (!status || j.status === status) && (!since || (j.completed_at || j.created_at) >= since))
      .sort((a, b) => (a.id < b.id ? 1 : -1))
      .slice(0, limit)
      .map(clone);
  }

  async countJobs() {
    const counts = { queued: 0, leased: 0, done: 0, error: 0, cancelled: 0 };
    for (const j of Object.values(this.data.jobs)) counts[j.status] = (counts[j.status] || 0) + 1;
    return counts;
  }

  async countAvailable(t) {
    return Object.values(this.data.jobs).filter((j) => claimable(j, t)).length;
  }

  async expireLeases(t) {
    let n = 0;
    for (const j of Object.values(this.data.jobs)) {
      if (j.status === 'leased' && j.lease_until < t && j.attempts >= j.max_attempts) {
        Object.assign(j, { status: 'error', error: 'lease_expired', completed_at: t, lease_until: null });
        n++;
      }
    }
    if (n) this.save();
    return n;
  }

  async purge(before) {
    let n = 0;
    for (const [id, j] of Object.entries(this.data.jobs)) {
      if (['done', 'error', 'cancelled'].includes(j.status) && (j.completed_at || j.created_at) < before) {
        delete this.data.jobs[id];
        n++;
      }
    }
    if (n) this.save();
    return n;
  }

  async touchAgent(agentId, info, t) {
    this.data.agents[agentId] = { agent_id: agentId, last_seen: t, info };
    this.save();
  }

  async listAgents() {
    return Object.values(this.data.agents).map(clone);
  }

  async useNonce(agentId, nonce, ts, t, ttl) {
    const key = agentId + ':' + nonce;
    for (const [k, v] of Object.entries(this.data.nonces)) if (v < t - ttl) delete this.data.nonces[k];
    if (this.data.nonces[key]) return false;
    this.data.nonces[key] = ts;
    this.save();
    return true;
  }
}

module.exports = { JsonStore };
