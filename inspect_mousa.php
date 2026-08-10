<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$v = \App\Models\Vehicle::withoutGlobalScopes()->find(32);
echo "=== VEHICLE 32 RECORD ===\n";
echo json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
