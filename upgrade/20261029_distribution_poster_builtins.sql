-- likeadmin:seed-builtin-distribution-posters-v1
-- Explicit upgrade adds the nullable tenant/builtin identity and seeds four defaults.
-- Existing drafts, versions, custom templates, hidden and deleted defaults are preserved.
-- Executed by SqlMigrationExecutor through BuiltinPosterService, not a raw SQL client.
SELECT 1;
