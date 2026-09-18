<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\VehicleAssignment;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class KetaImportService
{
    /**
     * Preview an uploaded Keeta Excel file.
     */
    public function previewFile(string $filePath): array
    {
        $companyId = app('current_company_id');
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, true);

        if (empty($allRows)) {
            return [
                'success' => false,
                'message' => 'الملف فارغ.',
                'rows' => [],
                'summary' => ['total' => 0, 'matched' => 0, 'unmatched' => 0],
            ];
        }

        // Header detection
        $headerRowIdx = 1;
        $headers = [];
        for ($r = 1; $r <= min(10, count($allRows)); $r++) {
            $row = $allRows[$r];
            foreach ($row as $val) {
                if ($val !== null && preg_match('/courier\s*id/i', trim((string) $val))) {
                    $headerRowIdx = $r;
                    $headers = array_map(fn ($v) => trim((string) $v), $row);
                    break 2;
                }
            }
        }

        if (empty($headers)) {
            // fallback to first row
            $headers = array_map(fn ($v) => trim((string) $v), $allRows[1] ?? []);
            $headerRowIdx = 1;
        }

        // Clean headers and find key columns
        $colMap = [];
        foreach ($headers as $col => $label) {
            $cleanLabel = trim($label);
            if (empty($cleanLabel)) {
                continue;
            }

            if (preg_match('/^Date$/i', $cleanLabel) || preg_match('/^\s*Date$/i', $cleanLabel)) {
                $colMap['date'] = $col;
            } elseif (preg_match('/Courier\s*ID/i', $cleanLabel)) {
                $colMap['courier_id'] = $col;
            } elseif (preg_match('/Courier\s*First\s*Name/i', $cleanLabel)) {
                $colMap['first_name'] = $col;
            } elseif (preg_match('/Courier\s*Last\s*Name/i', $cleanLabel)) {
                $colMap['last_name'] = $col;
            } elseif (preg_match('/Shift_Valid\s*Day\?/i', $cleanLabel)) {
                $colMap['valid_day'] = $col;
            } elseif (preg_match('/Shift_Courier\s*App\s*Online\s*Time/i', $cleanLabel)) {
                $colMap['online_time'] = $col;
            } elseif (preg_match('/Task\s*Volumes_Delivered\s*Tasks/i', $cleanLabel)) {
                $colMap['delivered_tasks'] = $col;
            } elseif (preg_match('/Delivery\s*Experience_On-time\s*Rate\s*\(D\)/i', $cleanLabel)) {
                $colMap['ontime_rate'] = $col;
            } elseif (preg_match('/Delivery\s*Experience_Avg\s*Delivery\s*Time/i', $cleanLabel)) {
                $colMap['avg_delivery_time'] = $col;
            }
        }

        // Fallback to literal columns A-AB if headers not matched
        $colMap['date'] = $colMap['date'] ?? 'A';
        $colMap['courier_id'] = $colMap['courier_id'] ?? 'B';
        $colMap['first_name'] = $colMap['first_name'] ?? 'C';
        $colMap['last_name'] = $colMap['last_name'] ?? 'D';
        $colMap['valid_day'] = $colMap['valid_day'] ?? 'I';
        $colMap['online_time'] = $colMap['online_time'] ?? 'J';
        $colMap['delivered_tasks'] = $colMap['delivered_tasks'] ?? 'O';
        $colMap['ontime_rate'] = $colMap['ontime_rate'] ?? 'W';
        $colMap['avg_delivery_time'] = $colMap['avg_delivery_time'] ?? 'Y';

        $parsedRows = [];
        $matchedCount = 0;
        $unmatchedCount = 0;

        for ($r = $headerRowIdx + 1; $r <= count($allRows); $r++) {
            $row = $allRows[$r];

            // Skip empty rows
            if (empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $dateRaw = $row[$colMap['date']] ?? null;
            $courierIdRaw = $row[$colMap['courier_id']] ?? null;

            if (! $dateRaw || ! $courierIdRaw) {
                continue;
            }

            $date = $this->normalizeKeetaDate($dateRaw);
            $courierId = trim((string) $courierIdRaw);

            if (! $date || empty($courierId)) {
                continue;
            }

            $firstName = trim((string) ($row[$colMap['first_name']] ?? ''));
            $lastName = trim((string) ($row[$colMap['last_name']] ?? ''));
            $courierName = trim($firstName.' '.$lastName);

            $validDayStr = trim((string) ($row[$colMap['valid_day']] ?? ''));
            $shiftValid = (strcasecmp($validDayStr, 'Yes') === 0);

            $onlineTimeStr = trim((string) ($row[$colMap['online_time']] ?? ''));
            $onlineHours = $this->parseOnlineHours($onlineTimeStr);

            $deliveredTasks = (int) ($row[$colMap['delivered_tasks']] ?? 0);

            $ontimeRateStr = trim((string) ($row[$colMap['ontime_rate']] ?? ''));
            $ontimeRate = $this->parseOntimeRate($ontimeRateStr);

            $avgDeliveryTimeRaw = $row[$colMap['avg_delivery_time']] ?? null;
            $avgDeliveryTime = $avgDeliveryTimeRaw !== null && $avgDeliveryTimeRaw !== '-' ? round(floatval($avgDeliveryTimeRaw)) : null;

            // Search for active ContractAssignment matching this courier_id
            $assignment = ContractAssignment::where('company_id', $companyId)
                ->where('courier_id', $courierId)
                ->where('start_date', '<=', $date)
                ->where(function ($query) use ($date) {
                    $query->whereNull('end_date')
                        ->orWhere('end_date', '>=', $date);
                })
                ->with(['employee', 'contract'])
                ->first();

            $employeeId = null;
            $employeeName = null;
            $contractId = null;
            $contractName = null;
            $isMatched = false;

            if ($assignment) {
                $employeeId = $assignment->employee_id;
                $employeeName = $assignment->employee->name;
                $contractId = $assignment->contract_id;
                $contractName = $assignment->contract->name;
                $isMatched = true;
                $matchedCount++;
            } else {
                $unmatchedCount++;
            }

            $parsedRows[] = [
                'row_number' => $r,
                'date' => $date,
                'courier_id' => $courierId,
                'courier_name' => $courierName,
                'shift_valid' => $shiftValid,
                'online_hours' => $onlineHours,
                'orders_count' => $deliveredTasks,
                'ontime_rate' => $ontimeRate,
                'avg_delivery_time' => $avgDeliveryTime,
                'employee_id' => $employeeId,
                'employee_name' => $employeeName,
                'contract_id' => $contractId,
                'contract_name' => $contractName,
                'is_matched' => $isMatched,
            ];
        }

        return [
            'success' => true,
            'rows' => $parsedRows,
            'summary' => [
                'total' => count($parsedRows),
                'matched' => $matchedCount,
                'unmatched' => $unmatchedCount,
            ],
        ];
    }

    /**
     * Commit the parsed rows to the database.
     *
     * Every day goes through DailyLogWriter, the writer the screens use, and answers to the same
     * rules. It used to be written here with `updateOrCreate` on the driver and the date alone:
     * a day he had worked on ANOTHER contract was found, moved to this one and overwritten; the
     * cash of a day already collected and handed over was reset to zero; and a month whose payroll
     * was approved was written into like any other. The platform's file knows orders, hours and
     * punctuality — nothing else on the day is its to touch.
     */
    public function commitImport(array $rows): array
    {
        $companyId = (int) app('current_company_id');
        $userId = (int) auth()->id();
        $importedCount = 0;
        $failedCount = 0;
        $errors = [];

        $contracts = Contract::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', array_unique(array_filter(array_column($rows, 'contract_id'))))
            ->get()
            ->keyBy('id');

        try {
            foreach (array_chunk($rows, 50) as $chunk) {
                DB::transaction(function () use ($chunk, $companyId, $userId, $contracts, &$importedCount, &$failedCount, &$errors) {
                    foreach ($chunk as $index => $row) {
                        $line = 'السطر '.($row['row_number'] ?? ($index + 1));

                        // If not matched or invalid, skip
                        if (empty($row['employee_id']) || empty($row['contract_id'])) {
                            $failedCount++;
                            $errors[] = "{$line}: لم يتم ربطه بموظف أو عقد تشغيل.";

                            continue;
                        }

                        $employeeId = (int) $row['employee_id'];
                        $contractId = (int) $row['contract_id'];
                        $date = (string) $row['date'];
                        $contract = $contracts->get($contractId);

                        // The rows come back from the browser: who is on which contract is asked of
                        // the database again, not taken from what the preview was told.
                        $assigned = $contract && ContractAssignment::withoutGlobalScopes()
                            ->where('company_id', $companyId)
                            ->where('employee_id', $employeeId)
                            ->where('contract_id', $contractId)
                            ->whereDate('start_date', '<=', $date)
                            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
                            ->exists();
                        if (! $assigned) {
                            $failedCount++;
                            $errors[] = "{$line}: السائق غير معيّن على هذا العقد في تاريخ {$date}.";

                            continue;
                        }

                        if ($locked = DailyLogWriter::lockedMonth($companyId, $contractId, $date)) {
                            $failedCount++;
                            $errors[] = "{$line} ({$date}): {$locked}";

                            continue;
                        }

                        // His day on THIS contract, if it is already on file — a day worked on
                        // another contract the same date is another row and is left alone.
                        $existing = DailyLog::withoutGlobalScopes()
                            ->where('company_id', $companyId)
                            ->where('employee_id', $employeeId)
                            ->where('contract_id', $contractId)
                            ->whereDate('log_date', $date)
                            ->first();

                        // Retrieve active vehicle assignment for this employee on that date
                        $vehicleAssignment = VehicleAssignment::where('employee_id', $employeeId)
                            ->where('assigned_date', '<=', $date)
                            ->where(function ($query) use ($date) {
                                $query->whereNull('unassigned_date')
                                    ->orWhere('unassigned_date', '>=', $date);
                            })
                            ->with('vehicle:id,vehicle_type_id')
                            ->first();

                        $vehicleId = $existing?->vehicle_id ?? $vehicleAssignment?->vehicle_id;
                        $vehicleTypeId = $existing?->vehicle?->vehicle_type_id ?? $vehicleAssignment?->vehicle?->vehicle_type_id;

                        // A day needs the vehicle it was worked on. Written without one, the row
                        // died on the database's NOT NULL and took the rest of its batch with it.
                        if (! $vehicleId) {
                            $failedCount++;
                            $errors[] = "{$line} ({$date}): لا مركبة معيّنة للسائق في هذا التاريخ — عيّن له مركبة ثم أعد الاستيراد.";

                            continue;
                        }

                        $orders = (int) ($row['orders_count'] ?? 0);
                        $ordersCash = (int) ($existing->orders_cash ?? 0);
                        $cashCollected = (float) ($existing->cash_collected ?? 0);

                        $unzoned = DailyLogWriter::unzonedOrdersBlock($contract, $vehicleTypeId, [
                            'orders_count' => $orders,
                            'zone' => $existing?->zone,
                            'notes' => $existing?->notes,
                        ]);
                        if ($unzoned) {
                            $failedCount++;
                            $errors[] = "{$line} ({$date}): {$unzoned}";

                            continue;
                        }

                        $written = DailyLogWriter::write($companyId, $userId, [
                            'employee_id' => $employeeId,
                            'contract_id' => $contractId,
                            'vehicle_id' => $vehicleId,
                            'log_date' => $date,
                            'orders_count' => $orders,
                            'orders_online' => max(0, $orders - $ordersCash),
                            'orders_cash' => $ordersCash,
                            // Carried over untouched, so the writer has nothing to refuse and the
                            // day's pending cash comes out exactly as it stood.
                            'cash_collected' => $cashCollected,
                            'shift_valid' => $row['shift_valid'] ?? true,
                            'online_hours' => $row['online_hours'] ?? 0.00,
                            'ontime_rate' => $row['ontime_rate'] ?? null,
                            'avg_delivery_time' => $row['avg_delivery_time'] ?? null,
                            // A day with deliveries on it is a working day, whatever it was set to
                            // before the file arrived; a day without keeps the status it has.
                            ...($orders > 0 ? ['driver_status' => 'working'] : []),
                        ]);

                        if (is_string($written)) {
                            $failedCount++;
                            $errors[] = "{$line} ({$date}): {$written}";

                            continue;
                        }

                        $importedCount++;
                    }
                });
            }

            return [
                'success' => true,
                'imported' => $importedCount,
                'failed' => $failedCount,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء حفظ السجلات: '.$e->getMessage(),
                'imported' => $importedCount,
                'failed' => count($rows) - $importedCount,
                'errors' => array_merge($errors, [$e->getMessage()]),
            ];
        }
    }

    /**
     * Parse online time (e.g. "10 hr, 30 min" or "0 sec") to decimal hours.
     */
    private function parseOnlineHours(string $timeStr): float
    {
        $timeStr = trim($timeStr);
        if (empty($timeStr) || stripos($timeStr, '0 sec') !== false || $timeStr === '-') {
            return 0.0;
        }

        $hours = 0.0;

        if (preg_match('/(\d+)\s*hr/i', $timeStr, $matches)) {
            $hours += (float) $matches[1];
        }

        if (preg_match('/(\d+)\s*min/i', $timeStr, $matches)) {
            $hours += (float) $matches[1] / 60.0;
        }

        return round($hours, 2);
    }

    /**
     * Parse ontime rate to percentage float.
     */
    private function parseOntimeRate(string $ontimeStr): ?float
    {
        $ontimeStr = trim($ontimeStr);
        if ($ontimeStr === '' || $ontimeStr === '-') {
            return null;
        }

        $clean = str_replace('%', '', $ontimeStr);
        $val = floatval($clean);

        if ($val > 0 && $val <= 1.0 && strpos($ontimeStr, '%') === false) {
            $val = $val * 100;
        }

        return round($val, 2);
    }

    /**
     * Normalize date formats.
     */
    private function normalizeKeetaDate($val): ?string
    {
        $val = trim((string) $val);
        if (empty($val) || $val === '-') {
            return null;
        }

        if (preg_match('/^\d{8}$/', $val)) {
            return substr($val, 0, 4).'-'.substr($val, 4, 2).'-'.substr($val, 6, 2);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val;
        }

        if (is_numeric($val) && (int) $val > 10000 && (int) $val < 100000) {
            try {
                $dt = Date::excelToDateTimeObject((int) $val);

                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }

        try {
            $timestamp = strtotime(str_replace('/', '-', $val));
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        } catch (\Throwable $e) {
        }

        return null;
    }
}
