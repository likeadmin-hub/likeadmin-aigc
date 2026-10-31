# Tenant file upgrade compatibility

Goal: prevent the confirmed missing storage-column failure when an explicit system upgrade seeds builtin posters for historical shared/split tenant file tables.

Use the existing builtin poster migration entry, with a preflight over the shared file table and tables selected by tenant tactics/serial. Add only absent storage_scope (varchar(20), platform), storage_engine (varchar(30), local), storage_domain (varchar(255), empty) columns. Preserve existing column definitions, receipts, templates, disabled/deleted defaults and tenant configuration. Reject unsafe identifiers, missing or nontransactional target tables and an active transaction before DDL. Ordinary list/seed requests perform no schema repair. Keep unknown-SQL replay guards unchanged.

Task 1:
- [x] Reproduce the actual upgrade marker against disposable MySQL legacy shared/split tables; include partial columns, custom prefixes, hyphenated split serials, repeat upgrades and preflight failure cases.
- [x] Add the bounded compatibility preparation before builtin poster migration writes; add an identical explicit upgrade marker under upgrade/ and public/upgrade/ for future packages.
- [x] Distinguish missing-table/column SQL errors from actual connection errors at the customer message boundary; preserve raw server diagnostics.
- [ ] Verify the regression, existing poster/customer-message tests and update staging/package contracts. Review, commit and merge into local develop, then rerun focused validation.

Delivery: source and local verification only. Do not overwrite published 1.0.426/427 artifacts, change update-chain rules, replay failed customer journals, push remote integration branches or publish a version without a release request. Existing clients blocked on the immutable 425→426 step require a separately distributed updater fix/recovery; a later package alone cannot repair an earlier failed step.

Validation before commit: the real MySQL upgrade marker reproduced SQLSTATE[42S22] before the fix, then passed legacy shared/split, partial-column/custom-value preservation, repeated marker, all-target preflight, InnoDB and active caller-transaction cases. Existing poster layout/assets/auth/H5/request/builtin checks and customer-message boundaries pass. PHP 8.2 syntax checks pass; staging and migration-coverage suites pass. A pre-existing customer-message wording assertion was updated to accept both task/process guard terms while preserving its stop/wait requirement. Fresh review found no important defects; its transaction-ownership test suggestion was added and passed.

Local migration uses the documented SqlMigrationExecutor path only after merge, guarded to hostname 127.0.0.1, database z_cn, prefix la_. No customer database or published package is changed by this source task.
