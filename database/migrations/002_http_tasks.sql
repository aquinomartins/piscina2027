CREATE TABLE IF NOT EXISTS task_requests (nonce_hash CHAR(64) PRIMARY KEY,created_at DATETIME(6) NOT NULL) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('002_http_tasks',UTC_TIMESTAMP(6));
