<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$e = \App\Models\Employee::withoutGlobalScopes()->find(108);
echo "=== EMP 108 RECORD ===\n";
echo json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$va = \App\Models\VehicleAssignment::withoutGlobalScopes()
    ->where('employee_id', 108)
    ->with('vehicle')
    ->get();
echo "\n=== VEHICLE ASSIGNMENTS ===\n";
echo json_encode($va, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$ca = \App\Models\ContractAssignment::withoutGlobalScopes()
    ->where('employee_id', 108)
    ->with(['contract', 'overrides'])
    ->get();
echo "\n=== CONTRACT ASSIGNMENTS ===\n";
echo json_encode($ca, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
