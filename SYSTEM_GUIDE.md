# RepairSync Cloud System Guide

This guide describes the current application structure and workflows represented by the source code. It replaces earlier documentation written for a Blade-based Laravel 11 version. It is not proof that a production deployment, external provider, or every workflow has been configured and tested.

## What the system does

RepairSync Cloud is a web-based repair-shop management system for electronics repair operations. It supports multiple shop workspaces and provides:

- Public shop information, repair booking, and repair-status tracking.
- Shop onboarding, accounts, service catalog, and workspace settings.
- Repair job intake, assignment, status history, device location, photos, and warranty records.
- Technician-specific access to assigned jobs.
- Inventory, stock movements, shop part requests, and job-specific part ordering.
- Customer payments, balances, RTO (return to owner) handling, and refunds.
- Shop dashboards, reports, exports, and shop audit logs.
- Platform-side shop and subscription administration.
- Queued email and SMS notification code, subject to deployment configuration.

There is no customer sign-in role in the current role model. Customers submit public consultation requests, use confirmation lookup, and track repairs.

## Technology and architecture

- **Backend:** PHP 8.2+ and Laravel 12. The checked-in Composer lockfile resolves Laravel 12.39.0.
- **Frontend:** Vue 3 single-page application. The checked-in npm lockfile resolves Vue 3.5.28.
- **API requests:** Axios sends same-origin JSON requests with browser credentials and a CSRF header.
- **Build and styling:** Vite 7 and Tailwind CSS 4. The frontend is mounted by a Blade shell; the main application pages are Vue components, not a traditional Blade page set.
- **Database:** Laravel supports SQLite and MySQL configuration. The default configuration falls back to SQLite; the production example uses MySQL. Check the deployment environment to know which database is in use.
- **Authentication:** Laravel session authentication with CSRF protection. This is not a bearer-token API architecture.
- **External services:** Mail and Twilio SMS are configurable. Their presence in source does not prove that credentials, a queue worker, or delivery are operational.

The browser loads the Blade application shell and Vite-built Vue code. Vue calls Laravel API routes. Controllers validate and authorize the request, use Eloquent models to access the database, and return JSON. Laravel queues customer notifications when applicable. File access is handled through local private/public disks and scoped application routes.

Useful entry points:

- `routes/api.php` — public, shop, platform, and technician API routes.
- `routes/web.php` — application shell and SPA fallback.
- `resources/js/spa/routes.js` — client-side page routes.
- `app/Http/Controllers/Api/` — API controller implementations.
- `app/Models/` and `database/migrations/` — domain models and schema history.
- `config/filesystems.php` — local, public, and S3 disk configuration.

## Roles

The persisted roles are `platform_admin`, `shop_admin`, `admin`, `staff`, and `technician`. `shop_admin` and `admin` are treated as aliases by role helper methods. The `customer` is not a persisted account role.

- **Platform administrator:** Manages shops, platform subscription settings/requests, subscription payments, and failed queue jobs.
- **Shop administrator (`shop_admin` or `admin`):** Has shop-level administration such as shop settings, user/technician management, reports, and audit-log access.
- **Shop staff:** Performs authorized daily shop operations, such as job, appointment, and inventory workflows. Some sensitive actions require an administrator.
- **Technician:** Uses the technician portal and is restricted to their linked profile, shop, and assigned jobs.
- **Customer:** Uses public consultation requests, confirmation lookup, and repair tracking without signing in.

Navigation visibility is not the security boundary. Backend route middleware and controller-level role, shop, and assignment checks determine access.

## Main workflow

1. A shop owner registers a shop and account. Onboarding creates a default service catalog.
2. The shop configures its profile, services, staff, technicians, business hours, and notification settings.
3. A customer submits a consultation request, or shop staff creates a job directly.
4. Staff review and respond to consultations; job orders are created through the normal job-intake workflow.
5. Staff assign a technician. The technician works on assigned jobs and records repair progress and evidence.
6. Staff and technicians record parts needs. Inventory and procurement actions update stock and job records.
7. Shop users record costs and customer payments. The system tracks payments and outstanding balance.
8. A job proceeds to pickup or the RTO/refund process. Customers can use public tracking to view permitted repair information.
9. Shops can use reports and shop audit logs. Platform administrators manage shop access and subscription requests.

## Job and related workflow statuses

The current job statuses exposed by the job controller include:

`RECEIVED`, `DIAGNOSING`, `WAITING_PARTS`, `IN_PROGRESS`, `READY_FOR_PICKUP`, `PICKED_UP`, `RTO`, and `RTO_RELEASED`.

Older migrations/documentation include `UNREPAIRABLE`; the later migration consolidates that status into RTO. Pickup is a dedicated action that requires the job to be ready for pickup. RTO release/refund actions have separate handling. Do not treat this list as a complete role-by-role transition matrix.

Consultation requests use `PENDING`, `CANCELLED`, and `COMPLETED`. The existing appointment schema retains historical booking values and columns; public creation now accepts consultations only.

## Security and operations notes

- Authenticated API routes check the session, active account, and active shop as applicable.
- Public tracking and some confirmation/photo lookup routes have throttles.
- Controllers apply shop and technician assignment checks; shop scoping is not a universal model-level global scope.
- Payment recording and selected stock, appointment, and subscription operations use transactions/row locks. Payment requests use an idempotency key; some parts operations support optional idempotency keys.
- Repair images and subscription proofs are served through scoped handlers. Verify the configured storage disk in production.
- Notifications require a functioning queue worker and valid mail/SMS provider settings.
- Audit logging is explicitly called by mutations; source does not establish that every mutation is logged or that logs are tamper-proof.

## Reports and customer communication

The application has dashboard and report endpoints, CSV export, and a print-oriented report view. The repository does not establish a configured server-side PDF-generation engine. Notification records and queued email/SMS delivery code exist, but delivery must be validated in the target environment.

The platform audit-log page is currently a placeholder, not a platform-wide feed. Shop audit logs are a separate feature.

## Running and testing

See `DEPLOYMENT_RUNBOOK.md` for production-oriented deployment instructions. Review its commands and storage steps for the target environment before execution.

The default PHPUnit configuration uses SQLite `:memory:` for feature/unit tests. The MySQL concurrency suite is separate and uses a dedicated test database; its guarded refresh step is destructive to that test database. Do not point it at production or a developer's application database. See `MYSQL_CONCURRENCY_TESTING.md`.

## Documentation boundaries

This guide describes source-level behavior. Verify roles and workflows in the running deployment before a live demonstration. Do not use demo credentials from older documentation. Configure real secrets outside version control.
