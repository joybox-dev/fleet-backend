<?php

namespace App\Services;

use App\Models\ContractAssignment;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleType;

/**
 * The assignment board («إسناد المركبات»): every driver and every vehicle of the company on one
 * screen, each saying what it holds, so pairing a driver with a vehicle is one look and two
 * clicks. Built because the assign dialog listed all vehicles by plate alone — the ones already
 * with a driver included — and the vehicles list offered no way to assign at all.
 */
class VehicleAssignmentBoardService
{
    /**
     * @return array{drivers: array<int, array<string, mixed>>, vehicles: array<int, array<string, mixed>>, types: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public static function build(int $companyId): array
    {
        $types = VehicleType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get(['id', 'name', 'name_ar']);
        $typeName = fn (?int $id) => $id ? (($t = $types->firstWhere('id', $id)) ? ($t->name_ar ?: $t->name) : null) : null;

        $active = VehicleAssignment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with(['employee:id,name', 'vehicle:id,plate_number,vehicle_type_id'])
            ->orderByDesc('id')
            ->get();
        $activeByVehicle = $active->keyBy('vehicle_id');
        $activeByEmployee = $active->keyBy('employee_id');

        // The last driver a free vehicle had: who to ask when it comes back with a dent.
        $previousByVehicle = VehicleAssignment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', false)
            ->with('employee:id,name')
            ->orderByDesc('unassigned_date')
            ->orderByDesc('id')
            ->get()
            ->unique('vehicle_id')
            ->keyBy('vehicle_id');

        $contractsByEmployee = ContractAssignment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->current()
            ->with('contract:id,name')
            ->get(['id', 'employee_id', 'contract_id'])
            ->groupBy('employee_id');

        $drivers = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('role_category', 'driver')
            ->orderBy('name')
            ->get(['id', 'name', 'name_ar', 'employee_number', 'status', 'phone'])
            // A driver who has left is not a candidate — unless he still holds a vehicle to free.
            ->filter(fn (Employee $e) => $e->status !== 'inactive' || $activeByEmployee->has($e->id))
            ->map(function (Employee $e) use ($activeByEmployee, $contractsByEmployee, $typeName) {
                $held = $activeByEmployee->get($e->id);

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'name_ar' => $e->name_ar,
                    'employee_number' => $e->employee_number,
                    'status' => $e->status,
                    'phone' => $e->phone,
                    'vehicle' => $held && $held->vehicle ? [
                        'id' => $held->vehicle->id,
                        'plate_number' => $held->vehicle->plate_number,
                        'type' => $typeName($held->vehicle->vehicle_type_id),
                        'assigned_date' => $held->assigned_date,
                    ] : null,
                    'contracts' => $contractsByEmployee->get($e->id, collect())
                        ->map(fn ($a) => $a->contract?->name)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $vehicles = Vehicle::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('plate_number')
            ->get(['id', 'plate_number', 'make', 'model', 'year', 'status', 'vehicle_type_id', 'reserved_by', 'reserved_until'])
            ->map(function (Vehicle $v) use ($activeByVehicle, $previousByVehicle, $typeName) {
                $held = $activeByVehicle->get($v->id);
                $previous = $held ? null : $previousByVehicle->get($v->id);

                return [
                    'id' => $v->id,
                    'plate_number' => $v->plate_number,
                    'make' => $v->make,
                    'model' => $v->model,
                    'year' => $v->year,
                    'status' => $v->status,
                    'vehicle_type_id' => $v->vehicle_type_id,
                    'type' => $typeName($v->vehicle_type_id),
                    'reserved_by' => $v->reserved_by,
                    'reserved_until' => $v->reserved_until?->toDateString(),
                    'driver' => $held && $held->employee ? ['id' => $held->employee->id, 'name' => $held->employee->name] : null,
                    'assigned_date' => $held?->assigned_date,
                    'last_driver' => $previous?->employee?->name,
                    'last_unassigned_date' => $previous?->unassigned_date,
                ];
            })
            ->values()
            ->all();

        $freeStatuses = ['available', 'idle'];

        return [
            'drivers' => $drivers,
            'vehicles' => $vehicles,
            'types' => $types->map(fn (VehicleType $t) => ['id' => $t->id, 'name' => $t->name_ar ?: $t->name])->values()->all(),
            'summary' => [
                'drivers_without_vehicle' => count(array_filter($drivers, fn ($d) => $d['vehicle'] === null && in_array($d['status'], ['active', 'probation'], true))),
                'drivers_with_vehicle' => count(array_filter($drivers, fn ($d) => $d['vehicle'] !== null)),
                'free_vehicles' => count(array_filter($vehicles, fn ($v) => $v['driver'] === null && in_array($v['status'], $freeStatuses, true))),
                'held_vehicles' => count(array_filter($vehicles, fn ($v) => $v['driver'] !== null)),
            ],
        ];
    }
}
