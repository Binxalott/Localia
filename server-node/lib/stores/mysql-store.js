'use strict';
/**
 * MySQL / MariaDB store. Requires:  npm install mysql2
 * Uses the same tables as the PHP server (server-php/schema/mysql.sql), so both
 * implementations can share one database. Tables are created automatically.
 */
const JSON_FIELDS = ['messages', 'options', 'meta', 'result_meta'];
const CLAIMABLE = "((status = 'queued' AND not_before <= ?) OR (status = 'leased' AND lease_until < ? AND attempts < max_attempts))";

function decode(row) {
  if (!row) return null;
  const job = { ...row };
  for (const f of JSON_FIELDS) job[f] = job[f] == null ? null : JSON.parse(job[f]);
  for (const f of ['priority', 'attempts', 'max_attempts', 'not_before', 'lease_until', 'created_at', 'leased_at', 'completed_at']) {
    if (job[f] != null) job[f] = Number(job[f]);
  }
  return job;
}

class MysqlStore {
  constructor(opts) {
    this.opts = opts;
    const p = opts.tablePrefix || 'localia_';
    this.t = { jobs: p + 'jobs', agents: p + 'agents', nonces: p + 'nonces' };
  }

  async init() {
    let mysql;
    try {
      mysql = require('mysql2/promise');
    } catch (e) {
      throw new Error('MySQL storage needs the mysql2 package: run `npm install mysql2` in server-node/');
    }
    this.pool = mysql.createPool({
      host: this.opts.host,
      port: this.opts.port,
      user: this.opts.user,
      password: this.opts.password,
      database: this.opts.database,
      connectionLimit: this.opts.connectionLimit || 5,
      charset: 'utf8mb4',
    });
    const { jobs, agents, nonces } = this.t;
    await this.q(`CREATE TABLE IF NOT EXISTS ${jobs} (
      id VARCHAR(40) NOT NULL PRIMARY KEY,
      status VARCHAR(16) NOT NULL,
      priority INT NOT NULL DEFAULT 0,
      prompt MEDIUMTEXT NULL,
      system_prompt MEDIUMTEXT NULL,
      messages MEDIUMTEXT NULL,
      model VARCHAR(191) NULL,
      options TEXT NULL,
      meta MEDIUMTEXT NULL,
      callback_url VARCHAR(1024) NULL,
      attempts INT NOT NULL DEFAULT 0,
      max_attempts INT NOT NULL DEFAULT 3,
      not_before BIGINT NOT NULL DEFAULT 0,
      agent_id VARCHAR(64) NULL,
      lease_token VARCHAR(64) NULL,
      lease_until BIGINT NULL,
      response MEDIUMTEXT NULL,
      error TEXT NULL,
      result_meta TEXT NULL,
      created_at BIGINT NOT NULL,
      leased_at BIGINT NULL,
      completed_at BIGINT NULL,
      KEY idx_status_priority (status, priority, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await this.q(`CREATE TABLE IF NOT EXISTS ${agents} (
      agent_id VARCHAR(64) NOT NULL PRIMARY KEY,
      last_seen BIGINT NOT NULL,
      info TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await this.q(`CREATE TABLE IF NOT EXISTS ${nonces} (
      agent_id VARCHAR(64) NOT NULL,
      nonce VARCHAR(64) NOT NULL,
      ts BIGINT NOT NULL,
      PRIMARY KEY (agent_id, nonce),
      KEY idx_ts (ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
  }

  async q(sql, params = []) {
    const [rows] = await this.pool.query(sql, params);
    return rows;
  }

  async insertJob(job) {
    const row = { ...job };
    for (const f of JSON_FIELDS) row[f] = row[f] == null ? null : JSON.stringify(row[f]);
    const cols = Object.keys(row);
    await this.q(`INSERT INTO ${this.t.jobs} (${cols.join(',')}) VALUES (${cols.map(() => '?').join(',')})`, cols.map((c) => row[c]));
  }

  async getJob(id) {
    return decode((await this.q(`SELECT * FROM ${this.t.jobs} WHERE id = ?`, [id]))[0]);
  }

  async claimJob(agentId, leaseToken, leaseUntil, t) {
    for (let i = 0; i < 5; i++) {
      const rows = await this.q(`SELECT id FROM ${this.t.jobs} WHERE ${CLAIMABLE} ORDER BY priority DESC, id ASC LIMIT 1`, [t, t]);
      if (!rows.length) return null;
      const res = await this.q(
        `UPDATE ${this.t.jobs} SET status = 'leased', agent_id = ?, lease_token = ?, lease_until = ?, leased_at = ?, attempts = attempts + 1
         WHERE id = ? AND ${CLAIMABLE}`,
        [agentId, leaseToken, leaseUntil, t, rows[0].id, t, t],
      );
      if (res.affectedRows === 1) return this.getJob(rows[0].id);
    }
    return null;
  }

  async finishJob(id, leaseToken, f) {
    const res = await this.q(
      `UPDATE ${this.t.jobs} SET status = ?, error = ?, response = ?, result_meta = ?, completed_at = ?, lease_until = NULL
       WHERE id = ? AND lease_token = ? AND status NOT IN ('done','cancelled')`,
      [f.status, f.error, f.response, JSON.stringify(f.result_meta), f.completed_at, id, leaseToken],
    );
    return res.affectedRows === 1;
  }

  async requeueJob(id, leaseToken, notBefore, error) {
    const res = await this.q(
      `UPDATE ${this.t.jobs} SET status = 'queued', not_before = ?, error = ?, lease_until = NULL WHERE id = ? AND lease_token = ? AND status = 'leased'`,
      [notBefore, error, id, leaseToken],
    );
    return res.affectedRows === 1;
  }

  async cancelJob(id, t) {
    const res = await this.q(
      `UPDATE ${this.t.jobs} SET status = 'cancelled', completed_at = ?, lease_until = NULL WHERE id = ? AND status IN ('queued','leased')`,
      [t, id],
    );
    return res.affectedRows === 1;
  }

  async listJobs({ status, limit, since }) {
    const where = [];
    const params = [];
    if (status) { where.push('status = ?'); params.push(status); }
    if (since) { where.push('COALESCE(completed_at, created_at) >= ?'); params.push(since); }
    params.push(limit);
    const rows = await this.q(`SELECT * FROM ${this.t.jobs} ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY id DESC LIMIT ?`, params);
    return rows.map(decode);
  }

  async countJobs() {
    const counts = { queued: 0, leased: 0, done: 0, error: 0, cancelled: 0 };
    for (const r of await this.q(`SELECT status, COUNT(*) AS n FROM ${this.t.jobs} GROUP BY status`)) counts[r.status] = Number(r.n);
    return counts;
  }

  async countAvailable(t) {
    return Number((await this.q(`SELECT COUNT(*) AS n FROM ${this.t.jobs} WHERE ${CLAIMABLE}`, [t, t]))[0].n);
  }

  async expireLeases(t) {
    const res = await this.q(
      `UPDATE ${this.t.jobs} SET status = 'error', error = 'lease_expired', completed_at = ?, lease_until = NULL
       WHERE status = 'leased' AND lease_until < ? AND attempts >= max_attempts`,
      [t, t],
    );
    return res.affectedRows;
  }

  async purge(before) {
    const res = await this.q(
      `DELETE FROM ${this.t.jobs} WHERE status IN ('done','error','cancelled') AND COALESCE(completed_at, created_at) < ?`,
      [before],
    );
    return res.affectedRows;
  }

  async touchAgent(agentId, info, t) {
    await this.q(
      `INSERT INTO ${this.t.agents} (agent_id, last_seen, info) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), info = VALUES(info)`,
      [agentId, t, JSON.stringify(info)],
    );
  }

  async listAgents() {
    return (await this.q(`SELECT * FROM ${this.t.agents}`)).map((r) => ({
      agent_id: r.agent_id, last_seen: Number(r.last_seen), info: r.info ? JSON.parse(r.info) : {},
    }));
  }

  async useNonce(agentId, nonce, ts, t, ttl) {
    await this.q(`DELETE FROM ${this.t.nonces} WHERE ts < ?`, [t - ttl]);
    const res = await this.q(`INSERT IGNORE INTO ${this.t.nonces} (agent_id, nonce, ts) VALUES (?, ?, ?)`, [agentId, nonce, ts]);
    return res.affectedRows === 1;
  }
}

module.exports = { MysqlStore };
