<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== ALL CONTRACTS WITHOUT GLOBAL SCOPES ===\n";
$contracts = \App\Models\Contract::withoutGlobalScopes()->get();
foreach ($contracts as $c) {
    echo "Contract ID: {$c->id} | Company ID: {$c->company_id} | Number: {$c->contract_number} | Name: {$c->name}\n";
    echo "  Client Payment Method: " . json_encode($c->client_payment_method) . "\n";
    echo "  Driver Pricing Rules: " . json_encode($c->driver_pricing_rules) . "\n";
    echo "  Client Pricing Rules: " . json_encode($c->client_pricing_rules) . "\n";
}

echo "\n=== DRIVERS MATCHING MOUSA WITHOUT GLOBAL SCOPES ===\n";
$employees = \App\Models\Employee::withoutGlobalScopes()->where('name', 'like', '%موسى%')->get();
foreach ($employees as $e) {
    echo "ID: {$e->id} | Company ID: {$e->company_id} | Code: {$e->employee_code} | Name: {$e->name} | VehicleTypeID: {$e->vehicle_type_id}\n";
    $assignments = \App\Models\ContractAssignment::withoutGlobalScopes()->where('employee_id', $e->id)->get();
    foreach ($assignments as $a) {
        echo "  Assignment Contract ID: {$a->contract_id} | Company ID: {$a->company_id}\n";
        $overrides = \App\Models\DriverContractOverride::withoutGlobalScopes()->where('contract_assignment_id', $a->id)->get();
        echo "  Overrides count: " . count($overrides) . "\n";
        foreach ($overrides as $ov) {
            echo "    Type: {$ov->override_type} | Zones: " . json_encode($ov->zones) . " | Tiers: " . json_encode($ov->zones_tiers) . "\n";
        }
    }
}
