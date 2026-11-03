# RepairSync Cloud
## Master System Documentation

**System:** RepairSync Cloud
**Description:** All-in-One Repair Shop SaaS — a web-based repair service management system for electronics repair shops.
**Repository:** `yongzii/repair-tracking`
**Branch inspected:** `main`
**Documentation basis:** Source files, migrations, configuration, tests, and project documentation in the checked-out repository.

> **Evidence and status note:** This is documentation of the source implementation, not a certification of a running production deployment. “Implemented” means relevant code, routes, models, or pages are present. It does not mean that the feature has been deployed, all environment settings are correct, or all tests have passed. Historical Markdown files sometimes describe an older Blade/Laravel 11 application; this document uses the current source as the authority.

---

## Table of Contents

- [Chapter 1 — General System Overview](#chapter-1--general-system-overview)
- [Chapter 2 — Technology Stack and Development](#chapter-2--technology-stack-and-development)
- [Chapter 3 — System Architecture](#chapter-3--system-architecture)
- [Chapter 4 — User Roles and Responsibilities](#chapter-4--user-roles-and-responsibilities)
- [Chapter 5 — Complete System Modules](#chapter-5--complete-system-modules)
- [Chapter 6 — Complete Business Process](#chapter-6--complete-business-process)
- [Chapter 7 — Database Design](#chapter-7--database-design)
- [Chapter 8 — Security and Data Protection](#chapter-8--security-and-data-protection)
- [Chapter 9 — Testing and Quality Assurance](#chapter-9--testing-and-quality-assurance)
- [Chapter 10 — Unique Features and Advantages](#chapter-10--unique-features-and-advantages)
- [Chapter 11 — Implementation and Deployment](#chapter-11--implementation-and-deployment)
- [Chapter 12 — System Limitations and Future Improvements](#chapter-12--system-limitations-and-future-improvements)
- [Appendix A — Source Evidence Index](#appendix-a--source-evidence-index)
- [Appendix B — Remaining Verification Checklist](#appendix-b--remaining-verification-checklist)

---

# Chapter 1 — General System Overview

## 1.1 System name and description

RepairSync Cloud is a web-based management system for electronics repair shops. It connects public customer-facing flows—shop discovery, consultation requests, and repair tracking—with authenticated shop, technician, and platform workflows.

The term “Cloud” describes the intended software-as-a-service (SaaS) operating model: multiple shops use workspaces within one application. It does **not** by itself prove a particular cloud host, live URL, or production topology.

## 1.2 Background and purpose

Repair work involves related records and decisions: a shop receives a device, records its condition and reported issue, diagnoses it, assigns a technician, may obtain parts, records costs and payments, updates the customer, and finally returns the device or follows a return-to-owner (RTO) path. If each piece of information is kept separately on paper, in messaging applications, or in spreadsheets, staff may have difficulty locating the latest status or reconciling records.

RepairSync provides a central application for recording and following those activities. Its purpose is to make the repair lifecycle visible to shop personnel and to provide selected status information to customers without requiring customer accounts.

## 1.3 Problems addressed

The implemented features address the following operational problems:

1. **Scattered repair records:** Job orders and their histories are stored as application records.
2. **Unclear repair progress:** Status changes are recorded and exposed through public tracking routes.
3. **Unassigned or hard-to-coordinate work:** Jobs can be assigned to technicians; the technician portal limits users to assigned work.
4. **Parts and stock uncertainty:** Inventory, stock movements, shop part requests, job part orders, and blocked-parts workflows are represented.
5. **Consultation follow-up:** Customers can submit a consultation and receive a shop response without creating a job order.
6. **Payment and refund confusion:** Job payments and RTO refunds have separate records and workflows.
7. **Weak operational visibility:** Shop dashboards, reports, audit logs, and alerts support review of operations.
8. **Manual customer updates:** Notification records and queued email/SMS code exist; successful delivery still depends on configuration.

These are goals the system supports. The source does not establish a measured reduction in repair time, costs, or customer complaints.

## 1.4 General objective

To provide electronics repair shops with a web-based system for managing shop workspaces, customer repair requests, job orders, technicians, inventory, repair progress, payments, and customer tracking, while enabling platform-level management of shops and subscriptions.

## 1.5 Specific objectives

The source implementation supports these practical objectives:

- Register a shop and create its initial owner account and service catalog.
- Keep shop operations associated with a shop workspace.
- Record customer, device, service, and issue information in a job order.
- Record status history and technician assignment.
- Provide technician-specific access to assigned jobs.
- Record repair photos and control whether they are visible to customers.
- Track inventory and parts-related workflows.
- Record payments, balances, pickup, warranty assessments, and refund activity.
- Accept public appointments and provide confirmation and tracking pages.
- Provide shop-level reports and audit-log viewing.
- Allow platform administrators to manage shop activation and subscription review.

## 1.6 Intended users

| User category | Main purpose |
|---|---|
| Platform administrator | Oversees shops, subscriptions, platform settings, and failed queue jobs. |
| Shop administrator | Manages a shop workspace, shop users, technicians, settings, reports, and sensitive shop operations. |
| Shop staff | Handles authorized daily workflows such as jobs, appointments, and inventory. |
| Technician | Works on jobs assigned to their linked technician profile. |
| Customer | Uses public shop, booking, appointment lookup, and repair tracking functions; no customer login role was identified. |

## 1.7 Scope and limitations

### In scope in the current source

- Shop onboarding and shop-specific workspace records.
- Platform-side shop and subscription request management.
- Public consultation requests, confirmation lookup, and staff responses.
- Job order lifecycle, technician assignment, status history, parts, photos, warranty, payment, pickup, and RTO/refunds.
- Shop inventory, stock movements, procurement-related records, and audit logs.
- Public repair tracking and customer-visible repair evidence.
- Shop dashboards, reports, and CSV export.
- Notification history and queued email/SMS code.

### Not established or not found in the inspected implementation

- A customer account portal, customer role, or customer password reset.
- An integrated payment gateway that processes online shop subscription payments.
- A complete platform-wide audit feed. The corresponding platform page is a placeholder.
- A server-side PDF-generation engine or actual PDF response. The route/method is named `exportPdf`, but its implementation returns a rendered `reports.export` view; describe it as a print-oriented HTML report, not a generated PDF.
- Multi-location or branch management for one shop.
- Automatic skill-based technician assignment or load balancing.
- Verified production hosting, live service health, backups, or provider delivery.

## 1.8 Main capabilities and advantages

The system’s main capabilities are described in Chapter 5. Its most defensible advantages are the integration of appointment and repair records, technician assignment, parts/inventory workflows, payment and refund ledgers, customer-facing status visibility, and separate platform administration. Chapter 10 explains each advantage without claiming industry-wide uniqueness.

---

# Chapter 2 — Technology Stack and Development

## 2.1 Verified technologies and packages

Package versions below distinguish manifest constraints from lockfile-resolved versions. The manifests and lockfiles may advance independently; the lockfile is the precise version snapshot inspected.

| Technology | Simple definition and role | Why it fits this system / integration | Evidence and version |
|---|---|---|---|
| **PHP** | A server-side programming language. It runs application logic on the server. | It is the language used by Laravel controllers, models, migrations, jobs, and commands. The browser does not execute these PHP files; it sends HTTP requests to the server. | Composer requires PHP `^8.2`; local audit runtime was PHP 8.2.26. `composer.json`. |
| **Laravel** | A PHP web application framework providing routing, sessions, validation, database access, queues, and other common web features. | It organizes the backend into routes, controllers, Eloquent models, jobs, middleware, services, and migrations. It returns JSON consumed by the Vue interface. | Manifest `^12.0`; lockfile Laravel 12.39.0. `bootstrap/app.php`, `routes/`, `app/`. |
| **Vue 3** | A JavaScript framework for building interactive interfaces from reusable components. | It renders public, shop, platform, and technician pages in a browser SPA. It calls Laravel endpoints through Axios. | Manifest `^3.5.13`; lockfile 3.5.28. `resources/js/spa/`. |
| **JavaScript** | The programming language executed by the browser and Node-based build tools. | It implements Vue components, custom path resolution, authentication state, form interactions, and Vite configuration. No application TypeScript source was established in the inspected frontend. | `resources/js/`, `vite.config.js`, `package.json`. |
| **MySQL** | A relational database server that stores structured data in tables with keys and constraints. | The production example selects MySQL. Migrations use Laravel’s schema builder and define relations/constraints. | MySQL connection in `config/database.php`; `.env.production.example`; `phpunit.mysql.xml`. |
| **SQLite** | A relational database stored in a file or in-memory database, without a separate database server process. | The base database config defaults to SQLite, and the default PHPUnit tests use SQLite `:memory:` for isolated test runs. | `config/database.php`, `phpunit.xml`. |
| **Axios** | A JavaScript HTTP client for making browser requests. | Vue pages use it to call same-origin `/api/...` endpoints. Bootstrap configures JSON headers, browser credentials, and the CSRF header. | Manifest `^1.11.0`; lockfile 1.13.5. `resources/js/bootstrap.js`. |
| **Vite** | A frontend development server and asset bundler. | It compiles the Vue entry and CSS for development/production; Laravel’s Vite plugin connects built assets to the Blade shell. | Vite manifest `^7.3.1`, lockfile 7.3.1; Laravel Vite plugin lockfile 2.1.0. `vite.config.js`. |
| **Tailwind CSS** | A utility-first CSS framework: styling is assembled from small classes in markup. | It styles Vue components and is compiled through the Tailwind Vite plugin. | Manifest `^4.0.0`, lockfile 4.1.18. `resources/css/`, `vite.config.js`. |
| **Twilio SDK** | A PHP client library for the Twilio communications service. | The notification service can use it for SMS delivery. Actual delivery requires valid provider settings and operational queue processing. | Composer manifest `^8.11`, lockfile 8.11.0. `app/Services/CustomerNotifier.php`, `config/services.php`. |
| **PHPUnit** | A PHP testing framework that runs automated tests and assertions. | Feature tests exercise HTTP workflows and database effects; unit tests exercise smaller code units; a separate suite targets MySQL concurrency. | Manifest `^11.5.3`, lockfile 11.5.44. `phpunit.xml`, `tests/`. |
| **Composer** | PHP’s dependency manager and autoloader. | Installs Laravel and PHP packages, maps `App\` and test namespaces, and defines setup/dev/test scripts. | `composer.json`, `composer.lock`. |
| **npm** | Node.js package manager for JavaScript dependencies and scripts. | Installs Vue, Axios, Vite, Tailwind, and build plugins; runs `npm run dev` or `npm run build`. | `package.json`, `package-lock.json`. |

### Other important packages

| Package | Use in project | Evidence |
|---|---|---|
| `laravel/framework` | Backend framework and integrated components. | `composer.json` |
| `laravel/tinker` | Interactive Laravel command-line REPL, primarily for development/operations. | `composer.json` |
| `laravel-vite-plugin` | Connects Laravel asset entry points to Vite. | `package.json`, `vite.config.js` |
| `@vitejs/plugin-vue` | Enables Vue single-file component compilation in Vite. | `package.json`, `vite.config.js` |
| `@tailwindcss/vite` | Integrates Tailwind CSS v4 with Vite. | `package.json`, `vite.config.js` |
| `concurrently` | Runs several development processes together through the Composer `dev` script. | `package.json`, `composer.json` |
| `fakerphp/faker` | Generates fake data for factories/tests. | `composer.json`, `database/factories/` |
| `mockery/mockery` | PHP test doubles/mocking support. | `composer.json` |
| `nunomaduro/collision` | Improves command-line error display during development/testing. | `composer.json` |
| `laravel/pint` | PHP code style formatter. | `composer.json` |
| `laravel/sail` | Docker-based local development tooling is included as a dependency; use in an environment only if configured. | `composer.json` |
| Font Awesome CDN | Provides icons referenced by the application shell. | `resources/views/app.blade.php` |
| QRCode.js CDN | Provides browser QR-code functionality where used. | `resources/views/app.blade.php` |

The presence of a package or integration in source does not prove that the package is used by every page or that the associated external service is configured.

## 2.2 Development approach evidenced by the repository

The repository shows a Laravel/Vue implementation with incremental schema evolution:

1. Early migrations establish users, queue/cache support, repair jobs, status histories, and technicians.
2. Later migrations add pickup, financial fields, technician relationships, user roles, and soft deletion.
3. Subsequent migrations introduce shop tenancy and shop IDs on core and child records.
4. Additional migrations add appointments, inventory/stock movements, payments, audit logging, repair photos, notifications, warranty, and service catalogs.
5. Later business rules add subscriptions, refunds, username-based accounts, part requests/orders, idempotency, and status consolidation.

This sequence is visible in `database/migrations/`; it describes repository history, not necessarily the team’s project-management process. The source does not establish a particular development methodology such as Scrum or waterfall.

## 2.3 Project structure

| Folder/file | Purpose |
|---|---|
| `app/Http/Controllers/Api/` | API request handling and workflow rules. |
| `app/Http/Middleware/` | Request checks such as active-account/shop checks. |
| `app/Models/` | Eloquent models and database relationships. |
| `app/Services/` | Shared services such as customer notification delivery. |
| `app/Jobs/` | Background queue jobs, including customer notifications. |
| `app/Support/` | Shared utilities such as audit logging. |
| `app/Console/Commands/` | Artisan commands for operational or audit tasks. |
| `routes/api.php` | API route declarations. |
| `routes/web.php` | SPA shell route/fallback. |
| `resources/js/spa/` | Vue app, layouts, pages, custom navigation, and composables. |
| `resources/css/` | Main styles, processed through Vite/Tailwind. |
| `resources/views/` | Blade application shell and report-print view. |
| `database/migrations/` | Versioned schema changes. |
| `database/seeders/` and `database/factories/` | Account/demo seeding and test-data factories. |
| `tests/` | PHPUnit feature, unit, and separate MySQL tests. |
| `config/` | Laravel configuration, including auth, database, file storage, queue, and services. |

## 2.4 How the frontend, backend, and database work together

Example: a shop user records a payment.

1. The Vue job page collects an amount and other required payment fields.
2. Axios sends a same-origin request to the Laravel API, carrying the session cookie and CSRF header.
3. Laravel’s web/session middleware identifies the user and checks CSRF.
4. The route reaches a controller action. The controller validates the amount, role, shop, and job state.
5. The controller uses Eloquent and a transaction/idempotency mechanism where applicable to record the payment and update related totals safely.
6. Laravel serializes the result as JSON.
7. Vue updates the page or presents a validation/server error.

This request-response cycle also applies to appointments, parts, inventory, reports, and most administrative operations.

---

# Chapter 3 — System Architecture

## 3.1 Overall architecture

RepairSync uses a browser-based client, Laravel application server, relational database, filesystem storage, and optional external mail/SMS providers. It is a conventional web application with a Vue SPA frontend and Laravel JSON API—not a microservice architecture in the inspected source.

```text
┌─────────────────────────────────────────────────────────────┐
│ Browser: customer, shop user, technician, platform admin     │
│ Vue 3 SPA pages + JavaScript + Tailwind CSS                  │
└───────────────────────────┬─────────────────────────────────┘
                            │ HTTPS in production (recommended)
                            │ Axios JSON + session cookie + CSRF
                            v
┌─────────────────────────────────────────────────────────────┐
│ Laravel 12 application                                       │
│ Web/API routes → middleware → controllers → services/models  │
│ Validation, roles, shop checks, transactions, JSON responses│
└──────────┬─────────────────────────┬────────────────────────┘
           │                         │
           v                         v
┌─────────────────────┐     ┌────────────────────────┐
│ MySQL or SQLite     │     │ File storage           │
│ Eloquent + migrations│     │ private/local/public/S3│
└─────────────────────┘     └────────────────────────┘
           │                         ^
           v                         │ scoped file handlers
┌─────────────────────────────┐      │
│ Laravel queue backend       │──────┘
│ database by default/config  │
└──────────────┬──────────────┘
               v
       ┌───────────────────┐
       │ Mail / Twilio SMS │
       │ external services │
       └───────────────────┘
```

In the diagram, “database by default/config” means the queue connection is configurable and defaults to `database` in `config/queue.php`; `.env` can override it. The diagram does not confirm that a worker is running.

## 3.2 Frontend and SPA

The Blade view `resources/views/app.blade.php` supplies metadata, CSRF token, the `#app` mount point, and Vite entry. `resources/js/app.js` imports CSS/bootstrap and mounts `resources/js/spa/App.vue`.

The SPA uses a custom route resolver and browser history in `resources/js/spa/routes.js` and `navigation.js`; it is not configured with Vue Router. `App.vue` loads page components, and page components call APIs directly through the global Axios instance or shared auth utilities.

Layouts group user experiences:

- `resources/js/spa/layouts/AdminLayout.vue`
- `resources/js/spa/layouts/TechnicianLayout.vue`
- `resources/js/spa/layouts/PlatformLayout.vue`

## 3.3 Backend architecture

- **Routes** map HTTP methods and paths to controller methods.
- **Middleware** establishes session behavior, authentication, and active account/shop checks.
- **Controllers** validate requests, enforce roles and shop scoping, apply business rules, and return JSON or files.
- **Models** map PHP objects to tables and define relationships.
- **Services/support classes** centralize functions such as notification delivery and audit logging.
- **Jobs** run deferred work such as customer notifications when the queue is configured asynchronously.
- **Migrations** define schema changes over time.

## 3.4 API communication

API routes are declared in `routes/api.php`. The route file wraps requests in Laravel’s `web` middleware, so the browser uses session cookies and CSRF protection. This is a same-origin session-authenticated API, not a stateless OAuth/JWT API.

Axios setup in `resources/js/bootstrap.js`:

- Sets `X-Requested-With` and JSON `Accept` headers.
- Enables credentials for same-origin session requests.
- Adds the CSRF token from the Blade document metadata.

Public routes include onboarding, shop profiles, appointments, and tracking. Authenticated route groups include `/api/admin`, `/api/platform`, and `/api/technician`.

## 3.5 Authentication and authorization

`config/auth.php` configures the `web` guard with Laravel’s session driver and the Eloquent `User` model. `AuthController` handles login/logout, current-user lookup, and password change. `EnsureActiveUser` rechecks the account and shop and logs out blocked users.

Authentication answers “who is signed in?” Authorization answers “what may that user do?” Authorization is applied by controller helpers and action-specific checks. Technician portal methods additionally resolve the technician profile and verify job assignment. Frontend navigation visibility is not the security boundary.

## 3.6 Database architecture

Eloquent models correspond to relational tables. Primary keys are Laravel-style `id` keys; foreign keys connect shops, users, jobs, technicians, appointments, parts, payments, histories, and other records. The migration history shows a transition from core repair records to shop tenancy and later business modules.

`config/database.php` supports SQLite, MySQL, MariaDB, PostgreSQL, and SQL Server drivers, but only SQLite and MySQL are evidenced in the default/test/production examples reviewed here. The production environment example selects MySQL; the base config defaults to SQLite.

## 3.7 Multi-tenant architecture

Each shop is a tenant/workspace identified by a `shops` row and slug. Core records such as users, technicians, jobs, inventory, and audit logs gained `shop_id`; child records also gained shop associations in later migrations.

This is a **shared database, shared schema** design in the source: multiple shop records are stored in the same tables, separated by `shop_id`. The reviewed source does not establish a separate database per shop. Controllers generally filter by the current shop and check related records.

There is no universal model-level global tenant scope in the inspected models. This makes consistent controller filtering, relationship validation, and cross-tenant tests important.

## 3.8 File storage

`config/filesystems.php` defines:

- `local`, rooted at `storage/app/private`.
- `public`, rooted at `storage/app/public` and exposed through `/storage`.
- `s3`, configured through AWS-compatible environment settings.

Repair photos and subscription proofs use scoped application handlers for access; appointment photos have public confirmation-code lookup and authenticated staff routes. Shop logos use public asset URLs. S3 is configured as an available disk option, not proven to be used in a running deployment.

## 3.9 Notifications and queues

`CustomerNotifier` and `SendCustomerNotification` implement notification creation/delivery paths using Laravel mail and Twilio SMS. Notification records include information about channels and delivery state. Queue configuration defaults to database but can be overridden. A queue worker must run for asynchronous delivery.

The code path is implemented; provider credentials, queue supervision, delivery rate, retries in production, and end-to-end notification delivery remain deployment verification tasks.

---

# Chapter 4 — User Roles and Responsibilities

## 4.1 Role model

`app/Models/User.php` defines:

- `platform_admin`
- `shop_admin`
- `admin`
- `staff`
- `technician`

`shop_admin` and `admin` are role aliases in `adminRoles()`/`expandRole()`, though they are separate stored strings. `availableRoles()` for shop user creation lists shop admin, staff, and technician; platform/admin roles are included in broader portal-role logic. No customer role is defined.

## 4.2 Role descriptions

### Platform Administrator

- **Purpose:** Operates the SaaS platform across shops.
- **Dashboard/pages:** Platform dashboard, shops, subscription requests/settings, failed jobs, and platform settings.
- **Actions:** Review shops; update shop platform details; toggle shop activation; reset shop-admin password; configure subscription options; approve/reject subscription requests; record subscription payments; inspect/retry/delete failed queue jobs.
- **Restrictions:** Platform admin is not synonymous with shop admin. Shop controllers generally expect shop association for shop operations.
- **Responsibilities:** Maintain shop access and subscription workflows; use failed-job tools carefully.
- **Evidence:** `/api/platform` route group in `routes/api.php`; `PlatformController`, `FailedJobController`; Vue pages in `resources/js/spa/pages/platform/`.

### Shop Administrator

- **Purpose:** Manages one shop’s workspace and privileged shop operations.
- **Dashboard/pages:** Shop dashboard, jobs, appointments, inventory, part requests, reports, technicians, users, audit logs, shop settings.
- **Actions:** Manage shop profile/catalog, users and technician records; access reports/audit logs; perform higher-impact repair and financial workflows where allowed.
- **Restrictions:** Cannot manage other shops or platform-wide subscriptions. Shop context is determined by the authenticated user and controller checks.
- **Responsibilities:** Configure the workspace and oversee shop operations.
- **Evidence:** `User::adminRoles()`, admin pages/layout, `/api/admin` group, `UserController`, `TechnicianController`, `ShopController`, `ReportsController`.

### Shop Staff

- **Purpose:** Performs day-to-day shop work without all administrator privileges.
- **Dashboard/pages:** Operational shop pages such as dashboard, jobs, appointments, tracking, and inventory as exposed by the UI and backend permission checks.
- **Actions:** Create/manage authorized job records, process appointments, update repair progress, record certain payments, and access inventory actions where permitted.
- **Restrictions:** Admin-only pages/actions such as broader user/technician administration, reports, and selected financial overrides are restricted. Exact access differs by action.
- **Responsibilities:** Maintain accurate job and customer-service records and escalate privileged actions.
- **Evidence:** `Controller::authorizeStaffOrAdmin()`, route group, role checks in controllers, `AdminLayout.vue`.

### Technician

- **Purpose:** Performs repair tasks on jobs assigned to a linked technician profile.
- **Dashboard/pages:** Technician dashboard, alerts, assigned job detail, customer tracking navigation.
- **Actions:** View assigned jobs; update permitted status; record repair approval; manage repair photos/customer visibility; add/remove parts; create parts requests/orders; update device location in supported workflows.
- **Restrictions:** Requires an active technician profile in the same shop; cannot access other shops’ jobs or jobs assigned to a different technician. Does not have broad shop administration.
- **Responsibilities:** Record repair progress and evidence; communicate parts needs through the system.
- **Evidence:** `TechnicianPortalController`, `Controller::findTechnician()`, `ensureAssignedToTechnician()`, `/api/technician` routes, technician Vue pages.

### Customer

- **Purpose:** Requests a service and checks progress without creating a system account.
- **Dashboard/pages:** Public landing/shop profile, booking/consultation page, confirmation lookup, public tracking.
- **Actions:** Submit appointment details and look up appointment/job status using the required shop/job/confirmation identifiers.
- **Restrictions:** Cannot access authenticated shop/platform/technician workspaces or change internal job data.
- **Responsibilities:** Supply accurate contact/device/concern information and use confirmation or tracking identifiers.
- **Evidence:** Public routes in `routes/api.php`, `AppointmentBookingPage.vue`, `TrackPage.vue`, `TrackingController`, `AppointmentController`.

## 4.3 Comparison table

| Capability | Platform admin | Shop admin | Staff | Technician | Customer |
|---|---:|---:|---:|---:|---:|
| Manage shops across platform | Yes | No | No | No | No |
| Review platform subscriptions | Yes | No | No | No | No |
| Manage shop users/settings | No (platform actions differ) | Yes | Restricted | No | No |
| Create/manage shop jobs | Not normal shop role | Yes | As authorized | Assigned-job actions | No |
| Work on assigned jobs | No | May oversee | May coordinate | Yes | No |
| Inventory and parts operations | No | Yes | Some operations | Job-linked actions | No |
| Public repair tracking | Can use public page | Can use public page | Can use public page | Can use public page | Yes |
| Submit public appointment | Can use public page | Can use public page | Can use public page | Can use public page | Yes |
| Shop reports/audit logs | No shop context by default | Yes | Restricted | No | No |
| Sign-in account | Yes | Yes | Yes | Yes | No account role found |

This is a conceptual comparison, not a complete action-by-action permission matrix. Effective permissions are set by backend methods and must be checked for each endpoint before asserting a specific access rule.

---

# Chapter 5 — Complete System Modules

The tables below summarize modules at a functional level. `shop_id` is present on many business records, but the exact nullability and foreign-key behavior varies by migration and table. Consult the specific migration before relying on a particular constraint.

## 5.1 Public landing, shop discovery, and shop profile

- **Purpose/users:** Introduce the service and let customers find a shop and its public contact/profile information.
- **How it works:** Vue public pages request the public shop list or a shop profile by slug.
- **Input/output:** Search/navigation input; public profile/catalog details in JSON and rendered view.
- **Tables:** `shops`, `service_categories`, `service_items`.
- **Rules:** Shop must be available through public listing/profile logic; active/access checks are applied in public workflows.
- **Source:** `WelcomePage.vue`, `AppointmentBookingPage.vue`, `ShopController::publicIndex()`, `publicProfile()`, `/api/public/shops`, `/api/shops/{shop:slug}/profile`.
- **Related:** Onboarding, booking, tracking.

## 5.2 Shop onboarding

- **Purpose/users:** Allow a prospective shop to create a workspace and initial owner.
- **How it works:** Check slug availability, validate registration, create shop and owner in a transaction, and create default service catalog items.
- **Input/output:** Shop name/slug/contact and owner details; newly created shop/account response.
- **Tables:** `shops`, `users`, `service_categories`, `service_items`.
- **Rules:** Slug must be available; onboarding validation and transaction rules are in `ShopOnboardingController`. Shop/account activation and trial dates should be confirmed for the chosen environment.
- **Source:** `ShopOnboardingPage.vue`, `ShopOnboardingController::checkSlug()`, `register()`.
- **Related:** Authentication, subscription, shop settings.

## 5.3 Authentication, password, and account lifecycle

- **Purpose/users:** Sign users in and manage active user accounts.
- **How it works:** Laravel session login; `AuthController` identifies accounts and returns the signed-in user. Shop admins manage shop user records; platform admin can reset shop-admin passwords.
- **Input/output:** Login identifier/password and password-change data; session and user JSON/error responses.
- **Tables:** `users`, `shops`; Laravel `sessions` and `password_reset_tokens` support tables exist in migration.
- **Rules:** Admin users use email login and staff/technicians use usernames per login implementation; inactive account/shop access is rejected. Password changes require authenticated active user.
- **Source:** `AuthController`, `UserController`, `EnsureActiveUser`, `LoginPage.vue`, `auth.js`.
- **Related:** Roles, all protected modules.

## 5.4 Platform administration and shop management

- **Purpose/users:** Provide central operations across tenant shops.
- **How it works:** Platform routes return shop/dashboard information and accept shop updates, activation toggles, password resets, subscription settings, and failed job actions.
- **Input/output:** Shop IDs, activation/settings/payment/review actions; platform summaries and result statuses.
- **Tables:** `shops`, `users`, `platform_subscription_settings`, `subscription_requests`, `platform_subscription_payments`; `failed_jobs` is a framework support table.
- **Rules:** Platform-only authorization is checked in controller actions; inactive shops affect account and public access.
- **Source:** `PlatformController`, `FailedJobController`, `/api/platform`, platform Vue pages.
- **Related:** Onboarding, subscription, queue/operations.

## 5.5 Subscription management

- **Purpose/users:** Track shop subscription plans, submitted proofs, review decisions, and platform-recorded payments/access periods.
- **How it works:** Shop selects an option/payment method and submits proof; platform admin approves/rejects pending request. Approval locks relevant records, creates payment/access record, updates shop access, and marks request approved.
- **Input/output:** Plan, payment method, reference, amount/proof; pending/approved/rejected response and access dates.
- **Tables:** `shops`, `subscription_requests`, `platform_subscription_settings`, `platform_subscription_payments`, `users` for requester/reviewer references.
- **Rules:** Only pending requests can be reviewed; approval/rejection re-checks locked state. Shop writes are restricted according to active-access/close-out behavior.
- **Source:** `SubscriptionRequestController`, `PlatformController::approveSubscriptionRequest()`, `rejectSubscriptionRequest()`, `Shop::hasActiveAccess()`, `isInCloseOutMode()`.
- **Related:** Platform administration, shop access, private file handling.
- **Limitation:** Payment recording and proof review are implemented; an online payment gateway was not verified.

## 5.6 Shop workspace, settings, and service catalog

- **Purpose/users:** Let an authorized shop administrator set shop identity, logo, operating information, warranty defaults, notification preferences, and service options.
- **How it works:** Shop API reads/updates the current user’s shop. Category and service endpoints add, edit, or deactivate catalog records.
- **Input/output:** Profile/settings/logo/catalog data; updated shop/profile/catalog.
- **Tables:** `shops`, `service_categories`, `service_items`, `audit_logs`.
- **Rules:** Records are shop-scoped; logo is public-facing; catalog deactivation is supported rather than only hard deletion.
- **Source:** `ShopController`, `ShopSettingsPage.vue`, `/api/admin/shop...`.
- **Related:** Public profile, job intake, warranty, notifications.

## 5.7 Customer consultations

- **Purpose/users:** Accept consultation requests from customers and let shop staff review, respond, cancel, or resolve them.
- **How it works:** Public consultation form submits to a shop slug route; the customer receives a confirmation code and may later view the response. Staff review the request and send a consultation reply.
- **Input/output:** Customer/contact/device/concern/history/photo; confirmation code, consultation state, and customer-facing response.
- **Tables:** `appointments`, `shops`, `users`, `audit_logs`.
- **Rules:** Public creation accepts `CONSULTATION` only. Active consultation states are `PENDING`, `CANCELLED`, and `COMPLETED`. Consultation does not create or convert into a job order; repairs use normal job intake.
- **Preservation:** The existing appointments table, its historical booking-related columns, and consultation records remain intact. Historical enum values do not mean drop-off booking remains active.
- **Source:** `AppointmentController`, `AppointmentBookingPage.vue`, `AppointmentsPage.vue`, appointment migrations.
- **Related:** Onboarding, job orders, public customer follow-up.

## 5.8 Job order management

- **Purpose/users:** Maintain the primary repair record from device intake through pickup/RTO.
- **How it works:** Staff create or update a job; the controller associates shop/customer/device/service details, initial status history, technician, and related financial/repair data.
- **Input/output:** Customer, device, issue, service, priority, estimates, contact, and assignment; job number, job status/detail, histories, linked records.
- **Tables:** `job_orders`, `shops`, `users`, `technicians`, `service_categories`, `service_items`, and children such as histories, parts, payments, photos, notifications, assessments, requests, orders, refunds.
- **Rules:** Soft deletion exists; delete restrictions apply after repair starts. Duplicate intake uses a recent-duplicate heuristic, not the same required idempotency key used for payment records.
- **Source:** `JobOrderController`, `JobOrder` model, `JobOrdersPage.vue`, job detail/edit/trash/invoice/print pages.
- **Related:** Technician, inventory, payments, tracking, warranty, reports.

## 5.9 Repair status and public tracking

- **Purpose/users:** Record repair progression and expose permitted status/timeline details to customers.
- **How it works:** Authorized shop/technician updates status; status history is recorded. Public tracking looks up a job using the shop/job identifiers and returns customer-visible information.
- **Input/output:** Status and optional note; current status, timeline, pickup/warranty/photo data as allowed.
- **Tables:** `job_orders`, `status_histories`, `shops`, `repair_photos`, `warranty_assessments`.
- **Rules:** Current statuses include `RECEIVED`, `DIAGNOSING`, `WAITING_PARTS`, `IN_PROGRESS`, `READY_FOR_PICKUP`, `PICKED_UP`, `RTO`, and `RTO_RELEASED`. The controller has terminal/dedicated transition checks but does not document one exhaustive transition matrix. Public tracking endpoints use throttling.
- **Source:** `JobOrderController::meta()`, `updateStatus()`, `TrackingController`, `TrackPage.vue`, `StatusHistory` model.
- **Related:** Notifications, parts, pickup, warranty.

## 5.10 Technician management and portal

- **Purpose/users:** Shop admin manages technician profiles; a technician works only on assigned repair jobs.
- **How it works:** Admin CRUD creates/updates/deactivates technician profiles and assignments. Technician user is linked to a profile; portal resolves it and checks shop and assignment.
- **Input/output:** Technician identity/contact/specialties/activity and job assignment; technician list, assigned jobs, repair actions.
- **Tables:** `technicians`, `users`, `job_orders`, `status_histories`, child repair/parts tables.
- **Rules:** A role alone is insufficient without an active linked profile. Cross-shop and other-technician assignment access is rejected.
- **Source:** `TechnicianController`, `TechnicianPortalController`, `Controller::findTechnician()`, `ensureAssignedToTechnician()`, technician Vue pages.
- **Related:** Job orders, shop users, part orders, photos.
- **Rating caveat:** `JobOrderController::rateTechnician()` and `/api/admin/job-orders/{jobOrder}/rate-technician` exist, and `technician_rating` is in the job model. A current customer-facing rating flow/dashboard was not verified; treat this as a partially surfaced backend capability until UI, authorization, intended actor, and business meaning are confirmed.

## 5.11 Inventory and stock movements

- **Purpose/users:** Track shop spare-part catalog, stock levels, manual adjustments, and movements.
- **How it works:** Authorized users search/add/edit/adjust stock. Stock changes create movement records; parts can be issued to jobs from inventory.
- **Input/output:** Part identifiers, quantities, costs, adjustment type/reason; stock counts and movement history.
- **Tables:** `spare_parts`, `stock_movements`, `shops`, optional `job_orders`, `users`, `job_order_parts`.
- **Rules:** Part-number uniqueness is scoped to shop by migration; job/shop association must be validated. Inventory and job issue actions use guarded/transactional operations in applicable paths.
- **Source:** `InventoryController`, `SparePart`, `StockMovement`, `InventoryPage.vue`, `InventoryController` routes.
- **Related:** Shop requests, job part orders, technician parts.

## 5.12 Shop part requests, blocked-job requests, and job-specific orders

These are three related but distinct workflows:

1. **Shop part request:** General procurement/restock request, optionally associated with a job. Staff reviews status and receives stock into inventory.
2. **Blocked-job parts request:** Technician reports a job blocked by missing parts; shop may fulfill using existing inventory.
3. **Job-specific part order:** A part needed for a specific repair, with customer decision/downpayment, supplier order, receipt, and issue-to-job steps.

- **Tables:** `part_requests`, `job_order_part_orders`, `job_orders`, `spare_parts`, `stock_movements`, `job_order_parts`, `shops`, `users`, `technicians`.
- **Rules:** Each has separate statuses and transitions. Some request/order creation accepts optional shop-scoped idempotency keys and payload hashes. Receiving/issuing are distinct actions.
- **Source:** `PartRequestController`, `BlockedJobPartController`, `JobOrderPartOrderController`, `PartRequest`, `JobOrderPartOrder`, `PartRequestsPage.vue`, `InventoryPage.vue`.
- **Related:** Technician workflow, inventory, customer approval, job status.

## 5.13 Payments, balances, pickup, and refunds

- **Purpose/users:** Track repair charges and collections, mark pickup, and record RTO refund obligations and issuance.
- **How it works:** Payment action records ledger row and applies it to job; model exposes balance from `total_cost - amount_paid` except RTO statuses, where balance returns zero. Pickup has a dedicated endpoint; refund obligations and payment records are separate.
- **Input/output:** Amount, method/type/reference/notes, pickup person, override reason where applicable, refund details; updated ledger, status, balance, and history.
- **Tables:** `job_order_payments`, `job_order_refunds`, `job_orders`, `status_histories`, `shops`, `users`, `audit_logs`.
- **Rules:** Payment recording uses an idempotency key; replay behavior checks intent. Pickup requires `READY_FOR_PICKUP`. Unpaid-balance override requires an administrator and reason. Refund issuance is a separate action.
- **Source:** `JobOrderController::recordPayment()`, `markPickedUp()`, RTO/refund methods; `JobOrder` balance accessor; payment/refund migrations.
- **Related:** Job completion, reports, audit logs.

## 5.14 Warranty and warranty-service work

- **Purpose/users:** Record coverage period and assess customer-reported repair issues.
- **How it works:** A job stores warranty days/details. Warranty status is derived from pickup date and duration; assessments can record an outcome and may link to a warranty-service job.
- **Input/output:** Warranty terms, issue description, assessment result/notes; active/expired calculation and assessment record.
- **Tables:** `job_orders`, `warranty_assessments`, `shops`, `users`, status history.
- **Rules:** Model `isUnderWarranty()` requires status `PICKED_UP`, pickup timestamp, and current time within the defined period. Warranty service may be issued/cancelled through dedicated actions.
- **Source:** `JobOrder`, `WarrantyAssessment`, `JobOrderController` warranty methods, warranty migration.
- **Related:** Job orders, customer tracking, reports.

## 5.15 Repair and appointment photos

- **Purpose/users:** Store visual evidence of devices and repairs for shop use and, when permitted, customer viewing.
- **How it works:** Uploaded images are validated and saved; database records store path and metadata; file endpoints check ownership/visibility or confirmation code.
- **Input/output:** Image upload, type, visibility; protected or public-confirmation image response.
- **Tables:** `repair_photos`, `appointments`, `job_orders`, `shops`, `users`.
- **Rules:** Repair photo customer visibility can be toggled. Appointment photos have a public route tied to matching shop and confirmation code plus staff-only route. Storage path/disk matters.
- **Source:** `RepairPhotoController`, `JobOrderController::storePhoto()`, `TechnicianPortalController`, `AppointmentController`, `AppointmentPhotoPrivacyTest`.
- **Related:** Job records, appointment workflow, tracking.

## 5.16 Customer notifications

- **Purpose/users:** Track communication caused by repair events and deliver through email/SMS where configured.
- **How it works:** `CustomerNotifier` creates/dispatches channel notifications; `SendCustomerNotification` performs queued delivery and updates delivery outcome. Shop settings control enabled notification events/channels.
- **Input/output:** Customer contact, trigger, channel, message; queued/sent/failed notification state.
- **Tables:** `customer_notifications`, `shops`, optional `job_orders`, queue support tables (`jobs`, `failed_jobs`).
- **Rules:** Queue and provider configuration are required for delivery. Retry behavior and notification retry endpoint exist; a record of a notification is not proof it reached the customer.
- **Source:** `CustomerNotifier`, `SendCustomerNotification`, `CustomerNotification`, shop settings, queue/mail/service config.
- **Related:** Status updates, booking, pickup, tracking.

## 5.17 Dashboards, alerts, and reports

- **Purpose/users:** Give shop/platform users summaries and timely operational counts.
- **How it works:** Controllers aggregate job/payment/repair data; Vue layouts refresh alerts; report endpoint returns filtered data and supports CSV/print-oriented output.
- **Input/output:** Date/status/service/payment filters; dashboard KPIs, breakdowns, reports, downloadable CSV or print view.
- **Tables:** Primarily `job_orders`, `job_order_payments`, `job_order_refunds`, service catalog, technicians, appointments.
- **Rules:** Shop reports are administrator-restricted. CSV data neutralizes formula-like cell values, covered by a test.
- **Source:** `DashboardController`, `ReportsController`, `ReportsPage.vue`, `resources/views/reports/export.blade.php`.
- **PDF note:** `ReportsController::exportPdf()` returns `response()->view('reports.export', $report)`. The current response is a rendered HTML report, not a generated PDF file. A user may print the view from the browser, but that is different from server-side PDF generation.

## 5.18 Audit logs

- **Purpose/users:** Preserve an operational trail of selected changes and support shop review.
- **How it works:** Explicit mutations call `AuditLogger`, which records actor/shop, action, record, before/after fields, request IP/user agent where available. Shop users can query shop audit logs.
- **Tables:** `audit_logs`, `shops`, `users`.
- **Rules:** Logging is explicit at call sites, not established as automatic middleware for every write. Logs are not shown to be immutable/tamper-proof.
- **Source:** `AuditLogger`, `AuditLogController`, `AuditLog`, `AuditLogsPage.vue`.
- **Important gap:** Platform audit-log page is a frontend placeholder with no API request; it is not a platform-wide event feed.

## 5.19 User and technician administration

- **Purpose/users:** Manage shop accounts and technician profiles.
- **How it works:** Shop users can be listed/created/edited/activated/deactivated/reset/deleted according to action-level role checks; technician profiles have their own CRUD and active status.
- **Tables:** `users`, `technicians`, `shops`.
- **Rules:** Shop scoping and role restrictions apply. Email/username uniqueness varies by role and migration; technician email uniqueness was changed to be shop-scoped, while user identifiers have their own constraints.
- **Source:** `UserController`, `TechnicianController`, `UsersPage.vue`, technician admin pages, user/account migrations.
- **Related:** Authentication, technician portal, platform password reset.

## 5.20 Failed queue jobs and operational commands

- **Purpose/users:** Let platform admin inspect/retry/delete failed queue jobs; provide guarded maintenance/audit commands.
- **How it works:** `FailedJobController` accesses framework failed-job records. Console commands audit subscription proofs or perform guarded legacy-proof migration.
- **Tables/files:** `failed_jobs`, storage paths, command arguments/configuration.
- **Rules:** Commands may have environment/confirmation guards. Read each command before execution; some are intentionally state-changing and are outside a read-only audit.
- **Source:** `FailedJobController`, `AuditSubscriptionProofs`, `MigrateLegacySubscriptionProofs`, `routes/console.php`.

---

# Chapter 6 — Complete Business Process

## 6.1 Lifecycle overview

```text
Platform provisions shop access
            |
            v
Shop configures workspace/catalog/users
            |
      +-----+---------------------+
      |                           |
Customer requests consultation  Staff creates job directly
      |                           |
      v                           v
Staff reviews and replies       Job order: RECEIVED
                  |
        Assign technician
                  |
     Diagnose / approval / parts
                  |
      Repair + update history
                  |
       Costs and payments
                  |
           +------+------+
           |             |
    Ready for pickup    RTO/refund path
           |             |
        PICKED_UP     RTO_RELEASED
           +------ customer tracking
                  |
            Reports/audit
```

The system supports multiple valid entry paths: a customer may book first, or staff may directly create a job.

## 6.2 Shop onboarding

1. Prospective shop enters shop and owner details on the onboarding page.
2. The frontend may request a slug availability check.
3. The backend validates the request and creates the shop and owner inside a transaction.
4. Default service categories/items are created.
5. The owner signs in and configures shop settings and staff.

**Expected result:** A shop workspace, owner account, and starter catalog exist. Verify the actual access/trial dates and seed conditions in the environment rather than assuming a specific production setup.

## 6.3 Platform activation and subscription review

1. Platform admin signs in and views shops/subscription requests.
2. Shop submits a subscription request with plan, method/reference, and proof as supported.
3. Platform admin opens the pending request and reviews its submitted details.
4. Approval or rejection is allowed only while the request is pending.
5. Approval records a subscription payment and updates shop access; rejection records the decision.

**Expected result:** Subscription state and shop access dates are updated as represented in the database. This is not proof that payment was captured through a gateway.

## 6.4 Shop workspace setup

1. Shop admin updates profile, contact details, logo, business hours, warranty defaults, and notification settings.
2. Shop admin configures service categories and service items.
3. Admin creates shop user accounts and technician profiles.
4. Technician accounts are linked to active technician profiles.

**Expected result:** Shop data is ready for consultation requests and job intake.

## 6.5 Consultation request

1. Customer opens a public consultation page for a shop.
2. Customer provides contact details, device category, issue history, and optional photo.
3. The backend validates the shop and consultation data, saves a consultation, and returns a confirmation code.
4. Customer can later query consultation state and any shop response using shop and confirmation details.
5. Shop staff review the request and may reply, cancel, or mark it resolved.

**Rules:** Public creation accepts `CONSULTATION`; `DROP_OFF` is rejected. The appointments table retains historical scheduling columns and status values for data preservation, but consultation does not require or set a preferred time.

## 6.6 Job order creation

1. Staff opens “New Job Order” through the normal job-intake workflow.
2. Staff records customer/contact, device, issue, service selection, and other intake fields.
3. The backend validates fields and shop/service relationships.
4. A job order is created with a job number and an initial status/history record.
5. An initial payment may be recorded when applicable.

**Rules:** Job orders are created through this normal intake workflow. Consultation requests do not create or convert into job orders. Direct job intake includes a recent-duplicate heuristic; it is not the same as a required idempotency key.

## 6.7 Technician assignment and diagnosis

1. Shop admin/staff assigns an active technician profile from the same shop.
2. Technician signs in and sees assigned jobs.
3. Technician records diagnosis/progress, customer repair approval, and other allowed job data.
4. Status history records changes.

**Expected result:** The job shows the responsible technician and activity. Technician access is validated by profile, shop, and job assignment.

## 6.8 Repair and parts

1. Technician records the work and required parts.
2. If stock exists, the applicable workflow issues parts and creates stock movements/job part records.
3. If parts must be purchased, users choose the relevant flow:
   - General shop purchasing request.
   - Blocked-job part request.
   - Job-specific part order with customer decision/downpayment steps.
4. A user with required authorization records order placement and receipt.
5. Received inventory is recorded; issued parts may be attached to a job.
6. Repair continues and job status is updated.

These workflows are separate. Receiving a part is a stock operation, not automatically the same as completing a repair.

## 6.9 Costs and customer payments

1. Shop staff updates estimated/actual costs where allowed.
2. Staff records each received customer payment.
3. The payment record includes an idempotency key to prevent duplicate recording on retries.
4. The job reports paid amount/payment status and computed balance.

**Example:** For total cost 1,500 and amount paid 800, the model balance is 700 while the job is in a normal repair state. For `RTO` or `RTO_RELEASED`, the balance accessor returns zero; refund handling is represented separately.

## 6.10 Completion, pickup, and notification

1. Authorized staff/technician moves the repair to `READY_FOR_PICKUP`.
2. Shop communicates with the customer using the configured notification path or another channel.
3. At collection, staff uses the dedicated pickup action and records who collected.
4. Pickup requires `READY_FOR_PICKUP`.
5. If balance remains unpaid, the designated admin override and reason are required.
6. System records pickup timestamp, status history, and any configured notification.

Notification history is not proof of actual external delivery; verify provider/worker status.

## 6.11 Public repair tracking

1. Customer opens public tracking.
2. Customer provides required shop/job information or uses a shop-scoped route.
3. Backend applies lookup and visibility restrictions.
4. Customer sees current status, timeline, and permitted information/photos.

**Rules:** Tracking endpoints are rate-limited. Public tracking is not an authenticated customer account.

## 6.12 Warranty and refund processes

### Warranty

1. Shop configures default warranty terms; a job can hold warranty days/details.
2. After pickup, the system can determine whether the job is within its warranty period.
3. Shop records a warranty assessment, decision, and optional service job.
4. Warranty actions are separate from ordinary repair status changes.

The model’s active warranty calculation requires a `PICKED_UP` status and timestamp and compares current time with pickup plus warranty days.

### RTO and refunds

1. Shop transitions a job through the RTO path using dedicated actions.
2. Refund obligations may be prepared/reconciled.
3. Staff issues a refund using a separate refund action and records details.
4. RTO release is a separate terminal action.

Do not treat “unrepairable” as a current status; a migration consolidates it into RTO-related status handling.

## 6.13 Reports and audit logs

1. Authorized shop admin chooses date/status/service/payment filters.
2. Report controller calculates shop data and returns JSON.
3. CSV export writes selected summary/detail values; CSV formula prefixes are sanitized.
4. Shop audit log shows recorded mutation events.
5. Platform failed-job page handles queue failures separately.

Platform audit logs are not an equivalent feed: the platform page is currently a placeholder.

## 6.14 Subscription expiration and shop deactivation

- `Shop::hasActiveAccess()` requires an active shop and a future access/trial end date.
- `Shop::isInCloseOutMode()` represents an active shop whose access date has passed.
- Helpers allow restricted behavior for shops in close-out mode while blocking some new work (for example new intake).
- Platform admin can toggle a shop active/inactive.
- `EnsureActiveUser` rejects inactive user/shop access and ends the session.

Exact allowed reads/writes during close-out depend on the controller/action and should be demonstrated only after verification in the target environment.

---

# Chapter 7 — Database Design

## 7.1 General relational design

The schema is relational: tables store entities and use primary/foreign keys to connect them. Most tables use an auto-incrementing `id` primary key from Laravel’s `$table->id()`. Foreign keys commonly use Laravel `foreignId()` and point to related `id` columns. The exact null/delete behavior is migration-specific; for example, early shop tenant columns were nullable and used `nullOnDelete`, while later child records may be required.

The database is shared by shops. Tenant association is commonly represented by `shop_id`, and controllers use it to scope access. Shop-scoped data isolation is not supplied automatically by a global Eloquent scope.

## 7.2 Business table inventory

The migrations/models define the following 22 business tables. This list excludes Laravel framework-support tables listed later.

| Table | Model | Purpose and important associations |
|---|---|---|
| `users` | `User` | Platform/shop accounts; role, active status, username/email, optional `shop_id` and technician link. |
| `shops` | `Shop` | Tenant/workspace; unique slug, name/contact/logo, plan/access dates, active flag, later settings. |
| `job_orders` | `JobOrder` | Core repair record; customer/device/service, status, costs/payments, technician, warranty, pickup/RTO, soft deletion. |
| `status_histories` | `StatusHistory` | Job status and notes over time; belongs to job and shop. |
| `technicians` | `Technician` | Shop technician profile, contact, specialties, active state; assigned to jobs and linked to user optionally. |
| `service_categories` | `ServiceCategory` | Shop-specific service category; parent for service items. |
| `service_items` | `ServiceItem` | Shop/category-specific selectable service. |
| `spare_parts` | `SparePart` | Shop inventory catalog, part number, stock and reorder/cost data. |
| `stock_movements` | `StockMovement` | Stock receipt, issue, or adjustment history, associated with shop/part and optionally job. |
| `job_order_parts` | `JobOrderPart` | Part usage associated with a job and shop; may reference inventory part. |
| `job_order_payments` | `JobOrderPayment` | Payment ledger entries associated with jobs/shops; idempotency key. |
| `job_order_refunds` | `JobOrderRefund` | Refund obligation/issuance records for job financial flows. |
| `job_order_part_orders` | `JobOrderPartOrder` | Job-specific part procurement, decision/downpayment, ordered/received/issued state. |
| `part_requests` | `PartRequest` | Shop purchasing/restock or job-associated request; request/receipt state and optional idempotency fields. |
| `appointments` | `Appointment` | Public consultation requests, confirmation code, customer response, status, and photo; schema retains historical booking fields. |
| `repair_photos` | `RepairPhoto` | Repair evidence, path/type/metadata/uploader/customer visibility. |
| `customer_notifications` | `CustomerNotification` | Channel, recipient, message, trigger, delivery status/timestamps for repair communication. |
| `warranty_assessments` | `WarrantyAssessment` | Warranty issue and decision, notes, optional related warranty-service job. |
| `audit_logs` | `AuditLog` | Actor, shop, action/module/record, before/after values and request metadata. |
| `platform_subscription_settings` | `PlatformSubscriptionSetting` | Platform plan/payment configuration. |
| `platform_subscription_payments` | `PlatformSubscriptionPayment` | Recorded subscription payments/access periods associated with shop. |
| `subscription_requests` | `SubscriptionRequest` | Shop request/proof/review status, plan/payment details, requester/reviewer references. |

Migration evidence includes:

- Core jobs/history: `database/migrations/2025_12_19_000001_create_job_orders_table.php`, `2025_12_19_000002_create_status_histories_table.php`.
- Shops/tenant fields: `2026_05_08_000001_create_shops_table.php`, `2026_05_08_000002_add_shop_id_to_core_tables.php`, `2026_05_09_000001_add_shop_id_to_child_tables.php`.
- Inventory and payment: `2026_03_27_000001_create_spare_parts_table.php`, `2026_03_27_000002_create_stock_movements_table.php`, `2026_03_27_000004_create_job_order_payments_table.php`.
- Appointments/catalog/warranty: migrations beginning `2026_05_09_000004_...` and `2026_07_28_000001_...` through `000004_...`.
- Subscription: `2026_07_28_000005_create_platform_subscription_payments_table.php`, `2026_07_29_000002_create_platform_subscription_settings_table.php`, `2026_07_29_000003_create_subscription_requests_table.php`.
- Parts/refunds/idempotency: `2026_10_09_000001_create_job_order_refunds_table.php`, `2026_10_10_000002_create_part_requests_table.php`, `2026_10_11_000001_create_job_order_part_orders_table.php`, `2026_09_23_000001_add_idempotency_key_to_job_order_payments_table.php`, `2026_10_26_000001_add_idempotency_to_part_creation_tables.php`.

## 7.3 Framework support tables

These are Laravel operational tables rather than core repair-domain records:

- `migrations` — Laravel tracks applied migration filenames.
- `sessions` — database session driver support when configured.
- `password_reset_tokens` — reset-token support table; a recovery UI/flow was not verified.
- `cache`, `cache_locks` — database cache support.
- `jobs`, `job_batches`, `failed_jobs` — queue execution, batch, and failed-job support.

## 7.4 Relationships

```text
Shop
 ├── Users
 ├── Technicians
 ├── Job Orders
 │    ├── Status Histories
 │    ├── Parts / Part Requests / Part Orders
 │    ├── Payments / Refunds
 │    ├── Repair Photos / Notifications
 │    ├── Warranty Assessments
 │    └── Warranty-service Job Orders (self-relation)
 ├── Consultations (stored in `appointments`; no job conversion)
 ├── Spare Parts ──────── Stock Movements
 ├── Service Categories ─ Service Items ─ Job Orders
 ├── Audit Logs
 └── Subscription Requests / Subscription Payments
```

Examples from model code:

- `Shop` has many users, technicians, jobs, inventory, appointments, service catalog records, subscription records, and audit logs.
- `JobOrder` belongs to shop, technician, service category/item, and optional parent warranty job. It has many histories, parts, payments, refunds, photos, notifications, warranty assessments, part requests, and part orders.
- `Technician` has many jobs; `User` belongs to an optional shop and technician.
- `Appointment` may link to a converted job.
- `SparePart` relates to stock movement/job part records.

Check `app/Models/Shop.php`, `JobOrder.php`, `User.php`, `Technician.php`, and the corresponding child models for exact relationship method names.

## 7.5 Shop-level association and constraints

The multi-shop migration added `shop_id` to core tables and backfilled legacy records to a default shop. A later migration adds `shop_id` to child records and backfills from related jobs or parts where supported. Some uniqueness constraints are global; others were changed to shop-scoped, including technician email and spare-part number.

Do not assume every human-facing identifier is unique only within a shop. Check the current migration and controller query for the specific identifier.

## 7.6 Schema history notes

- `repair_photos` was created, later dropped, and subsequently recreated with the newer evidence/visibility schema.
- Later migration consolidated `UNREPAIRABLE` into RTO handling.
- Additive migrations preserve a history of evolving requirements; the current schema is the result of applying them in order. This audit did not execute migrations or inspect a live schema.

---

# Chapter 8 — Security and Data Protection

## 8.1 Authentication

Laravel `web` session guard authenticates users. The Vue client sends the session cookie and CSRF token. Passwords are handled using Laravel’s hashed cast on the `User` model. Login is rate-limited using a normalized login identifier and IP-derived key.

**Not established:** Multi-factor authentication, external identity provider/SSO, account email verification policy, production session encryption state, or actual password-reset user flow.

## 8.2 Authorization and roles

Routes for shop, platform, and technician functions require authenticated active users. Controller helpers check roles, shop relationship, active access, and technician assignment as applicable. API controllers remain the actual security boundary; frontend route visibility does not protect data.

**Limitation:** There is no single declarative permission matrix or universal policy observed in the inspected code. Authorization checks are distributed among controllers and helpers. Audit and testing should cover each role/action pair.

## 8.3 Tenant isolation

Controllers commonly scope records using the authenticated user’s shop ID and validate related records. Cross-shop child-record tests exist. However, no global Eloquent tenant scope was established. A newly added controller query must therefore remember to scope by shop and validate that related job/part/user records belong to the same shop.

## 8.4 Sessions and CSRF

API routes are grouped under the `web` middleware, and Axios sends CSRF headers and credentials. This protects session-authenticated state-changing requests against common cross-site request forgery patterns when deployment origin/cookie settings are correct.

Production environment example recommends encrypted and secure cookies. Verify the effective environment configuration; source defaults alone do not prove these flags are enabled in production.

## 8.5 Input validation

Controllers validate identifiers, statuses, amounts, dates, roles, and uploads. Frontend validation improves usability but must not be treated as server-side security. Laravel validation remains authoritative.

## 8.6 Rate limiting

- Login limiter: five attempts per minute for normalized identifier/IP key, defined in `AppServiceProvider`.
- Public tracking and selected lookups/photos: route throttles of 30 per minute.
- Not every public route is visibly assigned a throttle; public appointment submission rate limiting should be verified before high-volume production use.

## 8.7 File access and upload handling

Repair photos and subscription proofs are validated and retrieved through application handlers with shop/job/visibility/path checks. Appointment photo access includes confirmation-code and staff-scoped routes. Shop logos are served as public branding assets.

Security depends on actual configured disk and deployment web-server behavior. The filesystem config includes both private local and public disks, while the production example currently sets `FILESYSTEM_DISK=public`. Verify upload code uses the intended explicit disk and that private files cannot be served directly.

## 8.8 Audit logging

`AuditLogger` records selected actions and can include actor, shop, record, before/after values, IP, and user agent. Controllers call it at relevant mutations.

Limitations:

- Logging is call-site based and not established as automatic for every write.
- The source does not show tamper-evident or immutable storage controls.
- The platform audit page is a placeholder and does not consume an audit API feed.

## 8.9 Transactions and concurrency

Sensitive operations use database transactions and row-level locking in relevant paths, including payment, pickup/RTO, stock operations, and subscription review. The separate MySQL concurrency suite tests concurrent finalization scenarios.

Not every operation is automatically atomic across database and filesystem. A failure between file storage and database write/delete can leave an orphaned file or record.

## 8.10 Duplicate request protection

- Job payments require an idempotency key and validate same-key replays against request intent.
- Part requests and job-specific part orders support optional shop-scoped idempotency key/payload hash.
- Job intake uses a recent-duplicate heuristic; it does not establish exactly-once creation under arbitrary concurrent retries.
- Subscription transitions use locks/state checks to prevent duplicate finalization in relevant paths.

## 8.11 Error handling and data protection recommendations

API exceptions return JSON-friendly messages for relevant errors; server-side errors are hidden behind a generic message. Operational logs still need review by authorized operators.

Recommendations—not claims about current implementation:

- Use TLS, least-privilege database/storage credentials, encrypted backups, and tested restore procedures.
- Keep secrets outside Git and restrict production environment-file access.
- Define retention/access policies for customer contact information, repair photos, payment references, audit logs, and subscription proofs.
- Validate rate limits for all public write endpoints and monitor abuse.
- Add cross-tenant negative tests to every sensitive API family.

---

# Chapter 9 — Testing and Quality Assurance

## 9.1 Framework and organization

The project uses PHPUnit 11.5.44 as locked. Default test suites are defined in `phpunit.xml`:

- `tests/Unit`
- `tests/Feature`

MySQL concurrency tests live separately under `tests/MySql` and use `phpunit.mysql.xml`. They are not included in the default test suite.

## 9.2 Static source counts

A static count of named PHPUnit test methods in the inspected tree found:

| Suite | Test files | Declared methods |
|---|---:|---:|
| Feature | 29 | 281 |
| Unit | 1 | 1 |
| MySQL concurrency | 1 | 10 |
| **Total** | **31** | **292** |

This is a count of declarations, not executed tests, assertions, coverage, or successful results. Tests may use data providers or helper methods that affect test-case counts at runtime.

## 9.3 Important tested scenarios

Test files cover topics including:

- Active and inactive account access.
- Username authentication and login rate limits.
- Public consultation creation, confirmation, and replies.
- Appointment photo privacy.
- Job-order safety and tenant isolation for job parts.
- Payment transaction safety and financial integrity.
- Part request and job part-order workflow.
- Repair-photo evidence and subscription-proof privacy.
- Warranty assessment workflow.
- CSV formula injection neutralization.
- Failed-job authorization.
- Customer notification history.
- Platform production safety.
- MySQL concurrency for simultaneous pickup, subscription approval, RTO, and related locking scenarios.

Representative evidence: `tests/Feature/LoginRateLimitTest.php`, `AppointmentConversionSafetyTest.php`, `JobOrderPartTenantIsolationTest.php`, `PaymentTransactionSafetyTest.php`, `PartRequestWorkflowTest.php`, `SubscriptionProofPrivacyTest.php`, `ReportCsvFormulaInjectionTest.php`, and `tests/MySql/WorkflowConcurrencyTest.php`.

## 9.4 Test environment

Default `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. The separate MySQL configuration targets a dedicated test database named `repair_tracking_test`, and its refresh command is explicitly destructive to that test database. It must not be pointed at application or production data.

This documentation did not run any tests. No pass/fail statement or code coverage percentage is claimed.

## 9.5 Coverage limitations

- No coverage report was generated in this audit.
- Not every route/role/data combination is proven covered.
- Production integrations (real SMTP, Twilio, storage provider, queue worker, hosting) need environment-level integration tests.
- Model/controller-level tenant isolation deserves comprehensive cross-shop tests for every data family.
- Default SQLite feature tests do not substitute for MySQL-specific transaction/concurrency verification.

---

# Chapter 10 — Unique Features and Advantages

“Unique” here means useful and distinctive within this project’s combination of modules. It does not mean no other repair system has similar features.

| Strength | What it does | Problem it solves | Manual-workflow comparison |
|---|---|---|---|
| **1. Shop-scoped SaaS workspaces** | Stores shop-owned records with `shop_id` and shop-specific settings/catalog. | Separates different shops’ operations in one application. | Replaces separate folders/spreadsheets with a central workspace, while requiring reliable tenant checks. |
| **2. Consultation follow-up** | Customers submit a consultation and receive a reply without a customer account. | Gives customers a way to ask for advice before using the normal repair intake process. | Keeps advice requests separate from job creation; repairs proceed through standard job intake. |
| **3. Assigned technician portal** | Technicians see and update jobs assigned to their profile. | Reduces reliance on broad shared admin access and makes assignment visible. | Instead of verbal handoffs or a whiteboard, technicians work from assigned digital records. |
| **4. Multiple parts and procurement workflows** | Tracks inventory changes, shop requests, blocked jobs, job-specific orders, receipt, and issue. | Makes stock availability and missing-parts dependencies easier to follow. | Replaces informal parts notes with separate request, receipt, and usage records. |
| **5. Financial ledger plus RTO refund records** | Records job payment entries and separate refund obligations/issuance. | Improves traceability of partial payments and refunds. | Provides more context than a single handwritten “paid” field. |
| **6. Public tracking with repair evidence controls** | Customers can check status/timeline and view photos marked customer-visible. | Gives customers a self-service status path without customer accounts. | Reduces repeated manual status inquiries while controlling which images are shared. |
| **7. Multi-level operations and subscription administration** | Platform admin manages shops and subscription requests separately from shop operations. | Supports a SaaS operator’s oversight and shop access decisions. | Separates service-provider administration from each repair shop’s daily work. |
| **8. Payment retry and concurrency safeguards** | Idempotency and locking protect selected sensitive actions from duplicate requests/races. | Helps prevent duplicate payment records or repeated finalization under retries/concurrent requests. | Adds safeguards that manual processes cannot enforce consistently, though it still requires testing. |

Avoid claiming proven efficiency or customer-satisfaction improvements until measured in a real pilot or user study.

---

# Chapter 11 — Implementation and Deployment

## 11.1 Local development environment

The repository defines a Composer `dev` script that runs Laravel’s development server, queue listener, log tail, and Vite concurrently. This is a development convenience, not production process supervision.

Relevant setup/build commands are described in `composer.json`, but the setup script includes migrations and must only be run in an intended local environment. This documentation does not execute them.

Common components:

- PHP and Composer for Laravel.
- Node.js and npm for Vue/Vite dependencies.
- A configured database (base config defaults to SQLite; MySQL is available).
- `.env` for local settings and `APP_KEY`.
- Vite development server or production-built assets.

## 11.2 Configuration

Important settings include:

- `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY`.
- `DB_CONNECTION` and connection credentials.
- `SESSION_DRIVER`, `SESSION_ENCRYPT`, cookie settings.
- `QUEUE_CONNECTION`.
- `FILESYSTEM_DISK`.
- `MAIL_*`, `TWILIO_*`.
- Platform administrator seed values and demo-data flag.

Never copy actual credentials into documentation or source control. `.env.example` is a development template; `.env.production.example` is a deployment template and must be completed securely.

## 11.3 Database options

- SQLite is the base fallback and used by default test configuration.
- MySQL is configured and selected by the production example.
- MySQL concurrency tests use a separate dedicated test database.

Production database selection, backups, schema state, and MySQL version are not verifiable from repository files alone. Do not infer the live database from local defaults.

## 11.4 Hosting and deployment architecture

The repository’s `DEPLOYMENT_RUNBOOK.md` describes a generic production setup using MySQL, database-backed queue, SMTP, optional Twilio, a queue worker, and a Linux/Supervisor example. It does not identify a confirmed cloud provider, web server, container platform, or production URL.

Deployment recommendations:

1. Use a supported PHP runtime with required PHP extensions and Composer dependencies.
2. Build production frontend assets with npm/Vite.
3. Configure `APP_DEBUG=false`, strong unique `APP_KEY`, HTTPS, secure cookies, and least-privilege DB credentials.
4. Apply migrations through a reviewed release procedure and backed-up production database.
5. Configure a persistent queue backend and supervised queue worker.
6. Configure private photo/proof storage and public logo storage intentionally.
7. Set up logs, monitoring, backups, and tested restore.
8. Verify mail/SMS end-to-end using test recipients before customer-facing use.

These are deployment recommendations, not evidence that these protections are active in a particular environment.

## 11.5 External services

- **Mail:** Laravel mail transports (SMTP and other supported transports) are configurable. The default local configuration may log mail instead of sending it.
- **Twilio SMS:** SDK and service code exist; credentials/from number and a working queue are required.
- **S3:** Disk configuration exists; actual bucket usage is unverified.
- **CDN scripts/styles:** Font Awesome and QRCode.js are referenced externally by the Blade shell; browser connectivity and CDN availability matter.

## 11.6 Operations and maintenance

Platform failed-job endpoints and console commands can affect operational records/files. Read command code and confirm environment before use. `MigrateLegacySubscriptionProofs` is a separately present untracked workspace file in the inspected working tree; verify its intended review/merge status before treating it as part of the repository’s committed release.

---

# Chapter 12 — System Limitations and Future Improvements

## 12.1 Implemented and verified at source level

- Laravel 12 API and Vue 3 SPA structure.
- Session authentication and role-specific experiences.
- Shop onboarding and shop-associated business records.
- Job, appointment, technician, parts, inventory, payment, warranty, photo, notification, report, and subscription code paths.
- Public booking and tracking routes.
- PHPUnit feature/unit tests and separate MySQL concurrency tests.

“Verified at source level” means the relevant files and code paths exist; runtime behavior remains subject to testing.

## 12.2 Partially implemented or requiring confirmation

- **Technician rating:** Backend route/method and model field exist; current user-facing customer feedback workflow and dashboard are not established.
- **PDF report:** The PDF-named route returns a rendered HTML report view. Server-side PDF generation was not found.
- **Platform audit logs:** Page placeholder only; no platform audit API feed is used by the page.
- **Notifications:** Code exists; provider and queue delivery are deployment-dependent.
- **Subscription:** Request/proof/review/payment-record workflow exists; online payment processing is unverified.
- **Tenant isolation:** Explicit controller-level checks exist; no global tenant scope.
- **Storage privacy:** Protected endpoints exist, but actual production disk configuration must be checked; production example currently selects `public` as default disk.
- **Close-out access:** Shop helper/controller gates exist, but exact read/write policy varies by endpoint.
- **Parts workflows:** Several distinct paths coexist; their UX and terminology should be made clear to users.
- **Audit coverage:** Explicit logging is not a guarantee of universal coverage.

## 12.3 Not found in inspected source

- Customer account portal/password reset.
- Verified customer ratings/comments workflow.
- Integrated online subscription payment gateway.
- Shop branches/multiple physical locations.
- Automatic technician assignment/load balancing.
- Platform-wide audit feed.
- Verified production host, uptime, backup/restore, or provider delivery.

These are statements about the inspected repository, not a claim that no separate system or later branch contains them.

## 12.4 Reasonable future improvements

1. Produce and test an endpoint-by-endpoint role/permission matrix.
2. Add or enforce a central tenant-scoping strategy, while preserving platform-admin use cases.
3. Add comprehensive cross-shop tests for every table and mutation family.
4. Clarify and, if desired, unify the three parts workflows in UX and documentation.
5. Document an exhaustive job status transition matrix and validate it centrally.
6. Complete platform-wide audit feed or remove/label the placeholder explicitly.
7. Decide whether technician ratings are intended; complete the customer-provided flow or remove/rename unused backend affordances.
8. Confirm PDF export expectations and add a supported PDF implementation only if required.
9. Make sensitive upload disks explicit and ensure public web roots cannot expose repair photos/proofs.
10. Add rate limiting to public appointment creation and review all public endpoints for abuse resistance.
11. Add end-to-end checks for real queue, mail, SMS, and storage providers in a safe staging environment.
12. Establish data retention, privacy notice, backup, restore, monitoring, and incident-response procedures.
13. Keep docs versioned with source changes and identify current/obsolete guides clearly.

---

# Appendix A — Source Evidence Index

| Area | Key evidence |
|---|---|
| Backend dependencies | `composer.json`, `composer.lock` |
| Frontend dependencies | `package.json`, `package-lock.json`, `vite.config.js` |
| Application bootstrap and exceptions | `bootstrap/app.php`, `bootstrap/providers.php` |
| API endpoints | `routes/api.php` |
| SPA shell and frontend bootstrap | `routes/web.php`, `resources/views/app.blade.php`, `resources/js/app.js`, `resources/js/bootstrap.js` |
| Vue paths/pages/layouts | `resources/js/spa/routes.js`, `resources/js/spa/pages/`, `resources/js/spa/layouts/` |
| Authentication and role constants | `app/Http/Controllers/Api/AuthController.php`, `app/Models/User.php`, `app/Http/Middleware/EnsureActiveUser.php` |
| Role/shop helper methods | `app/Http/Controllers/Controller.php` |
| Tenant and repair relationships | `app/Models/Shop.php`, `JobOrder.php`, `Technician.php`, `User.php` |
| Core schema | `database/migrations/` |
| Notification delivery | `app/Services/CustomerNotifier.php`, `app/Jobs/SendCustomerNotification.php` |
| Audit logging | `app/Support/AuditLogger.php`, `app/Http/Controllers/Api/AuditLogController.php` |
| File storage | `config/filesystems.php`, `RepairPhotoController.php`, `SubscriptionRequestController.php` |
| Queue/mail/SMS | `config/queue.php`, `config/mail.php`, `config/services.php` |
| Tests | `phpunit.xml`, `phpunit.mysql.xml`, `tests/Feature/`, `tests/Unit/`, `tests/MySql/` |
| Deployment guidance | `.env.example`, `.env.production.example`, `DEPLOYMENT_RUNBOOK.md` |

# Appendix B — Remaining Verification Checklist

Before a final defense/demo, fill in these environment-specific facts:

- [ ] Production URL, host/provider, web server, PHP runtime, and deployment topology.
- [ ] Actual production DB engine/version, schema migration status, backups, and restore procedure.
- [ ] Active account provisioning process and safe demo accounts (do not use historical hard-coded credentials).
- [ ] Effective role permissions for every sensitive action and route.
- [ ] Live tenant-isolation checks for jobs, users, appointments, inventory, files, payments, and reports.
- [ ] Effective filesystem disk and proof that repair photos/subscription proofs are not directly public.
- [ ] Queue worker configuration, retries, monitoring, and failed-job handling.
- [ ] Real mail/SMS provider test results, using approved test recipients.
- [ ] Current report endpoint response type (especially the PDF-named export endpoint).
- [ ] Whether technician rating route is intended, who may submit it, and whether a frontend exists.
- [ ] Whether the MySQL concurrency suite has been run against the dedicated test database.
- [ ] Actual PHPUnit execution output and any coverage report.
- [ ] Shop close-out and deactivation behavior in the live application.
- [ ] Data retention and privacy requirements for customer information, photos, payment references, proofs, and logs.

Once these values are confirmed, document them as deployment facts with date and environment. Do not substitute source configuration defaults for evidence from the running system.
