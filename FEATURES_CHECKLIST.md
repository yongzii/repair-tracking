# RepairSync Cloud Feature Inventory

This checklist is a source-based inventory, not a release certificate. “Implemented in source” means code/routes/pages exist; it does not mean that a live deployment or external integration was tested.

## Implemented in source

### Public experience

- [x] Public landing page and shop discovery/profile.
- [x] Shop registration/onboarding with slug availability check and default service catalog.
- [x] Public consultation requests and confirmation-code lookup.
- [x] Appointment confirmation-code lookup.
- [x] Public repair tracking with status history and permitted repair-photo display.
- [x] Throttling on public tracking and selected lookup/photo routes.

### Shop operations

- [x] Shop dashboard and alerts.
- [x] Job intake, listing, search/filter, detail, edit, print/invoice views, trash, restore, and force-delete endpoints.
- [x] Technician assignment and technician portal for assigned work.
- [x] Status updates and status history.
- [x] Repair approval record, photos, visibility control, and device-location updates.
- [x] Consultation review, customer response, cancellation, and resolution.
- [x] Shop profile, logo, business hours, notification settings, warranty default, and service catalog management.
- [x] Shop staff/user and technician administration.
- [x] Inventory catalog, stock adjustment, stock movement, and job-part handling.
- [x] Shop purchasing requests and inventory receiving.
- [x] Job-specific part order workflow, including customer decision/downpayment, supplier ordering, receipt, cancellation, and issue to job.
- [x] Job costs, payment ledger, pickup, warranty assessments/service, RTO, and refund workflow.
- [x] Shop reports, CSV export, print-oriented report output, and shop audit-log page.
- [x] Customer notification records and queued email/SMS code.

### Platform operations

- [x] Platform dashboard and shop listing/management.
- [x] Shop activation toggle and shop-admin password reset.
- [x] Subscription settings and shop-submitted subscription requests with payment proof.
- [x] Subscription request approval/rejection and recorded subscription payments.
- [x] Failed queue job listing, retry, and deletion endpoints.

## Partial, configuration-dependent, or requiring further verification

- [ ] **Email/SMS delivery:** Code exists; validate provider credentials, queue configuration, worker supervision, and actual delivery.
- [ ] **Subscription payments:** The code records payment information and reviews submitted proof. An integrated payment gateway was not verified.
- [ ] **PDF reporting:** A print-oriented report view exists; a server-side PDF generation dependency/implementation was not established.
- [ ] **Platform audit feed:** The platform audit page is an access-checked placeholder and makes no API request. Do not mark as a working feed.
- [ ] **Shop data isolation:** Controllers apply shop checks, but there is no universal model-level shop scope. Verify sensitive paths with cross-tenant tests.
- [ ] **Private file storage:** Protected file handlers exist. Confirm production disk selection and deployment migration of old public files.
- [ ] **Audit completeness:** Mutations call the audit logger at selected call sites; universal coverage and tamper resistance are not established.
- [ ] **Full status transition matrix:** Important transitions and terminal checks exist, but there is no single exhaustive matrix documented here.
- [ ] **Parts workflows:** Shop requests, job-specific orders, and blocked-job requests are separate/parallel flows, not a single unified workflow.
- [ ] **Production readiness:** This checklist does not establish hosting, backups, TLS, monitoring, secrets management, performance, or successful end-to-end testing.

## Not found as implemented in the inspected source

- [ ] Customer account/sign-in portal or persisted customer role.
- [ ] Customer password-recovery flow.
- [ ] Integrated online payment processor.
- [ ] Customer feedback/rating workflow.
- [ ] Multi-location shop model.
- [ ] Automatic technician assignment/load balancing.
- [ ] Dedicated platform-wide audit feed.

Absence here means not located in the inspected implementation, not proof that a separate untracked service cannot exist.

## Current lifecycle values to use in demonstrations

### Job orders

The current controller exposes:

`RECEIVED`, `DIAGNOSING`, `WAITING_PARTS`, `IN_PROGRESS`, `READY_FOR_PICKUP`, `PICKED_UP`, `RTO`, `RTO_RELEASED`.

`UNREPAIRABLE` is a legacy value consolidated into RTO by a later migration.

### Consultations

- Public creation accepts `CONSULTATION` only. Drop-off booking and appointment-to-job conversion are unavailable.
- Active consultation statuses are `PENDING`, `CANCELLED`, and `COMPLETED`.
- Historical appointment schema values and records remain in place; the appointments table is not removed.

### Shop part requests and job-specific part orders

These are separate workflows with separate models/statuses. Refer to `SYSTEM_GUIDE.md` and the relevant controller/model before presenting detailed transitions.

## Evidence locations

- API surface: `routes/api.php`
- Vue pages: `resources/js/spa/pages/`
- Backend: `app/Http/Controllers/Api/`
- Models: `app/Models/`
- Schema history: `database/migrations/`
- Feature and unit tests: `tests/Feature/`, `tests/Unit/`
- MySQL concurrency tests: `tests/MySql/`
