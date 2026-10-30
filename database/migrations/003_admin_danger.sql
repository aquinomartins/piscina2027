CREATE TABLE IF NOT EXISTS admin_restrictions (
 user_id BIGINT PRIMARY KEY, banned_until DATETIME(6) NULL, deleted_at DATETIME(6) NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin_nft_custody (
 nft_id BIGINT PRIMARY KEY, source_user_id BIGINT NOT NULL, operation_id CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(nft_id) REFERENCES nfts(id), FOREIGN KEY(source_user_id) REFERENCES users(id), FOREIGN KEY(operation_id) REFERENCES operations(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('003_admin_danger',UTC_TIMESTAMP(6));
