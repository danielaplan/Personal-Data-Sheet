# PDS project handoff

Last updated: 2026-10-03.

## Goal and source of truth

Build an authenticated PHP/MySQL Personal Data Sheet CRUD app with jQuery AJAX. The approved [design](superpowers/specs/2026-10-02-pds-crud-design.md) defines behavior and security requirements; the [implementation plan](superpowers/plans/2026-10-02-pds-crud-implementation.md) gives task order. The current `index.html` is still a static form.

## Completed work

- Task 1: Composer configuration and lockfile; HTTP request, JSON response, API exception, CSRF, endpoint, session/bootstrap, and PDO foundation in `src/` and `config/`.
- Task 2: six-table normalized schema in `database/schema.sql`, isolated database test harness in `tests/Support/DatabaseTestCase.php`, and schema constraint tests in `tests/Integration/PdsQueryTest.php`; verified on MySQL 8.0.44.
- Task 3: `Auth`, `LoginThrottle`, and `AuditLogger`; login/logout JSON endpoints; accessible `login.php` with jQuery login behavior; and CLI staff-account provisioning. Authentication regenerates sessions and CSRF tokens, expires sessions after 30 minutes, rechecks active accounts, and enforces account/address lockout after five failures. Audit metadata accepts only numeric `version`, `from_version`, and `to_version` fields; login metadata is empty.
- Added `Endpoint::fromGlobals()` so request-construction failures (including oversized bodies) also return the JSON error envelope. Added `app_auth()`, session lifetime configuration, and exception argument suppression in bootstrap.
- Brought the planned `HttpServer`, `HttpClient`, and authentication portion of `HttpApiTest` forward to verify Task 3 through real HTTP. Later tasks should extend these helpers/tests.
- No PDS validator, repository, CRUD service, PDS endpoints, dashboard, or PDS jQuery client yet. `index.html` remains the static prototype; `index.php` is planned for Task 9. Successful login intentionally targets `index.php`, which currently returns 404. `README.md` is not installation documentation yet.

## Verification status

- Latest complete run (2026-10-03): `rtk proxy powershell.exe -ExecutionPolicy Bypass -File .local/run-tests.ps1 --fail-on-risky --fail-on-warning --display-skipped` passed **29 tests, 312 assertions**, with no skips, warnings, or risky tests, using PHP 8.2.12 and isolated MySQL 8.0.44.
- Composer strict validation, syntax checks of all application/test PHP files, and `node --check assets/login.js` passed.
- Real HTTP tests cover cookie flags, session and CSRF rotation, rejected old sessions, malformed/oversized requests, login/logout method/JSON/CSRF enforcement, throttling, spoofed forwarded addresses, disabled accounts, web exclusion of the CLI script, and CLI provisioning/duplicates/password-input rules.
- Headless Chrome smoke checks passed at 1366×900, 375×812, and 812×375, including reduced motion, no horizontal overflow, error focus, successful login redirect, logout, and no browser storage. Desktop/mobile screenshots were visually reviewed; artifacts and the smoke script are under ignored `.local/`. The check verifies the intended dashboard redirect, not a working dashboard.
- Task 2 verified on 2026-10-03 against an isolated MySQL 8.0.44 instance on port 3307: 2 schema tests, 6 assertions, no skips or risky tests. Fixed the uniqueness test to assert SQLSTATE and duplicate-key error codes.
- The local XAMPP MariaDB is 10.4.32; the design requires MariaDB 10.6+ or MySQL 8.0+. During an attempted import into a disposable test database, the local server process exited. It was restarted and the disposable database was removed. Do not treat this as a passing schema test or repeat it against that unsupported server.

## Safe continuation

1. Continue with **Task 4: Authoritative PDS Validation and Normalization**, then Tasks 5–11 in order.
2. Reuse the isolated database for integration tests. Its dedicated process was shut down cleanly after verification; start it with `rtk proxy powershell.exe -ExecutionPolicy Bypass -File .local/resume-test-db.ps1`, wait for readiness in `.local/mysql-stderr.log`, then run `.local/run-tests.ps1`. Shut it down with `rtk proxy C:/xampp/php/php.exe .local/stop-test-db.php`. These scripts and credentials are local conveniences, not portable project setup.
3. Inspect `git status` before work: implementation files are untracked, and `README.md` and `style.css` are modified. The existing README change was preserved. No commits have been made for Tasks 1–3.

## Local tooling

- Prefix shell commands with `rtk` per `C:\Users\acer\.codex\RTK.md`.
- Use `C:\xampp\php\php.exe` for Composer and tests. The default PHP on `PATH` is 8.4 but lacks `mbstring` and `pdo_mysql`.
- `composer.phar` is installed locally and ignored by Git. For dependency installation, XAMPP PHP needs `-d extension=zip` because its ZIP extension is not enabled by default.
- No `.env` file is present. `.env.example` documents variables without secrets.
- `APP_KEY` must contain at least 32 bytes; use a random secret. `scripts/create-staff-user.php` accepts `--username`, `--display-name`, and either `--password-stdin` or the `PDS_STAFF_PASSWORD` process environment variable. It rejects command-line passwords, empty passwords, NUL bytes, and passwords longer than 72 bytes (bcrypt limit). No real staff account or default password was created; account fixtures exist only in the disposable test database.
- MySQL 8.0.44 binaries are installed at `C:\Program Files\MySQL\MySQL Server 8.0\bin`. The existing `MySQL80` Windows service was stopped and was not changed.
- An independent MySQL instance uses `.local/mysql-data`, loopback port 3307, and the disposable `pds_test` database. `.local/` is ignored by Git; its test credentials and server data must stay local. `.local/run-tests.ps1` supplies the isolated test connection to PHPUnit without printing credentials. The harness truncates the six test tables; never use it with needed data.
- Temporary HTTP/Chrome processes were stopped after verification. The test MySQL data directory is retained for reuse. `.local/.htaccess` denies Apache access to local test artifacts; full application deployment protections remain Task 11.
