# Point balance alerts implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship owner-isolated configurable point alerts, reliable capped delivery, admin screens and private recipient inboxes using existing SMS/in-app channels.
**Architecture:** Pure decimal policy + explicit tenant store, durable dirty queue/account rounds/deliveries, existing SMS engine adapters; core authenticated controllers and shared admin panel. No billing mutations.
**Tech Stack:** ThinkPHP 8/PHP 8.2, MySQL, Vue 3/Element Plus, existing Crontab.
**Spec:** docs/superpowers/specs/2026-10-19-point-alerts.md

## Global Constraints
- Thresholds <=10; two decimals; inclusive; defaults disabled, cycle/daily max3, interval60min; Shanghai date.
- Preserve unrelated changes; no production push/deployment; explicit tenant context; no outbound in balance transactions.
- Runtime migration only local 127.0.0.1/z_cn; schema/menus parity, no request DDL.

## Review Focus
- Concurrent workers/duplicate events cannot exceed limits or send a channel twice.
- Counter changes and recovery preserve daily caps; configuration edits do not reset counters.
- Split tenant users/configs/menus and foreign recipient IDs never cross owners.
- Recovery/disable cancels queued sends, provider timeouts stay unknown.
- Decimal/zero/invalid inputs and partial channel failures are visible and actionable.

### Task 1: Pure policy and durable schema
Files: app/common/service/point_alert/AlertPolicy.php; upgrade/20261019_point_alert.sql; public/upgrade mirror; tests/point-alert/policy.php.
- [x] Write policy tests: decimal validation, multi-level merge, daily/cycle/interval caps, repeat, zero, recovery daily retention.
- [x] Run tests RED.
- [x] Implement normalize(array):array and decide(array,array,int,int):array; integer hundredths arithmetic.
- [x] Mirror schema/install SQL; migration preflight + apply local only.
- [x] Run policy tests and PHP lint GREEN; commit.

### Task 2: Store, worker and notification adapters
Files: point_alert/AlertStore.php, AlertService.php, AlertDelivery.php; balance log hooks; PointAlertMaintain command; tests/point-alert/database.php.
Interfaces: AlertPolicy::normalize/decide; AlertService::touch(tenant,user), check(tenant,user,now), maintain(limit); owner_id0 platform, >0 tenant.
- [x] Write DB tests for disabled defaults, owner/split isolation, transaction rollback, durable cap admission, recovery cancellation and fake transport.
- [x] Implement explicit scoped store, account locks, transactional dirty/outbox, keyset fallback and unique delivery admission.
- [x] Reuse SMS engines/config/logs; durable unknown state, private message storage.
- [x] Run local DB tests/lint, commit.

### Task 3: Authenticated APIs, menus and UI
Files: platformapi/tenantapi notice.PointAlertController; api PointAlertController; migration menu sync; web shared point-alert panel and host wrappers; power mall receiving card; PC notice center/private APIs; mobile inbox.
Interfaces: rules/save/records/detail/preview/check; tenant receiving/contact APIs; user private inbox/unread/read/preferences.
- [x] Add API isolation tests and SFC/runtime tests for loading, failed fetch, dirty save, limits, preview-before-check and read isolation.
- [x] Implement shared admin panel with server-confirmed rule state, protected actions, clear disabled channel guidance, record drawer and receiving section.
- [x] Seed under existing message menu and power-mall permission actions; install/newtenant/upgrade parity.
- [x] Build affected frontends and run tests; commit.

### Task 4: Integration and final review
- [ ] Merge scoped checkpoints into local develop for verification; run local migration and worker with delivery fake transport only.
- [x] Exercise browser UI where auth permits, capture actionable screens, preserve existing settings.
- [x] Request one independent whole-change review; resolve important findings with regression tests.
- [x] Report exact tested coverage and remaining operational configuration. No remote publication.

## Execution ledger

- Task 1 complete: policy RED class missing -> GREEN; schema applied idempotently on local 127.0.0.1/z_cn after local-develop checkpoint merge.
- Task 2 policy/store database suite green: scoped owners, split users, durable caps, recovery cancellation, transaction rollback, coalesced level coverage, stale config and foreign preview token. No provider calls.
- Ruling: existing-channel first phase implements SMS and private in-app notifications; email is explicitly unavailable rather than a nonfunctional enabled switch, matching the earlier phased proposal. Email transport/binding remains phase two.
- Ruling: Docker Desktop directory caching required a /tmp code snapshot for CLI tests. Tests use the same current source and only local z_cn, with fixtures rolled back. Runtime verification after commits uses synchronized source.

- Independent review completed once. Four important findings fixed with RED/GREEN regressions: recovery preserving minimum interval, coalesced levels materializing added channels, PC tab loading isolation, and late private detail rejecting changed identity. Two feedback findings regraded important and fixed: failed enable preview and check rejection inside the open dialog.
- Eight independent PHP processes passed concurrent admission, at-most-once channel claims and max-cycle/max-day checks, using disposable local accounts and a file-recording fake transport. No real outbound messages.
- Nine frontend regressions pass. Platform, tenant, PC and H5 builds passed before the final review fixes; final local-develop builds and migration are the integration gate below.
- Browser component fixture verified editable caps/interval, dirty-state checking guard, successful save, explicit preview confirmation and per-channel record details. This is a labeled simulated-account harness, not proof of authenticated deployed UI.
- Existing tenant vue-tsc invocation is blocked by TypeScript configuration TS5102 (removed importsNotUsedAsValues/preserveValueImports); do not claim a passing repository typecheck.
