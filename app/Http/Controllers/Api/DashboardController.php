<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\Employee;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\CashSettlement;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/expiry-alerts
     * Detailed near-expiry items for dashboard notification center.
     * Returns per-item details with severity levels.
     */
    public function expiryAlerts(): JsonResponse
    {
        $today     = Carbon::today();
        $alertDate = $today->copy()->addDays(60)->toDateString();
        $todayStr  = $today->toDateString();

        // ── Vehicle document alerts ────────────────────────────────────
        $vehicleDocFields = [
            'insurance_expiry'                => 'تأمين السيارة',
            'comprehensive_insurance_expiry'  => 'تأمين شامل',
            'food_authority_license_expiry'   => 'رخصة هيئة الغذاء',
            'next_service_due'                => 'صيانة دورية',
        ];

        $vehicles = Vehicle::select('id', 'plate_number', 'make', 'model',
                'insurance_expiry', 'comprehensive_insurance_expiry',
                'food_authority_license_expiry', 'next_service_due')
            ->where(function ($q) use ($alertDate) {
                $q->where('insurance_expiry', '<=', $alertDate)
                  ->orWhere('comprehensive_insurance_expiry', '<=', $alertDate)
                  ->orWhere('food_authority_license_expiry', '<=', $alertDate)
                  ->orWhere('next_service_due', '<=', $alertDate);
            })
            ->get();

        $vehicleAlerts = [];
        foreach ($vehicles as $v) {
            foreach ($vehicleDocFields as $field => $label) {
                if (!$v->$field) continue;
                $alert = $this->buildAlert($label, $v->$field, $todayStr);
                if ($alert) {
                    $alert['entity_type'] = 'vehicle';
                    $alert['entity_id']   = $v->id;
                    $alert['entity_name'] = $v->plate_number;
                    $alert['entity_label'] = trim("{$v->make} {$v->model}");
                    $vehicleAlerts[] = $alert;
                }
            }
        }

        // ── Employee document alerts ───────────────────────────────────
        $employeeDocFields = [
            'health_card_expiry'      => 'كرت صحي',
            'residence_expiry'        => 'إقامة',
            'driving_license_expiry'  => 'رخصة قيادة',
            'work_permit_expiry'      => 'إذن عمل',
        ];

        $employees = Employee::select('id', 'name', 'name_ar',
                'health_card_expiry', 'residence_expiry',
                'driving_license_expiry', 'work_permit_expiry')
            ->whereIn('status', ['active', 'probation'])
            ->where(function ($q) use ($alertDate) {
                $q->where('health_card_expiry', '<=', $alertDate)
                  ->orWhere('residence_expiry', '<=', $alertDate)
                  ->orWhere('driving_license_expiry', '<=', $alertDate)
                  ->orWhere('work_permit_expiry', '<=', $alertDate);
            })
            ->get();

        $employeeAlerts = [];
        foreach ($employees as $e) {
            foreach ($employeeDocFields as $field => $label) {
                if (!$e->$field) continue;
                $alert = $this->buildAlert($label, $e->$field, $todayStr);
                if ($alert) {
                    $alert['entity_type'] = 'employee';
                    $alert['entity_id']   = $e->id;
                    $alert['entity_name'] = $e->name_ar ?: $e->name;
                    $alert['entity_label'] = $e->name;
                    $employeeAlerts[] = $alert;
                }
            }
        }

        // ── Contract expiry alerts ─────────────────────────────────────
        $contracts = Contract::select('id', 'name', 'contract_number', 'end_date')
            ->where('is_active', true)
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $alertDate)
            ->with('client:id,name')
            ->get();

        $contractAlerts = [];
        foreach ($contracts as $c) {
            $alert = $this->buildAlert('انتهاء العقد', $c->end_date, $todayStr);
            if ($alert) {
                $alert['entity_type'] = 'contract';
                $alert['entity_id']   = $c->id;
                $alert['entity_name'] = $c->name ?: $c->contract_number;
                $alert['entity_label'] = $c->client?->name ?? '—';
                $contractAlerts[] = $alert;
            }
        }

        // Sort each group by severity (expired first, then critical, then warning)
        $severityOrder = ['expired' => 0, 'critical' => 1, 'warning' => 2];
        $sortBySeverity = function ($a, $b) use ($severityOrder) {
            return ($severityOrder[$a['severity']] ?? 3) - ($severityOrder[$b['severity']] ?? 3);
        };

        usort($vehicleAlerts, $sortBySeverity);
        usort($employeeAlerts, $sortBySeverity);
        usort($contractAlerts, $sortBySeverity);

        // Compute summary counts per severity
        $allAlerts = array_merge($vehicleAlerts, $employeeAlerts, $contractAlerts);
        $expiredCount  = count(array_filter($allAlerts, fn($a) => $a['severity'] === 'expired'));
        $criticalCount = count(array_filter($allAlerts, fn($a) => $a['severity'] === 'critical'));
        $warningCount  = count(array_filter($allAlerts, fn($a) => $a['severity'] === 'warning'));

        return response()->json([
            'summary' => [
                'total'    => count($allAlerts),
                'expired'  => $expiredCount,
                'critical' => $criticalCount,
                'warning'  => $warningCount,
            ],
            'vehicles'  => $vehicleAlerts,
            'employees' => $employeeAlerts,
            'contracts' => $contractAlerts,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build a single alert array from a document expiry date.
     * Returns null if the date is beyond the 60-day window.
     */
    private function buildAlert(string $docLabel, string $expiryDate, string $today): ?array
    {
        $expiry = Carbon::parse($expiryDate);
        $now    = Carbon::parse($today);
        $days   = $now->diffInDays($expiry, false); // negative = expired

        if ($days > 60) return null; // outside alert window

        $severity = 'warning';
        if ($days < 0)       $severity = 'expired';
        elseif ($days <= 14) $severity = 'critical';

        return [
            'doc_label'      => $docLabel,
            'expiry_date'    => $expiryDate,
            'days_remaining' => $days,
            'severity'       => $severity,
        ];
    }

    /**
     * GET /api/dashboard/summary
     * Main screen: fleet status, pending cash, today's orders.
     */
    public function summary(): JsonResponse
    {
        // Fleet status breakdown — from meeting: available/working/maintenance/idle
        $fleetStatus = Vehicle::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $fleetStatus = array_merge([
            'available'   => 0,
            'working'     => 0,
            'maintenance' => 0,
            'idle'        => 0,
        ], $fleetStatus);

        // Employee status breakdown
        $employeeStatus = Employee::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Pending cash — from meeting: "الفلوس المعلقة خطيرة لأنها أمانة وليست ملك الشركة"
        $pendingCash = DailyLog::query()
            ->where('cash_pending', '>', 0)
            ->selectRaw('employee_id, SUM(cash_pending) as total_pending')
            ->with('employee:id,name')
            ->groupBy('employee_id')
            ->get()
            ->map(fn($row) => [
                'employee_id'   => $row->employee_id,
                'employee_name' => $row->employee?->name,
                'total_pending' => (float) $row->total_pending,
            ]);

        $totalPendingCash = $pendingCash->sum('total_pending');

        // Today's activity
        $today = now()->toDateString();
        $todayStats = DailyLog::whereDate('log_date', $today)
            ->selectRaw('COUNT(*) as logs, SUM(orders_count) as total_orders, SUM(income_amount) as total_income')
            ->first();

        // Expiring documents alert counts (within 60 days)
        $alertDate = now()->addDays(60)->toDateString();
        $vehicleAlertsCount = Vehicle::where(function ($q) use ($alertDate) {
            $q->where('insurance_expiry', '<=', $alertDate)
              ->orWhere('comprehensive_insurance_expiry', '<=', $alertDate)
              ->orWhere('food_authority_license_expiry', '<=', $alertDate);
        })->count();

        $employeeAlertsCount = Employee::where('status', 'active')
            ->where(function ($q) use ($alertDate) {
                $q->where('health_card_expiry', '<=', $alertDate)
                  ->orWhere('residence_expiry', '<=', $alertDate)
                  ->orWhere('driving_license_expiry', '<=', $alertDate)
                  ->orWhere('work_permit_expiry', '<=', $alertDate);
            })->count();

        return response()->json([
            'fleet_status'         => $fleetStatus,
            'fleet_total'          => array_sum($fleetStatus),
            'employee_status'      => $employeeStatus,
            'pending_cash' => [
                'total'   => $totalPendingCash,
                'drivers' => $pendingCash,
            ],
            'today' => [
                'date'         => $today,
                'logs_entered' => (int) ($todayStats->logs ?? 0),
                'total_orders' => (int) ($todayStats->total_orders ?? 0),
                'total_income' => (float) ($todayStats->total_income ?? 0),
            ],
            'alerts' => [
                'vehicle_docs'  => $vehicleAlertsCount,
                'employee_docs' => $employeeAlertsCount,
            ],
        ]);
    }
}
