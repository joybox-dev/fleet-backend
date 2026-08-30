<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\ContractScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One search box for the whole system.
 *
 * Finding a driver or a contract meant knowing which screen it lived on and filtering there.
 * Results are grouped by kind and carry the route to open, so the caller does not have to know
 * where anything is filed.
 */
class GlobalSearchController extends Controller
{
    private const PER_GROUP = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        // Two characters is the shortest term that narrows anything; below that every query is a
        // full table scan returning noise.
        if (mb_strlen($term) < 2) {
            return response()->json(['query' => $term, 'groups' => [], 'total' => 0]);
        }

        $like = '%'.$term.'%';
        $user = $request->user();
        $groups = [];

        if ($user->can('contracts.view')) {
            $groups[] = $this->group('contracts', 'العقود', '📋', Contract::query()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('contract_number', 'like', $like))
                ->with('client:id,name')
                ->orderByRaw("FIELD(status, 'active', 'suspended', 'ended') ASC")
                ->limit(self::PER_GROUP)
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->name,
                    'subtitle' => trim('#'.$c->contract_number.' · '.($c->client?->name ?? '')),
                    'status' => $c->status,
                    'route' => "/contracts/{$c->id}/dashboard",
                ]));
        }

        if ($user->can('employees.view')) {
            // Supervisors only see the drivers allocated to them; the search must not be a way
            // around that.
            $allowed = ContractScopeService::getAllocatedDriverIds($user);

            $groups[] = $this->group('employees', 'الموظفون', '👷', Employee::query()
                ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
                ->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('employee_number', 'like', $like)
                    ->orWhere('phone', 'like', $like))
                ->orderByRaw("FIELD(status, 'active') DESC")
                ->limit(self::PER_GROUP)
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'title' => $e->name,
                    'subtitle' => trim($e->employee_number.($e->phone ? ' · '.$e->phone : '')),
                    'status' => $e->status,
                    'route' => "/employees/{$e->id}",
                ]));
        }

        if ($user->can('vehicles.view')) {
            $groups[] = $this->group('vehicles', 'المركبات', '🚗', Vehicle::query()
                ->where(fn ($q) => $q->where('plate_number', 'like', $like)
                    ->orWhere('make', 'like', $like)
                    ->orWhere('model', 'like', $like))
                ->with('vehicleType:id,name_ar,name')
                ->limit(self::PER_GROUP)
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'title' => $v->plate_number,
                    'subtitle' => trim(($v->make ?? '').' '.($v->model ?? '').' · '.($v->vehicleType?->name_ar ?? '')),
                    'status' => $v->status,
                    'route' => "/vehicles/{$v->id}",
                ]));
        }

        if ($user->can('clients.view')) {
            $groups[] = $this->group('clients', 'العملاء', '🏢', Client::query()
                ->where('name', 'like', $like)
                ->limit(self::PER_GROUP)
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->name,
                    'subtitle' => $c->phone ?? '',
                    'status' => null,
                    'route' => '/clients',
                ]));
        }

        if ($user->can('violations.view')) {
            $groups[] = $this->group('violations', 'المخالفات', '⚠️', Violation::query()
                ->where(fn ($q) => $q->where('reference_number', 'like', $like)
                    ->orWhere('violation_type', 'like', $like))
                ->with('employee:id,name')
                ->latest('violation_date')
                ->limit(self::PER_GROUP)
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'title' => $v->reference_number ?: $v->violation_type,
                    'subtitle' => trim(($v->employee?->name ?? '').' · '.$v->violation_date),
                    'status' => $v->is_deducted ? 'مخصومة' : 'غير مخصومة',
                    'route' => '/violations',
                ]));
        }

        $groups = array_values(array_filter($groups, fn ($g) => count($g['items']) > 0));

        return response()->json([
            'query' => $term,
            'groups' => $groups,
            'total' => array_sum(array_map(fn ($g) => count($g['items']), $groups)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $key, string $label, string $icon, $items): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'items' => $items instanceof \Illuminate\Support\Collection ? $items->values()->all() : $items,
        ];
    }
}
