-- Keep bounded reconciliation live for old unresolved channel intents.
ALTER TABLE `la_tenant_brand_payment_intent` ADD INDEX `idx_reconcile` (`last_query_time`,`id`);
