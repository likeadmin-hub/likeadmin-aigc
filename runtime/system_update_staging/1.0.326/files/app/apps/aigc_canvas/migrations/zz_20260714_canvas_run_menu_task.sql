-- Tenant menu names are synchronized from menus/tenant.json by the app registry.
-- Keep this historical migration idempotent for installations without per-tenant table interpolation.
SELECT 1;
