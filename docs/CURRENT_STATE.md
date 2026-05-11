# FleetOps — Current State Reference

> Last updated: 2026-05-12
> Branch: `feature/multi-tenant`

## ⚡ Quick Context for New Sessions

This project is a **multi-tenant SaaS fleet management system** (Arabic/Kuwait market).
Phase 0 (multi-tenant foundation) is COMPLETE. Read this file first before doing anything.

---

## Architecture: SaaS Model

```
User → belongsTo → Company (via users.company_id)
All business models → BelongsToCompany trait → scoped by company_id
Super Admin → is_super_admin=true → can view all companies
```

### Key Rule: ONE user = ONE company
- `users.company_id` (FK to companies)
- `users.role` = admin | operator | accountant
- `users.is_super_admin` = platform owner (bypasses everything)
- NO pivot table. No multi-company per user.

---

## Files Created/Modified in Phase 0 (ALREADY DONE)

### Database (Migrations — already ran)
- `2026_05_11_200001_create_companies_table.php` — companies table
- `2026_05_11_200002_create_company_user_table.php` — pivot (later dropped)
- `2026_05_11_200003_add_is_super_admin_to_users.php`
- `2026_05_11_200004_add_company_id_to_all_tables.php` — adds company_id to 16 tables
- `2026_05_11_200005_enforce_company_id_not_null.php` — makes company_id NOT NULL
- `2026_05_12_010001_move_company_id_to_users_table.php` — adds company_id to users, drops pivot

### Backend Files (ALREADY EXIST)
| File | Purpose |
|------|---------|
| `app/Models/Company.php` | Company model with `DEFAULT_MODULES`, `ALL_MODULES`, `hasModule()` |
| `app/Models/User.php` | Has `company()` belongsTo, `isSuperAdmin()`, fillable includes `company_id` |
| `app/Traits/BelongsToCompany.php` | Global scope + auto-set company_id on create |
| `app/Http/Middleware/SetCurrentCompany.php` | Reads `user->company_id`, binds to container |
| `app/Http/Middleware/CheckModuleEnabled.php` | Gates API by company's enabled_modules |
| `app/Http/Middleware/SuperAdminOnly.php` | Restricts to is_super_admin users |
| `app/Http/Middleware/CheckRole.php` | Role check from `users.role`, super admin bypass |
| `app/Http/Controllers/Api/AuthController.php` | Login returns `current_company` with branding |
| `app/Http/Controllers/Api/CompanyController.php` | `current()` — user's company info |
| `app/Http/Controllers/Api/SuperAdminCompanyController.php` | Full CRUD, branding, modules, users |

### Models with `use BelongsToCompany` (17 models — ALREADY DONE)
Client, Contract, Employee, Vehicle, VehicleAssignment, DailyLog, Violation,
MaintenanceRecord, CustodyItem, CustodyType, CashSettlement, PayrollRun,
PayrollSlip, LeaveType, EmployeeLeave, Setting, EmployeeLeave

### Routes (in `routes/api.php` — ALREADY REGISTERED)
```
# Regular users (middleware: auth:sanctum, company)
GET  /api/company                          → CompanyController@current

# Super admin only (middleware: super_admin)
GET  /api/admin/companies                  → SuperAdminCompanyController@index
POST /api/admin/companies                  → SuperAdminCompanyController@store
GET  /api/admin/companies/{id}             → SuperAdminCompanyController@show
PUT  /api/admin/companies/{id}             → SuperAdminCompanyController@update
DELETE /api/admin/companies/{id}           → SuperAdminCompanyController@destroy
PUT  /api/admin/companies/{id}/modules     → updateModules
PUT  /api/admin/companies/{id}/branding    → updateBranding
GET  /api/admin/companies/{id}/users       → users
POST /api/admin/companies/{id}/users       → addUser (sets user.company_id)
DELETE /api/admin/companies/{id}/users/{u} → removeUser (nulls user.company_id)
GET  /api/admin/dashboard                  → dashboard (cross-company)
```

### Frontend Files (ALREADY EXIST)
| File | Purpose |
|------|---------|
| `src/context/CompanyContext.jsx` | `initFromLogin()`, `hasModule()`, branding CSS vars |
| `src/context/AuthContext.jsx` | Integrated with CompanyContext |
| `src/api/client.js` | Axios injects `X-Company-Id` header |
| `src/components/layout/Sidebar.jsx` | Module-gated links, dynamic logo |
| `src/components/layout/Header.jsx` | Super admin badge (no company switcher) |
| `src/App.jsx` | Wrapped with `CompanyProvider` |

### Container Bindings (set by SetCurrentCompany middleware)
```php
app('current_company_id')  // int — used by BelongsToCompany trait
app('current_company')     // Company model — used by CheckModuleEnabled
app('current_company_role') // string — admin/operator/accountant
app('is_super_admin')      // bool
```

---

## Database: Current Schema

### companies table
```
id, name, name_ar, code (unique), logo_path, branding (JSON), enabled_modules (JSON),
phone, email, address, tax_number, currency (default 'KWD'), is_active, settings (JSON),
created_at, updated_at
```

### users table
```
id, name, email, email_verified_at, password, remember_token,
role (admin/operator/accountant), is_super_admin (bool), company_id (FK → companies),
created_at, updated_at
```

### All other tables
All have `company_id` column (NOT NULL, FK → companies).

---

## Demo Data (from CleanDemoSeeder)

| Entity | Count | Details |
|--------|-------|---------|
| Company | 1 | "الشركة الافتراضية" (code: default, all modules enabled) |
| Users | 2 | mersal@fleetops.kw (admin, super_admin) / op@fleetops.kw (operator) |
| Clients | 3 | Talabat, Keeta, Deliveroo |
| Contracts | 3 | One per client |
| Employees | 2 | Demo drivers |
| Vehicles | 3 | Demo vehicles |
| + more | — | Assignments, logs, violations, custody, leaves |

**Login**: `mersal@fleetops.kw` / `abuhadram`

---

## What's NOT Done Yet (Phases 1-6)

See `docs/01_phase_0_1.md`, `docs/02_phase_2_3_4.md`, `docs/03_phase_5_6.md` for details.

### For any new module, follow this pattern:
1. Create migration with `company_id` FK
2. Create model with `use BelongsToCompany`
3. Create controller (data is auto-scoped, no manual where clause needed)
4. Add routes inside the `middleware(['auth:sanctum', 'company'])` group
5. Optionally wrap with `middleware('module:module_name')` for feature gating
6. Frontend: check `hasModule('module_name')` before showing UI
