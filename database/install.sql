CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS economic_gate (id INT PRIMARY KEY, sequence_no BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB;
INSERT IGNORE INTO economic_gate(id) VALUES(1);
CREATE TABLE IF NOT EXISTS users (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 display_name VARCHAR(80) NOT NULL, auth_version INT NOT NULL DEFAULT 1, role VARCHAR(20) NOT NULL DEFAULT 'participant', status VARCHAR(20) NOT NULL DEFAULT 'pending',
 email_verified_at DATETIME(6) NULL, approved_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL,
 INDEX(status,id), INDEX(display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS auth_tokens (id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,kind VARCHAR(20) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at DATETIME(6) NOT NULL,used_at DATETIME(6),FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rate_limits (bucket CHAR(64) PRIMARY KEY,attempts INT NOT NULL,expires_at DATETIME(6) NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS parameter_versions (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,author_id BIGINT NULL,initial_credit BIGINT NOT NULL,withdraw_money BIGINT NOT NULL,distribution_base BIGINT NOT NULL,admin_fee BIGINT NOT NULL,
 interval_minutes INT NULL,first_cutoff DATETIME(6) NULL,effective_at DATETIME(6) NOT NULL,reason VARCHAR(255) NOT NULL,
 FOREIGN KEY(author_id) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO parameter_versions(id,initial_credit,withdraw_money,distribution_base,admin_fee,effective_at,reason) VALUES(1,160000,140000,20000,10000,UTC_TIMESTAMP(6),'Parâmetros iniciais da especificação');
CREATE TABLE IF NOT EXISTS decision_versions (id BIGINT AUTO_INCREMENT PRIMARY KEY,author_id BIGINT NOT NULL,policy LONGTEXT NOT NULL,created_at DATETIME(6) NOT NULL,FOREIGN KEY(author_id) REFERENCES users(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS accounts (id BIGINT AUTO_INCREMENT PRIMARY KEY,code VARCHAR(100) NOT NULL UNIQUE,user_id BIGINT NULL,asset VARCHAR(8) NOT NULL,purpose VARCHAR(30) NOT NULL,balance BIGINT NOT NULL DEFAULT 0,FOREIGN KEY(user_id) REFERENCES users(id),INDEX(user_id,asset,purpose)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS operations (id CHAR(32) PRIMARY KEY,actor_id BIGINT NOT NULL,action VARCHAR(60) NOT NULL,idempotency_key VARCHAR(80) NOT NULL,payload_hash CHAR(64) NOT NULL,result LONGTEXT NULL,effective_at DATETIME(6) NOT NULL,sequence_no BIGINT NOT NULL,UNIQUE(actor_id,action,idempotency_key),FOREIGN KEY(actor_id) REFERENCES users(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ledger_transactions (id BIGINT AUTO_INCREMENT PRIMARY KEY,operation_id CHAR(32) NULL,kind VARCHAR(40) NOT NULL,reference VARCHAR(100) NOT NULL UNIQUE,created_at DATETIME(6) NOT NULL,FOREIGN KEY(operation_id) REFERENCES operations(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ledger_entries (id BIGINT AUTO_INCREMENT PRIMARY KEY,transaction_id BIGINT NOT NULL,account_id BIGINT NOT NULL,amount BIGINT NOT NULL,FOREIGN KEY(transaction_id) REFERENCES ledger_transactions(id),FOREIGN KEY(account_id) REFERENCES accounts(id),INDEX(account_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS initial_grants (user_id BIGINT PRIMARY KEY,parameter_id BIGINT NOT NULL,operation_id CHAR(32) NOT NULL UNIQUE,FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(parameter_id) REFERENCES parameter_versions(id),FOREIGN KEY(operation_id) REFERENCES operations(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS nfts (id BIGINT AUTO_INCREMENT PRIMARY KEY,creator_id BIGINT NOT NULL,owner_id BIGINT NULL,title VARCHAR(120) NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'PRIVATE',image_path VARCHAR(100) NULL,thumbnail_path VARCHAR(100) NULL,image_sha CHAR(64) NULL,reserved_proposal BIGINT NULL,created_at DATETIME(6) NOT NULL,FOREIGN KEY(creator_id) REFERENCES users(id),FOREIGN KEY(owner_id) REFERENCES users(id),INDEX(state,id),INDEX(owner_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS nft_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,nft_id BIGINT NOT NULL,actor_id BIGINT NOT NULL,kind VARCHAR(30) NOT NULL,from_user BIGINT NULL,to_user BIGINT NULL,operation_id CHAR(32) NOT NULL,effective_at DATETIME(6) NOT NULL,FOREIGN KEY(nft_id) REFERENCES nfts(id),FOREIGN KEY(actor_id) REFERENCES users(id),FOREIGN KEY(operation_id) REFERENCES operations(id),INDEX(nft_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS deposits (id BIGINT AUTO_INCREMENT PRIMARY KEY,nft_id BIGINT NOT NULL,user_id BIGINT NOT NULL,operation_id CHAR(32) NOT NULL UNIQUE,created_at DATETIME(6) NOT NULL,FOREIGN KEY(nft_id) REFERENCES nfts(id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(operation_id) REFERENCES operations(id),INDEX(nft_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS proposals (id BIGINT AUTO_INCREMENT PRIMARY KEY,buyer_id BIGINT NOT NULL,seller_id BIGINT NOT NULL,current_version INT NOT NULL DEFAULT 1,state VARCHAR(30) NOT NULL DEFAULT 'WAITING',created_at DATETIME(6) NOT NULL,settled_at DATETIME(6) NULL,FOREIGN KEY(buyer_id) REFERENCES users(id),FOREIGN KEY(seller_id) REFERENCES users(id),INDEX(buyer_id,state,id),INDEX(seller_id,state,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS proposal_versions (proposal_id BIGINT NOT NULL,version INT NOT NULL,author_id BIGINT NOT NULL,asset VARCHAR(8) NOT NULL,nft_id BIGINT NULL,quantity BIGINT NOT NULL,unit_price BIGINT NOT NULL,total BIGINT NOT NULL,expires_at DATETIME(6) NOT NULL,rounding_rule VARCHAR(40) NOT NULL DEFAULT 'HALF_UP_CENT',created_at DATETIME(6) NOT NULL,PRIMARY KEY(proposal_id,version),FOREIGN KEY(proposal_id) REFERENCES proposals(id),FOREIGN KEY(author_id) REFERENCES users(id),FOREIGN KEY(nft_id) REFERENCES nfts(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS approvals (proposal_id BIGINT NOT NULL,version INT NOT NULL,user_id BIGINT NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(proposal_id,version,user_id),FOREIGN KEY(proposal_id,version) REFERENCES proposal_versions(proposal_id,version),FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS reservations (id BIGINT AUTO_INCREMENT PRIMARY KEY,proposal_id BIGINT NOT NULL,version INT NOT NULL,user_id BIGINT NOT NULL,asset VARCHAR(8) NOT NULL,amount BIGINT NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',UNIQUE(proposal_id,version,user_id),FOREIGN KEY(proposal_id,version) REFERENCES proposal_versions(proposal_id,version),FOREIGN KEY(user_id) REFERENCES users(id),INDEX(state,proposal_id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settlements (proposal_id BIGINT PRIMARY KEY,version INT NOT NULL,operation_id CHAR(32) NOT NULL UNIQUE,FOREIGN KEY(proposal_id) REFERENCES proposals(id),FOREIGN KEY(operation_id) REFERENCES operations(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS listings (id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,side VARCHAR(8) NOT NULL,asset VARCHAR(8) NOT NULL,nft_id BIGINT NULL,quantity BIGINT NOT NULL,unit_price BIGINT NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',expires_at DATETIME(6) NOT NULL,created_at DATETIME(6) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(nft_id) REFERENCES nfts(id),INDEX(state,asset,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS periods (id BIGINT AUTO_INCREMENT PRIMARY KEY,cutoff_at DATETIME(6) NOT NULL UNIQUE,parameter_id BIGINT NOT NULL,nft_count INT NOT NULL,total_shares BIGINT NOT NULL,distribution_total BIGINT NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'SNAPSHOT',processing_started_at DATETIME(6) NULL,processed_at DATETIME(6) NULL,gate_sequence BIGINT NOT NULL,FOREIGN KEY(parameter_id) REFERENCES parameter_versions(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS period_snapshots (period_id BIGINT NOT NULL,user_id BIGINT NOT NULL,shares BIGINT NOT NULL,eligible TINYINT NOT NULL,processed TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(period_id,user_id),FOREIGN KEY(period_id) REFERENCES periods(id),FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rights (id BIGINT AUTO_INCREMENT PRIMARY KEY,period_id BIGINT NOT NULL,user_id BIGINT NOT NULL,amount BIGINT NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'PENDING',accepted_operation CHAR(32) NULL UNIQUE,UNIQUE(period_id,user_id),FOREIGN KEY(period_id) REFERENCES periods(id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(accepted_operation) REFERENCES operations(id),INDEX(user_id,state,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fees (id BIGINT AUTO_INCREMENT PRIMARY KEY,period_id BIGINT NOT NULL,user_id BIGINT NOT NULL,amount BIGINT NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'PENDING',paid_operation CHAR(32) NULL UNIQUE,UNIQUE(period_id,user_id),FOREIGN KEY(period_id) REFERENCES periods(id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(paid_operation) REFERENCES operations(id),INDEX(user_id,state,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,actor_id BIGINT NULL,operation_id CHAR(32) NULL,kind VARCHAR(60) NOT NULL,data LONGTEXT NOT NULL,created_at DATETIME(6) NOT NULL,FOREIGN KEY(actor_id) REFERENCES users(id),FOREIGN KEY(operation_id) REFERENCES operations(id),INDEX(kind,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notifications (id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,message VARCHAR(255) NOT NULL,created_at DATETIME(6) NOT NULL,read_at DATETIME(6) NULL,FOREIGN KEY(user_id) REFERENCES users(id),INDEX(user_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS outbox (id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NOT NULL,subject VARCHAR(190) NOT NULL,body TEXT NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'PENDING',attempts INT NOT NULL DEFAULT 0,next_attempt_at DATETIME(6) NOT NULL,locked_until DATETIME(6) NULL,last_error VARCHAR(255) NULL,FOREIGN KEY(user_id) REFERENCES users(id),INDEX(state,next_attempt_at)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS task_runs (id BIGINT AUTO_INCREMENT PRIMARY KEY,started_at DATETIME(6) NOT NULL,finished_at DATETIME(6) NULL,status VARCHAR(20) NOT NULL,summary LONGTEXT NULL) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('001_initial',UTC_TIMESTAMP(6));
CREATE TABLE IF NOT EXISTS task_requests (nonce_hash CHAR(64) PRIMARY KEY,created_at DATETIME(6) NOT NULL) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('002_http_tasks',UTC_TIMESTAMP(6));

CREATE TABLE IF NOT EXISTS admin_restrictions (
 user_id BIGINT PRIMARY KEY, banned_until DATETIME(6) NULL, deleted_at DATETIME(6) NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin_nft_custody (
 nft_id BIGINT PRIMARY KEY, source_user_id BIGINT NOT NULL, operation_id CHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(nft_id) REFERENCES nfts(id), FOREIGN KEY(source_user_id) REFERENCES users(id), FOREIGN KEY(operation_id) REFERENCES operations(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('003_admin_danger',UTC_TIMESTAMP(6));

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
