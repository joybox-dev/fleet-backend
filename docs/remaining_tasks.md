# FleetOps — Remaining Tasks (Contract Scope)

> Last updated: 2026-05-13
> Branch: `feature/native-accounting`
> Contract deadline: 75 working days

---

## ✅ COMPLETED Items (No Work Needed)

| # | Item | What's Done |
|---|------|-------------|
| 2 | العهد والكاش | Full CRUD, custody types, cash settlement with FIFO, pending cash endpoint, frontend pages |
| 9 | التقارير | 9 report endpoints: missing docs, expiring docs, violations, pending cash, weekly orders, fleet status, vehicle P&L, driver status, contract P&L |
| 11 | Multi-Company | 17 models scoped, company isolation, branding, module gating, super admin dashboard, full frontend |

---

## Sprint 1: Frontend for Backend-Ready Items

**Estimated: 3 days**
**Contract items: 1, 3, 6, 8**

### Task 1.1 — Violation Image Upload
> Contract item 1: المخالفات

**Backend:**
- [ ] Add file upload handling in `ViolationController@store` (accept `image` file, store in `storage/violations/`, save path)
- [ ] Add file upload handling in `ViolationController@update`

**Frontend:**
- [ ] Add image picker to violation create/edit form in `ViolationList.jsx`
- [ ] Show violation image in detail/list view
- [ ] Use existing `UploadController` endpoint for file upload

**Files to modify:**
- `app/Http/Controllers/Api/ViolationController.php`
- `fleet-frontend/src/pages/violations/ViolationList.jsx`

---

### Task 1.2 — Driver Guarantees Page
> Contract item 3: ضمانات السائقين

**Backend:** ✅ Done (controller, model, routes all exist)

**Frontend:**
- [ ] Create `src/api/index.js` — add guarantee API functions:
  - `getGuarantees(filters)`
  - `createGuarantee(data)`
  - `returnGuarantee(id, data)`
  - `deleteGuarantee(id)`
- [ ] Create `src/pages/guarantees/GuaranteesPage.jsx`:
  - Table: employee, type, document number, received date, status, actions
  - Create dialog: employee select, type select, document number, date, file upload, notes
  - Return action: date picker + confirm
  - Filter by: status (held/returned), employee
- [ ] Add sidebar link under custody section
- [ ] Add route in `App.jsx`

**Files to create:**
- `fleet-frontend/src/pages/guarantees/GuaranteesPage.jsx`

**Files to modify:**
- `fleet-frontend/src/api/index.js`
- `fleet-frontend/src/components/layout/Sidebar.jsx`
- `fleet-frontend/src/App.jsx`

---

### Task 1.3 — Vehicle Expenses Page
> Contract item 6: مصاريف المركبات

**Backend:** ✅ Done (CRUD + summary endpoint)

**Frontend:**
- [ ] Create `src/api/index.js` — add vehicle expense API functions:
  - `getVehicleExpenses(filters)`
  - `createVehicleExpense(data)`
  - `updateVehicleExpense(id, data)`
  - `deleteVehicleExpense(id)`
  - `getVehicleExpenseSummary(filters)`
- [ ] Create `src/pages/vehicle-expenses/VehicleExpensesPage.jsx`:
  - Table: vehicle, type, amount, date, vendor, description, actions
  - Create/edit dialog: vehicle select, type select (fuel/insurance/tires/registration/fine/repair/other), amount, date, vendor, receipt upload, notes
  - Summary section: total by type (bar chart or cards)
  - Filter by: vehicle, type, date range
- [ ] Add sidebar link under vehicles section
- [ ] Add route in `App.jsx`

**Files to create:**
- `fleet-frontend/src/pages/vehicle-expenses/VehicleExpensesPage.jsx`

**Files to modify:**
- `fleet-frontend/src/api/index.js`
- `fleet-frontend/src/components/layout/Sidebar.jsx`
- `fleet-frontend/src/App.jsx`

---

### Task 1.4 — Salary Advances Page
> Contract item 8: حسابات المندوبين — السلف

**Backend:** ✅ Done (CRUD + cancel + deduction model)

**Frontend:**
- [ ] Create `src/api/index.js` — add salary advance API functions:
  - `getSalaryAdvances(filters)`
  - `createSalaryAdvance(data)`
  - `getSalaryAdvance(id)` (with deductions)
  - `cancelSalaryAdvance(id)`
- [ ] Create `src/pages/salary-advances/SalaryAdvancesPage.jsx`:
  - Table: employee, amount, monthly installment, paid/total installments, remaining, status, actions
  - Create dialog: employee select, amount, monthly installment (auto-calculates total installments), date, reason
  - Detail view: deduction history table
  - Cancel action with confirmation
  - Filter by: status (active/completed/cancelled), employee
- [ ] Verify payroll integration — `PayrollController@run` should auto-deduct active advances
- [ ] Add sidebar link under payroll section
- [ ] Add route in `App.jsx`

**Backend (verify):**
- [ ] Check `PayrollController@run` deducts active salary advances automatically
- [ ] If not, add advance deduction logic to payroll run

**Files to create:**
- `fleet-frontend/src/pages/salary-advances/SalaryAdvancesPage.jsx`

**Files to modify:**
- `fleet-frontend/src/api/index.js`
- `fleet-frontend/src/components/layout/Sidebar.jsx`
- `fleet-frontend/src/App.jsx`
- `app/Http/Controllers/Api/PayrollController.php` (if advance deduction missing)

---

## Sprint 2: Operations & HR

**Estimated: 5 days**
**Contract items: 4, 7**

### Task 2.1 — Operations Dashboard + Contract Capacity
> Contract item 4: العمليات — drivers per brand, absent/leave counts, deficit

**Note:** Absent driver counts come from the **existing leave system** (no separate attendance system needed).

**Backend:**
- [ ] Add fields to `contracts` table migration:
  - `required_drivers` (int) — how many drivers needed for this brand
  - `daily_target` (int) — daily order target
  - `monthly_target` (int) — monthly order target
- [ ] Create `OperationsController.php`:
  - `dashboard()` — returns per contract:
    - Required drivers (from contract)
    - Assigned drivers (from vehicle_assignments)
    - Drivers on leave today (from employee_leaves where status=approved and date in range)
    - Deficit = required - (assigned - on_leave)
    - Orders today vs daily target
  - Uses existing `EmployeeLeave` model — no new tables needed

**Frontend:**
- [ ] Create `src/pages/operations/OperationsPage.jsx`:
  - Cards per contract/brand: required vs available drivers (red if deficit)
  - On-leave driver count (linked from leave system)
  - Target vs actual orders comparison
- [ ] Add sidebar link
- [ ] Add route

**Files to create:**
- `database/migrations/xxxx_add_capacity_to_contracts.php`
- `app/Http/Controllers/Api/OperationsController.php`
- `fleet-frontend/src/pages/operations/OperationsPage.jsx`

---

### Task 2.3 — Employee Documents
> Contract item 7: نظام تقييم HR — وثائق الموظفين

**Backend:**
- [ ] Create migration: `employee_documents` table
  - `id, employee_id, company_id, document_type (passport/civil_id/work_permit/driving_license/residence/health_card/contract/other), document_number, file_path, issue_date, expiry_date, status (valid/expired/pending_renewal), notes, created_at, updated_at`
- [ ] Create model: `EmployeeDocument.php` with `BelongsToCompany`
- [ ] Create controller: `EmployeeDocumentController.php`
  - `index(employee_id)` — list docs for employee
  - `store(data)` — upload document with file
  - `update(id, data)` — update expiry/status
  - `destroy(id)` — delete
- [ ] Add routes

**Frontend:**
- [ ] Add documents tab to `EmployeeProfile.jsx`
  - Table of documents with type, number, expiry, status
  - Upload new document dialog
  - Visual indicators: green (valid), yellow (expiring), red (expired)

**Files to create:**
- `database/migrations/xxxx_create_employee_documents_table.php`
- `app/Models/EmployeeDocument.php`
- `app/Http/Controllers/Api/EmployeeDocumentController.php`

**Files to modify:**
- `fleet-frontend/src/pages/employees/EmployeeProfile.jsx`

---

### Task 2.4 — Employee Evaluations
> Contract item 7: نظام تقييم HR — نظام تقييم الموظفين

**Backend:**
- [ ] Create migration: `evaluation_criteria` table
  - `id, company_id, name, name_ar, weight (decimal), is_active, created_at, updated_at`
- [ ] Create migration: `employee_evaluations` table
  - `id, employee_id, company_id, evaluator_id, evaluation_date, period_from, period_to, overall_score (decimal), status (draft/submitted/approved), notes, created_at, updated_at`
- [ ] Create migration: `evaluation_scores` table
  - `id, evaluation_id, criterion_id, score (decimal), notes`
- [ ] Create models: `EvaluationCriterion`, `EmployeeEvaluation`, `EvaluationScore`
- [ ] Create controller: `EvaluationController.php`
  - CRUD for criteria (company-level)
  - CRUD for evaluations
  - Score submission per criterion
- [ ] Add routes

**Frontend:**
- [ ] Create `src/pages/evaluations/EvaluationsPage.jsx`:
  - Criteria management (settings)
  - Create evaluation: select employee, period, score each criterion
  - History view per employee
- [ ] Add evaluations tab to `EmployeeProfile.jsx`

**Files to create:**
- `database/migrations/xxxx_create_evaluation_criteria_table.php`
- `database/migrations/xxxx_create_employee_evaluations_table.php`
- `database/migrations/xxxx_create_evaluation_scores_table.php`
- `app/Models/EvaluationCriterion.php`
- `app/Models/EmployeeEvaluation.php`
- `app/Models/EvaluationScore.php`
- `app/Http/Controllers/Api/EvaluationController.php`
- `fleet-frontend/src/pages/evaluations/EvaluationsPage.jsx`

---

### Task 2.5 — Auto Employee Numbering
> Contract item 7: ترقيم وظيفي تلقائي

**Backend:**
- [ ] Add auto-generation in `EmployeeController@store`:
  - Format: `EMP-YYYY-XXXX` (e.g. `EMP-2026-0001`)
  - Auto-increment per company per year

**Files to modify:**
- `app/Http/Controllers/Api/EmployeeController.php`

---

## Sprint 3: Excel Import/Export

**Estimated: 3 days**
**Contract items: 5, 9 (export part)**

### Task 3.1 — Import Engine
> Contract item 5: دعم رفع ملفات Excel

**Backend:**
- [ ] Install `maatwebsite/excel` package
- [ ] Create migration: `import_logs` table
  - `id, company_id, user_id, entity_type (employees/vehicles/daily_logs/etc), file_path, rows_total, rows_imported, rows_failed, status (pending/processing/completed/failed), errors (json), created_at, updated_at`
- [ ] Create model: `ImportLog.php`
- [ ] Create service: `ImportService.php`
  - `preview(file, entityType)` — parse and return first N rows with validation
  - `import(file, entityType, mappings)` — actually import with error tracking
- [ ] Create controller: `ImportController.php`
  - `POST /api/import/preview` — upload file, return preview
  - `POST /api/import/confirm` — confirm and run import
  - `GET /api/import/logs` — import history
  - `GET /api/import/template/{entity}` — download template
- [ ] Create importers per entity:
  - `EmployeeImporter`, `VehicleImporter`, `DailyLogImporter`
- [ ] Add routes

**Frontend:**
- [ ] Create `src/pages/import/ImportPage.jsx`:
  - Step 1: Select entity type + upload file
  - Step 2: Preview data in table, edit cells, mark rows to skip
  - Step 3: Confirm → show progress → show results (imported/failed)
  - Import history table
  - Download template buttons

**Files to create:**
- `database/migrations/xxxx_create_import_logs_table.php`
- `app/Models/ImportLog.php`
- `app/Services/ImportService.php`
- `app/Http/Controllers/Api/ImportController.php`
- `app/Imports/EmployeeImporter.php`
- `app/Imports/VehicleImporter.php`
- `app/Imports/DailyLogImporter.php`
- `fleet-frontend/src/pages/import/ImportPage.jsx`



---

## Sprint 4: Native Accounting System

**Estimated: 7-8 days**
**Contract item: 10**

### Task 4.1 — Chart of Accounts
> شجرة الحسابات

**Backend:**
- [ ] Create migration: `accounts` table
  - `id, company_id, parent_id (self-ref), code, name, name_ar, type (asset/liability/equity/income/expense), sub_type (bank/cash/receivable/payable/etc), is_group (bool), is_active, balance (decimal), currency, description, created_at, updated_at`
- [ ] Create model: `Account.php` with `BelongsToCompany`, tree methods (`children`, `parent`, `ancestors`)
- [ ] Create controller: `AccountController.php`
  - `index()` — return full tree
  - `store()` — create account
  - `update()` — edit account
  - `destroy()` — delete (only if no journal entries)
  - `balance(id)` — account balance with date range
- [ ] Create seeder: `KuwaitChartOfAccountsSeeder.php` — default COA template
- [ ] Add routes

**Frontend:**
- [ ] Create `src/pages/accounting/ChartOfAccountsPage.jsx`:
  - Tree view with expand/collapse
  - Add/edit account dialog
  - Balance display per account

---

### Task 4.2 — Journal Entries
> القيود اليومية

**Backend:**
- [ ] Create migration: `journal_entries` table
  - `id, company_id, entry_number (auto), entry_date, reference_type (nullable), reference_id (nullable), description, status (draft/posted/reversed), posted_by, posted_at, created_by, created_at, updated_at`
- [ ] Create migration: `journal_entry_lines` table
  - `id, journal_entry_id, account_id, debit (decimal 15,3), credit (decimal 15,3), description, created_at, updated_at`
- [ ] Create models: `JournalEntry`, `JournalEntryLine`
- [ ] Create controller: `JournalEntryController.php`
  - `store()` — validate SUM(debit) = SUM(credit), create entry + lines
  - `post(id)` — mark as posted, update account balances
  - `reverse(id)` — create reversing entry
- [ ] Add routes

**Frontend:**
- [ ] Create `src/pages/accounting/JournalEntriesPage.jsx`:
  - List view with filters (date range, status)
  - Create dialog: date, description, dynamic rows (account + debit/credit)
  - Running total showing balance (must = 0)
  - Post / reverse actions

---

### Task 4.3 — General Ledger
> دفتر الأستاذ

**Backend:**
- [ ] Add `ledger(account_id, from, to)` method to `JournalEntryController`
  - Returns all entries for an account with running balance

**Frontend:**
- [ ] Create `src/pages/accounting/GeneralLedgerPage.jsx`:
  - Account selector + date range
  - Table: date, entry number, description, debit, credit, running balance

---

### Task 4.4 — Invoices
> الفواتير

**Backend:**
- [ ] Create migration: `invoices` table
  - `id, company_id, invoice_number (auto), invoice_type (sales/purchase), client_id (nullable), vendor_name (nullable), invoice_date, due_date, subtotal, tax_amount, total, status (draft/sent/paid/partial/overdue/cancelled), notes, journal_entry_id (FK), created_by, created_at, updated_at`
- [ ] Create migration: `invoice_items` table
  - `id, invoice_id, description, quantity, unit_price, amount, account_id`
- [ ] Create models: `Invoice`, `InvoiceItem`
- [ ] Create controller: `InvoiceController.php`
  - CRUD + auto-create journal entry when posted

**Frontend:**
- [ ] Create `src/pages/accounting/InvoicesPage.jsx`:
  - List with filters (type, status, date)
  - Create: client/vendor, line items, auto-total
  - PDF generation for invoice

---

### Task 4.5 — Payments
> المدفوعات

**Backend:**
- [ ] Create migration: `payments` table
  - `id, company_id, payment_number (auto), payment_type (received/sent), invoice_id (nullable), amount, payment_date, payment_method (cash/bank/transfer), reference, account_id (from), notes, journal_entry_id (FK), created_by, created_at, updated_at`
- [ ] Create model: `Payment.php`
- [ ] Create controller: `PaymentController.php`
  - CRUD + auto-create journal entry + update invoice status

**Frontend:**
- [ ] Create `src/pages/accounting/PaymentsPage.jsx`:
  - List with filters
  - Record payment: link to invoice or standalone
  - Auto-updates invoice status (paid/partial)

---

### Task 4.6 — Financial Reports
> التقارير المالية

**Backend:**
- [ ] Create controller: `FinancialReportController.php`
  - `trialBalance(date)` — all accounts with debit/credit totals
  - `profitAndLoss(from, to)` — income - expenses
  - `balanceSheet(date)` — assets = liabilities + equity
  - `cashFlow(from, to)` — cash movements

**Frontend:**
- [ ] Create `src/pages/accounting/TrialBalancePage.jsx`
- [ ] Create `src/pages/accounting/ProfitLossPage.jsx`
- [ ] Create `src/pages/accounting/BalanceSheetPage.jsx`



---

## Task Checklist Summary

| Sprint | Tasks | Days | Status |
|--------|-------|------|--------|
| **Sprint 1** | 1.1, 1.2, 1.3, 1.4 | 3 | ⬜ TODO |
| **Sprint 2** | 2.1, 2.3, 2.4, 2.5 | 4 | ⬜ TODO |
| **Sprint 3** | 3.1 | 2 | ⬜ TODO |
| **Sprint 4** | 4.1, 4.2, 4.3, 4.4, 4.5, 4.6 | 7-8 | ⬜ TODO |
| **TOTAL** | | **16-17 days** | |

> [!NOTE]
> Tasks from `new_req.md` NOT in contract (deferred):
> - Export engine (PDF/Excel) — new_req items 15
> - Auto journal entries from events — enhancement
> - CRM — new_req item 14
> - Dashboard analytics — new_req item 2
> - Orders/delivery system — new_req item 4
> - WhatsApp API — new_req item 6
> - Vehicle handover forms — new_req item 10
