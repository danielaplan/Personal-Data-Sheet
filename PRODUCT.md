# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Plain static HTML / CSS / Bootstrap 5 + PHP 8.x + MySQL 8.x. The codebase ships templates that post data via jQuery AJAX to PHP endpoints. This keeps it simple, works on shared hosting, and avoids framework dependency. If a framework is needed later, it can be added incrementally — the data layer and CRUD UI are stack-agnostic. Deploy target is flexible (shared hosting, a VPS, a PHP cloud platform).

## Users

[Primary user: student completing a school activity. The form was given as an activity and converted to running software with HTML, CSS, JavaScript, jQuery, MySQL, and PHP backend with Add/Edit/Delete/Search using jQuery AJAX. Secondary audience: instructors or evaluators who view submitted records.]

## Product Purpose

Web CRUD UI for a Personal Data Sheet (PDS) record set, with Add/Edit/Delete/Search operations using jQuery AJAX against a PHP/MySQL backend. The user interface renders the Philippine government PDS form fields, validates inputs, persists data to MySQL, and produces a printable PDF of each completed form.

## Positioning

The product exists so a student (or anyone) can fill out a PDS form online instead of on paper, with the ability to look up, edit, and remove prior submissions. It replaces a purely paper workflow with a searchable, filterable digital record.

## Operating Context

- PHP 8.x with PDO MySQL extension
- Bootstrap 5 for layout and form styling
- jQuery 3.x + DataTables (for the table in the Educational Background section)
- MySQL 8.x with a `pds` database and schema.sql definitions
- Front-end runs in a desktop or laptop browser
- Submission data is POSTed via jQuery AJAX to PHP handler scripts

## Capabilities and Constraints

- Add a new PDS record (POST all section data via jQuery AJAX)
- Edit an existing PDS record (PUT/PATCH via jQuery AJAX after lookup)
- Delete a PDS record (DELETE via jQuery AJAX after lookup)
- Search/view prior submissions
- Print/export a single record to PDF
- Form fields match the Philippine PDS: Personal Information (1-15), Citizenship (16-17), Residential Address (18-20), Permanent Address (21-24), Contact Information (25), Family Background (22-25), Educational Background (levels elementary → graduate)
- Validation is client-side (HTML5 required/pattern) + server-side (PHP)
- No user authentication / login system (the form is open-access for the activity)
- PHP sessions used for flash messages; no persistent login cookies
- Database schema lives in `database/schema.sql`; migrations not yet implemented

## Evidence on Hand

- `index.html` — the landing PDS form with all sections
- `style.css` — container, login-panel, form-control, focus-visible styles
- `composer.json`, `composer.lock` — PHP dependencies (e.g., if any)
- `database/schema.sql` — MySQL schema for the pds table
- `src/*.php` — Auth, Endpoint, Response, Request, AuditLogger, exceptions
- `api/auth/login.php`, `api/auth/logout.php` — login/logout endpoints
- `tests/` — unit and integration tests for auth, HTTP API, and database
- `style.css` defines login-page, login-panel, login-eyebrow, focus-visible outline

## Brand Commitments

None explicitly made. The form follows the official Philippine PDS field layout and terminology as the authoritative reference.

## Product Principles

1. **Form fidelity** — render every PDS field exactly as the printed form expects, so a printed output matches the official form.
2. **Search & filter first** — users should find a prior submission quickly before editing or printing.
3. **PHP‑centric** — keep backend logic in PHP; the frontend (HTML/JS) stays thin and template-driven.
4. **Zero-auth open access** — this is a school activity form; no login, no session fixation risk beyond flash messages.

## Accessibility & Inclusion

None explicitly required beyond HTML5 semantic structure (form labels associated with inputs, `alt` text for any images). If the user later requires WCAG compliance, add aria-required, aria-invalid, and color-contrast checks against the `#f4f6f8` background and `#212529` text.