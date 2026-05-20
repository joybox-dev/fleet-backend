# FleetOps — Remaining Tasks (Contract Scope)

> Last updated: 2026-05-20 (Sprint 1 + Sprint 2 complete)
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

## ✅ Sprint 2.5: Feature Integration — COMPLETED

**Completed: 2026-05-14 | Scope: Cross-feature connectivity audit**
**Objective: Ensure no feature sits standalone — everything affects everything else**

### Task 2.5.1 ✅ Backend — Model Relationships
- Added 5 missing `hasMany` relationships to `Employee.php`:
  - `salaryAdvances()`, `guarantees()`, `documents()`, `evaluations()`, `liableMaintenance()`
- Added `expenses()` hasMany to `Vehicle.php` → links Vehicle ↔ VehicleExpense
- **Impact**: Enables eager loading and future count queries for all employee/vehicle sub-features

### Task 2.5.2 ✅ Employee Balance — Complete Deduction Breakdown
- Updated `EmployeeController@balance` to include 2 missing deduction sources:
  - `debits.advances` — total salary advance installments deducted from payroll
  - `debits.leaves` — total unpaid leave deductions
- **Before**: Balance only showed violations + maintenance + custody (3/5 deduction types)
- **After**: All 5 deduction types now visible in balance breakdown

### Task 2.5.3 ✅ Employee Profile — 3 New Connected Sections
Added 3 missing sections to `EmployeeProfile.jsx`:
- **💵 السلف (Salary Advances)**: Active advances with amount, progress bar, installment tracking, remaining balance
- **🔐 الضمانات (Guarantees)**: Held documents table (type, doc number, received date, status badge)
- **📦 العُهد (Custody Items)**: Items on employee record with status (active, returned, lost, damaged)

### Task 2.5.4 ✅ Balance Detail — Advance & Leave Rows
- Added 2 new deduction rows to the expandable balance detail on Employee Profile:
  - أقساط سلف (advance installments)
  - خصم إجازات (leave deductions)

### Connection Map (after fix)
```
Employee Profile now shows ALL related data:
├── 📊 الحساب المالي (violations + maintenance + custody + advances + leaves)
├── 📄 المستندات (document expiry stepper)
├── 🚗 التعيينات (vehicle assignments)
├── ⚠️ المخالفات (violations table)
├── 🏖️ الإجازات (leave balance + history)
├── 📂 الوثائق المرفقة (scanned documents)
├── 📝 التقييمات (evaluation scores)
├── 💵 السلف (salary advances + progress)     ← NEW
├── 🔐 الضمانات (held guarantees)              ← NEW
└── 📦 العُهد (custody items)                  ← NEW
```

**Files Modified:**
- `app/Models/Employee.php` — 5 new relationships
- `app/Models/Vehicle.php` — 1 new relationship
- `app/Http/Controllers/Api/EmployeeController.php` — balance endpoint enhanced
- `src/pages/employees/EmployeeProfile.jsx` — 3 new sections + 2 balance rows

---

## Sprint 3: Advanced Operational Modules & Platform Infrastructure

**Estimated: 6 days**
**Focus: Advanced import/export engines, SaaS subscription tiers, unified OMS/Dispatching interface, and WhatsApp driver self-service portals.**

### Task 3.1 — Flexible Import Engine & AI-Powered Mapper (From Section 9 & Task 3.1)
- [ ] **Dynamic Excel Column Mapping Interface**:
  - Build frontend mapping wizard: upload any custom Excel file, inspect rows, and visually pair Excel headers with corresponding database attributes (e.g., matching "الرقم المدني" to `civil_id`).
  - Create standard spreadsheet templates for bulk importing Employees, Vehicles, and Operations.
- [ ] **AI Heuristics Auto-Mapper Hook**:
  - Implement a lightweight regex/heuristic matching service (`app/Services/ImportService.php`) that parses column names and automatically infers standard fields, minimizing manual setup.
  - Expose this AI helper as a premium, paid-tier add-on feature inside subscription options.
- [ ] **Import Audit Logs & Failure Safe**:
  - Add backend `ImportLog` table and migration to track history, total rows, failure details, and skip flags per row.

### Task 3.2 — Restaurant & Store Delivery System (OMS) (From Section 6)
- [ ] **Unified Order Receiver API**:
  - Create dedicated public API endpoints (`POST /api/orders/receive`) with unified payloads mapping customer info, geolocations, items, payment statuses (Cash or Visa), and origin platforms (Shopify, Beeorder, Yallago, Movo).
- [ ] **All-in-One Dispatching Console**:
  - Build the operational Dispatching Board UI showing orders grouped by their lifecycle states.
- [ ] **Operational Order Dispatch Workflows**:
  - Enforce status progression: `New` ➡️ `Preparing` ➡️ `Ready for Dispatch` ➡️ `Delivering` (assigned to driver) ➡️ `Delivered` / `Cancelled`.
  - Handle cash/visa allocations automatically when assigning/settling delivery logs.

### Task 3.3 — SaaS Subscription Architecture & Combined Audits (From Section 7)
- [ ] **3-Tier Subscription Control Hierarchy**:
  - *Super Super Admin (Mersal Platform)*: General licensing billing, tenant dashboard, global system health.
  - *Tenant Client Super Admin (Abu Omar)*: Single master account controlling multiple companies (Al-Buraq, Al-Nisr, Aspect) with a unified analytics dashboard.
  - *Company Admin (Miss Faten)*: Access restricted entirely to operations and ledger within a single multi-tenant scoped company.
- [ ] **Custom Subscription State Restrictions**:
  - Implement dynamic route/action gating for tenant subscriptions:
    - `Active`: Normal full-system capabilities.
    - `Blocked`: Total login lockout with full data retention.
    - `View-Only (Read-Only Mode)`: Grace period fallback allowing historical reviews and data export but strictly disabling all create, save, edit, and delete buttons.

### Task 3.4 — WhatsApp Driver Portal & Digital Wallet (From Section 8 & 16)
- [ ] **Tokenized Secure Link Generator**:
  - Build automated background service to generate unique, secure, single-use token links (`/driver/portal/{token}`) pushed directly to drivers daily via WhatsApp API.
- [ ] **Responsive Mobile Daily Log Forms**:
  - Build super lightweight mobile web view requiring no complex authentication:
    - Input Odometer readings (reads last Odometer from DB as default/validator).
    - Camera capture upload for odometer picture verification.
    - Total completed orders count and physical cash collected.
    - Selection of active contract worked on during the shift.
- [ ] **Driver Digital Wallet & Transparency Hub**:
  - Build a driver wallet tab displaying real-time monthly bonus earnings, registered traffic violations, pending cash liabilities, bank vs. cash payouts, and notification triggers.
- [ ] **WhatsApp Alert Cost Optimization Hook**:
  - Limit automatic WhatsApp Meta messages to critical/urgent events only (unpaid cash limits, expiring vehicle/work papers, new violations) to prevent escalating billing from Meta API usage.

---

## Sprint 3.5: Operational Optimization & Core Bug Fixes

**Estimated: 4-5 days**
**Focus: Enforcing rigorous multi-tenant validation, resolving soft-delete audit crashes, refining daily log mathematical constraints, and implementing automated scheduling flows.**

### Task 3.5.1 — Core Tenant Isolation & Security (From bugs-1.md #1 & #3)
- [ ] **Multi-Company Uniqueness Scoping**:
  - Update unique database checks in `ClientController`, `EmployeeController`, `ContractController`, and `VehicleController` to be scoped under `company_id` using `Rule::unique(...)->where('company_id', $companyId)`.
  - Fix cross-tenant naming blocks for clients, contracts, and employees.
- [ ] **Console/Queue Context Defensive Guard**:
  - Update global scope in `BelongsToCompany` to force query isolation (`where('company_id', $companyId)`) defaulting to `0` if `current_company_id` is unbound, preventing accidental leaks of cross-tenant data in background commands/jobs.

### Task 3.5.2 — Soft-Delete Audit Trail Stabilization (From bugs-1.md #2)
- [ ] **Historical Relationships Auditing**:
  - Update `withTrashed()` on key relationship methods in historical transaction models (`DailyLog`, `Violation`, `MaintenanceRecord`, `VehicleAssignment`, `SalaryAdvance`, `DriverGuarantee`) pointing to soft-deleted entities (`Employee`, `Vehicle`, `Contract`).
  - Fix frontend crashes by ensuring soft-deleted related entities are returned safely with standard naming fallback (e.g. "Deleted Driver / Vehicle / Contract").

### Task 3.5.3 — Daily Log, Odometer Verification & Mathematical Consistency (From bugs-1.md #4, #5 & Sections 2.4, 2.5)
- [ ] **Daily Log Mathematical Consistency Check**:
  - Add backend validator callback to `DailyLogController` (both `store` and `update`) ensuring `orders_online + orders_cash == orders_count` to prevent corrupted operational entries.
  - Implement same logic in Frontend `DailyLog` form validation with dynamic sum indicator.
  - Enforce Odometer end check validation on edit/update: `odometer_end >= odometer_start`.
- [ ] **Odometer Photo Verification & Oil Change Alert Engine**:
  - Require image uploads in the daily log for Odometer checks to prevent tampering.
  - Trigger dynamic red indicator/warning box next to vehicles in the dashboard once the odometer difference reaches 4000 km since their last registered oil change.
- [ ] **Daily Log UI/UX Terms and Simplification**:
  - Rename "طلبات أونلاين" to "طلبات مدفوعة أونلاين / فيزا" inside daily screens.
  - Hide separate cash/online input in basic entry fields, capturing only Total Orders and Cash Collected as requested by operations, while maintaining optional full inputs.
  - Integrate date filters, worker search, contract tabs, and descending chronological sorting.

### Task 3.5.4 — Flexible Onboarding & Customized HR (From bugs-1.md #HR & Sections 2.1, 2.2, 2.3)
- [ ] **recruitment Type Form Steppers**:
  - Local Transfer: asks only for local papers (Civil ID, Health Certificate).
  - Hiring from Abroad: dynamically injects arrival steps (Arrival date, Medical check, Work permit tracking, Kuwaiti Driving test/license).
- [ ] **Document Warning Alerts & Driver Archives**:
  - Reduce document warning threshold from 60 days to 30 days.
  - Rename "ضمانات" tab to "أرشيف السائق / ملف السائق" (Driver Scanned Archive) inside the profile page, allowing scanned files storage (passports, civil IDs, marriage certifications, legal documents).
- [ ] **Flexible Evaluation Criteria per Employee**:
  - Redesign evaluation criteria workflow: allow criteria to be customizable per employee.
  - When creating a new evaluation, populate the employee's previous criteria, allow deleting criteria, and allow choosing/adding new custom criteria.
- [ ] **Employee Profile Statistics**:
  - Display employee's actual daily and monthly completed orders count on the profile dashboard tab.

### Task 3.5.5 — Operations & Payroll Recalculation Sync (From bugs-1.md #Payroll & Section 1.6)
- [ ] **Daily Log Correction & Payroll Recalculation**:
  - Allow correcting daily logs and triggering recalculation of the corresponding monthly Payroll Slip for the selected employee to prevent lockups on salary payments.

### Task 3.5.6 — Automated Violations Flow (From bugs-1.md #Violations)
- [ ] **Vehicle-Date Driven Driver Assignment**:
  - Change Violation Creation Form: prevent selecting employee manually.
  - User selects **Date** and **Vehicle** ➡️ backend automatically checks `VehicleAssignment` table to find the employee possessing the vehicle at that date/time and assigns them.
  - If the employee assigned is soft-deleted, display "Unknown" and route the liability to the company.
  - Liability toggle behavior:
    - If driver is **Active**, the "Is Driver Liable?" checkbox is optional/toggleable.
    - If driver is **Inactive or Deleted**, the liability automatically defaults to the **Company** and is locked/read-only.

### Task 3.5.7 — Expected Revenues, Targets & Cost Center Allocations (From Sections 11, 13 & bugs-1.md #191)
- [ ] **Contract Target Metrics & Revenues**:
  - Add fields `expected_revenue` and `target_drivers_count` directly into the Contract Creation Form on the frontend.
  - Match actual contract income against expectations to throw red dashboard flags for poor-performing accounts.
- [ ] **Supervisor Cost Center Allocation**:
  - Create cost center links for administrative/operational staff (Supervisors).
  - Charge supervisors' salaries directly onto designated active contracts (either 100% on one contract, or divided equally/proportionally among multiple contracts) to show true operational margins.

### Task 3.5.8 — Maintenance Trackers & Garage Metrics (From Section 14)
- [ ] **Expected Maintenance Duration & Delays**:
  - Add `expected_days` field to Maintenance Records.
  - Trigger automatic red alert banners for vehicles remaining in the garage beyond their expected release dates.
- [ ] **Maintenance Analytics Dashboard**:
  - Generate aggregated charts: monthly maintenance expenditure, repair counts per vehicle, breakdown category analysis, and Garage Quality & Price Rating dashboard.

### Task 3.5.9 — Internal Task Management System (From Section 17)
- [ ] **Administrative Tasks Dispatcher**:
  - Build internal Task Planner dashboard allowing administrators and managers to create task lists, set due dates, assign team members, and log status (New, In-Progress, Closed).

### Task 3.5.10 — Dashboard Smart Analytics & Roles (From Section 12)
- [ ] **Dashboard Smart Period Comparisons**:
  - Implement dashboard financial comparisons (Daily, Weekly, Monthly, Quarterly, and Yearly filters) highlighting performance fluctuations.
  - Set customized dashboard widgets according to role:
    - *GM*: Combined P&L, Contract Performance, and high-level KPIs.
    - *Accountant*: Cash balances, Ledger status, and pending salaries.
    - *Operator*: Logistics metrics, Daily logs, and pending tasks.
    - *Maintenance*: Oil warnings, garage records, and vehicle counts.

---

## Sprint 4: Native Accounting System (Strictly Contained ERP Module)

**Estimated: 7-8 days**
**Contract item: 10**

### Task 4.1 — Chart of Accounts
> شجرة الحسابات (Refined Section 10.1)

**Backend:**
- [ ] Create migration: `accounts` table
  - `id, company_id, parent_id (self-ref), code, name, name_ar, type (asset/liability/equity/income/expense), sub_type (bank/cash/receivable/payable/etc), is_group (bool), is_active, balance (decimal), currency (default base company currency e.g. KWD, or foreign currency for specific bank/client accounts), description, created_at, updated_at`
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
  - Add/edit account dialog (with currency selector)
  - Balance display per account

---

### Task 4.2 — Journal Entries
> القيود اليومية (مع دعم تعدد العملات Multi-Currency - Refined Section 10.2)

**Backend:**
- [ ] Create migration: `journal_entries` table
  - `id, company_id, entry_number (auto), entry_date, reference_type (nullable), reference_id (nullable), description, status (draft/posted/reversed), posted_by, posted_at, created_by, created_at, updated_at`
- [ ] Create migration: `journal_entry_lines` table (Multi-Currency optimized)
  - `id, journal_entry_id, account_id, amount (foreign currency amount), currency (KWD/USD/SAR/etc), exchange_rate (decimal 15,6, default 1.0), base_amount (converted to base currency), debit (decimal 15,3 in base currency), credit (decimal 15,3 in base currency), description, created_at, updated_at`
- [ ] Create models: `JournalEntry`, `JournalEntryLine`
- [ ] Create controller: `JournalEntryController.php`
  - `store()` — validate SUM(debit) = SUM(credit) in base currency, create entry + lines (handles amount conversion via rate)
  - `post(id)` — mark as posted, update account balances
  - `reverse(id)` — create reversing entry
  - Add FX Gain/Loss calculation and automated journal entries for realized exchange differences on payments/settlements.
- [ ] Add routes

**Frontend:**
- [ ] Create `src/pages/accounting/JournalEntriesPage.jsx`:
  - List view with filters (date range, status)
  - Create dialog: date, description, currency, exchange rate, dynamic rows (account + amount + debit/credit in base currency)
  - Running total showing balance (must = 0 in base currency)
  - Post / reverse actions

---

### Task 4.3 — General Ledger
> دفتر الأستاذ (Refined Section 10.2)

**Backend:**
- [ ] Add `ledger(account_id, from, to)` method to `JournalEntryController`
  - Returns all entries for an account with running balance

**Frontend:**
- [ ] Create `src/pages/accounting/GeneralLedgerPage.jsx`:
  - Account selector + date range
  - Table: date, entry number, description, debit, credit, running balance

---

### Task 4.4 — Invoices & Salaries Integration
> الفواتير وربط الرواتب (Refined from bugs-1.md #173 & Task 4.4)

**Backend:**
- [ ] Create migration: `invoices` table
  - `id, company_id, invoice_number (auto), invoice_type (sales/purchase), client_id (nullable), vendor_name (nullable), invoice_date, due_date, subtotal, tax_amount, total, status (draft/sent/paid/partial/overdue/cancelled), notes, journal_entry_id (FK), created_by, created_at, updated_at`
- [ ] Create migration: `invoice_items` table
  - `id, invoice_id, description, quantity, unit_price, amount, account_id`
- [ ] Create models: `Invoice`, `InvoiceItem`
- [ ] Create controller: `InvoiceController.php`
  - CRUD + auto-create journal entry when posted
- [ ] **Employee Salary Invoicing Integration (from bugs-1.md #Salary Vouchers)**:
  - Add a dedicated button/action on the Salary/Payroll slip view (frontend + backend) to generate a corresponding sales/purchase invoice voucher in the Native ERP module.
  - Automatically map the salary slip's allowances/deductions to the respective accounting ledger accounts (e.g. Salary Expense, Debits, Cash/Bank Payable).

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

### Task 4.6 — Financial Reports & Payroll Receipts
> التقارير المالية وسندات استلام الرواتب (Refined Section 15 & Task 4.6)

**Backend:**
- [ ] Create controller: `FinancialReportController.php`
  - `trialBalance(date)` — all accounts with debit/credit totals
  - `profitAndLoss(from, to)` — income - expenses
  - `balanceSheet(date)` — assets = liabilities + equity
  - `cashFlow(from, to)` — cash movements
- [ ] **Payroll Receipt Voucher Printing (From Section 15)**:
  - Implement high-fidelity printable salary payment receipt voucher displaying basic pay, bonuses, active advance deductions, violation charges, official bank payout portion, and physical cash portion with signature slots.

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
| **Sprint 2.5** | 2.5.1–2.5.4 (Integration) | 0.5 | ✅ DONE |
| **Sprint 3** | 3.1, 3.2, 3.3, 3.4 (Operational & SaaS) | 6 | ⬜ TODO |
| **Sprint 3.5** | 3.5.1–3.5.10 (Stabilization & Refinements) | 5 | ⬜ TODO |
| **Sprint 4** | 4.1, 4.2, 4.3, 4.4, 4.5, 4.6 (ERP & Vouchers) | 8 | ⬜ TODO |
| **TOTAL** | | **19 days remaining** | |

> [!NOTE]
> Deferred Tasks outside of Contract Scope:
> - CRM module — deferred
> - Automatic external multi-bank gateway integration — deferred
