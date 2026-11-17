-- Prêmios diretos reutilizam galeria_events e sua conta financiada no livro.
-- Os eventos anteriores mantêm modo VOTING, valores e regulamentos intactos.
SET @prize_mode_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='galeria_events' AND column_name='mode')=0,'ALTER TABLE galeria_events ADD COLUMN mode VARCHAR(12) NOT NULL DEFAULT ''VOTING''','DO 0');
PREPARE prize_mode_stmt FROM @prize_mode_sql;
EXECUTE prize_mode_stmt;
DEALLOCATE PREPARE prize_mode_stmt;
ALTER TABLE galeria_events MODIFY capacity INT NULL DEFAULT 4, MODIFY voting_starts DATETIME(6) NULL, MODIFY voting_ends DATETIME(6) NULL, MODIFY review_hours INT NULL DEFAULT 24, MODIFY resolution_hours INT NULL DEFAULT 24;

CREATE TABLE IF NOT EXISTS galeria_prize_consents (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, protocol VARCHAR(12) NOT NULL,
 association_id BIGINT NOT NULL, association_key VARCHAR(64) NOT NULL,
 link_id BIGINT NULL, agreement_id BIGINT NULL, contract_version INT NOT NULL,
 contract_hash CHAR(64) NOT NULL, frame_id BIGINT NOT NULL, nft_id BIGINT NOT NULL,
 frame_user BIGINT NOT NULL, nft_user BIGINT NOT NULL,
 frame_bps INT NOT NULL, nft_bps INT NOT NULL, terms LONGTEXT NOT NULL, terms_hash CHAR(64) NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'PENDING', created_by BIGINT NOT NULL,
 operation_id CHAR(32) NOT NULL UNIQUE, created_at DATETIME(6) NOT NULL, resolved_at DATETIME(6) NULL,
 active_key VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN state IN ('PENDING','ACTIVE') THEN association_key ELSE NULL END) STORED UNIQUE,
 FOREIGN KEY(link_id) REFERENCES galeria_links(id), FOREIGN KEY(agreement_id) REFERENCES galeria_agreements(id),
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(nft_id) REFERENCES nfts(id),
 FOREIGN KEY(frame_user) REFERENCES users(id), FOREIGN KEY(nft_user) REFERENCES users(id),
 FOREIGN KEY(created_by) REFERENCES users(id), FOREIGN KEY(operation_id) REFERENCES operations(id),
 CHECK(frame_bps>=0 AND nft_bps>=0 AND frame_bps+nft_bps=10000),
 CHECK((protocol='simple' AND link_id IS NOT NULL AND link_id=association_id AND agreement_id IS NULL) OR (protocol='physical' AND agreement_id IS NOT NULL AND agreement_id=association_id AND link_id IS NULL)),
 INDEX(frame_user,state,id), INDEX(nft_user,state,id), INDEX(association_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_prize_acceptances (
 consent_id BIGINT NOT NULL, user_id BIGINT NOT NULL, terms_hash CHAR(64) NOT NULL,
 operation_id CHAR(32) NOT NULL UNIQUE, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(consent_id,user_id), FOREIGN KEY(consent_id) REFERENCES galeria_prize_consents(id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(operation_id) REFERENCES operations(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_prize_awards (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, event_id BIGINT NOT NULL UNIQUE,
 consent_id BIGINT NOT NULL, actor_id BIGINT NOT NULL,
 operation_id CHAR(32) NOT NULL UNIQUE, asset VARCHAR(8) NOT NULL, amount BIGINT NOT NULL,
 frame_user BIGINT NOT NULL, nft_user BIGINT NOT NULL, frame_bps INT NOT NULL, nft_bps INT NOT NULL,
 frame_amount BIGINT NOT NULL, nft_amount BIGINT NOT NULL, snapshot LONGTEXT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(event_id) REFERENCES galeria_events(id), FOREIGN KEY(consent_id) REFERENCES galeria_prize_consents(id),
 FOREIGN KEY(actor_id) REFERENCES users(id), FOREIGN KEY(operation_id) REFERENCES operations(id),
 FOREIGN KEY(frame_user) REFERENCES users(id), FOREIGN KEY(nft_user) REFERENCES users(id),
 CHECK(asset IN ('MR','BYC') AND amount>0 AND frame_amount>=0 AND nft_amount>=0 AND frame_amount+nft_amount=amount),
 CHECK(frame_bps>=0 AND nft_bps>=0 AND frame_bps+nft_bps=10000),
 INDEX(frame_user,id), INDEX(nft_user,id)
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('006_premios_associacoes',UTC_TIMESTAMP(6));
