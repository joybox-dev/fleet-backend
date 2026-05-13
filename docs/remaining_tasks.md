# FleetOps — Remaining Tasks (Contract Scope)

> Last updated: 2026-05-13 (Sprint 1 + Sprint 2 complete)
> Branch: `feature/native-accounting`
> Contract deadline: 75 working days

---

## ✅ COMPLETED Items (No Work Needed)

| # | Item | What's Done |
|---|------|-------------|
| 1 | المخالفات | Photo upload via `uploadApi`, image preview in form, fullscreen viewer, 📷 column in table |
| 2 | العهد والكاش | Full CRUD, custody types, cash settlement with FIFO, pending cash endpoint, frontend pages |
| 3 | ضمانات السائقين | Backend CRUD + return, frontend page with stats cards, filters, create/return modals |
| 4 | العمليات | Operations dashboard: per-contract capacity (required/assigned/leave/deficit), order targets, batch-optimized queries |
| 6 | مصاريف المركبات | Backend CRUD + summary, frontend page with expense type icons, summary cards, filters |
| 7 | HR نظام تقييم | Employee documents (CRUD + file upload + auto-status), Evaluation system (criteria + weighted scoring + approve), Auto numbering (EMP-XXXX) |
| 8 | السلف | Backend CRUD + cancel + deductions, frontend page with installment calc, progress bar, deduction history |
| 9 | التقارير | 9 report endpoints: missing docs, expiring docs, violations, pending cash, weekly orders, fleet status, vehicle P&L, driver status, contract P&L |
| 11 | Multi-Company | 17 models scoped, company isolation, branding, module gating, super admin dashboard, full frontend |

---

## ✅ Sprint 1: Frontend for Backend-Ready Items — COMPLETED

**Completed: 2026-05-13 | Tested: E2E browser tests passed**
**Contract items: 1, 3, 6, 8**

### Task 1.1 ✅ Violation Image Upload
- Replaced text input with file upload picker using existing `uploadApi`
- Image preview in create/edit form with remove button
- 📷 column in violations table — click to view fullscreen
- Fullscreen image viewer overlay with close button

### Task 1.2 ✅ Driver Guarantees Page
- Created `GuaranteesPage.jsx` — full CRUD with return flow
- Stats cards: total, held (🔒), returned (✅)
- Create modal: employee, type (passport/civil_id/contract/bank/other), document number, date
- Return modal: date + notes confirmation
- Filters: status, employee
- Added `guaranteesApi` to API client

### Task 1.3 ✅ Vehicle Expenses Page
- Created `VehicleExpensesPage.jsx` — full CRUD
- Summary cards: total + breakdown by type (⛽ fuel, 🛡️ insurance, 🔧 tires, etc.)
- Create/edit modal: vehicle, type, amount, date, vendor, description
- Filters: vehicle, type, date range
- Added `vehicleExpensesApi` to API client

### Task 1.4 ✅ Salary Advances Page
- Created `SalaryAdvancesPage.jsx` — create + cancel + detail view
- Auto installment calculator (amount ÷ monthly = months)
- Detail modal: summary grid + progress bar + deduction history table
- Stats cards: total, active (🟢), remaining balance (💰), completed (✅)
- Filters: status, employee
- Added `salaryAdvancesApi` to API client

### Infrastructure
- Added 3 sidebar links: 💰 مصاريف المركبات, 🔐 ضمانات السائقين, 💵 السلف
- Added 3 routes in `App.jsx`
- Added `guaranteesApi`, `vehicleExpensesApi`, `salaryAdvancesApi`, `missingDocs` to `api/index.js`

---

## ✅ Sprint 2: Operations & HR — COMPLETED

**Completed: 2026-05-13 | Build: ✅ clean (698 modules)**
**Contract items: 4, 7**

### Task 2.1 ✅ Operations Dashboard + Contract Capacity
- Migration: added `required_drivers`, `daily_target`, `monthly_target` to contracts
- `OperationsController@dashboard` — batch-optimized (6 queries total, zero N+1)
- Per-contract cards: required/assigned/leave/available/deficit with color coding
- Order progress bars: daily + monthly targets with % completion
- Global summary stats row
- Sidebar link: 🎯 لوحة العمليات

### Task 2.3 ✅ Employee Documents
- Migration: `employee_documents` table (8 doc types, auto-status)
- Model: `EmployeeDocument` with `refreshStatus()` — auto-sets valid/pending_renewal/expired based on expiry
- Controller: CRUD nested under `/employees/{id}/documents`
- Profile tab: 📂 الوثائق المرفقة with table + add modal + file upload via `uploadApi`
- Status indicators: ✅ ساري / ⚠️ قارب الانتهاء / ❌ منتهي

### Task 2.4 ✅ Employee Evaluations
- 3 migrations: `evaluation_criteria`, `employee_evaluations`, `evaluation_scores`
- 3 models with weighted score calculation (`calculateOverallScore()`)
- `EvaluationController`: CRUD for criteria + evaluations, approve flow
- Full evaluations page with:
  - Criteria management modal (⚙️)
  - Create evaluation with per-criterion scoring (0-10)
  - Detail modal with score breakdown bars
  - Approve/delete flows
  - Filters: employee, status
- Profile tab: 📝 التقييمات showing evaluation history
- Sidebar link: 📝 التقييمات

### Task 2.5 ✅ Auto Employee Numbering
- Already existed: `EMP-XXXX` format in `EmployeeController@store` (line 56-63)
- Auto-increment per company

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
| **Sprint 1** | 1.1, 1.2, 1.3, 1.4 | 3 | ✅ DONE |
| **Sprint 2** | 2.1, 2.3, 2.4, 2.5 | 4 | ✅ DONE |
| **Sprint 3** | 3.1 | 2 | ⬜ TODO |
| **Sprint 4** | 4.1, 4.2, 4.3, 4.4, 4.5, 4.6 | 7-8 | ⬜ TODO |
| **TOTAL** | | **9-10 days remaining** | |

> [!NOTE]
> Tasks from `new_req.md` NOT in contract (deferred):
> - Export engine (PDF/Excel) — new_req items 15
> - Auto journal entries from events — enhancement
> - CRM — new_req item 14
> - Dashboard analytics — new_req item 2
> - Orders/delivery system — new_req item 4
> - WhatsApp API — new_req item 6
> - Vehicle handover forms — new_req item 10
