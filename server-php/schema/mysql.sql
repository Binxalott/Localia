-- Localia schema for MySQL / MariaDB.
-- Tables are also created automatically on first request; this file is for manual installs.
-- Shared by the PHP server and the Node server (storage.driver = 'mysql').
CREATE TABLE IF NOT EXISTS localia_jobs (
  id            VARCHAR(40)   NOT NULL PRIMARY KEY,   -- time-sortable: job_<base36 ms><random>
  status        VARCHAR(16)   NOT NULL,               -- queued | leased | done | error | cancelled
  priority      INT           NOT NULL DEFAULT 0,     -- higher runs first
  prompt        MEDIUMTEXT    NULL,
  system_prompt MEDIUMTEXT    NULL,
  messages      MEDIUMTEXT    NULL,                   -- JSON array of {role, content}
  model         VARCHAR(191)  NULL,
  options       TEXT          NULL,                   -- JSON: temperature, max_tokens, format, ...
  meta          MEDIUMTEXT    NULL,                   -- JSON: your own data, never sent to the agent
  callback_url  VARCHAR(1024) NULL,
  attempts      INT           NOT NULL DEFAULT 0,
  max_attempts  INT           NOT NULL DEFAULT 3,
  not_before    BIGINT        NOT NULL DEFAULT 0,     -- unix time; job is hidden until then
  agent_id      VARCHAR(64)   NULL,
  lease_token   VARCHAR(64)   NULL,
  lease_until   BIGINT        NULL,
  response      MEDIUMTEXT    NULL,
  error         TEXT          NULL,
  result_meta   TEXT          NULL,                   -- JSON: model, duration_ms, usage, agent
  created_at    BIGINT        NOT NULL,
  leased_at     BIGINT        NULL,
  completed_at  BIGINT        NULL,
  KEY idx_status_priority (status, priority, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS localia_agents (
  agent_id  VARCHAR(64) NOT NULL PRIMARY KEY,
  last_seen BIGINT      NOT NULL,
  info      TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS localia_nonces (
  agent_id VARCHAR(64) NOT NULL,
  nonce    VARCHAR(64) NOT NULL,
  ts       BIGINT      NOT NULL,
  PRIMARY KEY (agent_id, nonce),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Example user:
-- CREATE USER 'localia'@'localhost' IDENTIFIED BY 'CHANGE_ME_DB_PASSWORD';
-- GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON localia.* TO 'localia'@'localhost';
