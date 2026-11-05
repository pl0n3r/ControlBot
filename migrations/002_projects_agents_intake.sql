-- #1453: additive, expand-compatible tables. No DROP/ALTER; deploy is prohibited.
-- Composite scope keys prevent cross-tenant reads and writes.
CREATE TABLE IF NOT EXISTS controlbot_projects (
  scope_id VARCHAR(64) NOT NULL,
  record_id VARCHAR(96) NOT NULL,
  payload_json TEXT NOT NULL,
  recorded_at BIGINT NOT NULL,
  PRIMARY KEY (scope_id, record_id)
);
CREATE TABLE IF NOT EXISTS controlbot_agents (
  scope_id VARCHAR(64) NOT NULL,
  record_id VARCHAR(96) NOT NULL,
  payload_json TEXT NOT NULL,
  recorded_at BIGINT NOT NULL,
  PRIMARY KEY (scope_id, record_id)
);
CREATE TABLE IF NOT EXISTS controlbot_agent_sessions (
  scope_id VARCHAR(64) NOT NULL,
  record_id VARCHAR(96) NOT NULL,
  payload_json TEXT NOT NULL,
  recorded_at BIGINT NOT NULL,
  PRIMARY KEY (scope_id, record_id)
);
CREATE TABLE IF NOT EXISTS controlbot_intake (
  scope_id VARCHAR(64) NOT NULL,
  record_id VARCHAR(96) NOT NULL,
  payload_json TEXT NOT NULL,
  recorded_at BIGINT NOT NULL,
  PRIMARY KEY (scope_id, record_id)
);
CREATE TABLE IF NOT EXISTS controlbot_pause_state (
  scope_id VARCHAR(64) NOT NULL,
  record_id VARCHAR(96) NOT NULL,
  payload_json TEXT NOT NULL,
  recorded_at BIGINT NOT NULL,
  PRIMARY KEY (scope_id, record_id)
);
