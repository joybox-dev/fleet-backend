# Phase 0: Multi-Tenant Foundation — ✅ COMPLETE

> Completed 2026-05-12

## What Was Done

### Database
- Created `companies` table with branding (JSON), enabled_modules (JSON), settings
- Added `company_id` to ALL 16 core business tables (NOT NULL with FK constraint)
- Added `is_super_admin` boolean to `users` table
- Added `company_id` to `users` table (each user → one company, SaaS model)
- Dropped `company_user` pivot table (simplified from many-to-many to belongsTo)

### Backend Architecture
- **`BelongsToCompany` trait** — Auto-scoping global scope + auto-set on creation. Applied to 17 models.
- **`SetCurrentCompany` middleware** — Reads `user->company_id` directly. Super admin can override via `X-Company-Id` header.
- **`CheckModuleEnabled` middleware** — Gates API access per company's `enabled_modules`.
- **`SuperAdminOnly` middleware** — Restricts management endpoints.
- **`CheckRole` middleware** — Uses `users.role` directly, super admin bypass.
- **`AuthController`** — Returns `current_company` with branding/modules on login.
- **`CompanyController`** — Single `current()` endpoint for user's company info.
- **`SuperAdminCompanyController`** — Full CRUD, branding, modules, user assignment via `company_id`.

### Frontend
- **`CompanyContext`** — Manages branding (CSS custom properties), module gating.
- **`Sidebar`** — Module-gated navigation, dynamic company logo.
- **`Header`** — Super admin badge.
- **`api/client.js`** — Axios injects `X-Company-Id` header automatically.

### Data Migration
- `MigrateToMultiTenantSeeder` — Creates default company, backfills all existing data.
- `CleanDemoSeeder` — Updated with company context and `company_id` on users.

### Key Design Decisions
1. **SaaS model**: Each user belongs to ONE company (no multi-company per user)
2. **Single database**: Shared DB with column-level isolation via `company_id`
3. **Role on users table**: `users.role` column (admin/operator/accountant), not on pivot
4. **Super admin**: Platform-level flag, can view any company via header override

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
