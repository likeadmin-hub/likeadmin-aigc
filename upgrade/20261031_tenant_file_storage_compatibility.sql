-- likeadmin:seed-builtin-distribution-posters-v1
-- Explicit compatibility preparation repairs missing storage receipt columns on
-- the shared tenant file table and actual split tenant file tables before seeding.
-- Existing storage definitions/values and edited, disabled or deleted posters stay intact.
-- Executed through BuiltinPosterService by SqlMigrationExecutor; never as raw SQL.
SELECT 1;
