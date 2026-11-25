-- Ejecutar en SQL Server Management Studio o Azure Data Studio.
-- Crea la base y las mismas tablas lógicas que SQLite. No borra tablas existentes.
IF DB_ID(N'CaleidosGestionContractual') IS NULL
    EXEC(N'CREATE DATABASE CaleidosGestionContractual');
GO
USE CaleidosGestionContractual;
GO
IF OBJECT_ID(N'dbo.users', N'U') IS NULL
CREATE TABLE dbo.users (
    id VARCHAR(36) NOT NULL PRIMARY KEY, name NVARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    role VARCHAR(20) NOT NULL CHECK (role IN ('commercial','legal','finance','management','admin')),
    password_hash VARCHAR(255) NOT NULL,
    active INT NOT NULL DEFAULT 1 CHECK (active IN (0,1))
);
GO
IF OBJECT_ID(N'dbo.contracts', N'U') IS NULL
CREATE TABLE dbo.contracts (
    id VARCHAR(36) NOT NULL PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE,
    title NVARCHAR(160) NOT NULL, client NVARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL,
    service NVARCHAR(100) NOT NULL, amount DECIMAL(15,2) NOT NULL CHECK (amount > 0),
    currency VARCHAR(3) NOT NULL CHECK (currency IN ('PEN','USD')),
    start_date VARCHAR(10) NOT NULL, end_date VARCHAR(10) NOT NULL, description NVARCHAR(MAX) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('review','observed','approved','sent','signed')),
    stage INT NOT NULL CHECK (stage BETWEEN 0 AND 4), owner_id VARCHAR(36) NOT NULL REFERENCES dbo.users(id),
    created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
    CHECK (end_date >= start_date)
);
GO
IF OBJECT_ID(N'dbo.contract_versions', N'U') IS NULL
CREATE TABLE dbo.contract_versions (
    id VARCHAR(36) NOT NULL PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES dbo.contracts(id),
    version_number INT NOT NULL, original_name NVARCHAR(200) NOT NULL, storage_name VARCHAR(80) NULL,
    file_size INT NOT NULL, clause_text NVARCHAR(MAX) NOT NULL, summary NVARCHAR(MAX) NOT NULL,
    author_id VARCHAR(36) NOT NULL REFERENCES dbo.users(id), created_at VARCHAR(32) NOT NULL,
    UNIQUE (contract_id, version_number)
);
GO
IF OBJECT_ID(N'dbo.approvals', N'U') IS NULL
CREATE TABLE dbo.approvals (
    id VARCHAR(36) NOT NULL PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES dbo.contracts(id),
    phase INT NOT NULL CHECK (phase BETWEEN 0 AND 3), version_number INT NOT NULL,
    user_id VARCHAR(36) NOT NULL REFERENCES dbo.users(id), created_at VARCHAR(32) NOT NULL,
    UNIQUE (contract_id, phase)
);
GO
IF OBJECT_ID(N'dbo.observations', N'U') IS NULL
CREATE TABLE dbo.observations (
    id VARCHAR(36) NOT NULL PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES dbo.contracts(id),
    user_id VARCHAR(36) NOT NULL REFERENCES dbo.users(id), category NVARCHAR(100) NOT NULL,
    comment NVARCHAR(MAX) NOT NULL, version_number INT NOT NULL,
    from_client INT NOT NULL DEFAULT 0, resolved INT NOT NULL DEFAULT 0,
    resolved_version INT NULL, created_at VARCHAR(32) NOT NULL
);
GO
IF OBJECT_ID(N'dbo.contract_events', N'U') IS NULL
CREATE TABLE dbo.contract_events (
    id VARCHAR(36) NOT NULL PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES dbo.contracts(id),
    user_id VARCHAR(36) NOT NULL REFERENCES dbo.users(id), action NVARCHAR(160) NOT NULL,
    detail NVARCHAR(MAX) NOT NULL, created_at VARCHAR(32) NOT NULL
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'ix_contracts_status' AND object_id = OBJECT_ID(N'dbo.contracts'))
    CREATE INDEX ix_contracts_status ON dbo.contracts(status, stage);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'ix_events_contract' AND object_id = OBJECT_ID(N'dbo.contract_events'))
    CREATE INDEX ix_events_contract ON dbo.contract_events(contract_id, created_at);
GO
-- Los datos demo se cargan desde PHP al conectar por primera vez a una base vacía.
-- Este script no transfiere los datos existentes del archivo SQLite.
-- Después de este script, ejecuta migrations/002_demo_workspaces.sqlserver.sql
-- para borradores, responsables, plazos y mediciones. PHP también aplica esta
-- migración automáticamente al conectar una base que todavía no la tenga.
