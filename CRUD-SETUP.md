# Personal Data Sheet: jQuery AJAX CRUD

The index supports adding, searching, editing, and deleting MySQL records without reloading the page. All form fields, including the five children rows and five education levels, are stored together. jQuery 3.7.1 is included locally, with its license notice, so AJAX works without a CDN connection.

## Run locally (XAMPP on this computer)

Start MySQL in XAMPP, then run from this project directory:

```powershell
C:\xampp\php\php.exe scripts/setup-records.php
C:\xampp\php\php.exe -S 127.0.0.1:8088 -t .
```

Open <http://127.0.0.1:8088/index.html>. Opening the HTML file directly cannot execute PHP.

Setup creates the `pds` database and `pds_records` table if missing. It does not remove other tables or records. PHP needs `pdo_mysql` and `mbstring`; this computer's XAMPP PHP includes both. The separate PHP installation on PATH does not have `pdo_mysql` enabled.

For another MySQL installation, set these environment variables before setup and before starting PHP: `PDS_DB_HOST`, `PDS_DB_PORT`, `PDS_DB_NAME`, `PDS_DB_USER`, `PDS_DB_PASSWORD`. Defaults are `127.0.0.1`, `3306`, `pds`, `root`, and an empty password for local XAMPP. Never place real credentials in public files.

## Use

- Complete first name and surname, plus any other applicable fields, then select **Save record**.
- Open **Saved records** in the sidebar to access the standalone records directory. Switch back to **Personal data form** to continue your draft; switching tabs preserves unsaved entries.
- Search by partial name, mobile, or email. Results are paginated, ten per page.
- Select **Edit** to open the form tab with every field loaded, change details, and select **Save changes**.
- Select **Delete** and confirm to remove a saved record.
- **Add new record** starts an empty form. **Clear form** never deletes saved records.
- **Print form** prints the current form; the browser's print dialog can save it as PDF.

This is the open-access school activity described in PRODUCT.md: all visitors to the app can view and change records. Use the loopback server for local work.

## API

`api/records.php` responds with JSON. All mutations require a session CSRF token from `GET ?action=session`, sent as `X-CSRF-Token`, and a JSON body.

| Operation | Request |
| --- | --- |
| Search/list | `GET ?q=term&page=1` |
| Load | `GET ?id=123` |
| Add | `POST` with `{ "fields": { ... } }` |
| Edit | `PUT ?id=123` with `{ "fields": { ... }, "version": 1 }` |
| Delete | `DELETE ?id=123` with `{ "version": 1 }` |

Prepared statements handle database values. PHP validates fields and returns 422 with field errors. Version checks return 409 if another user changed or deleted the record, preventing stale edits/deletes. The UI preserves entries on failures and escapes record text when rendering rows. Field keys are defined in `api/field-schema.json` and the form's `name` attributes.

## Verify

With the local PHP server running, run `node --test tests/records-crud.test.cjs`. The integration test creates uniquely named temporary records, checks all 98 stored fields (the two citizenship radio controls share one value), exercises CRUD, validation, CSRF protection, pagination and conflicting updates, and deletes its own records afterward. To target another local test server, set `PDS_TEST_URL`.
