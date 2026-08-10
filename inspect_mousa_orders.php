<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$logsAug = \App\Models\DailyLog::withoutGlobalScopes()
    ->where('employee_id', 34)
    ->where('contract_id', 6)
    ->whereYear('log_date', 2026)
    ->whereMonth('log_date', 8)
    ->get();

$totalAug = $logsAug->sum('orders_count');

$allLogs = \App\Models\DailyLog::withoutGlobalScopes()
    ->where('employee_id', 34)
    ->where('contract_id', 6)
    ->get();

$totalAll = $allLogs->sum('orders_count');

echo "=== MOUSA HASSANAIN ORDERS IN CONTRACT 6 ===\n";
echo "August 2026 Days Count: " . $logsAug->count() . "\n";
echo "August 2026 Total Orders: " . $totalAug . "\n";
echo "All Time Total Orders in Contract 6: " . $totalAll . "\n";

echo "\n=== AUGUST 2026 DAILY LOGS BREAKDOWN ===\n";
foreach ($logsAug as $l) {
    echo "Date: {$l->log_date} | Orders: {$l->orders_count} | Zone: {$l->zone} | Vehicle ID: {$l->vehicle_id} | Status: {$l->driver_status}\n";
}
