<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$emps = \App\Models\Employee::withoutGlobalScopes()
    ->where('name', 'like', '%رمضان%')
    ->orWhere('name_ar', 'like', '%رمضان%')
    ->get();

echo "=== EMPLOYEES WITH RAMADAN ===\n";
echo json_encode($emps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
