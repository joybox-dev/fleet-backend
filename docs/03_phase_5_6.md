# Phase 5: Excel Import (3-4 days) — ⬜ TODO

## 5.1 — Backend Import Service (2 days)
- Install `maatwebsite/excel`
- Table: `import_logs` — entity_type, file_name, total/success/failed rows, errors JSON, status
- `ImportController` with preview (parse + show sample) and execute (validate + import) endpoints
- Importer classes per entity: EmployeeImporter, VehicleImporter, DailyLogImporter, ViolationImporter

## 5.2 — Frontend Import UI (1-2 days)
- 3-step wizard: Upload → Map Columns → Confirm & Execute
- Add import button to employee/vehicle/daily-log list pages

---

# Phase 6: Accounting System (10-14 days) — ⬜ TODO

> Largest standalone module. Replaces ERPNext financial dependency.

## 6.1 — Chart of Accounts (2 days)
- Table: `accounts` — code, name, type (asset/liability/equity/income/expense), parent_id (tree), is_group
- Default Kuwait chart seeder (Assets 1xxx, Liabilities 2xxx, Equity 3xxx, Income 4xxx, Expenses 5xxx)
- Tree view frontend + CRUD

## 6.2 — Journal Entries (3 days)
- Table: `journal_entries` — entry_number, entry_date, reference_type/id, status (draft/posted/reversed)
- Table: `journal_entry_lines` — account_id, debit, credit
- Core rules: debits must equal credits, can't post to groups, posted entries immutable
- Auto-generated entries from payroll, violations, daily logs

## 6.3 — Invoices & Payments (3 days)
- Table: `invoices` — type (sales/purchase), client_id, totals, status, linked journal_entry
- Table: `invoice_items` — description, quantity, unit_price, total
- Table: `payments` — type (receipt/payment), method, amount, linked journal_entry
- Auto journal entry on posting

## 6.4 — Financial Reports (2 days)
- Trial Balance: sum all account debits/credits
- Profit & Loss: income - expenses for period
- Balance Sheet: assets = liabilities + equity
- General Ledger: all transactions per account
- Cash Flow: changes in cash/bank over period
- All reports support Excel export + date range filter

---

# Gap Scoreboard (Updated 2026-05-12)

| # | Module | Before | Now | Δ |
|---|--------|--------|-----|---|
| 1 | المخالفات | ⚠️ 75% | ⚠️ 75% | Phase 1 will complete |
| 2 | العهد والكاش | ✅ 95% | ✅ 95% | Phase 1 will add 3-state |
| 3 | ضمانات السائقين | ❌ 0% | ❌ 0% | Phase 2 |
| 4 | العمليات | ⚠️ 40% | ⚠️ 40% | Phase 3 |
| 5 | رفع Excel | ⚠️ 30% | ⚠️ 30% | Phase 5 |
| 6 | مصاريف المركبات | ⚠️ 30% | ⚠️ 30% | Phase 2 |
| 7 | HR تقييم | ⚠️ 25% | ⚠️ 25% | Phase 4 |
| 8 | حسابات المندوبين | ⚠️ 40% | ⚠️ 40% | Phase 2 (advances) |
| 9 | التقارير | ⚠️ 70% | ⚠️ 70% | Phase 1 (missing docs) |
| 10 | الحسابات (Finance) | ❌ 5% | ❌ 5% | Phase 6 |
| 11 | تعدد الشركات | ❌ 0% | ✅ **100%** | **DONE** ✅ |
