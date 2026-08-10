<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$e = \App\Models\Employee::withoutGlobalScopes()->where('name', 'like', '%نور الدين%')->first();
echo "=== MOHAMED RAMADAN NOUR ELDIN ===\n";
echo json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if ($e) {
    $assignments = \App\Models\VehicleAssignment::withoutGlobalScopes()
        ->where('employee_id', $e->id)
        ->with('vehicle')
        ->get();
    echo "\n=== VEHICLE ASSIGNMENTS ===\n";
    echo json_encode($assignments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    $contractAssignments = \App\Models\ContractAssignment::withoutGlobalScopes()
        ->where('employee_id', $e->id)
        ->with('overrides')
        ->get();
    echo "\n=== CONTRACT ASSIGNMENTS & OVERRIDES ===\n";
    echo json_encode($contractAssignments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
