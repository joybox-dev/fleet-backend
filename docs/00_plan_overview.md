# FleetOps Implementation Plan — Overview (Updated 2026-05-12)

## Current Status

Phase 0 (Multi-Tenant Foundation) is **COMPLETE** ✅.
The system uses a **SaaS single-database** model with `company_id` column isolation.

## Architecture Decision: SaaS Model

- **Each user belongs to exactly ONE company** (`users.company_id`)
- No pivot table — simple `belongsTo` relationship
- Super admin (`is_super_admin = true`) can view/manage all companies
- Company role stored on `users.role` directly

## Phase Order & Dependencies

```
Phase 0 ✅ Multi-Tenant Foundation (DONE)
  │
  ├── Phase 1: Quick Wins (2-3 days)
  │     │
  │     ├── Phase 2: New Modules (5-7 days)
  │     │     ├── 2.1 Driver Guarantees
  │     │     ├── 2.2 Vehicle Expenses
  │     │     └── 2.3 Salary Advances
  │     │
  │     ├── Phase 3: Operations (5-7 days)
  │     │     ├── 3.1 Attendance System
  │     │     └── 3.2 Contract Capacity
  │     │
  │     ├── Phase 4: HR System (4-5 days)
  │     │     ├── 4.1 Employee Documents
  │     │     └── 4.2 Employee Evaluations
  │     │
  │     └── Phase 5: Excel Import (3-4 days)
  │
  └── Phase 6: Accounting System (10-14 days)
        ├── 6.1 Chart of Accounts
        ├── 6.2 Journal Entries
        ├── 6.3 Invoices & Payments
        └── 6.4 Financial Reports
```

## Phase Summary

| Phase | Scope | Status | Est. Effort |
|-------|-------|--------|-------------|
| **0** | Multi-Tenant Foundation | ✅ **DONE** | — |
| **1** | Quick Wins (5 items) | ⬜ TODO | 2-3 days |
| **2** | 3 New Modules (Guarantees, Vehicle Expenses, Advances) | ⬜ TODO | 5-7 days |
| **3** | Operations (Attendance + Capacity) | ⬜ TODO | 5-7 days |
| **4** | HR (Documents + Evaluations) | ⬜ TODO | 4-5 days |
| **5** | Excel Import | ⬜ TODO | 3-4 days |
| **6** | Accounting System | ⬜ TODO | 10-14 days |
| | **TOTAL REMAINING** | | **~30-41 days** |

## What Phase 0 Delivered

| Component | Description |
|-----------|-------------|
| `companies` table | Full company model with branding, modules, settings |
| `users.company_id` | Each user belongs to one company (SaaS model) |
| `users.is_super_admin` | Platform-level super admin flag |
| `BelongsToCompany` trait | Auto-scoping global scope applied to 17 models |
| `SetCurrentCompany` middleware | Resolves tenant from `user->company_id` |
| `CheckModuleEnabled` middleware | Feature gating per company |
| `SuperAdminOnly` middleware | Guards management endpoints |
| `CheckRole` middleware | Updated for direct `users.role` |
| `SuperAdminCompanyController` | Full CRUD, branding, modules, user management |
| `CompanyController` | Company info endpoint |
| `AuthController` | Returns company context on login |
| Frontend `CompanyContext` | Branding via CSS custom properties |
| Frontend `Sidebar` | Module-gated navigation, dynamic logo |
| Frontend `Header` | Super admin badge |
| `MigrateToMultiTenantSeeder` | Backfill existing data |
| `CleanDemoSeeder` | Updated with company context |

## Tables With `company_id`

All 16 core tables: `clients`, `contracts`, `employees`, `vehicles`, `vehicle_assignments`,
`daily_logs`, `violations`, `maintenance_records`, `custody_items`, `custody_types`,
`cash_settlements`, `payroll_runs`, `payroll_slips`, `leave_types`, `employee_leaves`, `settings`

## New Tables per Phase

| Phase | Table | Purpose |
|-------|-------|---------|
| 0 ✅ | `companies` | Multi-tenant companies |
| 2 | `driver_guarantees` | Passport/document deposits |
| 2 | `vehicle_expenses` | General vehicle costs |
| 2 | `salary_advances` | Employee advance loans |
| 2 | `advance_deductions` | Monthly installment tracking |
| 3 | `attendances` | Daily attendance records |
| 4 | `employee_documents` | Scanned doc file storage |
| 4 | `employee_evaluations` | Performance reviews |
| 4 | `evaluation_criteria` | Rating criteria definitions |
| 5 | `import_logs` | Excel import history |
| 6 | `accounts` | Chart of Accounts tree |
| 6 | `journal_entries` | Accounting journal |
| 6 | `journal_entry_lines` | Debit/credit lines |
| 6 | `invoices` | Sales/purchase invoices |
| 6 | `invoice_items` | Invoice line items |
| 6 | `payments` | Payment records |

## Access Model

```
┌─────────────────────────────────────────────────┐
│                 SUPER ADMIN                      │
│  • is_super_admin = true on users table          │
│  • Sees ALL companies                            │
│  • Can create/edit/delete companies              │
│  • Can toggle modules per company                │
│  • Can set branding per company                  │
│  • Can assign users to companies                 │
│  • Cross-company aggregate dashboard             │
│  • Bypasses all role & module checks             │
├─────────────────────────────────────────────────┤
│              COMPANY ADMIN                       │
│  • users.role = 'admin'                          │
│  • users.company_id = their company              │
│  • Full access WITHIN their company              │
│  • Can only see enabled modules                  │
├─────────────────────────────────────────────────┤
│           COMPANY USER                           │
│  • users.role = 'operator' | 'accountant'        │
│  • users.company_id = their company              │
│  • Limited access per role                       │
│  • Can only see enabled modules                  │
│  • Scoped to their company's data only           │
└─────────────────────────────────────────────────┘
```

See detailed plans per phase in companion files.
