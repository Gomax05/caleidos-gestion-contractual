-- Alternativa manual a MigrationRunner. Ejecutar después de schema.sqlserver.sql
-- en la base CaleidosGestionContractual. No elimina registros.
IF OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL
    CREATE TABLE dbo.schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL);
GO
IF NOT EXISTS (SELECT 1 FROM dbo.schema_migrations WHERE version = '002_demo_workspaces')
BEGIN
    BEGIN TRY
        BEGIN TRANSACTION;
        IF COL_LENGTH('dbo.contracts', 'assignee_id') IS NULL ALTER TABLE dbo.contracts ADD assignee_id VARCHAR(36) NULL REFERENCES dbo.users(id);
        IF COL_LENGTH('dbo.contracts', 'due_date') IS NULL ALTER TABLE dbo.contracts ADD due_date VARCHAR(10) NULL;
        IF COL_LENGTH('dbo.contracts', 'assigned_at') IS NULL ALTER TABLE dbo.contracts ADD assigned_at VARCHAR(32) NULL;
        IF COL_LENGTH('dbo.contracts', 'review_days') IS NULL ALTER TABLE dbo.contracts ADD review_days INT NOT NULL DEFAULT 3;
        CREATE TABLE dbo.contract_drafts (
            id VARCHAR(36) PRIMARY KEY, owner_id VARCHAR(36) NOT NULL REFERENCES dbo.users(id), payload NVARCHAR(MAX) NOT NULL,
            original_name NVARCHAR(200) NULL, storage_name VARCHAR(80) NULL, file_size INT NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL
        );
        CREATE TABLE dbo.review_periods (
            id VARCHAR(36) PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES dbo.contracts(id),
            user_id VARCHAR(36) NOT NULL REFERENCES dbo.users(id), phase INT NOT NULL, task_type VARCHAR(20) NOT NULL,
            started_at VARCHAR(32) NOT NULL, completed_at VARCHAR(32) NULL, outcome VARCHAR(80) NULL
        );
        EXEC('CREATE INDEX ix_contracts_assignee ON dbo.contracts(assignee_id, due_date)');
        CREATE INDEX ix_drafts_owner ON dbo.contract_drafts(owner_id, updated_at);
        CREATE INDEX ix_periods_contract ON dbo.review_periods(contract_id, completed_at);
        INSERT INTO dbo.schema_migrations(version, applied_at) VALUES ('002_demo_workspaces', CONVERT(VARCHAR(19), SYSUTCDATETIME(),126) + '+00:00');
        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO
