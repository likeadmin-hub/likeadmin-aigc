# Virtual payment and package promotion implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline, TDD for each task, one fresh whole-branch review at the end.

**Goal:** Approved mini-program virtual payment and shared one-use package promotions, verified locally without publication.
**Architecture:** A common promotion/price-snapshot service feeds existing recharge/membership orders. A separate XPay gateway normalizes signed receipts, delivery and refund state, and owns bounded reconciliation. Frontends consume server quotes and pure countdown/payment lifecycle helpers.
**Tech Stack:** ThinkPHP/PHP8.2/MySQL8; Vue/Nuxt/UniApp; existing EasyWeChat HTTP/token and encryption adapters.
**Spec:** docs/superpowers/specs/2026-10-14-virtual-pay-promotion.md

## Global Constraints
All spec rules binding. Feature branches in split repos; each checkpoint -> local develop -> isolated migrations/tests. No shared database/runtime updates, remote push or release. No vendor edits. Preserve unrelated changes. Real financial operations SKIPPED. Tests/fixtures never production entrypoints. One final reviewer only.

## Task 1: Promotion policy and persistent foundation
Files: service/promotion/PackagePromotionPolicy.php, upgrade/20261014_package_promotion.sql + public mirror/install SQL, tenantData.sql, tests/promotion-policy.php + schema tests.
Produces: cents/eligibility boundaries and tables for configuration, claim, quote snapshots; default disabled and menu parity.
- [ ] RED money, OR windows, lifetime one-use, expired/zero timestamps, saved vs restarted activity and schema assertions.
- [ ] Implement static migrations and policy; GREEN real fresh/upgrade/repeated/prefix schema.
- [ ] Scoped commit; merge local develop; execute isolated migration and verify.

## Task 2: Shared quote, order, promotion claims
Files: promotion/PackagePromotionService.php, PackagePricingService.php, RechargeLogic.php, MembershipService.php, PayNotifyLogic.php, API package-price/controller + tenant promotion endpoints, tests/promotion-orders.integration.php.
Consumes task1; produces server quotes, stable namespace claims, immutable order snapshots and atomic consumption/release.
- [ ] RED actual auth/order/OR/concurrent reservation/once usage/price drift/refund no-reset cases.
- [ ] Implement snapshots and fast existing paid-service integration preserving legacy behavior; query required before uncertain release.
- [ ] GREEN focused tests, commit, merge develop, isolated verification.

## Task 3: Virtual configuration, products, sessions, signing gateway
Files: virtualpay/VirtualPayConfigService.php, VirtualPayGateway.php, VirtualPaySessionService.php, VirtualPayPolicy.php, finance/VirtualPayController.php, migrations/install/menu, tests/virtualpay-gateway.integration.php.
Consumes quote snapshots; produces bound config/merchant contexts and immutable JSON/signature request contracts. Encrypt credentials, preserve old credential versions for outstanding transactions. Support direct/open-platform tokens and required permissions.
- [ ] RED app isolation, masks/leave-blank, wrong session identity, sandbox/production, raw JSON HMAC and goods/amount validation.
- [ ] Implement/configure API adapters (external transport injectable only by tests); GREEN, commit+merge+isolated SQL.

## Task 4: Payment verification, rights and shipping
Files: VirtualPayService.php, VirtualPayNotifyService.php, VirtualPayDeliveryService.php, MiniappShippingService.php, payment adapter and authorized callback dispatcher, new transaction/event/outbox tables, tests/virtualpay-payment.integration.php.
Consumes tasks2/3; produces idempotent verified payments, snapshot-based entitlement grant, distinct shipping acknowledgments.
- [ ] RED signature/replay, wrong identity/amount, Apple receipt without Wx transaction, late/duplicate payment, callback acknowledgment, transactional rollback and shipping unknown retry.
- [ ] Implement true notify/query trust boundaries, existing grant services, managed-order adapter, preserving unrelated callbacks; GREEN, scoped commit+merge.

## Task 5: Refund, recovery and operational controls
Files: VirtualPayRefundService.php, VirtualPayMaintenanceService.php, command/VirtualPayMaintain.php, Crontab.php + upgrade process gate, finance order lists, tests/virtualpay-recovery.integration.php.
Consumes task4; produces fixed-number query-first refund reconciliation, Apple fast inquiry/audit, safe entitlement recovery/manual boundary, bounded retry and visible actions.
- [ ] RED all uncertain/refund/claim cancellation/running task protection scenarios.
- [ ] Implement bounded maintenance/cron/runtime lease and safe recovery actions; GREEN, commit+merge.

## Task 6: Tenant configuration and promotion UI
Files: tenant finance views/API, reusable promotion/virtual goods editor, UX contract, tests.
Consumes server DTOs; produces effective-app config page, masked keys, promotion setup/timers/restart and product binding/recovery views.
- [ ] RED SFC user behavior and scoped visual/a11y contract.
- [ ] Implement current canonical ElementPlus components and durable DESIGN docs; GREEN tests/build, commit+merge.

## Task 7: PC/H5/mini-program prices and virtual checkout
Files: packages promotion/payment helpers, PC membership/recharge components/API, UniApp payment module and pages, locales/tests.
Consumes authoritative quote/intent contract; produces one-use/confirmed-price UX and MP_WEIXIN-only native virtual adapter, stopped polling, official confirmation component when applicable.
- [ ] RED quote drift, lifecycle/concurrency, raw signData, capability/version and sandbox Apple errors.
- [ ] Implement + GREEN three client regression groups; commit+merge.

## Task 8: Isolated full acceptance, review and handoff
Files: disposable fixtures/browser/native SDK harness, tests/report/operations docs.
- [ ] Real backend/DB/HTTP/UI: two package types, both price variants, concurrent one-use, lost callback and shipping failure recovery, safe refunds, legacy and OEM.
- [ ] Full tests/syntax/builds/migration/menu audit, commit-pinned runtime vs acceptance evidence. Real debit/refund SKIPPED.
- [ ] One fresh whole-branch review; fix important findings RED->GREEN; record deferred minors and rulings.
- [ ] Commit report, merge local develop, verify no remote publication and preserve business state. Save ledger before cleanup.

## Review Focus
Cross-app/tenant/user or merged-account identity; coupon preoccupation and delayed channel result; mutable rights after purchase; unknown shipping/refund results and Apple callback deadlines; signed JSON/body compatibility and old callbacks; crypto key scope and sensitive DTO leaks; async writes after runtime generation changes.
