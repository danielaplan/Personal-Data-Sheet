# Personal Data Sheet (PDS)

A student CRUD project for entering and managing Personal Data Sheets using **HTML, CSS, JavaScript, jQuery AJAX, PHP, and MySQL/MariaDB**.

CRUD means **Create, Read, Update, and Delete**. In this app, students can add a record, search and open existing records, edit details, and delete records. Requests run through jQuery AJAX without reloading the page.

## Features

- Personal information, citizenship, addresses, contact details, family background, education, and signature/date fields.
- A **Personal data form** sidebar tab for adding and editing records.
- A separate **Saved records** sidebar tab for searching and managing existing data.
- Search by name, mobile number, or email, with ten results per page.
- Required first name and surname, plus validation of email addresses, dates, and numeric fields.
- Confirmation before deleting a saved record.
- Print the current form or save it as PDF through the browser's print dialog.
- Responsive layout and keyboard-accessible navigation.

## Technology stack

| Technology | Purpose |
| --- | --- |
| HTML5 | Page structure and form fields |
| Custom CSS3 | Styling, responsive layout, and print layout |
| JavaScript | Tab switching, form state, and interaction |
| jQuery 3.7.1 | AJAX requests between the page and PHP |
| PHP 8.1+ with PDO MySQL and mbstring | API, validation, and database access |
| MySQL/MariaDB | Permanent record storage |
| XAMPP | Convenient local PHP and database environment |
| Node.js and Python 3 | Optional development checks and tests |

The current page does **not** load Bootstrap. Its layout uses custom CSS. jQuery is included locally, so no CDN connection is needed to load it.

## Run the project on Windows

### 1. Start the database

Open the **XAMPP Control Panel** and start **MySQL**. The instructions below use PHP's local development server, so starting Apache is not necessary.

### 2. Open a terminal in the project folder

For this computer, open PowerShell and run:

```powershell
cd C:\Users\acer\Desktop\PDS
```

On another computer, use the folder where you saved this project. The commands below assume XAMPP is installed in `C:\xampp`; adjust that path if needed.

### 3. Create the database and table

```powershell
C:\xampp\php\php.exe scripts/setup-records.php
```

Expected output:

```text
PDS record storage is ready.
```

Setup creates the `pds` database and `pds_records` table if they do not already exist. It does not clear existing records. You normally only need to run setup once per database installation.

The default local connection uses host `127.0.0.1`, port `3306`, username `root`, and an empty password. For different credentials, see [CRUD-SETUP.md](CRUD-SETUP.md).

### 4. Start the PHP server

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8088 -t .
```

Keep this terminal open while using the app. Press **Ctrl+C** when you want to stop the server.

### 5. Open the app

Visit **http://127.0.0.1:8088/index.html** in your browser.

Do not double-click `index.html` to run the app. A `file://` page cannot execute the PHP API or save records to the database. On later runs, start MySQL and the PHP server again; your saved records remain in the database.

## How to use it

### Add a record

1. Open **Personal data form** in the sidebar, or select **Add new record** from Saved records.
2. Enter the first name and surname. Complete the other applicable fields.
3. Select **Save record** at the bottom of the form.
4. Wait for the saved-successfully message.

### Search and read records

1. Open **Saved records** in the sidebar.
2. Enter part of a name, mobile number, or email. Results update after a short pause; you can also select **Search**.
3. Use **Previous** and **Next** to move between result pages.
4. Select **Edit** to load the full record into the form for reading or updating.

### Edit a record

1. Find it in **Saved records** and select **Edit**.
2. The app opens the form tab with the saved values.
3. Change the details and select **Save changes**.

### Delete a record

1. Find it in **Saved records**.
2. Select **Delete** and confirm the named record.
3. The record is removed from the database and the list refreshes.

### Print or save as PDF

Open or complete a form, then select **Print form** or **Review & print**. Choose a printer or **Save as PDF** in the browser's print dialog. Printing does not save changes to the database.

Switching sidebar tabs preserves the current unsaved form. Refreshing or closing the page can discard unsaved entries. **Clear form** clears the editor and starts a new record; it does not delete database records.

## How the code works

1. The user performs an action in `index.html`.
2. `index.js` sends a request using `$.ajax()`.
3. `api/records.php` validates the request and uses prepared PDO statements to read or write MySQL data.
4. PHP returns a JSON response.
5. jQuery updates the form, records list, or feedback message without reloading the page.

| Action | HTTP method |
| --- | --- |
| Search or read | GET |
| Add | POST |
| Edit | PUT |
| Delete | DELETE |

The form has **99 controls** mapped to **98 stored values** because the two citizenship radio buttons share one value. Each database row holds the complete form as JSON, together with summary columns used for the records list and search. A version number prevents an older edit from silently overwriting a newer one.

## Important files

| File | Purpose |
| --- | --- |
| `index.html` | Form and saved-records panels |
| `index.js` | jQuery AJAX CRUD and sidebar behavior |
| `form.css` | Main form and print styling |
| `records.css` | Records list styling |
| `sidebar-tabs.css` | Sidebar tab styling |
| `jquery-3.7.1.min.js` | Local jQuery library |
| `api/records.php` | JSON CRUD API |
| `api/field-schema.json` | Stored field names and types |
| `config/record-store.php` | Database connection settings |
| `database/records.sql` | Table definition |
| `scripts/setup-records.php` | Database setup command |
| `scripts/check-form.py` | HTML and field-mapping checks |
| `tests/records-crud.test.cjs` | Database API integration test |

## Troubleshooting

| Problem | What to check |
| --- | --- |
| The page cannot be reached | Keep the PHP server terminal running and use the matching port. |
| Record storage is unavailable | Start MySQL, run database setup, and check connection settings. |
| PHP reports a missing database driver | Use `C:\xampp\php\php.exe`; this computer's other PHP installation does not have PDO MySQL enabled. |
| Port 8088 is already in use | Use the existing project server, or start PHP on another free port and open that port in the browser. |
| Saving shows field errors | Check first name, surname, email, date, and number fields. |
| A record changed or was deleted | Reload it from Saved records before editing again. Preserve any unsaved text you still need. |
| Your session changed | Copy any unsaved entries you need, then reload the page. |

## Verification

With MySQL and the PHP server running, these optional developer commands check the project:

```powershell
node --test tests/records-crud.test.cjs
python scripts/check-form.py
node --check index.js
```

The API test creates temporary records, verifies saving and loading all fields, editing, deletion, search, pagination, validation, CSRF protection, and stale-edit handling, then removes its own test records.

These checks passed on this workstation. Browser interaction and visual testing were not completed because browser access was not permitted.

## Project scope

This is an **open-access local school activity**, with no login or per-user record ownership. Anyone who can access the app can read and change its records. Use sample data for classroom demonstrations. The print feature is a browser-form printout, not a certified reproduction of the official government PDS document.
