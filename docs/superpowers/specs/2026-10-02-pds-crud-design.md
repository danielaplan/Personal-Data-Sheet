# Personal Data Sheet CRUD Design

**Date:** 2026-10-02  
**Status:** Approved design  
**Target stack:** PHP 8.2+, MySQL 8.0+/MariaDB 10.6+, jQuery 3.7.1, Bootstrap 5.0.2

## 1. Context

The current project is a static front-end prototype consisting of:

- `index.html`: a Bootstrap-based Personal Data Sheet (PDS) form covering personal information, family background, educational background, and a typed signature field.
- `style.css`: minimal layout rules.
- An empty `.vscode` directory.

The project has no custom JavaScript, jQuery, AJAX behavior, backend, API, persistent storage, authentication, tests, package configuration, or Git repository. The existing form is the visual starting point, but its controls need stable `name` attributes and a consistent data contract before records can be persisted.

## 2. Goals

The first version will:

1. Save every field currently present in the PDS form.
2. Let authenticated staff add, search, view, edit, archive, and restore PDS records without full-page reloads.
3. Use jQuery `$.ajax()` for browser-to-server requests.
4. Use PHP session authentication with one staff role.
5. Persist data in normalized MySQL tables.
6. Support any number of child rows.
7. Preserve the five existing educational levels as structured related rows.
8. Protect sensitive personal information through endpoint authorization, CSRF protection, prepared SQL, safe rendering, controlled logging, and secure deployment guidance.
9. Record login, create, update, archive, and restore audit events without copying sensitive field values into the audit log.
10. Detect concurrent edits so one staff member cannot silently overwrite a newer update.

## 3. Non-goals

The first version will not include:

- Official PDS pages or sections not present in the current HTML.
- Signature images, digital certificates, or document uploads. The existing signature field becomes a typed signatory-name acknowledgment only.
- Multiple roles, per-record ownership, or staff-account management screens.
- PDF/Excel export, email, external identity providers, or offline operation.
- Permanent deletion through the browser.
- A PHP application framework.

## 4. Application structure

The project will use a small layered PHP structure:

```text
bootstrap/
├── index.php
├── login.php
├── style.css
├── .env.example
├── composer.json
├── README.md
├── assets/
│   └── app.js
├── api/
│   ├── auth/
│   │   ├── login.php
│   │   └── logout.php
│   └── pds/
│       ├── list.php
│       ├── get.php
│       ├── create.php
│       ├── update.php
│       ├── archive.php
│       └── restore.php
├── config/
│   ├── bootstrap.php
│   └── database.php
├── database/
│   └── schema.sql
├── scripts/
│   └── create-staff-user.php
├── src/
│   ├── Auth.php
│   ├── Csrf.php
│   ├── JsonResponse.php
│   ├── LoginThrottle.php
│   ├── PdsRepository.php
│   ├── PdsService.php
│   └── PdsValidator.php
└── tests/
    ├── Integration/
    └── Unit/
```

Responsibilities:

- `login.php` renders the staff login interface.
- `index.php` rejects unauthenticated access before rendering the dashboard and exposes the session CSRF token to JavaScript through a meta element.
- `assets/app.js` owns jQuery event handling, AJAX requests, form serialization/population, table rendering, search, pagination, dynamic child rows, dirty-state warnings, and visible request state.
- Files under `api/` are thin transport handlers. They authenticate the request, parse input, call a service, and emit JSON.
- `PdsService` coordinates validation, database transactions, related rows, audit events, and update-version checks.
- `PdsRepository` contains PDO queries and no HTML or request handling.
- `PdsValidator` accepts only documented fields, normalizes blank optional values to `null`, and returns field-keyed errors.
- `JsonResponse` produces a uniform response envelope.
- `scripts/create-staff-user.php` creates staff accounts from the command line and never publishes a default password.

Composer provides PSR-4 autoloading and the small runtime dependency `vlucas/phpdotenv`; PHPUnit is a development-only dependency. No framework is introduced.

## 5. Authentication and session design

### Login

`POST /api/auth/login.php` accepts JSON containing `username` and `password`.

- Usernames are compared case-insensitively and are unique in storage.
- Passwords are stored with PHP `password_hash()` and checked with `password_verify()`.
- A successful login regenerates the PHP session ID and clears the applicable throttle records.
- Five failed attempts within 15 minutes lock both the normalized-account key and source-address key for 15 minutes.
- Authentication failures return one generic message so the API does not reveal whether a username exists.
- Account and source identifiers used for throttling are stored only as server-keyed HMAC values; raw attempted usernames and source addresses are not exposed in the UI or retained in throttle records.

### Session

- Authenticated sessions expire after 30 minutes of inactivity.
- Production cookies use `HttpOnly`, `Secure`, and `SameSite=Strict`.
- The session stores only staff ID, display name, last-activity time, and the CSRF token.
- Every protected page and endpoint performs its own session check.
- `POST /api/auth/logout.php` requires a valid CSRF token and destroys the session.

### CSRF

`login.php` starts an anonymous session and issues a CSRF token before credentials are submitted. Every state-changing AJAX request, including login, sends its session token in the `X-CSRF-Token` header. A missing or invalid token returns HTTP `403` and performs no mutation.

## 6. Database model

All tables use InnoDB, UTF-8 (`utf8mb4`), foreign keys, and UTC timestamps.

### `staff_users`

- `id`: unsigned primary key.
- `username`: unique, normalized username.
- `display_name`: staff-facing name.
- `password_hash`: PHP password hash.
- `is_active`: account-enabled flag.
- `last_login_at`, `created_at`, `updated_at`.

### `login_throttles`

- `throttle_key`: primary key containing a server-keyed HMAC for either a normalized-account key or a source-address key.
- `failed_attempts`, `window_started_at`, `locked_until`, `updated_at`.

Throttle rows contain no raw username or source address and are deleted after successful login or after their retention window expires.

### `pds_records`

One row represents one PDS and stores scalar fields in these groups:

- Personal name: surname, first name, name extension, middle name.
- Birth/demographics: birth date, birth place, sex at birth, height, weight, blood type.
- Government/work identifiers: UMID, Pag-IBIG, PhilHealth, PhilSys, TIN, agency employee number.
- Citizenship: citizenship type and dual-citizenship details.
- Residential address: house/block/lot, street, subdivision/village, barangay, city/municipality, province, ZIP code.
- Permanent address: the same address components.
- Contact information: telephone, mobile, email.
- Spouse: surname, first name, name extension, middle name, occupation, employer/business, business address, telephone.
- Father: surname, first name, name extension, middle name.
- Mother: surname, first name, name extension, middle name.
- Acknowledgment: typed signatory name and signature date.

System columns:

- `id`: unsigned primary key.
- `version`: positive integer beginning at 1 and incremented after each successful update.
- `created_by`, `updated_by`: foreign keys to `staff_users`.
- `created_at`, `updated_at`.
- `archived_at`: nullable UTC timestamp.
- `archived_by`: nullable foreign key to `staff_users`.

`agency_employee_number` is nullable and unique when present. Indexes cover surname/first name, agency employee number, email, archive status, and updated time.

### `pds_children`

- `id`: unsigned primary key.
- `pds_id`: cascading foreign key to `pds_records`.
- `full_name`.
- `birth_date`.
- `sort_order`.

Empty child rows are not stored. A PDS can have any number of children.

### `pds_education`

- `id`: unsigned primary key.
- `pds_id`: cascading foreign key to `pds_records`.
- `level`: one of `elementary`, `secondary`, `vocational`, `college`, or `graduate`.
- `school_name`.
- `degree_course`.
- `attendance_from_year`, `attendance_to_year`.
- `highest_units_earned`.
- `graduation_year`.
- `honors`.

A unique constraint on `(pds_id, level)` allows at most one row for each currently displayed level. Completely empty levels are omitted from storage and returned to the browser as blank level objects.

### `audit_log`

- `id`: unsigned primary key.
- `staff_user_id`: nullable foreign key so security events can record an unresolved login actor.
- `pds_id`: nullable foreign key.
- `action`: `login_success`, `login_failure`, `create`, `update`, `archive`, or `restore`.
- `occurred_at`.
- `metadata`: minimal JSON containing only operational details such as the prior and resulting version number.

The audit log never stores passwords, session/CSRF tokens, complete request payloads, or government identifier values.

## 7. API contract

All endpoints return JSON with this envelope:

```json
{
  "ok": true,
  "data": {},
  "message": "Human-readable summary",
  "errors": {}
}
```

- `ok` is always boolean.
- `data` is an object, array, or `null`.
- `message` is safe to show to staff.
- `errors` maps form field paths to validation messages and is empty outside validation failures.

### Authentication endpoints

- `POST /api/auth/login.php`
- `POST /api/auth/logout.php`

### PDS endpoints

- `GET /api/pds/list.php?q=&page=1&page_size=10&status=active`
  - `status` is `active` or `archived`.
  - `q` is trimmed and limited to 100 characters.
  - Search matches surname, first name, middle name, agency employee number, or email.
  - `page_size` defaults to 10 and is capped at 50.
  - Results contain only ID, version, display name, agency employee number, birth date, email, updated time, and archive state. Government identifiers are excluded.
- `GET /api/pds/get.php?id=<positive integer>`
  - Returns one complete active or archived record, including children and education.
- `POST /api/pds/create.php`
  - Accepts the complete documented PDS JSON payload.
  - Creates the main row, children, education, and audit event in one transaction.
- `POST /api/pds/update.php`
  - Requires record `id`, expected `version`, and the complete documented payload.
  - Updates only when the stored version matches the expected version.
  - Replaces related child and education rows inside the same transaction.
- `POST /api/pds/archive.php`
  - Requires `id` and `version`.
  - Sets archive metadata and increments the version.
- `POST /api/pds/restore.php`
  - Requires `id` and `version`.
  - Clears archive metadata and increments the version.

State-changing endpoints accept `Content-Type: application/json` only and require the CSRF header. Unexpected top-level or nested fields are rejected rather than silently stored.

## 8. Browser payload

The JavaScript payload has three top-level members:

```text
record: scalar fields from the current form
children: ordered array of { full_name, birth_date }
education: ordered array of level-specific education objects
```

Input IDs remain available for labels, while `name` attributes match the API’s snake-case field keys. Radio buttons use a shared `citizenship_type` name. The JavaScript serializer reads an explicit field map rather than submitting arbitrary DOM controls.

The canonical `record` keys are:

```text
surname, first_name, name_extension, middle_name,
birth_date, birth_place, sex_at_birth, height_m, weight_kg, blood_type,
umid_number, pagibig_number, philhealth_number, philsys_number,
tin_number, agency_employee_number,
citizenship_type, dual_citizenship_details,
residential_house, residential_street, residential_subdivision,
residential_barangay, residential_city, residential_province,
residential_zip,
permanent_house, permanent_street, permanent_subdivision,
permanent_barangay, permanent_city, permanent_province, permanent_zip,
telephone, mobile, email,
spouse_surname, spouse_first_name, spouse_name_extension,
spouse_middle_name, spouse_occupation, spouse_employer,
spouse_business_address, spouse_telephone,
father_surname, father_first_name, father_name_extension,
father_middle_name,
mother_surname, mother_first_name, mother_name_extension,
mother_middle_name,
signatory_name, signature_date
```

Each `children` item contains `full_name` and `birth_date`. Each `education` item contains `level`, `school_name`, `degree_course`, `attendance_from_year`, `attendance_to_year`, `highest_units_earned`, `graduation_year`, and `honors`.

Blank optional strings are sent as `null`. Dates use ISO `YYYY-MM-DD`. Numeric height and weight values are sent as numbers or `null`, not localized strings.

## 9. UI behavior

### Dashboard

The records panel appears above the PDS form and contains:

- Search input.
- Active/Archived filter.
- “Add PDS” button.
- Paginated results table.
- Loading, empty, and error status region.

Columns are name, agency employee number, birth date, email, last updated, and actions. Wide tables scroll within their own responsive container.

### Search

- jQuery debounces search input by 300 milliseconds.
- Starting a new search resets the page to 1.
- An in-flight older search is aborted before issuing a newer request.
- A failed request leaves the previous results visible and announces the failure separately.

### Add mode

- “Add PDS” resets scalar fields and creates one empty child row.
- Education rows remain visible for the five fixed levels.
- The page shows “Add Personal Data Sheet” and focuses surname.
- Save calls the create endpoint.

### Edit mode

- “Edit” fetches the complete record rather than relying on data embedded in the table.
- Scalar fields, radio/select values, children, and education are populated from the response.
- The record ID and version remain in JavaScript state, not editable inputs.
- Save calls the update endpoint with the expected version.

### Archive and restore

- Archive confirmation names the selected person and explains that the record can be restored.
- Confirming archive sends the record ID and current version.
- Archived results expose Restore rather than Edit by default. Staff must restore a record before modifying it.
- There is no permanent-delete action in the browser.

### Form state

- Submit controls are disabled while a save request is pending.
- A dirty-state flag warns before resetting, changing records, logging out, or leaving the page with unsaved edits.
- Successful mutations reset the form and refresh the current search/page where possible.
- Success/error messages use an ARIA live region.
- Validation errors appear beside their controls and in a focusable summary at the start of the form.

## 10. Validation rules

Server validation is authoritative; matching browser rules exist only for usability.

- Surname and first name are required and limited to 100 characters.
- Birth date is required, must be a valid calendar date, and cannot be later than today.
- Email is optional but must be valid when supplied and is limited to 254 characters.
- Height and weight are optional positive decimal values; height is capped at 3.00 meters and weight at 1,000 kilograms to reject accidental malformed values without imposing narrow assumptions.
- Sex at birth, blood type, citizenship type, and education level accept only listed values.
- Dual-citizenship details are required when citizenship type is `dual` and ignored otherwise.
- Agency employee number is optional, limited to 50 characters, and must be unique when present.
- Government identifiers and telephone/mobile values are treated as strings, preserve leading zeros, and are limited to 50 characters.
- Address components, occupations, employers, school names, courses, and honors have explicit database-aligned length limits between 100 and 255 characters.
- ZIP codes are strings limited to 20 characters.
- Education years are optional four-digit integers from 1900 through the current year plus one; `from` cannot exceed `to`.
- Graduation year follows the same range and cannot precede the attendance start year.
- Children with both fields blank are omitted. A partially completed child row receives field-level errors. Child names are limited to 200 characters, and birth dates cannot be in the future.
- Typed signatory name is optional and limited to 200 characters. Signature date is optional, must be valid, and cannot be in the future.
- Every request has a configured JSON body-size ceiling of 1 MiB.

## 11. Transactions and concurrent editing

Create, update, archive, and restore run inside database transactions.

Update uses optimistic concurrency:

```text
UPDATE pds_records
SET ..., version = version + 1
WHERE id = :id AND version = :expected_version
```

If no row is updated because the stored version changed, the API rolls back and returns HTTP `409`. The browser keeps all unsaved values and tells the staff member to reload the record before deciding how to reapply changes. The first version does not attempt automatic field merging.

## 12. Error handling

- `400 Bad Request`: malformed JSON, unsupported content type, or invalid query structure.
- `401 Unauthorized`: missing/expired session; the browser redirects to login while preserving no sensitive payload in browser storage.
- `403 Forbidden`: failed CSRF or disabled account.
- `404 Not Found`: record does not exist or is inaccessible.
- `409 Conflict`: version conflict or duplicate agency employee number.
- `422 Unprocessable Entity`: field validation errors.
- `429 Too Many Requests`: login throttling or temporary lockout.
- `500 Internal Server Error`: unexpected server/database failure.

The API logs a request correlation ID and technical exception details server-side. It returns only a generic message and correlation ID to the browser. Transactions roll back on every failed mutation. Search errors do not clear previously rendered results.

## 13. Security and privacy controls

- PDO emulated prepares are disabled; all variable SQL values use bound parameters.
- Every API endpoint authenticates before reading or mutating PDS data.
- Browser output uses jQuery `.text()` and input `.val()`; record values are never concatenated into executable HTML.
- The server sends `Cache-Control: no-store` for authenticated HTML and JSON containing PDS data.
- Production deployment uses HTTPS, restricted database credentials, encrypted host storage/backups, and a web root that does not expose `.env`, `config`, `database`, `scripts`, `src`, or `tests`.
- Secrets come from environment variables and are represented only by empty/example values in `.env.example`.
- PHP display-errors is disabled in production.
- Composer dependencies are pinned through `composer.lock`; the jQuery and Bootstrap CDN resources use explicit versions and Subresource Integrity.
- Sensitive PDS payloads are not placed in URLs, access-log query parameters, client-side persistent storage, or audit metadata.

## 14. Testing strategy

### Unit tests

- Scalar normalization and unknown-field rejection.
- Required fields and length limits.
- Date, year, email, enum, numeric, citizenship, child, education, and signature rules.
- JSON response shape.

### Integration tests

A separate test database verifies:

- Successful login, invalid login, lockout, inactivity expiry, and logout.
- Authentication and CSRF enforcement on every endpoint.
- Create and fetch of a complete PDS.
- Dynamic zero, one, and multiple child rows.
- Education persistence for all five levels.
- Search fields, active/archive filters, result projection, and pagination limits.
- Update version increments.
- Stale update returns `409` and changes nothing.
- Archive and restore behavior.
- Duplicate agency employee number conflict.
- Transaction rollback when a related-row write fails.
- Sensitive values never appear in list results or audit rows.

### Browser smoke verification

The implementation is manually checked at desktop and mobile widths for:

- Login/logout.
- Initial loading, empty, populated, and failed list states.
- Add and Edit modes.
- Dynamic child-row controls.
- Field-level and summary validation.
- Debounced search and pagination.
- Archive/restore confirmation.
- Unsaved-change warnings.
- Expired-session redirection.
- Keyboard focus and ARIA status announcements.

## 15. Setup and deployment documentation

`README.md` will document:

1. Supported PHP/MySQL versions and required PHP extensions (`pdo_mysql`, `mbstring`, `json`, and `session`).
2. Composer dependency installation.
3. Database creation and `database/schema.sql` import.
4. Environment-variable configuration.
5. Web-server document-root/protection rules.
6. CLI staff-user creation.
7. Local operation under Apache environments such as XAMPP.
8. PHPUnit execution against the isolated test database.
9. Production HTTPS, cookie, error-display, storage, backup, and database-permission requirements.

## 16. Acceptance criteria

The design is successfully implemented when:

1. An unauthenticated visitor cannot access the dashboard or any PDS endpoint.
2. An authenticated staff member can create a complete record containing scalar fields, dynamic children, and education rows without a page reload.
3. Search returns paginated matching records without exposing government identifiers in list responses.
4. Editing reloads the complete record and persists a valid update atomically.
5. A stale edit cannot overwrite a newer version and leaves the stale form intact for review.
6. Archive removes a record from active search, and Restore returns it to active search.
7. Invalid data is rejected server-side and displayed beside the relevant controls.
8. SQL injection, reflected/stored HTML injection, missing authentication, and missing CSRF attempts fail safely.
9. Audit events identify the actor, action, target record, and time without storing sensitive field values.
10. Automated tests pass against an isolated test database, and the browser smoke checklist passes at desktop and mobile widths.
