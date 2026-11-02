-- likeadmin:migrate-tenant-user-accounts-v1
-- Explicit migration handles the shared user table and existing tenant user tables.
-- Preflights all tables, preserves deleted-account reservations, and supports custom prefixes.
-- Applied by SqlMigrationExecutor through TenantUserAccountMigration; never run as raw SQL.
SELECT 1;
