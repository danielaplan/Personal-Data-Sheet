# PDS CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the static Personal Data Sheet prototype into a secure, authenticated PHP/MySQL CRUD application with AJAX search, editing, archiving, restoration, audit logging, and optimistic concurrency.

**Architecture:** Keep HTTP scripts thin: they construct a request, enforce a shared endpoint policy, invoke `PdsService`, and return a uniform JSON envelope. `PdsService` owns validation and transaction boundaries; `PdsRepository` owns PDO queries; the jQuery client owns explicit form serialization, rendering, and UI state without persistent browser storage.

**Tech Stack:** PHP 8.2+, PDO MySQL, MySQL 8.0+/MariaDB 10.6+, Composer, `vlucas/phpdotenv`, PHPUnit 11, jQuery 3.7.1, Bootstrap 5.0.2, Apache/XAMPP.

**Spec:** `docs/superpowers/specs/2026-10-02-pds-crud-design.md`

## Global Constraints

- Support PHP 8.2 or newer and MySQL 8.0+/MariaDB 10.6+.
- Use jQuery 3.7.1 `$.ajax()` for browser-to-server requests and Bootstrap 5.0.2 for the existing interface.
- Do not introduce a PHP application framework.
- Save all fields currently present in the PDS form, any number of children, and exactly the five documented education levels.
- Require an authenticated staff session for the dashboard and every PDS endpoint.
- Require a valid `X-CSRF-Token` header for login, logout, create, update, archive, and restore.
- Accept JSON only for state-changing API requests and cap request bodies at 1 MiB.
- Reject unknown top-level and nested fields.
- Use native prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`) and transactions for create, update, archive, and restore.
- Use optimistic concurrency; stale versions return HTTP 409 and never overwrite newer data.
- Never expose government identifiers in list results, audit metadata, URLs, logs, or browser persistence.
- There is no browser-accessible permanent delete operation.
- All authenticated HTML and PDS JSON responses use `Cache-Control: no-store`.
- Store database timestamps in UTC and use `utf8mb4` InnoDB tables.
- Commit commands below are review checkpoints only; run them only if the user explicitly authorizes commits.

---

## Locked File Structure

```text
.
├── .env.example                 # documented environment contract, no secrets
├── .gitignore                   # runtime and dependency exclusions
├── .htaccess                    # Apache index and private-directory protections
├── composer.json                # runtime and development dependencies/autoloading
├── phpunit.xml                  # unit/integration suite configuration
├── index.php                    # authenticated dashboard and PDS form
├── login.php                    # anonymous login page and CSRF bootstrap
├── style.css                    # dashboard, validation, responsive form styles
├── assets/
│   ├── app.js                   # dashboard/form state and PDS AJAX behavior
│   └── login.js                 # login AJAX behavior only
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
│   ├── bootstrap.php            # env, timezone, session, correlation ID, factories
│   └── database.php             # configured PDO factory
├── database/
│   └── schema.sql               # complete normalized schema
├── scripts/
│   └── create-staff-user.php    # interactive-free CLI account creation
├── src/
│   ├── ApiException.php         # safe HTTP status/message/error carrier
│   ├── AuditLogger.php          # allowlisted audit actions and safe metadata
│   ├── Auth.php                 # login/session/inactivity/account checks
│   ├── Csrf.php                 # token issue/rotation/constant-time verification
│   ├── Endpoint.php             # shared API policy, exception translation, no-store
│   ├── JsonResponse.php         # uniform response envelope and JSON emission
│   ├── LoginThrottle.php        # keyed-HMAC account/address throttling
│   ├── PdsRepository.php        # all PDS PDO statements
│   ├── PdsService.php           # validation, transactions, audit, concurrency
│   ├── PdsValidator.php         # allowlisting, normalization, field errors
│   └── Request.php              # global request adapter and JSON/query parsing
├── tests/
│   ├── bootstrap.php
│   ├── Support/
│   │   ├── DatabaseTestCase.php
│   │   ├── HttpClient.php
│   │   └── HttpServer.php
│   ├── Unit/
│   │   ├── HttpFoundationTest.php
│   │   ├── PdsValidatorTest.php
│   │   └── SecurityPrimitivesTest.php
│   └── Integration/
│       ├── AuthenticationTest.php
│       ├── PdsQueryTest.php
│       ├── PdsMutationTest.php
│       └── HttpApiTest.php
└── README.md                    # complete local, test, and deployment guide
```

`index.html` is removed only after `index.php` contains the complete converted form; keeping both can cause Apache to serve the unauthenticated HTML first.

### Canonical scalar storage contract

Use these exact API keys and database limits:

| Group | Keys | Storage/limit |
| --- | --- | --- |
| Required identity | `surname`, `first_name` | `VARCHAR(100) NOT NULL` |
| Optional identity | `name_extension`, `middle_name` | `VARCHAR(100) NULL` |
| Birth/demographic | `birth_date`, `birth_place`, `sex_at_birth`, `height_m`, `weight_kg`, `blood_type` | date required; place 255; enum values below; decimals `4,2` and `6,2` |
| Government/work IDs | `umid_number`, `pagibig_number`, `philhealth_number`, `philsys_number`, `tin_number`, `agency_employee_number` | `VARCHAR(50) NULL`; agency number unique when non-null |
| Citizenship | `citizenship_type`, `dual_citizenship_details` | `filipino|dual`; details 255 |
| Each address | house 100, street 150, subdivision 150, barangay 150, city 150, province 150, ZIP 20 | nullable strings |
| Contact | `telephone`, `mobile`, `email` | 50, 50, and 254 |
| Spouse | four name fields 100; occupation 150; employer 255; address 255; telephone 50 | nullable strings |
| Parents | all eight parent name fields | `VARCHAR(100) NULL` |
| Acknowledgment | `signatory_name`, `signature_date` | 200 and date |

Canonical enum values are `male|female`, `O+|O-|A+|A-|B+|B-|AB+|AB-`, `filipino|dual`, and `elementary|secondary|vocational|college|graduate`.

---

### Task 1: Composer Runtime and HTTP Foundation

**Files:**
- Create: `composer.json`
- Create: `.env.example`
- Create: `.gitignore`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`
- Create: `src/ApiException.php`
- Create: `src/JsonResponse.php`
- Create: `src/Request.php`
- Create: `src/Csrf.php`
- Create: `src/Endpoint.php`
- Create: `config/bootstrap.php`
- Create: `config/database.php`
- Test: `tests/Unit/HttpFoundationTest.php`
- Test: `tests/Unit/SecurityPrimitivesTest.php`

**Interfaces:**
- Produces: `Pds\Request::fromGlobals(): Request`, `Request::requireMethod(string): void`, `Request::json(int $maxBytes = 1048576): array`, `Request::header(string): ?string`, and typed query accessors.
- Produces: `Pds\ApiException` with `status(): int`, `publicMessage(): string`, and `errors(): array`.
- Produces: `Pds\JsonResponse::payload(bool, mixed, string, array): array` and `JsonResponse::send(int, bool, mixed, string, array): never`.
- Produces: `Pds\Csrf::token(): string`, `Csrf::rotate(): string`, and `Csrf::assertValid(?string): void`.
- Produces: `Pds\Endpoint::run(Request, callable): never` and `Endpoint::protected(Request, Auth, bool, callable): never`; the latter receives the request-scoped `Auth` service, authenticates first, validates CSRF when requested, then passes the authenticated user to the handler.
- Produces: global factories `app_pdo(): PDO`, `app_request_id(): string`, and `app_is_production(): bool` from `config/bootstrap.php`.

- [ ] **Step 1: Define dependencies, autoloading, environment defaults, and test bootstrap**

```json
{
  "name": "pds/personal-data-sheet",
  "description": "Authenticated Personal Data Sheet CRUD application",
  "type": "project",
  "require": {
    "php": "^8.2",
    "ext-json": "*",
    "ext-mbstring": "*",
    "ext-pdo": "*",
    "ext-session": "*",
    "vlucas/phpdotenv": "^5.6"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.5"
  },
  "autoload": {"psr-4": {"Pds\\": "src/"}},
  "autoload-dev": {"psr-4": {"Pds\\Tests\\": "tests/"}},
  "scripts": {"test": "phpunit"},
  "config": {"sort-packages": true}
}
```

Use this exact environment contract; `.gitignore` excludes `.env`, `/vendor/`, `.phpunit.cache/`, and `*.log` without excluding `.env.example`.

```dotenv
APP_ENV=development
APP_KEY=
APP_URL=http://localhost/PDS
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=pds;charset=utf8mb4
DB_USER=pds_app
DB_PASSWORD=
SESSION_SECURE_COOKIE=0
TRUSTED_PROXY=
TEST_DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=pds_test;charset=utf8mb4
TEST_DB_USER=pds_test
TEST_DB_PASSWORD=
```

Configure `phpunit.xml` without database credentials:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.5/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true">
  <testsuites>
    <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    <testsuite name="Integration"><directory>tests/Integration</directory></testsuite>
  </testsuites>
  <php>
    <env name="APP_ENV" value="test" force="true"/>
    <ini name="display_errors" value="1"/>
  </php>
</phpunit>
```

- [ ] **Step 2: Install dependencies**

Run: `composer install`

Expected: Composer creates `vendor/` and `composer.lock`; no secrets are generated.

- [ ] **Step 3: Write failing HTTP-foundation tests**

```php
<?php

declare(strict_types=1);

namespace Pds\Tests\Unit;

use Pds\ApiException;
use Pds\Csrf;
use Pds\JsonResponse;
use Pds\Request;
use PHPUnit\Framework\TestCase;

final class HttpFoundationTest extends TestCase
{
    public function testResponseEnvelopeAlwaysHasFourKeys(): void
    {
        self::assertSame(
            ['ok' => true, 'data' => ['id' => 7], 'message' => 'Created', 'errors' => []],
            JsonResponse::payload(true, ['id' => 7], 'Created', [])
        );
    }

    public function testJsonRejectsWrongMethodContentTypeMalformedAndOversizedBodies(): void
    {
        $this->expectException(ApiException::class);
        $request = new Request('POST', 'text/plain', '{}', [], []);
        $request->json();
    }

    public function testJsonAcceptsAnObjectAndRejectsAList(): void
    {
        self::assertSame(['name' => 'Ada'], (new Request('POST', 'application/json; charset=utf-8', '{"name":"Ada"}', [], []))->json());

        $this->expectException(ApiException::class);
        (new Request('POST', 'application/json', '[1,2]', [], []))->json();
    }
}
```

Add CSRF tests that initialize `$_SESSION = []`, assert a 64-character hexadecimal token, accept the matching token, reject missing/mismatched tokens with status 403, and assert `rotate()` invalidates the old token.

- [ ] **Step 4: Run tests and verify the missing-class failure**

Run: `php vendor/bin/phpunit tests/Unit/HttpFoundationTest.php tests/Unit/SecurityPrimitivesTest.php`

Expected: FAIL because the five foundation classes do not exist.

- [ ] **Step 5: Implement the minimal HTTP foundation**

`Request::fromGlobals()` checks `CONTENT_LENGTH` before reading and reads at most 1,048,577 bytes from `php://input`, so the ceiling is enforced before retaining an unbounded body. `Request::json()` requires media type `application/json`, checks `strlen($body) <= 1048576`, uses `json_decode(..., true, 512, JSON_THROW_ON_ERROR)`, and rejects non-object top-level JSON; endpoints enforce their own methods through `requireMethod()`. `JsonResponse::send()` sets the status, JSON content type, `Cache-Control: no-store`, `X-Request-ID`, encodes with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`, prints, and exits.

```php
final class ApiException extends RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly string $safeMessage,
        private readonly array $fieldErrors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    public function status(): int { return $this->httpStatus; }
    public function publicMessage(): string { return $this->safeMessage; }
    public function errors(): array { return $this->fieldErrors; }
}
```

`Endpoint::run()` catches `ApiException` and sends its safe status/message/errors; it catches all other `Throwable`, logs the correlation ID plus exception class/message/trace via `error_log()`, and returns status 500 with only `Unexpected server error. Reference: <request-id>`.

`config/bootstrap.php` must load Composer and Dotenv, set UTC, configure strict/HttpOnly/SameSite session cookies before `session_start()`, emit the request ID header, and never enable `display_errors` in production. `config/database.php` must create PDO with exceptions, associative fetches, native prepares, and `SET time_zone = '+00:00'`.

- [ ] **Step 6: Run foundation tests and syntax checks**

Run: `php vendor/bin/phpunit tests/Unit/HttpFoundationTest.php tests/Unit/SecurityPrimitivesTest.php`

Expected: PASS.

Run: `php -l config/bootstrap.php && php -l config/database.php && php -l src/Endpoint.php`

Expected: each file reports no syntax errors.

- [ ] **Step 7: Review checkpoint**

```bash
git add composer.json composer.lock .env.example .gitignore phpunit.xml tests/bootstrap.php tests/Unit src/ApiException.php src/JsonResponse.php src/Request.php src/Csrf.php src/Endpoint.php config
git commit -m "feat: add secure PHP runtime foundation"
```

### Task 2: Normalized Schema and Isolated Database Test Harness

**Files:**
- Create: `database/schema.sql`
- Create: `tests/Support/DatabaseTestCase.php`
- Test: `tests/Integration/PdsQueryTest.php`

**Interfaces:**
- Produces: tables `staff_users`, `login_throttles`, `pds_records`, `pds_children`, `pds_education`, and `audit_log`.
- Produces: `DatabaseTestCase::pdo(): PDO`, `DatabaseTestCase::resetDatabase(): void`, and `DatabaseTestCase::createStaffUser(array $overrides = []): int`.
- Consumes: environment variables and PDO factory rules from Task 1.

- [ ] **Step 1: Write the failing schema smoke test**

```php
public function testSchemaCreatesAllTablesAndCoreConstraints(): void
{
    $tables = $this->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    self::assertEqualsCanonicalizing(
        ['staff_users', 'login_throttles', 'pds_records', 'pds_children', 'pds_education', 'audit_log'],
        $tables
    );

    $this->expectException(PDOException::class);
    $this->pdo()->exec("INSERT INTO pds_education (pds_id, level) VALUES (999999, 'college')");
}
```

`DatabaseTestCase::setUpBeforeClass()` skips with a precise message when `TEST_DB_DSN` is empty; otherwise it connects only to the isolated test database, imports `database/schema.sql`, and refuses to run when `APP_ENV !== 'test'`.

- [ ] **Step 2: Run the schema test and verify failure**

Run: `php vendor/bin/phpunit tests/Integration/PdsQueryTest.php --filter Schema`

Expected with configured test DB: FAIL because `database/schema.sql` is absent. Expected without it: SKIP naming `TEST_DB_DSN`.

- [ ] **Step 3: Create the complete schema**

Use `BIGINT UNSIGNED AUTO_INCREMENT`, InnoDB, and the MySQL 8/MariaDB-compatible `utf8mb4_unicode_ci` collation throughout. Define all scalar columns from the canonical contract above, plus:

```sql
version INT UNSIGNED NOT NULL DEFAULT 1,
created_by BIGINT UNSIGNED NOT NULL,
updated_by BIGINT UNSIGNED NOT NULL,
created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
archived_at DATETIME(6) NULL,
archived_by BIGINT UNSIGNED NULL,
UNIQUE KEY uq_pds_agency_employee_number (agency_employee_number),
KEY idx_pds_name (surname, first_name),
KEY idx_pds_email (email),
KEY idx_pds_archive_updated (archived_at, updated_at)
```

`pds_children` has `full_name VARCHAR(200)`, nullable `birth_date`, and nonnegative `sort_order`. `pds_education` uses an enum for the five canonical levels, the exact length limits from the design, and `UNIQUE (pds_id, level)`. Both related tables cascade on PDS deletion at the database level even though the browser has no delete feature.

`login_throttles.throttle_key` is `CHAR(64)` and stores no raw identifier. `audit_log.action` is an enum of the six documented actions, `metadata` is nullable JSON, and the PDS foreign key uses `ON DELETE SET NULL` so audit history remains valid.

- [ ] **Step 4: Implement safe database reset and fixture creation**

`resetDatabase()` disables foreign-key checks only for truncation, truncates child/audit/PDS/throttle/staff tables in dependency order, then always re-enables checks in a `finally` block. `createStaffUser()` inserts `password_hash('Correct Horse Battery Staple', PASSWORD_DEFAULT)` and returns the ID.

- [ ] **Step 5: Run the schema test**

Run: `php vendor/bin/phpunit tests/Integration/PdsQueryTest.php --filter Schema`

Expected: PASS with a configured isolated database, otherwise the intentional SKIP.

- [ ] **Step 6: Review checkpoint**

```bash
git add database/schema.sql tests/Support/DatabaseTestCase.php tests/Integration/PdsQueryTest.php
git commit -m "feat: add normalized PDS database schema"
```

### Task 3: Authentication, Throttling, Audit, and Login UI

**Files:**
- Create: `src/AuditLogger.php`
- Create: `src/LoginThrottle.php`
- Create: `src/Auth.php`
- Create: `api/auth/login.php`
- Create: `api/auth/logout.php`
- Create: `login.php`
- Create: `assets/login.js`
- Create: `scripts/create-staff-user.php`
- Test: `tests/Integration/AuthenticationTest.php`
- Test: `tests/Unit/SecurityPrimitivesTest.php`

**Interfaces:**
- Produces: `LoginThrottle::assertAllowed(string $normalizedUsername, string $sourceAddress): void`, `recordFailure(...)`, and `clear(...)`.
- Produces: `Auth::attempt(string, string, string): array`, `Auth::requireUser(): array`, `Auth::requirePageUser(string $loginPath = 'login.php'): array`, `Auth::establish(array): void`, and `Auth::logout(): void`. API authentication throws a safe `ApiException`; page authentication redirects before output.
- Produces: `AuditLogger::record(?int $staffId, ?int $pdsId, string $action, array $metadata = []): void`.
- Consumes: `Csrf`, `Request`, `Endpoint`, `app_pdo()`, and schema from Tasks 1–2.

- [ ] **Step 1: Write failing authentication and throttle tests**

Cover all of these exact cases:

```php
public function testSuccessfulLoginRegeneratesSessionAndAuditsWithoutSensitiveData(): void;
public function testInvalidUsernameAndInvalidPasswordReturnTheSameMessage(): void;
public function testFiveFailuresLockBothAccountAndAddressForFifteenMinutes(): void;
public function testSuccessfulLoginClearsBothThrottleRows(): void;
public function testInactiveAccountCannotLoginOrContinueAnExistingSession(): void;
public function testSessionExpiresAfterThirtyMinutesOfInactivity(): void;
public function testLogoutClearsSessionAndRotatesCsrfToken(): void;
public function testAuditLoggerRejectsUnknownActionsAndSensitiveMetadataKeys(): void;
```

The audit test inspects encoded metadata and asserts it contains none of `password`, `token`, `csrf`, `umid`, `pagibig`, `philhealth`, `philsys`, `tin`, `payload`, `username`, or `source_address`.

- [ ] **Step 2: Run tests and verify failure**

Run: `php vendor/bin/phpunit tests/Unit/SecurityPrimitivesTest.php tests/Integration/AuthenticationTest.php`

Expected: FAIL because auth/throttle/audit classes are absent.

- [ ] **Step 3: Implement keyed-HMAC throttling**

Normalize usernames with `mb_strtolower(trim($username), 'UTF-8')`. Compute separate keys with:

```php
hash_hmac('sha256', 'account:' . $normalizedUsername, $_ENV['APP_KEY']);
hash_hmac('sha256', 'address:' . $sourceAddress, $_ENV['APP_KEY']);
```

Within one transaction, lock each relevant throttle row, reset an expired 15-minute window, increment failures, and set `locked_until` on the fifth failure. `assertAllowed()` returns status 429 with a generic message when either key is locked. Clear both keys on success. Expired rows are deleted opportunistically after successful login and when updated more than 24 hours after the end of their lock/window.

- [ ] **Step 4: Implement session authentication and safe audit logging**

On success, call `session_regenerate_id(true)`, rotate the CSRF token, and store only:

```php
$_SESSION['staff'] = [
    'id' => (int) $user['id'],
    'display_name' => (string) $user['display_name'],
    'last_activity' => time(),
];
```

`requireUser()` rejects missing/expired sessions with 401 for APIs, queries `staff_users.is_active` on every protected request, returns 403 for disabled accounts, and updates `last_activity`. `requirePageUser()` performs the same checks but clears an invalid session and redirects to the supplied login path before any HTML. Authentication failures always use `Invalid username or password.`; lockout uses `Too many login attempts. Try again later.`. Resolve the source address from `REMOTE_ADDR` by default; honor `X-Forwarded-For` only when `REMOTE_ADDR` exactly matches the configured `TRUSTED_PROXY`, and then accept only the first syntactically valid address.

`Auth::attempt()` records `login_success` with the resolved staff ID and `login_failure` with a nullable staff ID; both use empty metadata so attempted usernames, source addresses, and credentials cannot enter the audit row. `AuditLogger` must allow only `login_success`, `login_failure`, `create`, `update`, `archive`, and `restore`, and recursively reject metadata keys matching the sensitive denylist.

- [ ] **Step 5: Implement login/logout endpoints and pages**

`login.php` starts the anonymous session through bootstrap, redirects already-authenticated staff to `index.php`, emits `<meta name="csrf-token">`, and renders accessible username/password/error fields. `assets/login.js` sends JSON plus `X-CSRF-Token`, disables submit while pending, uses `.text()` for messages, and redirects to `index.php` only after success.

`api/auth/login.php` requires POST JSON and CSRF before calling `Auth::attempt()`. `api/auth/logout.php` uses `Endpoint::protected(..., requireCsrf: true, ...)`, destroys the session, and returns the standard envelope.

- [ ] **Step 6: Implement the CLI account creator**

Require CLI SAPI and arguments `--username`, `--display-name`, and either `--password-stdin` or an environment variable `PDS_STAFF_PASSWORD`. Reject terminal command-line password arguments so passwords do not enter shell history. Normalize the username, hash the password with `PASSWORD_DEFAULT`, and return exit 1 for duplicate usernames.

- [ ] **Step 7: Run tests and syntax checks**

Run: `php vendor/bin/phpunit tests/Unit/SecurityPrimitivesTest.php tests/Integration/AuthenticationTest.php`

Expected: PASS or database-dependent tests SKIP only when test DB variables are absent.

Run: `php -l login.php && php -l api/auth/login.php && php -l api/auth/logout.php && php -l scripts/create-staff-user.php`

Expected: no syntax errors.

- [ ] **Step 8: Review checkpoint**

```bash
git add src/AuditLogger.php src/LoginThrottle.php src/Auth.php api/auth login.php assets/login.js scripts/create-staff-user.php tests/Integration/AuthenticationTest.php tests/Unit/SecurityPrimitivesTest.php
git commit -m "feat: add authenticated staff sessions"
```

### Task 4: Authoritative PDS Validation and Normalization

**Files:**
- Create: `src/PdsValidator.php`
- Test: `tests/Unit/PdsValidatorTest.php`

**Interfaces:**
- Produces: `PdsValidator::validateCreate(array $input): array` and `PdsValidator::validateUpdate(array $input): array`.
- Returns normalized `record`, `children`, and five ordered education objects; update additionally returns positive integer `id` and `version`.
- Throws `ApiException(422, 'Please correct the highlighted fields.', $errors)` for validation failures.

- [ ] **Step 1: Build a complete valid-payload fixture in the test**

The fixture must include every canonical scalar key, two children, and all five education objects. Use canonical lower-case API enum values and ISO dates. Assert blank optional strings normalize to `null`, numeric strings normalize to floats/integers, completely blank children are omitted, and empty education levels return level-specific blank objects.

- [ ] **Step 2: Add failing parameterized validation tests**

Cover unknown top-level, record, child, and education keys; missing surname/first name/birth date; invalid calendar/future dates; every enum; email; positive height/weight caps; all string limits; dual-citizenship dependency; agency number length; partial children; education year range/order; graduation-before-start; duplicate/missing education levels; signature date; non-object record/items; and invalid update identity/version.

Use exact error paths such as:

```php
[
    'record.surname' => 'Surname is required.',
    'children.1.birth_date' => 'Birth date is required when a child name is provided.',
    'education.college.attendance_to_year' => 'Attendance end year cannot be earlier than the start year.',
]
```

- [ ] **Step 3: Run tests and verify failure**

Run: `php vendor/bin/phpunit tests/Unit/PdsValidatorTest.php`

Expected: FAIL because `PdsValidator` does not exist.

- [ ] **Step 4: Implement explicit allowlists and rules**

Define the exact 53-key allowlist below plus a per-field maximum map matching the locked contract. Do not loop over arbitrary request keys when constructing normalized output. Validate exact top-level keys `record`, `children`, `education` for create and those plus `id`, `version` for update.

```php
private const RECORD_FIELDS = [
    'surname', 'first_name', 'name_extension', 'middle_name',
    'birth_date', 'birth_place', 'sex_at_birth', 'height_m', 'weight_kg', 'blood_type',
    'umid_number', 'pagibig_number', 'philhealth_number', 'philsys_number',
    'tin_number', 'agency_employee_number',
    'citizenship_type', 'dual_citizenship_details',
    'residential_house', 'residential_street', 'residential_subdivision',
    'residential_barangay', 'residential_city', 'residential_province', 'residential_zip',
    'permanent_house', 'permanent_street', 'permanent_subdivision',
    'permanent_barangay', 'permanent_city', 'permanent_province', 'permanent_zip',
    'telephone', 'mobile', 'email',
    'spouse_surname', 'spouse_first_name', 'spouse_name_extension', 'spouse_middle_name',
    'spouse_occupation', 'spouse_employer', 'spouse_business_address', 'spouse_telephone',
    'father_surname', 'father_first_name', 'father_name_extension', 'father_middle_name',
    'mother_surname', 'mother_first_name', 'mother_name_extension', 'mother_middle_name',
    'signatory_name', 'signature_date',
];
```

Use strict `DateTimeImmutable::createFromFormat('!Y-m-d', $value)` plus round-trip formatting. Year bounds are `1900` through `(int) date('Y') + 1`. Citizenship details normalize to `null` when type is not `dual`, regardless of supplied text. Sort children by input order and education by `elementary`, `secondary`, `vocational`, `college`, `graduate`.

- [ ] **Step 5: Run validator tests**

Run: `php vendor/bin/phpunit tests/Unit/PdsValidatorTest.php`

Expected: PASS.

- [ ] **Step 6: Review checkpoint**

```bash
git add src/PdsValidator.php tests/Unit/PdsValidatorTest.php
git commit -m "feat: validate and normalize PDS payloads"
```

### Task 5: Repository Queries, Search, Projection, and Complete Fetch

**Files:**
- Create: `src/PdsRepository.php`
- Modify: `tests/Integration/PdsQueryTest.php`

**Interfaces:**
- Produces: `PdsRepository::list(string $query, int $page, int $pageSize, string $status): array` returning `items` plus `{page,page_size,total,total_pages}`.
- Produces: `PdsRepository::find(int $id): ?array` returning scalar `record`, ordered `children`, and five ordered `education` objects.
- Produces internal mutation helpers later consumed by `PdsService` without owning transactions.

- [ ] **Step 1: Write failing list and fetch integration tests**

Seed active and archived records containing distinctive surname, first name, middle name, employee number, email, and government identifiers. Assert:

- blank search paginates at 10 by default;
- page size cannot exceed 50;
- each of the five search fields matches case-insensitively;
- active and archived statuses never mix;
- order is newest `updated_at`, then descending ID;
- list items contain only `id`, `version`, `display_name`, `agency_employee_number`, `birth_date`, `email`, `updated_at`, and `is_archived`;
- no government identifier key/value appears in serialized list data;
- `find()` returns complete related rows and blank objects for omitted education levels;
- a missing ID returns `null`.

- [ ] **Step 2: Run tests and verify failure**

Run: `php vendor/bin/phpunit tests/Integration/PdsQueryTest.php --filter 'List|Find'`

Expected with test DB: FAIL because `PdsRepository` is absent.

- [ ] **Step 3: Implement bounded search and pagination**

Trim `q`, reject over 100 characters, validate `status` against `active|archived`, clamp page to at least 1, and reject page sizes below 1 or above 50 with status 400. Escape `%`, `_`, and `\\` before constructing a bound `LIKE` value and include `ESCAPE '\\'` in SQL.

Run separate count and projection queries using only bound parameters. Construct `display_name` in PHP from first, middle, surname, and extension so database-specific concatenation does not leak into the contract.

- [ ] **Step 4: Implement complete fetch**

Fetch the scalar row by ID, then children ordered by `sort_order,id`, then education. Map date/decimal values to JSON-safe strings/numbers and merge education rows into this fixed order:

```php
['elementary', 'secondary', 'vocational', 'college', 'graduate']
```

Every absent level receives all seven nullable fields set to `null` while preserving its `level`.

- [ ] **Step 5: Run query tests**

Run: `php vendor/bin/phpunit tests/Integration/PdsQueryTest.php`

Expected: PASS or intentional test-DB SKIP.

- [ ] **Step 6: Review checkpoint**

```bash
git add src/PdsRepository.php tests/Integration/PdsQueryTest.php
git commit -m "feat: query and search PDS records"
```

### Task 6: Transactional Create and Update with Optimistic Concurrency

**Files:**
- Create: `src/PdsService.php`
- Modify: `src/PdsRepository.php`
- Create: `tests/Integration/PdsMutationTest.php`

**Interfaces:**
- Produces: `PdsService::create(array $input, int $staffId): array`.
- Produces: `PdsService::update(array $input, int $staffId): array`.
- Produces: `PdsService::get(int $id): array` and `PdsService::list(string, int, int, string): array` pass-throughs.
- Consumes: `PdsValidator`, `PdsRepository`, `AuditLogger`, and one shared PDO connection.

- [ ] **Step 1: Write failing complete-create tests**

Assert a valid complete payload creates version 1, stores all scalar fields, zero/one/multiple child cases, all five education rows when populated, omits completely blank rows, records exactly one `create` audit event, and never puts government identifiers into audit metadata.

- [ ] **Step 2: Write failing update/concurrency/rollback tests**

Assert a valid update increments 1 to 2, replaces children and education atomically, records `{from_version:1,to_version:2}`, and preserves creator metadata. Then update once more and assert replaying version 1 returns 409 and leaves every table unchanged. Trigger a controlled related-row failure with an overlong test-only fixture and assert the main update, replacements, and audit event all roll back.

Assert duplicate non-null agency numbers return 409 with field error `record.agency_employee_number`; multiple null agency numbers remain valid.

- [ ] **Step 3: Run mutation tests and verify failure**

Run: `php vendor/bin/phpunit tests/Integration/PdsMutationTest.php --filter 'Create|Update|Conflict|Rollback'`

Expected with test DB: FAIL because service/mutation methods are absent.

- [ ] **Step 4: Implement repository mutation helpers**

Use an explicit scalar-column allowlist to generate insert/update SQL. Implement:

```php
insertRecord(array $record, int $staffId): int;
updateRecord(int $id, int $expectedVersion, array $record, int $staffId): bool;
replaceChildren(int $pdsId, array $children): void;
replaceEducation(int $pdsId, array $education): void;
currentVersionAndStatus(int $id): ?array;
```

The update predicate is `WHERE id = :id AND version = :expected_version AND archived_at IS NULL`, and it sets `version = version + 1`. Never interpolate request-provided column names.

- [ ] **Step 5: Implement service transaction boundaries**

Use this exact transaction shape for every mutation:

```php
$this->pdo->beginTransaction();
try {
    // main row, related rows, audit row
    $this->pdo->commit();
} catch (Throwable $error) {
    if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
    }
    throw $this->translateDatabaseException($error);
}
```

When update row count is zero, inspect current version/status: missing means 404; otherwise return 409 with `This record changed since it was loaded. Reload it before reapplying your changes.` Never retry or merge automatically.

Translate only recognized unique-constraint violations for `uq_pds_agency_employee_number`; unknown PDO exceptions remain internal and reach the generic 500 handler.

- [ ] **Step 6: Run mutation tests**

Run: `php vendor/bin/phpunit tests/Integration/PdsMutationTest.php --filter 'Create|Update|Conflict|Rollback'`

Expected: PASS or intentional test-DB SKIP.

- [ ] **Step 7: Review checkpoint**

```bash
git add src/PdsService.php src/PdsRepository.php tests/Integration/PdsMutationTest.php
git commit -m "feat: create and update PDS records atomically"
```

### Task 7: Archive and Restore Lifecycle

**Files:**
- Modify: `src/PdsRepository.php`
- Modify: `src/PdsService.php`
- Modify: `tests/Integration/PdsMutationTest.php`

**Interfaces:**
- Produces: `PdsService::archive(int $id, int $version, int $staffId): array`.
- Produces: `PdsService::restore(int $id, int $version, int $staffId): array`.
- Returns `{id, version, is_archived}` for both operations.

- [ ] **Step 1: Write failing archive/restore tests**

Assert archive sets `archived_at`, `archived_by`, and version 2; removes the row from active search; adds it to archived search; records a minimal archive audit event; and rejects the old version with 409. Assert restore clears both archive columns, increments to version 3, reverses search visibility, records restore, and rejects restoring an already active record with 409.

- [ ] **Step 2: Run tests and verify failure**

Run: `php vendor/bin/phpunit tests/Integration/PdsMutationTest.php --filter 'Archive|Restore'`

Expected: FAIL because lifecycle methods are absent.

- [ ] **Step 3: Implement conditional lifecycle updates**

Archive predicate: `id = :id AND version = :version AND archived_at IS NULL`.

Restore predicate: `id = :id AND version = :version AND archived_at IS NOT NULL`.

Both increment version inside the same transaction as their audit event. Metadata includes only `from_version` and `to_version`.

- [ ] **Step 4: Run lifecycle tests**

Run: `php vendor/bin/phpunit tests/Integration/PdsMutationTest.php --filter 'Archive|Restore'`

Expected: PASS or intentional test-DB SKIP.

- [ ] **Step 5: Review checkpoint**

```bash
git add src/PdsRepository.php src/PdsService.php tests/Integration/PdsMutationTest.php
git commit -m "feat: archive and restore PDS records"
```

### Task 8: Protected PDS JSON Endpoints

**Files:**
- Create: `api/pds/list.php`
- Create: `api/pds/get.php`
- Create: `api/pds/create.php`
- Create: `api/pds/update.php`
- Create: `api/pds/archive.php`
- Create: `api/pds/restore.php`
- Modify: `src/Endpoint.php`
- Test: `tests/Unit/SecurityPrimitivesTest.php`

**Interfaces:**
- Produces the six API contracts from spec section 7.
- Consumes `Endpoint::protected()`, request parsing, `PdsService`, and authenticated staff ID.

- [ ] **Step 1: Write a failing endpoint-policy test**

Create a data provider listing all six endpoint paths and whether they are state-changing. The test reads each endpoint source and asserts it calls the single protected wrapper; mutation endpoints additionally pass `requireCsrf: true`. Add behavioral unit tests around the wrapper proving authentication runs before a handler and CSRF runs before every mutation handler.

- [ ] **Step 2: Run the policy test and verify failure**

Run: `php vendor/bin/phpunit tests/Unit/SecurityPrimitivesTest.php --filter Endpoint`

Expected: FAIL because the endpoint files do not exist.

- [ ] **Step 3: Add a single service factory to bootstrap**

Add `app_auth(): Auth` and `app_pds_service(): PdsService` factories. Both reuse the request-scoped PDO; the auth factory constructs `LoginThrottle` and `AuditLogger`, while the PDS factory constructs `PdsValidator`, `PdsRepository`, and `AuditLogger`. Do not instantiate separate PDO connections for auth/service/repository/audit within one request.

- [ ] **Step 4: Implement thin endpoints**

Each endpoint contains only strict bootstrap, request parsing, protected wrapper invocation, one service call, and `JsonResponse::send()`. Exact success statuses/messages:

| Endpoint | Status | Message |
| --- | ---: | --- |
| list/get | 200 | `Records loaded.` / `Record loaded.` |
| create | 201 | `Personal data sheet created.` |
| update | 200 | `Personal data sheet updated.` |
| archive | 200 | `Personal data sheet archived.` |
| restore | 200 | `Personal data sheet restored.` |

List parses `q`, `page`, `page_size`, and `status`; get validates a positive `id`; mutations require POST JSON. Archive/restore allow exactly `id` and `version` and reject extra keys.

- [ ] **Step 5: Run policy and syntax checks**

Run: `php vendor/bin/phpunit tests/Unit/SecurityPrimitivesTest.php --filter Endpoint`

Expected: PASS.

Run: `php -l api/pds/list.php && php -l api/pds/get.php && php -l api/pds/create.php && php -l api/pds/update.php && php -l api/pds/archive.php && php -l api/pds/restore.php`

Expected: no syntax errors.

- [ ] **Step 6: Review checkpoint**

```bash
git add api/pds src/Endpoint.php config/bootstrap.php tests/Unit/SecurityPrimitivesTest.php
git commit -m "feat: expose protected PDS JSON endpoints"
```

### Task 9: Authenticated Dashboard and Accessible Form Markup

**Files:**
- Create: `tests/Support/HttpServer.php`
- Create: `tests/Support/HttpClient.php`
- Create: `tests/Integration/HttpApiTest.php`
- Create: `index.php` by converting `index.html`
- Delete: `index.html` after successful conversion review
- Modify: `style.css`

**Interfaces:**
- Produces stable DOM hooks consumed by `assets/app.js`: `#record-search`, `#record-status`, `#records-body`, `#pagination`, `#add-pds`, `#pds-form`, `#form-title`, `#form-errors`, `#children-body`, `#add-child`, `#save-pds`, `#reset-pds`, `#logout`, and `#app-status`.
- Every scalar control uses its canonical snake-case `name`; education rows/controls use `data-education-level` and `data-education-field`; child rows/controls use `data-child-row` and `data-child-field`.

- [ ] **Step 1: Implement the reusable HTTP test harness**

`HttpServer` reserves a loopback port, starts `PHP_BINARY -S 127.0.0.1:<port> -t <repo-root>` with `APP_ENV=test` and test DB variables, waits up to five seconds for `login.php`, captures stderr to a temporary file, and always terminates the child process in teardown. `HttpClient` uses stream contexts with `ignore_errors => true`, preserves the PHP session cookie, never follows redirects implicitly, sends arbitrary headers/JSON, and returns `{status, headers, json, body}`.

- [ ] **Step 2: Write failing authenticated-page markup tests**

Assert unauthenticated `GET /index.php` redirects to `/login.php`. Log in through the real CSRF-protected endpoint with a seeded staff account, then assert the dashboard contains a CSRF meta element, all required DOM hooks, every canonical scalar name exactly once except `citizenship_type` (two radio controls), all five education levels, and no `index.html` fallback.

- [ ] **Step 3: Run the markup test and verify failure**

Run: `php vendor/bin/phpunit tests/Integration/HttpApiTest.php --filter DashboardMarkup`

Expected with test DB: FAIL because `index.php` does not exist.

- [ ] **Step 4: Convert the static prototype into authenticated dashboard markup**

Call bootstrap and the request-scoped `Auth::requirePageUser('login.php')` before any HTML. Emit CSRF and signed-in display name safely with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

Add the records panel before the form: search input, active/archived select, Add PDS button, responsive table with the six documented columns plus actions, loading/empty/error region, and pagination navigation.

Retain all existing PDS sections, replace the warning placeholder text, set canonical names/IDs, add `required`, `maxlength`, `min`, `max`, and `step` attributes matching server rules, and provide validation anchors. Children start with one removable row and an Add child button. Education renders exactly five fixed rows. Change “e-signature/digital certificate” copy to “Typed signatory-name acknowledgment.”

- [ ] **Step 5: Add accessible form/status structures and responsive CSS**

`#form-errors` is focusable, initially hidden, and has `role="alert"`. `#app-status` uses `role="status" aria-live="polite"`. Table containers own horizontal overflow; `body` never does. Add visible focus styles, invalid feedback, pending disabled states, mobile action stacking, and no custom color contrast below WCAG AA.

- [ ] **Step 6: Review conversion before deleting the prototype**

Compare every original control in `index.html` against the canonical field table and `index.php`. Only after the check passes, remove `index.html` so Apache resolves `index.php`.

- [ ] **Step 7: Run markup and syntax checks**

Run: `php vendor/bin/phpunit tests/Integration/HttpApiTest.php --filter DashboardMarkup`

Expected: PASS or intentional test-DB SKIP.

Run: `php -l index.php`

Expected: no syntax errors.

- [ ] **Step 8: Review checkpoint**

```bash
git add index.php style.css tests/Support/HttpServer.php tests/Support/HttpClient.php tests/Integration/HttpApiTest.php
git rm index.html
git commit -m "feat: convert PDS form into authenticated dashboard"
```

### Task 10: jQuery CRUD Client and Form State

**Files:**
- Create: `assets/app.js`
- Modify: `index.php`
- Modify: `style.css`

**Interfaces:**
- Consumes all DOM hooks from Task 9 and all endpoints from Task 8.
- Maintains in-memory state only: `{mode, recordId, version, dirty, savePending, listRequest, query, page, pageSize, status}`.
- Produces no cookies, localStorage, sessionStorage, IndexedDB, or sensitive URL parameters.

- [ ] **Step 1: Define the explicit scalar field map and state model**

Use the exact field list below; each non-radio control has an ID equal to its key, while citizenship uses the explicit checked-radio selector. Do not use `serialize()`, `serializeArray()`, or arbitrary `[name]` iteration. Define fixed education order and a child-row factory that builds DOM nodes with jQuery, sets values with `.val()`, and text with `.text()`.

```js
const RECORD_FIELDS = Object.freeze([
  'surname', 'first_name', 'name_extension', 'middle_name',
  'birth_date', 'birth_place', 'sex_at_birth', 'height_m', 'weight_kg', 'blood_type',
  'umid_number', 'pagibig_number', 'philhealth_number', 'philsys_number',
  'tin_number', 'agency_employee_number',
  'citizenship_type', 'dual_citizenship_details',
  'residential_house', 'residential_street', 'residential_subdivision',
  'residential_barangay', 'residential_city', 'residential_province', 'residential_zip',
  'permanent_house', 'permanent_street', 'permanent_subdivision',
  'permanent_barangay', 'permanent_city', 'permanent_province', 'permanent_zip',
  'telephone', 'mobile', 'email',
  'spouse_surname', 'spouse_first_name', 'spouse_name_extension', 'spouse_middle_name',
  'spouse_occupation', 'spouse_employer', 'spouse_business_address', 'spouse_telephone',
  'father_surname', 'father_first_name', 'father_name_extension', 'father_middle_name',
  'mother_surname', 'mother_first_name', 'mother_name_extension', 'mother_middle_name',
  'signatory_name', 'signature_date'
]);
const EDUCATION_LEVELS = Object.freeze(['elementary', 'secondary', 'vocational', 'college', 'graduate']);
const DECIMAL_FIELDS = new Set(['height_m', 'weight_kg']);
```

- [ ] **Step 2: Implement safe common AJAX behavior**

Read CSRF from the meta element at request time. Mutation helper sends `Content-Type: application/json` and `X-CSRF-Token`. On 401, clear no storage (none exists) and navigate to `login.php`. On 403/409/422, preserve the form and route messages to the appropriate UI. Never log request/response payloads.

- [ ] **Step 3: Implement debounced list/search/pagination rendering**

Use a 300 ms timer; reset page to 1 on query/status changes; abort the prior jqXHR before a new request. Keep existing rows during loading and failure. Render all values through created cells plus `.text()`, use Edit/Archive actions for active rows and Restore for archived rows, and use delegated click handlers with numeric IDs/version stored through `.data()`.

- [ ] **Step 4: Implement explicit serialization and population**

`buildPayload()` returns exactly:

```js
function buildPayload() {
  const record = {};
  RECORD_FIELDS.forEach((key) => {
    const raw = key === 'citizenship_type'
      ? $('[name="citizenship_type"]:checked').val()
      : $(`#${key}`).val();
    const value = typeof raw === 'string' ? raw.trim() : '';
    record[key] = DECIMAL_FIELDS.has(key) && value !== '' ? Number(value) : (value === '' ? null : value);
  });

  const children = $('[data-child-row]').map(function () {
    const fullName = $(this).find('[data-child-field="full_name"]').val().trim();
    const birthDate = $(this).find('[data-child-field="birth_date"]').val().trim();
    return { full_name: fullName || null, birth_date: birthDate || null };
  }).get();

  const education = EDUCATION_LEVELS.map((level) => {
    const row = $(`[data-education-level="${level}"]`);
    const text = (field) => row.find(`[data-education-field="${field}"]`).val().trim() || null;
    const year = (field) => {
      const value = text(field);
      return value === null ? null : Number(value);
    };
    return {
      level,
      school_name: text('school_name'),
      degree_course: text('degree_course'),
      attendance_from_year: year('attendance_from_year'),
      attendance_to_year: year('attendance_to_year'),
      highest_units_earned: text('highest_units_earned'),
      graduation_year: year('graduation_year'),
      honors: text('honors')
    };
  });

  return { record, children, education };
}
```

Convert `height_m` and `weight_kg` to finite numbers or `null`; convert education years to integers or `null`. Populate radios/selects explicitly, rebuild any number of children, and always show five education rows.

- [ ] **Step 5: Implement Add/Edit/Save modes**

Add mode clears errors and values, creates one empty child, preserves fixed education rows, sets “Add Personal Data Sheet,” and focuses surname. Edit fetches complete data and stores ID/version only in JavaScript state. Save disables submit, calls create/update according to mode, resets on success, and refreshes the current list/page.

- [ ] **Step 6: Implement archive/restore and dirty-state guards**

Archive confirmation includes the safely rendered person’s name and says it can be restored. Archived rows have no Edit action. Guard reset, add, edit, logout, and `beforeunload` when dirty. Programmatic population/reset temporarily suppresses dirty tracking. Logout remains POST+CSRF.

- [ ] **Step 7: Implement validation display and request status**

Clear prior errors, map server paths to scalar controls, current child indexes, and education `data-education-level/data-education-field` controls, add `.is-invalid`, fill or create adjacent `.invalid-feedback`, render a summary using DOM text nodes, focus the summary on 422, and announce save/list outcomes in the live region.

- [ ] **Step 8: Perform static client security checks**

Run: `rg "\.html\(|innerHTML|localStorage|sessionStorage|serialize(Array)?\(|console\.log" assets/app.js assets/login.js`

Expected: no matches.

Run: `rg "jquery-3\.7\.1|min\.js|integrity=|crossorigin=" index.php login.php`

Expected: pinned jQuery/Bootstrap assets include SRI and `crossorigin="anonymous"`.

- [ ] **Step 9: Review checkpoint**

```bash
git add assets/app.js index.php style.css
git commit -m "feat: add AJAX PDS dashboard interactions"
```

### Task 11: HTTP Integration Coverage, Apache Protection, Documentation, and Final Verification

**Files:**
- Modify: `tests/Support/HttpServer.php`
- Modify: `tests/Support/HttpClient.php`
- Complete: `tests/Integration/HttpApiTest.php`
- Create: `.htaccess`
- Replace: `README.md`
- Modify: `.env.example`

**Interfaces:**
- Consumes the test-only server and cookie-preserving HTTP client from Task 9.
- Documents the complete installation, operation, test, and production-security contract.

- [ ] **Step 1: Write the end-to-end HTTP tests**

Cover:

```text
login CSRF missing -> 403
invalid login -> 401 generic message
fifth failed login -> 429
successful login -> 200 and session cookie
all six PDS endpoints unauthenticated -> 401
all four mutation endpoints without CSRF -> 403
complete create -> 201/version 1
get -> exact complete payload
list -> safe projection with no government identifiers
update -> 200/version 2
stale update -> 409 and unchanged data
archive -> absent from active and present in archived
restore -> present in active
logout with CSRF -> 200; subsequent list -> 401
malformed JSON -> 400
wrong content type -> 400
unknown fields -> 422
oversized body -> 400
```

Also query the audit table and assert sensitive fixture values are absent from every metadata value.

- [ ] **Step 2: Run HTTP tests and fix only observed failures**

Run: `php vendor/bin/phpunit tests/Integration/HttpApiTest.php`

Expected: PASS with configured test DB; otherwise one explicit suite-level SKIP.

- [ ] **Step 3: Add Apache protections**

Set `DirectoryIndex index.php`, disable indexes, deny web access to `.env`, Composer metadata/lock files where appropriate, `config`, `database`, `docs`, `scripts`, `src`, `tests`, and `vendor`, and add `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, and a same-origin-focused Content Security Policy compatible only with the pinned jQuery/Bootstrap CDN hosts. Explain equivalent Nginx/IIS rules in README rather than claiming `.htaccess` protects non-Apache servers.

- [ ] **Step 4: Write the complete README**

Document exact prerequisites (`pdo_mysql`, `mbstring`, `json`, `session`), Composer install, test/production database creation, schema import, `.env` creation, generating a strong `APP_KEY`, account creation through stdin, XAMPP virtual-host setup, test execution, production HTTPS/cookie/error-display/storage/backup/database-least-privilege controls, trusted-proxy caveats, internal-directory blocking, and the browser smoke checklist from spec section 14.

Do not include a default username/password, real secret, or production DB host.

- [ ] **Step 5: Run the full automated verification suite**

Run: `composer validate --strict`

Expected: valid Composer configuration.

Run: `composer test`

Expected: all unit tests pass; integration tests pass with configured isolated MySQL/MariaDB or are clearly skipped only because test DB variables are absent.

Run: `php -r "$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.')); foreach($it as $f){if($f->isFile()&&$f->getExtension()==='php'&&!str_contains($f->getPathname(),DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)){passthru(PHP_BINARY.' -l '.escapeshellarg($f->getPathname()),$c); if($c) exit($c);}}"`

Expected: every project PHP file has no syntax errors.

- [ ] **Step 6: Run database security assertions**

Run the integration suite and inspect failures specifically for prepared-statement emulation, list projection, audit metadata, transaction rollback, stale-write protection, active/archive filtering, and session/CSRF enforcement. Do not mark verification complete when these tests are skipped unless the user explicitly accepts database verification as pending.

- [ ] **Step 7: Run browser smoke verification**

Launch through Apache/XAMPP with a disposable test database. At desktop and mobile widths verify login/logout, list loading/empty/error states, create/edit, zero/one/many children, five education levels, server validation placement, debounced search, pagination, archive/restore, conflict preservation, dirty warnings, expired-session redirect, keyboard focus, live announcements, and responsive table scrolling.

- [ ] **Step 8: Review final diff and repository hygiene**

Run: `git status --short`

Expected: only intended source, test, configuration-example, lock, and documentation files; no `.env`, database dumps, logs, cache, or vendor files.

Run: `git diff --check`

Expected: no whitespace errors.

- [ ] **Step 9: Final review checkpoint**

```bash
git add .htaccess .env.example README.md tests/Support tests/Integration/HttpApiTest.php
git commit -m "test: verify and document secure PDS CRUD flow"
```

---

## Plan Self-Review Record

- **Spec coverage:** Tasks 1–3 cover runtime, sessions, CSRF, throttling, staff provisioning, and login audit; Tasks 2 and 4–7 cover the normalized model, validation, transactions, complete relations, optimistic concurrency, archive/restore, and audit; Tasks 8–10 cover every endpoint and browser behavior; Task 11 covers HTTP integration, deployment protection, documentation, and the browser checklist.
- **Scope:** Authentication, CRUD backend, and browser client form one deployable vertical application rather than independent products; splitting them would leave intermediate plans unusable or unauthenticated.
- **Ambiguities resolved:** canonical enum values are lower-case API values; field limits are locked above; `index.html` is removed after conversion; Apache is the documented local/primary deployment and other servers require equivalent deny rules; commits remain conditional on explicit authorization.
- **Type/interface consistency:** All endpoints consume `Request`, `Endpoint`, `Auth`, and one request-scoped `PdsService`; service methods and returned version fields remain consistent across backend tests and browser state.
- **Placeholder scan:** The plan contains no deferred implementation markers; every required behavior is assigned to a task and an executable verification step.
