# Point balance alerts implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship owner-isolated configurable point alerts, reliable capped delivery, admin screens and private recipient inboxes.
**Architecture:** Pure decimal policy + explicit tenant store, durable dirty queue/account rounds/deliveries, existing SMS engine adapters, scoped SMTP; core authenticated controllers and shared admin panel. No billing mutations.
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
- [ ] Write policy tests: decimal validation, multi-level merge, daily/cycle/interval caps, repeat, zero, recovery daily retention.
- [ ] Run tests RED.
- [ ] Implement normalize(array):array and decide(array,array,int,int):array; integer hundredths arithmetic.
- [ ] Mirror schema/install SQL; migration preflight + apply local only.
- [ ] Run policy tests and PHP lint GREEN; commit.

### Task 2: Store, worker and notification adapters
Files: point_alert/AlertStore.php, AlertService.php, AlertDelivery.php, ScopedSmtp.php; balance log hooks; PointAlertMaintain command; tests/point-alert/database.php.
Interfaces: AlertPolicy::normalize/decide; AlertService::touch(tenant,user), check(tenant,user,now), maintain(limit); owner_id0 platform, >0 tenant.
- [ ] Write DB tests for disabled defaults, owner/split isolation, transaction rollback, durable cap admission, recovery cancellation and fake transport.
- [ ] Implement explicit scoped store, account locks, transactional dirty/outbox, keyset fallback and unique delivery admission.
- [ ] Reuse SMS engines/config/logs; durable unknown state, private message storage, SMTP TLS adapter.
- [ ] Run local DB tests/lint, commit.

### Task 3: Authenticated APIs, menus and UI
Files: platformapi/tenantapi notice.PointAlertController; api PointAlertController; migration menu sync; web shared point-alert panel and host wrappers; power mall receiving card; PC notice center/private APIs; mobile inbox.
Interfaces: rules/save/records/detail/preview/check; tenant receiving/contact APIs; user private inbox/unread/read/preferences.
- [ ] Add API isolation tests and SFC/runtime tests for loading, failed fetch, dirty save, limits, preview-before-check and read isolation.
- [ ] Implement shared admin panel with server-confirmed rule state, protected actions, clear disabled channel guidance, record drawer and receiving section.
- [ ] Seed under existing message menu and power-mall permission actions; install/newtenant/upgrade parity.
- [ ] Build affected frontends and run tests; commit.

### Task 4: Integration and final review
- [ ] Merge scoped checkpoints into local develop for verification; run local migration and worker with delivery fake transport only.
- [ ] Exercise browser UI where auth permits, capture actionable screens, preserve existing settings.
- [ ] Request one independent whole-change review; resolve important findings with regression tests.
- [ ] Report exact tested coverage and remaining operational configuration. No remote publication.
