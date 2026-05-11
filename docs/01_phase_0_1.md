# Phase 0: Multi-Tenant Foundation — ✅ COMPLETE

> Completed 2026-05-12

## What Was Done

### Database
- Created `companies` table with branding (JSON), enabled_modules (JSON), settings
- Added `company_id` to ALL 16 core business tables (NOT NULL with FK constraint)
- Added `is_super_admin` boolean to `users` table
- Added `company_id` to `users` table (each user → one company, SaaS model)
- Dropped `company_user` pivot table (simplified from many-to-many to belongsTo)
- Ran `php artisan storage:link` for logo uploads

### Backend Architecture
- **`BelongsToCompany` trait** — Auto-scoping global scope + auto-set on creation. Applied to 17 models.
- **`SetCurrentCompany` middleware** — Reads `user->company_id` directly. Super admin can override via `X-Company-Id` header.
- **`CheckModuleEnabled` middleware** — Gates API access per company's `enabled_modules`.
- **`SuperAdminOnly` middleware** — Restricts management endpoints.
- **`CheckRole` middleware** — Uses `users.role` directly, super admin bypass.
- **`AuthController`** — Returns `current_company` with branding/modules on login.
- **`CompanyController`** — `current()` for user's company info + `update()` for admin self-service.
- **`SuperAdminCompanyController`** — Full CRUD, branding, modules, user CRUD (create/update/remove with safety guards).

### Super Admin Platform Management
- **Admin Dashboard** (`/admin/dashboard`) — Cross-company stats: total companies, employees, vehicles, pending cash.
- **Company Management** (`/admin/companies`) — Create/edit/delete companies, toggle modules, manage users.
- **User CRUD** — Create new users for a company, update name/email/role/password, remove (with protection).
- **Safety Guards** — Can't remove yourself, can't remove super admins (both frontend + backend enforced).

### Company Self-Service (Settings Page)
- **Company Info Tab** — Edit name (EN/AR), phone, email, address, tax number.
- **Branding Tab** — Upload logo + pick primary/accent colors with color pickers.
- Info boxes explain what each color affects.
- Changes apply instantly (no re-login needed) via `refreshCompany()`.
- **WhatsApp Tab** — API credentials (existing).
- **Custody Types Tab** — CRUD for custody item types (existing).

### Frontend
- **`CompanyContext`** — `initFromLogin()`, `hasModule()`, `refreshCompany()`, branding CSS vars.
- **Branding maps to `--accent-primary`** (buttons, sidebar, links, login page, table rows).
- **`Sidebar`** — Module-gated navigation, dynamic company logo, admin section.
- **`Header`** — Super admin badge.
- **`api/client.js`** — Axios injects `X-Company-Id` header automatically.
- **`api/index.js`** — `adminApi` (platform management) + `companyApi` (self-service).
- **`vite.config.js`** — Proxy `/api` + `/storage` to backend for dev.

### Data Migration
- `MigrateToMultiTenantSeeder` — Creates default company, backfills all existing data.
- `CleanDemoSeeder` — Updated with company context and `company_id` on users.

### Key Design Decisions
1. **SaaS model**: Each user belongs to ONE company (no multi-company per user)
2. **Single database**: Shared DB with column-level isolation via `company_id`
3. **Role on users table**: `users.role` column (admin/operator/accountant), not on pivot
4. **Super admin**: Platform-level flag, can view any company via header override
5. **Module gating**: SA sees all, regular users see only enabled modules
6. **Branding**: Stored in `companies.branding` JSON, applied as CSS custom properties

### Complete API Reference (Phase 0)
```
# Auth
POST /api/auth/login                             → login (returns user + company + branding)
POST /api/auth/logout                            → logout
GET  /api/auth/me                                → current user info

# Company Self-Service (admin role required)
GET  /api/company                                → CompanyController@current
PUT  /api/company                                → CompanyController@update (info + branding)
POST /api/company                                → CompanyController@update (with logo upload)

# Super Admin — Platform Management
GET    /api/admin/companies                      → list all companies
POST   /api/admin/companies                      → create company
GET    /api/admin/companies/{id}                 → show company
PUT    /api/admin/companies/{id}                 → update company
DELETE /api/admin/companies/{id}                 → delete company
PUT    /api/admin/companies/{id}/modules         → toggle modules
PUT    /api/admin/companies/{id}/branding        → update branding
GET    /api/admin/companies/{id}/users           → list users
POST   /api/admin/companies/{id}/users           → assign existing user
POST   /api/admin/companies/{id}/users/create    → create new user
PUT    /api/admin/companies/{id}/users/{uid}     → update user (name/email/role/password)
DELETE /api/admin/companies/{id}/users/{uid}     → remove user (protected)
GET    /api/admin/dashboard                      → aggregate dashboard
```

---

# Phase 1: Quick Wins (2-3 days) — ⬜ TODO

> 5 independent tasks, no dependencies between them.

## 1.1 — Violation Photo Upload

**Current**: `photo_path` is a text URL input.
**Target**: Real file upload widget using existing `UploadController`.

### Backend
- No changes needed — `UploadController` already handles file uploads.
- `ViolationController::store()` already accepts `photo_path`.

### Frontend (`ViolationList.jsx`)
```jsx
<div className="form-field">
  <label>صورة المخالفة</label>
  <input type="file" accept="image/*" capture="environment"
    onChange={async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const res = await uploadApi.single(file, 'violations');
      setForm({ ...form, photo_path: res.data.path });
    }} />
  {form.photo_path && <img src={form.photo_path} style={{maxHeight:100}} />}
</div>
```

---

## 1.2 — WhatsApp Auto-Send on Violation

**Current**: `WhatsAppService` exists but is NOT called when creating violations.
**Target**: Auto-send message to driver on violation creation.

### Backend (`ViolationController::store()`)
After `Violation::create()`, add:
```php
$employee = $violation->employee;
if ($employee?->has_whatsapp && $employee?->phone) {
    try {
        app(\App\Services\WhatsAppService::class)->sendMessage(
            $employee->phone,
            "⚠️ مخالفة مرورية\nالتاريخ: {$violation->violation_date}\n"
            . "النوع: {$violation->violation_type}\n"
            . "المبلغ: {$violation->amount} د.ك"
        );
    } catch (\Throwable $e) {
        \Log::warning('WhatsApp send failed', ['violation_id' => $violation->id]);
    }
}
```

---

## 1.3 — Auto Employee Number

**Current**: `employee_number` field exists but is NOT auto-generated.
**Target**: Auto-generate `EMP-0001` format on creation.

### Backend (`EmployeeController::store()`)
Before `Employee::create()`:
```php
$lastNum = Employee::max(DB::raw("CAST(SUBSTR(employee_number, 5) AS UNSIGNED)"));
$validated['employee_number'] = 'EMP-' . str_pad(($lastNum ?? 0) + 1, 4, '0', STR_PAD_LEFT);
```

---

## 1.4 — Missing Documents Report

**Current**: Only expiring docs report exists. No report for missing (NULL) documents.
**Target**: New report for employees with missing civil_id, residence_expiry, etc.

### Backend
New `ReportController::missingDocs()` method + route: `GET /api/reports/missing-docs`

Returns employees with NULL values in: `civil_id`, `residence_expiry`, `work_permit_expiry`,
`health_card_expiry`, `driving_license_expiry`.

---

## 1.5 — Custody 3-State Status

**Current**: Binary `is_returned` boolean.
**Target**: Proper `status` enum: `active` → `held` → `returned`.

### Migration
```php
Schema::table('custody_items', function (Blueprint $table) {
    $table->enum('status', ['active', 'held', 'returned'])->default('active')->after('is_returned');
});
// Data migration: is_returned=true → status='returned', else 'active'
// Then drop is_returned column
```
