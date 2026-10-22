# Two-level market markup Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this approved plan inline; obtain one fresh whole-branch review at completion.

**Goal:** Platform and tenants configure additive/percentage prices, restore base prices, and use identical prices in discovery, reservation, settlement and refunds.
**Architecture:** A pure decimal price policy plus a tenant-isolated SQL rule store. Resolve platform SKU prices centrally at the SKU model read boundary; route all tenant runtime price reads through the same resolver. Persist rule provenance with consumption snapshots; never recalculate historical settlement.
**Tech Stack:** PHP 8.2, ThinkPHP/MySQL, Vue 3/Element Plus, existing point services.
**Spec:** Approved conversation of 2026-10-22, including restore current purchase base rather than historical cost.

## Global Constraints
- SKU > product > global. Add points per SKU billing unit; percentage is a markup on purchase price.
- Legacy manual prices remain until explicitly replaced. Existing applications adopt unified market prices explicitly.
- Restore clears descendant manual/rule overrides and installs zero markup at the selected scope, including when a parent rule exists.
- DB-backed tenant isolation; six-decimal nonnegative finite amounts; invalid below-cost manual prices cannot execute.
- Preview and apply have admin/tenant binding, stale-price rejection and permission checks. No shared/production DB writes or remote develop push.

## Review Focus
- New SKU and upstream cost changes must inherit rules without rewriting historical snapshots.
- Tenant restore cannot remove platform markup or another tenant's rules.
- Mixed image/video/text/application/API entry points and application overrides must not bypass pricing.
- Concurrent restore/save/sync must invalidate stale previews; failure and duplicate callbacks must preserve balances.
- Variable units (seconds, images, input/output/cache tokens) retain independent prices and actual usage semantics.

### Task 1: Policy and database administration
**Files:** `app/common/service/power/MarketPricePolicy.php`, `MarketPriceRuleService.php`, `tests/market-markup/*`, install SQL and both upgrade directories.
**Interfaces:** `MarketPricePolicy::resolve(base,legacy,rules,productId,skuId):array`; `MarketPriceRuleService::preview(owner,admin,params):array`, `apply(owner,admin,token):array`, `tenantPrice(owner,sku,configured):float`.
- [x] Write failing policy tests for 10 -> 12 -> 15, unit add, precedence, restore zero, bounds and below-cost manual.
- [x] Implement policy, rule persistence, revisioned preview/apply and migration parity; run policy tests and DB fixture tests.
- [x] Commit and merge local develop; run local migration before runtime acceptance.

### Task 2: Runtime and controller integration
**Files:** PowerMarketSku, market controllers, market runtimes, AppSalePriceService, AiConsumptionLog, permission middleware.
**Interfaces:** Controller `pricingRules`, `previewPricing`, `applyPricing`; read requires market access, mutations reuse savePrices authorization.
- [x] Test and integrate all model/application/catalog/native API prices; include rule snapshots and preserve existing settlement/refund idempotency.
- [x] Add explicit follow-market application configuration with no destructive legacy rewrite.
- [x] Commit and merge local develop, execute actual reservation/settlement/refund fixtures and external live smoke with configured local tenant.

### Task 3: Shared administration UI
**Files:** shared market pricing component and workflow, platform/tenant market pages and APIs.
**Interfaces:** configuration by global/product/SKU and selected products, server preview token required for apply, refresh after success.
- [x] Test configuration/restore preview state, stale/error retention and duplicate prevention.
- [x] Add global, selected, product and SKU entry points, clear units, source and restore confirmation.
- [x] Commit and merge local develop; build both frontends and accept via real browser and authenticated backend.

### Task 4: Acceptance and review
- [x] Run policy, DB, relevant existing regression suites, real upstream settlement and browser acceptance; retain evidence in runtime/market-markup.
- [x] Fresh reviewer checks whole changes, fix important findings and rerun focused checks.
- [x] Report only verified outcomes and exact uncompleted boundaries; publish only upon explicit request.
