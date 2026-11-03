# Technician Portal Setup and Demonstration Guide

This guide reflects the current technician account/profile and assigned-job workflow. It does not use embedded demo credentials. Use only a local or approved test environment and accounts created for that environment.

## Before setup

1. Confirm the application environment, database, and test/demo data are the intended ones.
2. Ensure migrations have already been applied by the environment owner; this guide does not instruct you to migrate a production database.
3. Have a shop administrator account available to manage the shop and create technician records.
4. Create or identify a technician profile and an active login user with the `technician` role.
5. Link the user to the technician profile. Confirm both records belong to the same shop and the technician profile is active.

Account/profile linkage behavior is implemented in `app/Http/Controllers/Controller.php` (`findTechnician`, `resolveTechnician`). Do not assume a technician record automatically creates a login account.

## Assign a technician to a job

1. Sign in as an authorized shop administrator or staff account.
2. Create or open a job order in the shop workspace.
3. Assign an active technician profile from the same shop.
4. Save and confirm that the job detail identifies the assigned technician.

The job action must be performed in the shop workspace. A technician should only see a job assigned to their linked profile.

## Sign in and work an assigned job

1. Sign in using the technician account configured for the test environment.
2. Open the technician dashboard at `/technician`.
3. Select a job assigned to that technician.
4. Review the job details and available actions.
5. Demonstrate only applicable actions: status update, repair approval record, photo upload/visibility, parts, blocked-parts request, or device location.
6. Confirm the resulting job history/record in the interface.

The frontend is in `resources/js/spa/pages/technician/`; the API is grouped under `/api/technician` in `routes/api.php`.

## Demonstrate assignment restrictions safely

In a test environment, verify that:

- An unlinked/inactive technician profile cannot access the technician workflow.
- A technician cannot access a job assigned to another technician.
- A technician cannot access another shop's job.
- A same-shop, correctly assigned technician can access their job.

Use synthetic records only. Do not change production records to demonstrate access controls.

## Parts workflow notes

The system has multiple related parts paths:

- Shop purchasing request and inventory receiving.
- Job-specific part order, which can include a customer decision/downpayment before supplier ordering.
- Blocked-job parts request/fulfillment.

Choose one workflow for the demo and explain its purpose. Do not describe the separate paths as one unified queue.

## Not part of this guide

- Customer technician ratings or a technician star-rating dashboard are not established in the current source.
- Do not use old credentials from historical Markdown files.
- Do not claim test or production status without current evidence.
- Do not run migration refreshes or database cleanup commands against production.

## Troubleshooting checklist

- Confirm the user is active and has the `technician` role.
- Confirm the user and technician profile use the same shop.
- Confirm the profile is active and linked to the account (or has the supported fallback identity).
- Confirm the job is assigned to that profile and shop.
- Check the application logs and API response for authorization or validation failures.
- For notifications, verify queue worker and provider configuration separately; successful technician actions do not prove message delivery.
