<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\DailyLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


/**
 * Super-admin-only company management endpoints.
 * Full CRUD, module toggling, branding, user management.
 */
class SuperAdminCompanyController extends Controller
{
    /**
     * GET /api/admin/companies — list all companies with stats.
     */
    public function index(): JsonResponse
    {
        $companies = Company::orderBy('name')
            ->withCount([
                'users' => fn($q) => $q,
            ])
            ->get()
            ->map(function ($c) {
                return [
                    'id'              => $c->id,
                    'name'            => $c->name,
                    'name_ar'         => $c->name_ar,
                    'code'            => $c->code,
                    'logo_path'       => $c->logo_path,
                    'is_active'       => $c->is_active,
                    'currency'        => $c->currency,
                    'users_count'     => $c->users_count,
                    'modules_count'   => count($c->enabled_modules ?? []),
                    'created_at'      => $c->created_at,
                ];
            });

        return response()->json(['companies' => $companies]);
    }

    /**
     * POST /api/admin/companies — create a new company.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'name_ar'  => 'nullable|string|max:255',
            'code'     => 'required|string|max:50|unique:companies,code|alpha_dash',
            'phone'    => 'nullable|string|max:20',
            'email'    => 'nullable|email|max:255',
            'address'  => 'nullable|string|max:500',
            'tax_number' => 'nullable|string|max:50',
            'currency' => 'nullable|string|max:3',
        ]);

        $validated['enabled_modules'] = Company::DEFAULT_MODULES;
        $validated['is_active'] = true;

        $company = Company::create($validated);



        return response()->json([
            'message' => 'تم إنشاء الشركة بنجاح.',
            'company' => $company,
        ], 201);
    }

    /**
     * GET /api/admin/companies/{company} — show company details.
     */
    public function show(Company $company): JsonResponse
    {
        $company->loadCount('users');

        return response()->json(['company' => $company]);
    }

    /**
     * PUT /api/admin/companies/{company} — update company info.
     */
    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'name_ar'   => 'nullable|string|max:255',
            'code'      => 'sometimes|string|max:50|alpha_dash|unique:companies,code,' . $company->id,
            'phone'     => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:255',
            'address'   => 'nullable|string|max:500',
            'tax_number'=> 'nullable|string|max:50',
            'currency'  => 'nullable|string|max:3',
            'is_active' => 'nullable|boolean',
        ]);

        $company->update($validated);

        return response()->json([
            'message' => 'تم تحديث بيانات الشركة.',
            'company' => $company->fresh(),
        ]);
    }

    /**
     * PUT /api/admin/companies/{company}/modules — toggle enabled modules.
     */
    public function updateModules(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'enabled_modules'   => 'required|array',
            'enabled_modules.*' => 'string|in:' . implode(',', Company::ALL_MODULES),
        ]);

        $company->update(['enabled_modules' => $validated['enabled_modules']]);

        return response()->json([
            'message'         => 'تم تحديث الوحدات المفعّلة.',
            'enabled_modules' => $company->enabled_modules,
        ]);
    }

    /**
     * PUT /api/admin/companies/{company}/branding — update branding/theme.
     */
    public function updateBranding(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'branding'              => 'required|array',
            'branding.primary_color'=> 'nullable|string|max:20',
            'branding.accent_color' => 'nullable|string|max:20',
            'branding.sidebar_bg'   => 'nullable|string|max:20',
            'branding.sidebar_text' => 'nullable|string|max:20',
            'branding.header_bg'    => 'nullable|string|max:20',
            'branding.font_family'  => 'nullable|string|max:50',
            'logo'                  => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('logos', 'public');
            $company->logo_path = '/storage/' . $path;
        }

        $company->branding = $validated['branding'];
        $company->save();

        return response()->json([
            'message'  => 'تم تحديث الهوية البصرية.',
            'branding' => $company->branding,
            'logo_path'=> $company->logo_path,
        ]);
    }

    /**
     * GET /api/admin/companies/{company}/users — list company users.
     */
    public function users(Company $company): JsonResponse
    {
        $users = $company->users()
            ->select('id', 'name', 'email', 'role', 'is_super_admin')
            ->get();

        return response()->json(['users' => $users]);
    }

    /**
     * POST /api/admin/companies/{company}/users — add user to company.
     */
    public function addUser(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role'    => 'required|in:admin,operator,accountant',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if ($user->company_id === $company->id) {
            return response()->json(['message' => 'المستخدم موجود بالفعل في هذه الشركة.'], 422);
        }

        $user->update([
            'company_id' => $company->id,
            'role'       => $validated['role'],
        ]);

        return response()->json(['message' => 'تم إضافة المستخدم للشركة.'], 201);
    }

    /**
     * POST /api/admin/companies/{company}/users/create — create a new user for this company.
     */
    public function createUser(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'role'     => 'required|in:admin,operator,accountant',
        ]);

        $user = User::create([
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'password'   => bcrypt($validated['password']),
            'role'       => $validated['role'],
            'company_id' => $company->id,
        ]);

        return response()->json([
            'message' => 'تم إنشاء المستخدم بنجاح.',
            'user'    => $user->only(['id', 'name', 'email', 'role', 'company_id']),
        ], 201);
    }

    /**
     * PUT /api/admin/companies/{company}/users/{user} — update user info.
     */
    public function updateUser(Request $request, Company $company, User $user): JsonResponse
    {
        if ($user->company_id !== $company->id) {
            return response()->json(['message' => 'المستخدم غير موجود في هذه الشركة.'], 404);
        }

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => 'sometimes|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role'     => 'sometimes|in:admin,operator,accountant',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'تم تحديث بيانات المستخدم.',
            'user'    => $user->fresh()->only(['id', 'name', 'email', 'role', 'is_super_admin', 'company_id']),
        ]);
    }

    /**
     * DELETE /api/admin/companies/{company}/users/{user} — remove user from company.
     */
    public function removeUser(Request $request, Company $company, User $user): JsonResponse
    {
        if ($user->company_id !== $company->id) {
            return response()->json(['message' => 'المستخدم غير موجود في هذه الشركة.'], 404);
        }

        // Prevent removing yourself
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'لا يمكنك إزالة نفسك.'], 422);
        }

        // Prevent removing super admins
        if ($user->is_super_admin) {
            return response()->json(['message' => 'لا يمكن إزالة مسؤول أعلى.'], 422);
        }

        $user->update(['company_id' => null]);

        return response()->json(['message' => 'تم إزالة المستخدم من الشركة.']);
    }

    /**
     * GET /api/admin/dashboard — cross-company aggregate dashboard.
     */
    public function dashboard(): JsonResponse
    {
        $companies = Company::where('is_active', true)->get()->map(fn($c) => [
            'id'              => $c->id,
            'name'            => $c->name,
            'name_ar'         => $c->name_ar,
            'code'            => $c->code,
            'is_active'       => $c->is_active,
            'employees_count' => Employee::withoutGlobalScope('company')->where('company_id', $c->id)->count(),
            'vehicles_count'  => Vehicle::withoutGlobalScope('company')->where('company_id', $c->id)->count(),
            'pending_cash'    => DailyLog::withoutGlobalScope('company')->where('company_id', $c->id)->sum('cash_pending'),
            'modules_count'   => count($c->enabled_modules ?? []),
        ]);

        return response()->json([
            'total_companies'  => $companies->count(),
            'total_employees'  => $companies->sum('employees_count'),
            'total_vehicles'   => $companies->sum('vehicles_count'),
            'total_pending_cash' => $companies->sum('pending_cash'),
            'companies'        => $companies,
        ]);
    }
}
