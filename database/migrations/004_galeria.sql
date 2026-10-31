CREATE TABLE IF NOT EXISTS galeria_settings (
 id INT PRIMARY KEY, accepting TINYINT NOT NULL DEFAULT 0, has_commitments TINYINT NOT NULL DEFAULT 0,
 updated_at DATETIME(6) NULL, updated_by BIGINT NULL, reason VARCHAR(255) NULL, agreement_cursor BIGINT NOT NULL DEFAULT 0,
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO galeria_settings(id) VALUES(1);
CREATE TABLE IF NOT EXISTS galeria_spaces (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, address VARCHAR(255) NOT NULL,
 responsible VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
 created_at DATETIME(6) NOT NULL, INDEX(status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_points (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, space_id BIGINT NOT NULL, name VARCHAR(120) NOT NULL,
 authorized_from DATETIME(6) NOT NULL, authorized_until DATETIME(6) NOT NULL,
 opens VARCHAR(5) NOT NULL, closes VARCHAR(5) NOT NULL, weekdays VARCHAR(20) NOT NULL,
 conditions TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
 FOREIGN KEY(space_id) REFERENCES galeria_spaces(id), INDEX(space_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_frames (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, authority_id CHAR(32) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL, holder_id BIGINT NULL, status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
 type_key VARCHAR(40) NOT NULL, metadata LONGTEXT NOT NULL, version INT NOT NULL DEFAULT 1,
 verified_at DATETIME(6) NULL, verified_by BIGINT NULL, created_by BIGINT NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(holder_id) REFERENCES users(id), FOREIGN KEY(verified_by) REFERENCES users(id),
 FOREIGN KEY(created_by) REFERENCES users(id), INDEX(holder_id,id), INDEX(status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_frame_versions (
 frame_id BIGINT NOT NULL, version INT NOT NULL, metadata LONGTEXT NOT NULL,
 author_id BIGINT NOT NULL, reason VARCHAR(255) NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(frame_id,version), FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_allocations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NOT NULL, point_id BIGINT NOT NULL,
 starts_at DATETIME(6) NOT NULL, ends_at DATETIME(6) NOT NULL, operation_id CHAR(32) NOT NULL,
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(point_id) REFERENCES galeria_points(id),
 FOREIGN KEY(operation_id) REFERENCES operations(id), INDEX(frame_id,starts_at,ends_at), INDEX(point_id,starts_at,ends_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_ownership (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NOT NULL, user_id BIGINT NOT NULL,
 starts_at DATETIME(6) NOT NULL, ends_at DATETIME(6) NULL, operation_id CHAR(32) NOT NULL,
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(operation_id) REFERENCES operations(id), INDEX(frame_id,starts_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_blocks (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NULL, point_id BIGINT NULL,
 starts_at DATETIME(6) NOT NULL, ends_at DATETIME(6) NOT NULL, reason VARCHAR(255) NOT NULL,
 operation_id CHAR(32) NOT NULL, FOREIGN KEY(frame_id) REFERENCES galeria_frames(id),
 FOREIGN KEY(point_id) REFERENCES galeria_points(id), FOREIGN KEY(operation_id) REFERENCES operations(id),
 INDEX(frame_id,starts_at,ends_at), INDEX(point_id,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_photos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NOT NULL, path VARCHAR(100) NOT NULL,
 sha CHAR(64) NOT NULL, operation_id CHAR(32) NOT NULL UNIQUE, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(operation_id) REFERENCES operations(id), INDEX(frame_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_offers (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NOT NULL, seller_id BIGINT NULL, buyer_id BIGINT NULL,
 asset VARCHAR(8) NOT NULL, amount BIGINT NOT NULL, version INT NOT NULL DEFAULT 1,
 terms TEXT NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'ACTIVE', expires_at DATETIME(6) NOT NULL,
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(frame_id) REFERENCES galeria_frames(id),
 FOREIGN KEY(seller_id) REFERENCES users(id), FOREIGN KEY(buyer_id) REFERENCES users(id), INDEX(frame_id,state), INDEX(state,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_sales (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NOT NULL, offer_id BIGINT NOT NULL UNIQUE,
 buyer_id BIGINT NOT NULL, seller_id BIGINT NULL, asset VARCHAR(8) NOT NULL, amount BIGINT NOT NULL,
 operation_id CHAR(32) NOT NULL UNIQUE, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(offer_id) REFERENCES galeria_offers(id),
 FOREIGN KEY(buyer_id) REFERENCES users(id), FOREIGN KEY(seller_id) REFERENCES users(id), FOREIGN KEY(operation_id) REFERENCES operations(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_policies (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, space_id BIGINT NOT NULL, type_key VARCHAR(40) NOT NULL,
 x BIGINT NOT NULL, recipient_id BIGINT NOT NULL, terms LONGTEXT NOT NULL,
 author_id BIGINT NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(space_id) REFERENCES galeria_spaces(id), FOREIGN KEY(recipient_id) REFERENCES users(id),
 FOREIGN KEY(author_id) REFERENCES users(id), INDEX(space_id,type_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_proposals (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NOT NULL, nft_id BIGINT NOT NULL,
 frame_user BIGINT NOT NULL, nft_user BIGINT NOT NULL, current_version INT NOT NULL DEFAULT 1,
 state VARCHAR(20) NOT NULL DEFAULT 'PENDING', created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(nft_id) REFERENCES nfts(id),
 FOREIGN KEY(frame_user) REFERENCES users(id), FOREIGN KEY(nft_user) REFERENCES users(id),
 INDEX(frame_user,state,id), INDEX(nft_user,state,id), INDEX(frame_id,state)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_proposal_versions (
 proposal_id BIGINT NOT NULL, version INT NOT NULL, author_id BIGINT NOT NULL, terms LONGTEXT NOT NULL,
 expires_at DATETIME(6) NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(proposal_id,version), FOREIGN KEY(proposal_id) REFERENCES galeria_proposals(id), FOREIGN KEY(author_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_acceptances (
 proposal_id BIGINT NOT NULL, version INT NOT NULL, user_id BIGINT NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(proposal_id,version,user_id), FOREIGN KEY(proposal_id,version) REFERENCES galeria_proposal_versions(proposal_id,version),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_events (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(120) NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
 capacity INT NOT NULL DEFAULT 4, voting_starts DATETIME(6) NOT NULL, voting_ends DATETIME(6) NOT NULL,
 review_hours INT NOT NULL DEFAULT 24, resolution_hours INT NOT NULL DEFAULT 24,
 prize_asset VARCHAR(8) NOT NULL, prize_amount BIGINT NOT NULL, funds_state VARCHAR(20) NOT NULL DEFAULT 'UNFUNDED',
 source_account BIGINT NULL, rules LONGTEXT NOT NULL, published_at DATETIME(6) NULL, closed_at DATETIME(6) NULL,
 review_until DATETIME(6) NULL, resolution_until DATETIME(6) NULL, previous_state VARCHAR(20) NULL,
 result_version INT NOT NULL DEFAULT 0, created_by BIGINT NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(source_account) REFERENCES accounts(id), FOREIGN KEY(created_by) REFERENCES users(id), INDEX(state,voting_ends), INDEX(closed_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS galeria_agreements (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, proposal_id BIGINT NOT NULL UNIQUE, version INT NOT NULL,
 frame_id BIGINT NOT NULL, nft_id BIGINT NOT NULL, point_id BIGINT NOT NULL, space_id BIGINT NOT NULL,
 frame_user BIGINT NOT NULL, nft_user BIGINT NOT NULL, recipient_id BIGINT NOT NULL, event_id BIGINT NULL,
 starts_at DATETIME(6) NOT NULL, ends_at DATETIME(6) NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'RESERVED',
 funds_state VARCHAR(20) NOT NULL DEFAULT 'HELD', x BIGINT NOT NULL, nft_bps INT NOT NULL,
 policy_id BIGINT NOT NULL, terms LONGTEXT NOT NULL, physical_at DATETIME(6) NULL, completed_at DATETIME(6) NULL,
 cancel_requested_by BIGINT NULL, resolution_until DATETIME(6) NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(proposal_id) REFERENCES galeria_proposals(id), FOREIGN KEY(frame_id) REFERENCES galeria_frames(id),
 FOREIGN KEY(nft_id) REFERENCES nfts(id), FOREIGN KEY(point_id) REFERENCES galeria_points(id), FOREIGN KEY(space_id) REFERENCES galeria_spaces(id),
 FOREIGN KEY(frame_user) REFERENCES users(id), FOREIGN KEY(nft_user) REFERENCES users(id), FOREIGN KEY(recipient_id) REFERENCES users(id),
 FOREIGN KEY(policy_id) REFERENCES galeria_policies(id), FOREIGN KEY(event_id) REFERENCES galeria_events(id),
 INDEX(frame_id,state,starts_at,ends_at), INDEX(point_id,state,starts_at,ends_at), INDEX(nft_id,state,starts_at,ends_at), INDEX(event_id,state)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_candidates (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, event_id BIGINT NOT NULL, agreement_id BIGINT NOT NULL UNIQUE,
 frame_id BIGINT NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'VALID', snapshot LONGTEXT NULL,
 FOREIGN KEY(event_id) REFERENCES galeria_events(id), FOREIGN KEY(agreement_id) REFERENCES galeria_agreements(id),
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), UNIQUE(event_id,frame_id), INDEX(event_id,state,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_electors (
 event_id BIGINT NOT NULL, user_id BIGINT NOT NULL, credential_hash CHAR(64) NOT NULL, active TINYINT NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL, PRIMARY KEY(event_id,user_id), UNIQUE(event_id,credential_hash),
 FOREIGN KEY(event_id) REFERENCES galeria_events(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_votes (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, event_id BIGINT NOT NULL, user_id BIGINT NOT NULL, candidate_id BIGINT NOT NULL,
 created_at DATETIME(6) NOT NULL, invalidated_at DATETIME(6) NULL, invalidated_by BIGINT NULL, reason VARCHAR(255) NULL,
 FOREIGN KEY(event_id,user_id) REFERENCES galeria_electors(event_id,user_id), FOREIGN KEY(candidate_id) REFERENCES galeria_candidates(id),
 FOREIGN KEY(invalidated_by) REFERENCES users(id), UNIQUE(event_id,user_id), INDEX(event_id,invalidated_at,candidate_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_results (
 event_id BIGINT NOT NULL, version INT NOT NULL, data LONGTEXT NOT NULL, author_id BIGINT NOT NULL,
 reason VARCHAR(255) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY(event_id,version),
 FOREIGN KEY(event_id) REFERENCES galeria_events(id), FOREIGN KEY(author_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_incidents (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, agreement_id BIGINT NULL, event_id BIGINT NULL, reason VARCHAR(255) NOT NULL,
 resolution VARCHAR(255) NULL, operation_id CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL, resolved_at DATETIME(6) NULL,
 FOREIGN KEY(agreement_id) REFERENCES galeria_agreements(id), FOREIGN KEY(event_id) REFERENCES galeria_events(id),
 FOREIGN KEY(operation_id) REFERENCES operations(id), INDEX(event_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_finance (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, operation_id CHAR(32) NOT NULL, transaction_id BIGINT NOT NULL UNIQUE,
 entity_type VARCHAR(20) NOT NULL, entity_id BIGINT NOT NULL, asset VARCHAR(8) NOT NULL, amount BIGINT NOT NULL,
 from_account BIGINT NOT NULL, to_account BIGINT NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(operation_id) REFERENCES operations(id), FOREIGN KEY(transaction_id) REFERENCES ledger_transactions(id),
 FOREIGN KEY(from_account) REFERENCES accounts(id), FOREIGN KEY(to_account) REFERENCES accounts(id), INDEX(entity_type,entity_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_frame_custody (
 frame_id BIGINT PRIMARY KEY, source_user_id BIGINT NOT NULL, operation_id CHAR(32) NOT NULL,
 created_at DATETIME(6) NOT NULL, FOREIGN KEY(frame_id) REFERENCES galeria_frames(id),
 FOREIGN KEY(source_user_id) REFERENCES users(id), FOREIGN KEY(operation_id) REFERENCES operations(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_trade_proposals (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NOT NULL, seller_id BIGINT NOT NULL, buyer_id BIGINT NOT NULL,
 current_version INT NOT NULL DEFAULT 1, state VARCHAR(20) NOT NULL DEFAULT 'PENDING', created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(frame_id) REFERENCES galeria_frames(id), FOREIGN KEY(seller_id) REFERENCES users(id), FOREIGN KEY(buyer_id) REFERENCES users(id),
 INDEX(buyer_id,state,id), INDEX(seller_id,state,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_trade_versions (
 proposal_id BIGINT NOT NULL, version INT NOT NULL, author_id BIGINT NOT NULL, terms LONGTEXT NOT NULL,
 created_at DATETIME(6) NOT NULL, PRIMARY KEY(proposal_id,version),
 FOREIGN KEY(proposal_id) REFERENCES galeria_trade_proposals(id), FOREIGN KEY(author_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_trade_acceptances (
 proposal_id BIGINT NOT NULL, version INT NOT NULL, user_id BIGINT NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(proposal_id,version,user_id), FOREIGN KEY(proposal_id,version) REFERENCES galeria_trade_versions(proposal_id,version),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS galeria_availability (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, frame_id BIGINT NOT NULL, point_id BIGINT NOT NULL, space_id BIGINT NOT NULL,
 starts_at DATETIME(6) NOT NULL, ends_at DATETIME(6) NOT NULL, cancelled_at DATETIME(6) NULL,
 opens VARCHAR(5) NOT NULL, closes VARCHAR(5) NOT NULL, weekdays VARCHAR(20) NOT NULL,
 operation_id CHAR(32) NOT NULL, FOREIGN KEY(frame_id) REFERENCES galeria_frames(id),
 FOREIGN KEY(point_id) REFERENCES galeria_points(id), FOREIGN KEY(space_id) REFERENCES galeria_spaces(id),
 FOREIGN KEY(operation_id) REFERENCES operations(id), INDEX(space_id,starts_at,ends_at), INDEX(frame_id,starts_at)
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('004_galeria',UTC_TIMESTAMP(6));
