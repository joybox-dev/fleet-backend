<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$u1 = \App\Models\User::withoutGlobalScopes()->where('email', 'ahmad2@mirsal.co')->first();
$u2 = \App\Models\User::withoutGlobalScopes()->where('email', 'mersal@fleetops.kw')->first();

echo "=== USER 1: ahmad2@mirsal.co ===\n";
echo json_encode($u1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
if ($u1) {
    $e1 = \App\Models\Employee::withoutGlobalScopes()->where('user_id', $u1->id)->first();
    echo "Employee 1: " . json_encode($e1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== USER 2: mersal@fleetops.kw ===\n";
echo json_encode($u2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
if ($u2) {
    $e2 = \App\Models\Employee::withoutGlobalScopes()->where('user_id', $u2->id)->first();
    echo "Employee 2: " . json_encode($e2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
