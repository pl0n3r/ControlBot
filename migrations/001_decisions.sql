CREATE TABLE IF NOT EXISTS controlbot_decisions (
    decision_id VARCHAR(128) NOT NULL PRIMARY KEY,
    payload_json TEXT NOT NULL,
    recorded_at BIGINT NOT NULL
);
