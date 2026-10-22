# 实施裁决与验收边界

以下保留实施期间的全部裁决，按发生顺序记录理由与风险。不存在延期处理的 Minor 审查问题。

- Ruling: work in existing split feature checkouts, commit-only isolated runtime for verification — required local develop checkpoints and current selected business generations must remain intact — cost if wrong: create additional isolated source worktrees.

- Ruling: new orders explicitly pay_way=0 until channel launch; historical recharge schema default2 is not evidence of launch — safe never-started cancellation requires empty pay_sn and snapshot channel0 — cost if wrong: existing payment adapters require explicit initialization.

- Ruling: order -> snapshot -> claim lock order for close/paid; catalog lock serializes save/create — avoids cyclic callback/cancel locks — cost if wrong: refine locking with overlap tests.

- Ruling: virtualpay.credential_key is a new private installation environment key (>=32 bytes), with independent AES-GCM vault. Do not set/change existing wechat.credential_key — changing its fallback would invalidate older open-platform encrypted credentials and affect business — cost if wrong: operators must provision and preserve a separate private key before enabling virtual payment.

- Ruling: authorized-mode callbacks reuse component message receiver and never expose shared component token/AES to tenants; manual-mode callback settings remain tenant/app version-scoped — preserves existing authorization receivers — cost if wrong: custom authorizer push settings need separate review.

- Ruling: customer HTTP checkout is always production env0; sandbox keys/mappings remain configurable only for isolated diagnostics, never selected by public request — sandbox payments cannot grant production user rights by changing an env parameter — cost if wrong: separate test harness/application needed for sandbox trials.

- Ruling: generic shipping upload is submitted once; unknown responses cause queries/manual attention, never blind reupload — official API limits reupload and unknown result may already be effective — cost if wrong: operator must inspect an unresolved shipping order.

- Ruling: no new global billing freeze for refund requests. Request and completion each prove unused rights; if tasks/ledger change while channel processes refund, financial completion remains recorded and rights go to manual review — avoids changing active task billing/queues — cost if wrong: operator must handle rights consumed during asynchronous refund.

- Ruling: verified payment consumes discount even when entitlement delivery fails; refund does not restore it. This fixes task4 financial/rights separation; late closed payments never reactivate the closed application or take another order's reservation — cost if wrong: a failed-delivery refunded customer requires explicit commercial compensation outside one-use discount.

- Ruling: ordinary new package orders freeze merchant/config fingerprint (no secret in snapshot), reuse fixed attempt, cannot switch until confirmed closed. Changed credentials fail closed/manual. Verified late/duplicate ordinary funds get separate durable manual receipt, never second grant. Existing channel SDKs retained; tests inject only external SDK I/O.

- Ruling: verified-phone account merge blocks unsettled orders and shadow accounts retaining delivered rights; fully refunded used discounts copy conservatively to target, old financial references stay unchanged. Creation and new delivery user lock prevent stale deleted identity/grant races. Old identity regression passes.

- Ruling: bounded maintenance max20 items/45sec scheduling budget, process named lock + item lease/named lock, generation checks after HTTP; expired uncertain snapshots back off individually to avoid starving later orders. Runtime process guard includes virtualpay:maintain.

- Final: Ruling: physical iOS/Android SDK and merchant qualification remain unverified — mock SDK and builds cannot establish true device eligibility; current delivery is local only — cost if wrong: launch incompatibility until real device acceptance.

- Final: Ruling: real debit/refund and external response compatibility remain untested as explicitly requested — test gateway changes external ledger only; retain manual real-channel acceptance before release — cost if wrong: production-channel incompatibility may remain.

- Final: Ruling: inherited typecheck/premium audit findings are documented, not repaired in unrelated modules — focused regressions and builds pass — cost if wrong: hidden unrelated type regressions require separate baseline cleanup.

- Final: Ruling: production credentials/cron/migrations are not executed — user explicitly prohibits publication — cost if wrong: deployment remains an operator task before online readiness.


2026-10-22 superseding decision (explicit owner correction): provision payment vault keys per tenant from the tenant UI, with first-save automatic generation. Use encrypted tenant configuration under the existing channel credential protection mechanism; never ask tenants to edit deployment files. The installation-wide virtualpay.credential_key is read only for old ciphertext compatibility. Existing tenant keys are immutable/idempotent, concurrent initialization is serialized, and historical config versions/sessions remain readable without rewriting history. No change to the WeChat credential root or payment/signing/accounting semantics.
