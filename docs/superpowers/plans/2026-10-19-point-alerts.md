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
- [x] Merge scoped checkpoints into local develop for verification; run local migration and worker with delivery fake transport only.
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

## Final integration evidence

- Code checkpoints: server `47c2c1b70`, web `5a571ae4`, both merged to local develop. No remote push, release package or production deployment.
- Local migration applied idempotently against 127.0.0.1/z_cn after merge. Verified one platform page, two shared-tenant pages, one active point-alert cron entry, zero saved rule rows and zero remaining fixture accounts. New and existing settings therefore remain disabled.
- All 21 changed runtime source/schema files matched host/container SHA256 after refreshing Docker bind-mount stale/truncated files. Direct `php think point-alert:maintain` then returned `{"checked":0,"delivered":0}`; no enabled rules or outbound sends.
- Final local-develop builds: platform Vite 22.28s, tenant Vite 25.43s, PC Nuxt/Nitro and H5 Uni build all exited 0. Build outputs stayed outside deploy directories except ignored PC .output.
- Final regressions: policy + transactional database isolation/recovery + eight-process admission/delivery/caps passed. Frontend 9/9 and existing PC theme contracts 11/11 passed. Added bilingual catalog messages and private-screen text coverage passed.
- Existing global checks remain limited: tenant vue-tsc TS5102 from removed tsconfig options; global PC i18n audit reports 554 pre-existing missing-message issues. No successful global typecheck/i18n claim.
- Browser fixture screenshot: workspace `runtime/point-alert-ui/rules-verified.png`. This proves the shared component interactions with simulated accounts; authenticated deployed pages, real provider acceptance and unattended online scheduler execution are not certified by this local test.
- Operational setup: enable and save rules separately at platform/tenant; SMS requires each sender's existing SMS configuration, alert template/variables, tenant receiving contacts or user bound mobile. Email remains disabled phase two. Counts are per admitted round, shared across channels, with no automatic replay of uncertain provider results.
