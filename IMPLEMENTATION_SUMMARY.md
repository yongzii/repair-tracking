# RepairSync Cloud — Current Implementation Summary

This is a current, source-oriented overview of the repository. It replaces an earlier Laravel 11/Blade-era summary. It does not claim that every feature has been deployed or tested.

## Application

RepairSync Cloud is a multi-shop web repair operations system. It combines public consultation requests and repair tracking with authenticated shop, technician, and platform workflows.

## Verified technology from project manifests

- PHP `^8.2`; local audit runtime: PHP 8.2.26.
- Laravel `^12.0`; lockfile version 12.39.0.
- Vue `^3.5.13`; lockfile version 3.5.28.
- Vite `^7.3.1`; lockfile version 7.3.1.
- Axios `^1.11.0`; lockfile version 1.13.5.
- Tailwind CSS `^4.0.0`; lockfile version 4.1.18.
- MySQL and SQLite connection configurations. The production example selects MySQL; the base config defaults to SQLite.
- PHPUnit `^11.5.3`; lockfile version 11.5.44.
- Twilio SDK `^8.11`; lockfile version 8.11.0.

The interface is a Vue SPA mounted into a Blade shell. Axios communicates with same-origin Laravel API routes using session cookies and CSRF protection. Laravel controllers and Eloquent models implement validation, authorization, business rules, and database access.

## Main domains

1. **Platform:** shops, activation, subscription settings/requests/payments, failed queue jobs.
2. **Shop workspace:** dashboard, settings, users, technicians, service catalog, audit logs.
3. **Repair work:** consultation requests, job intake, status history, technician assignment, repair evidence, warranty, pickup, RTO/refunds.
4. **Parts and inventory:** inventory, stock movements, shop purchasing requests, job-specific part orders, blocked-job part requests.
5. **Customer access:** public consultation requests and confirmation lookup, shop-scoped repair tracking, customer-visible repair photos.
6. **Reporting and notifications:** shop reports/CSV export, queued email/SMS code, notification history.

## Roles

Persisted roles are `platform_admin`, `shop_admin`, `admin`, `staff`, and `technician`. The role helpers treat `shop_admin` and `admin` as aliases. Customers do not sign in and are not represented by a customer role in the current model.

## Important implementation notes

- Shop isolation is applied through controller queries and checks; it is not a universal model-level global scope.
- Public tracking and selected public lookup routes are throttled.
- Sensitive actions use database transactions and row locks where appropriate. Payment recording uses a required idempotency key; some parts-creation paths support optional idempotency keys.
- Repair photos and subscription proofs use scoped file routes. Verify actual production disk configuration.
- Email/SMS requires configured providers and a running queue worker.
- Platform audit logs are not exposed as a working feed; the frontend page is a placeholder.
- `UNREPAIRABLE` is legacy; current job handling uses RTO-related statuses.
- PDF generation, online payment processing, customer accounts, and customer rating features were not verified.

## Test inventory

The source contains 29 feature test files, one unit test file, and one separate MySQL concurrency test file. A static count found 292 declared test methods. This is not a coverage figure and no tests are claimed to have passed in this document.

Default PHPUnit config uses in-memory SQLite. MySQL concurrency tests are separate and use a dedicated test database; their guarded refresh operation is destructive to that database.

## Further reading

- `SYSTEM_GUIDE.md` — current system overview and workflows.
- `FEATURES_CHECKLIST.md` — implemented, partial, and not-found feature inventory.
- `DEPLOYMENT_RUNBOOK.md` — deployment procedure and operational checks.
- `MYSQL_CONCURRENCY_TESTING.md` — separate MySQL concurrency test process.
