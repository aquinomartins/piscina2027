-- Pagamentos externos somente de TESTE. Nenhuma emissão ou alteração de saldo nesta migração.
CREATE TABLE IF NOT EXISTS payment_test_intents (
 id CHAR(32) PRIMARY KEY, actor_id BIGINT NOT NULL, idempotency_key VARCHAR(80) NOT NULL,
 payload_hash CHAR(64) NOT NULL, external_reference VARCHAR(80) NOT NULL UNIQUE,
 amount BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'BRL', asset VARCHAR(8) NOT NULL DEFAULT 'MR',
 destination VARCHAR(24) NOT NULL, method VARCHAR(16) NOT NULL, payment_method_id VARCHAR(40) NOT NULL,
 collector_id VARCHAR(40) NOT NULL, credential_fingerprint CHAR(64) NOT NULL,
 provider VARCHAR(32) NOT NULL DEFAULT 'mercadopago_payments', experimental TINYINT NOT NULL DEFAULT 1,
 state VARCHAR(24) NOT NULL DEFAULT 'SUBMITTING', provider_id VARCHAR(40) NULL UNIQUE,
 provider_status VARCHAR(40) NULL, provider_updated_at VARCHAR(40) NULL,
 verified_snapshot LONGTEXT NULL, verified_hash CHAR(64) NULL,
 credit_operation_id CHAR(32) NULL UNIQUE, credit_transaction_id BIGINT NULL UNIQUE,
 created_at DATETIME(6) NOT NULL, authorized_at DATETIME(6) NOT NULL,
 checked_at DATETIME(6) NULL, credited_at DATETIME(6) NULL, error_code VARCHAR(40) NULL,
 FOREIGN KEY(actor_id) REFERENCES users(id), FOREIGN KEY(credit_operation_id) REFERENCES operations(id),
 FOREIGN KEY(credit_transaction_id) REFERENCES ledger_transactions(id),
 UNIQUE(actor_id,idempotency_key), INDEX(state,created_at),
 CHECK(amount>0 AND currency='BRL' AND asset='MR' AND experimental=1),
 CHECK(destination IN ('wallet','treasury','galeria_revenue')),
 CHECK(method IN ('pix','credit_card','debit_card'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS payment_test_events (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, intent_id CHAR(32) NOT NULL,
 kind VARCHAR(40) NOT NULL, data LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(intent_id) REFERENCES payment_test_intents(id), INDEX(intent_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Cancelar um envio ainda sem registro impede uma requisição atrasada com a mesma chave.
CREATE TABLE IF NOT EXISTS payment_test_abandoned (
 actor_id BIGINT NOT NULL, idempotency_key VARCHAR(80) NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(actor_id,idempotency_key), FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES('007_pagamentos_teste',UTC_TIMESTAMP(6));
