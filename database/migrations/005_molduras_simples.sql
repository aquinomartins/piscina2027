-- Oferta sem prazo é uma opção nova. As ofertas antigas conservam seu vencimento.
ALTER TABLE galeria_offers MODIFY expires_at DATETIME(6) NULL;

CREATE TABLE IF NOT EXISTS galeria_features (
 id INT PRIMARY KEY, physical_available TINYINT NOT NULL DEFAULT 0,
 events_available TINYINT NOT NULL DEFAULT 0, share_charges_available TINYINT NOT NULL DEFAULT 0,
 updated_at DATETIME(6) NULL, updated_by BIGINT NULL,
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO galeria_features(id) VALUES(1);

CREATE TABLE IF NOT EXISTS galeria_link_proposals (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 frame_id BIGINT NOT NULL, nft_id BIGINT NOT NULL,
 frame_user BIGINT NOT NULL, nft_user BIGINT NOT NULL,
 current_version INT NOT NULL DEFAULT 1, state VARCHAR(20) NOT NULL DEFAULT 'PENDING',
 created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(nft_id) REFERENCES nfts(id),
 FOREIGN KEY(frame_user) REFERENCES users(id), FOREIGN KEY(nft_user) REFERENCES users(id),
 INDEX(frame_user,state,id), INDEX(nft_user,state,id), INDEX(frame_id,state)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_link_versions (
 proposal_id BIGINT NOT NULL, version INT NOT NULL, author_id BIGINT NOT NULL,
 terms LONGTEXT NOT NULL, expires_at DATETIME(6) NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(proposal_id,version), FOREIGN KEY(proposal_id) REFERENCES galeria_link_proposals(id),
 FOREIGN KEY(author_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_link_acceptances (
 proposal_id BIGINT NOT NULL, version INT NOT NULL, user_id BIGINT NOT NULL,
 created_at DATETIME(6) NOT NULL, PRIMARY KEY(proposal_id,version,user_id),
 FOREIGN KEY(proposal_id,version) REFERENCES galeria_link_versions(proposal_id,version),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_links (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, proposal_id BIGINT NOT NULL UNIQUE, version INT NOT NULL,
 frame_id BIGINT NOT NULL, nft_id BIGINT NOT NULL, frame_user BIGINT NOT NULL, nft_user BIGINT NOT NULL,
 active_frame BIGINT NULL UNIQUE, active_nft BIGINT NULL UNIQUE,
 nft_bps INT NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'ACTIVE', terms LONGTEXT NOT NULL,
 end_requested_by BIGINT NULL,
 operation_id CHAR(32) NOT NULL UNIQUE, created_at DATETIME(6) NOT NULL,
 ended_at DATETIME(6) NULL, ended_by BIGINT NULL, end_reason VARCHAR(255) NULL, end_operation CHAR(32) NULL,
 FOREIGN KEY(proposal_id) REFERENCES galeria_link_proposals(id), FOREIGN KEY(frame_id) REFERENCES galeria_frames(id),
 FOREIGN KEY(nft_id) REFERENCES nfts(id), FOREIGN KEY(frame_user) REFERENCES users(id),
 FOREIGN KEY(nft_user) REFERENCES users(id), FOREIGN KEY(operation_id) REFERENCES operations(id),
 FOREIGN KEY(ended_by) REFERENCES users(id), FOREIGN KEY(end_operation) REFERENCES operations(id),
 INDEX(frame_user,state,id), INDEX(nft_user,state,id), INDEX(frame_id,id)
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('005_molduras_simples',UTC_TIMESTAMP(6));
