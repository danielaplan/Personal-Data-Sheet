# Surface Brief: PDS Search & List

## 1. Job and audience

- **Who:** Anyone — the CRUD area is a public demonstration app (no login, no trusted user role).
- **Context:** The user already filled out a PDS via the form (`index.html`). Now they need to find that record again — or someone else's — to review, print, or update it.
- **Visitor mode:** Operate. The user is task-focused: find a record, act on it. Scanability and speed outrank expression.
- **Primary job:** Locate a prior PDS submission in seconds, then take it into an action.

## 2. Outcome and proof

- **Primary task:** Search/filter for a submission, then open it for **Edit** (pre-populate the PDS form with the existing record).
- **Supporting tasks:** View/Print a record as a printable PDF; Delete a record.
- **Success:** A record is found and opened for editing in 2-3 interactions from landing. Search is fast and forgiving (name-based, partial match).
- **Product-specific truth:** PDS submissions contain personal, potentially sensitive data — search is a plain text search over that data; delete is destructive and must be confirmed.

## 3. Selected direction

- **World:** The established "Clear Desk" / "Studio Neutral" system (soft off-white page, near-black text, single primary accent for focus, flat surfaces). New surface extends it, does not invent one.
- **Structure:** A single-column list page. Top bar = page heading, global search input, and filter controls. Body = searchable, filterable table of submissions with an Actions column.
- **Focal moment:** The row the user wants — Edit is the primary action on that row; View/Print and Delete are secondary.
- **Implementation consequence:** Table-driven layout over a PHP/AJAX endpoint; pagination or an infinite scroll for large result sets.

## 4. Scope and boundaries

- **In scope:** One surface — the search/list page (header + search/filter + table + row actions).
- **Out of scope (untouched):** The PDS form submit view (`index.html`), no login page, no PDF generation on this page (Print delegates to the detail/print endpoint).
- **Anti-goals:** No authentication gating; no feature creep into full record editing here; no decorative visuals that slow scanning.

## 5. List content (columns)

- **Key PDS fields + Actions.** Typical range, not minimum, not maximum:
  - Surname | First Name | Middle Name | Date of Birth | Mobile | Email | Date Submitted | **Actions**
- Each data row is one prior submission.

## 6. States and ranges

- **Initial (empty data):** Table shows "No submissions found — this is a demo app." with a link back to the PDS form to create the first record.
- **Empty (no matches):** Table empty, state message with the search terms that produced no results.
- **Loading:** Skeleton rows or spinner on the table while AJAX fetches run.
- **Error:** Failed-fetch state (retry link) without losing the current search.
- **Pagination:** Default page size (e.g., 10-25 rows) with page numbers / "next" for larger data.
- **No record detail on this page** — clicking Edit/View opens the detail page.

## 7. Search and filtering

- **Global search box:** Debounced text search over all text fields (surname, firstname, middlename, mobile, email, philsysid, etc.).
- **Column filters:** Date range picker (date submitted) and citizenship dropdown (Filipino / Dual Citizen).
- **Filter behavior:** Filters apply immediately (client-side for small demo data; server-side when the backend supports filtering).

## 8. Row actions

- **Primary: Edit** — opens the PDS form pre-populated with this record. Styled as the primary button.
- **Secondary (icon buttons):**
  - **View/Print** — opens the detail view and triggers the printable PDF.
  - **Delete** — destructive; requires a confirmation dialog before the AJAX delete.

## 9. Interaction and layout

- **Hierarchy:** Heading at top; search and filters grouped together for scanning; table takes the full content width; actions column is rightmost and consistent.
- **Responsive:** On small screens the table gains a horizontal scroll; action icons shrink to avoid layout break.
- **Feedback:** Focus-visible outlines on search input and buttons; success/error toasts or inline messages after Edit/Print/Delete.
- **Transitions:** Search/filter results update the table body; loading skeleton shown during fetch.

## 10. Constraints and open decisions

- **Stack:** PHP 8.x + MySQL 8.x + Bootstrap 5 + jQuery (AJAX), DataTables already referenced in the Education table of the PDS form.
- **No auth:** Public access to all records; no role-based restrictions.
- **Accessibility:** Form labels, keyboard navigation on the table and actions, focus-visible consistency with the system rule.
- **Open decisions for the builder:** exact pagination count, whether filters are server- or client-side (default: server-side to match the CRUD API), and the confirmation flow for Delete.
