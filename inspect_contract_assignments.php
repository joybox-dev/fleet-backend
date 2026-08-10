<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ca = \App\Models\ContractAssignment::withoutGlobalScopes()
    ->whereHas('employee', function($q) {
        $q->where('name', 'like', '%محمد رمضان%');
    })
    ->with(['employee', 'contract'])
    ->get();

echo "=== CONTRACT ASSIGNMENTS FOR MOHAMED RAMADAN ===\n";
echo json_encode($ca, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
