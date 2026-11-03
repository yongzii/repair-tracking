# Technician Portal — Current Implementation

This document describes the technician workflow in the current RepairSync Cloud source. It replaces an older report about a Blade-based technician performance/rating feature.

## Purpose

The technician portal gives repair technicians a focused view of jobs assigned to their linked technician profile. It is separate from the shop administrator/staff workspace and is not a customer portal.

## Technician identity and job access

- A technician account has the `technician` role.
- The account must resolve to an active technician profile associated with the same shop.
- Profile resolution uses the linked technician ID where present, or a shop-scoped email match as a fallback.
- Technician job access checks both shop association and assignment to that technician profile.
- A technician cannot use this portal to view another shop's job or another technician's assignment.

The role and assignment helper behavior is in `app/Http/Controllers/Controller.php`; the account/profile relationships are in `app/Models/User.php` and `app/Models/Technician.php`.

## Portal capabilities

The API and Vue page source includes:

- Technician dashboard and alerts.
- Assigned-job detail.
- Permitted job status updates.
- Recording customer repair approval.
- Uploading, deleting, and changing customer visibility of repair photos.
- Adding/removing job parts.
- Creating part requests and blocked-job parts requests.
- Creating job-specific part orders and issuing received parts to assigned work.
- Updating device location when supported by the workflow.

Backend routes are grouped under `/api/technician` in `routes/api.php`. Controllers are `TechnicianPortalController`, `PartRequestController`, `BlockedJobPartController`, `JobOrderPartOrderController`, and `RepairPhotoController`. Frontend pages are `resources/js/spa/pages/technician/TechnicianDashboardPage.vue` and `TechnicianJobOrderPage.vue`.

## Technician manager responsibilities

Shop administrators can create, edit, deactivate, and manage technician profiles through the shop workspace. A technician profile is not automatically equivalent to a login account; the account/profile association must be configured for technician portal access.

Technician CRUD and profile assignment are handled in `app/Http/Controllers/Api/TechnicianController.php` and the shop user management implementation.

## Typical technician workflow

1. Sign in with an active technician account.
2. Open the technician dashboard and choose an assigned job.
3. Review device/customer information and the current job history.
4. Record diagnosis/progress, repair approval, photos, parts needs, or device location as appropriate.
5. Update the job status using the allowed action.
6. Coordinate with shop staff for purchasing, receiving, customer communications, and pickup.

The shop remains responsible for broader operational and financial actions such as user administration, reporting, payment handling, and shop settings.

## Important distinctions

- Assignment checks are enforced by backend helpers/controllers, not merely by hiding UI links.
- Shop staff/admin and technicians do not have identical permissions.
- Shop part requests, job-specific part orders, and blocked-job parts requests are separate related workflows.
- The inspected source does not establish a customer star-rating system or a technician performance rating dashboard. Earlier documentation describing technician star ratings is obsolete.
- A technician must be linked to an active profile in the correct shop; having the `technician` role alone is insufficient.

## Validation and testing

The test suite includes authentication, tenant-isolation, procurement, and repair-workflow tests. Review `tests/Feature/JobOrderPartTenantIsolationTest.php` and related technician/parts tests before changing permissions. This document does not claim tests were run or passed.
