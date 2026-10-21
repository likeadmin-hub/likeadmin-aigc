# Redeem card local verification

No migration has been run by writing these scripts. PHP 8.2+ is required.

Pure checks (no database access):

```
php tests/redeem-card/policy.php
php tests/redeem-card/schema.php
```

After the owner explicitly approves applying the migration to the local `127.0.0.1 / z_cn` database:

```
php tests/redeem-card/apply-local-schema.php --apply
php tests/redeem-card/integration.php
php tests/redeem-card/concurrency.php --allow-committed-fixtures
```

`apply-local-schema.php` uses the existing SQL migration executor and only applies the card schema/shared menu SQL. Reloading tenant menus triggers the existing PackageProvisionService path for split tables; the apply helper does not reseed unrelated membership or recharge plans. MySQL DDL cannot be rolled back. The integration test rolls back all fixture business rows. The concurrency test needs committed fixture rows for independent PHP connections and removes only its generated IDs in `finally`; run it without interrupting the process. It creates no provider consumption, payment order or tenant deduction.

The upgrade SQL is `upgrade/20261021_redeem_card.sql`, identical to `public/upgrade/20261021_redeem_card.sql`. Fresh install and tenant bootstrap contain the same four shared tables and the finance-menu seed. Existing split menu tables are synchronized by the existing menu loading/provision path.

Generated codes contain 128 random bits and are stored only as hashes plus masked labels. Raw codes are returned once from `generate`, and the tenant operation-log listener redacts them from params/results. Batch creation is not idempotent: clients must disable automatic retries; if the response is lost, find the batch in the batch list and disable that batch before generating another.

Points are credited by UserPointService with change type 205 and a deterministic source. Memberships reuse assignPlan: issued plan/bonus rights are snapshotted; a free permanent membership starts from now; an active same-plan membership extends its current expiry; another active paid plan causes `membership_conflict` without consuming the card. No silent replacement, combined-plan grant or paid-checkout record is created.
