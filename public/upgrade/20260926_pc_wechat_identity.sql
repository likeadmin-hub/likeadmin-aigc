-- likeadmin:migrate-pc-wechat-bindings-v1
-- SqlMigrationExecutor invokes PcWechatMigration for the base table and all existing tenant shards.
-- Retain all legacy bindings with an empty appid; never infer ownership from current credentials.
SELECT 1;
