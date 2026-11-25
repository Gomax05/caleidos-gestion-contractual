CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY, name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE, role VARCHAR(20) NOT NULL CHECK (role IN ('commercial','legal','finance','management','admin')),
    password_hash VARCHAR(255) NOT NULL, active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1))
);
CREATE TABLE IF NOT EXISTS contracts (
    id VARCHAR(36) PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE,
    title VARCHAR(160) NOT NULL, client VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL,
    service VARCHAR(100) NOT NULL, amount DECIMAL(15,2) NOT NULL CHECK (amount > 0),
    currency VARCHAR(3) NOT NULL CHECK (currency IN ('PEN','USD')),
    start_date VARCHAR(10) NOT NULL, end_date VARCHAR(10) NOT NULL, description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('review','observed','approved','sent','signed')),
    stage INTEGER NOT NULL CHECK (stage BETWEEN 0 AND 4),
    owner_id VARCHAR(36) NOT NULL REFERENCES users(id),
    created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
    CHECK (end_date >= start_date)
);
CREATE TABLE IF NOT EXISTS contract_versions (
    id VARCHAR(36) PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES contracts(id),
    version_number INTEGER NOT NULL, original_name VARCHAR(200) NOT NULL, storage_name VARCHAR(80),
    file_size INTEGER NOT NULL, clause_text TEXT NOT NULL, summary TEXT NOT NULL,
    author_id VARCHAR(36) NOT NULL REFERENCES users(id), created_at VARCHAR(32) NOT NULL,
    UNIQUE (contract_id, version_number)
);
CREATE TABLE IF NOT EXISTS approvals (
    id VARCHAR(36) PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES contracts(id),
    phase INTEGER NOT NULL CHECK (phase BETWEEN 0 AND 3), version_number INTEGER NOT NULL,
    user_id VARCHAR(36) NOT NULL REFERENCES users(id), created_at VARCHAR(32) NOT NULL,
    UNIQUE (contract_id, phase)
);
CREATE TABLE IF NOT EXISTS observations (
    id VARCHAR(36) PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES contracts(id),
    user_id VARCHAR(36) NOT NULL REFERENCES users(id), category VARCHAR(100) NOT NULL,
    comment TEXT NOT NULL, version_number INTEGER NOT NULL,
    from_client INTEGER NOT NULL DEFAULT 0, resolved INTEGER NOT NULL DEFAULT 0,
    resolved_version INTEGER, created_at VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS contract_events (
    id VARCHAR(36) PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES contracts(id),
    user_id VARCHAR(36) NOT NULL REFERENCES users(id), action VARCHAR(160) NOT NULL,
    detail TEXT NOT NULL, created_at VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_contracts_status ON contracts(status, stage);
CREATE INDEX IF NOT EXISTS ix_events_contract ON contract_events(contract_id, created_at);
