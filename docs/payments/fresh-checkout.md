# Fresh cash checkout

Explicit purchases always create a new order. Optional request_id is scoped to tenant, user and business type and bound to the immutable creation payload; a transport replay returns only that purchase result. Different request IDs create independent orders. Missing request IDs retain compatibility with older clients and create a new order.

CashCheckoutService creates gateway-compatible 31-character random order numbers and records customer purchase identities in the shared cash_checkout table. Database unique keys enforce global order numbers, scoped purchase IDs and channel/transaction receipt identity. User-row locking serializes simultaneous creations; fulfillment holds its order row and claims the receipt before mutating balances or membership rights. Existing OEM verified-receipt uniqueness and settlement locks remain authoritative; tenant power and OEM order numbers use distinct prefixes and existing unique order indexes.

Pending orders are retained for callbacks and accounting but never queried by the purchase page or used as new checkouts. A reserved promotion does not block a fresh regular-price purchase and cannot issue the once-only discount twice. Quote changes still require confirmation. Historical orders remain settleable with the same order/transaction guard.

Schema lifecycle: public/install/db/like.sql creates the global shared table for a fresh installation. upgrade/20261019_cash_checkout_identity.sql and its public/upgrade mirror create it idempotently for existing sites. New tenants use scoped rows in that global table; no tenant-sharded table or seed data is required. No historical orders, receipts, balances or discount claims are rewritten.

Verification: cash-checkout.integration.php plus real multi-process cash-checkout-concurrency.integration.php in the explicitly disposable OEM database, ordinary/virtual payment and promotion regression suites, PC component/browser checks, PC/H5/MP production builds. Native device payment and real bank transfers require separate acceptance.
