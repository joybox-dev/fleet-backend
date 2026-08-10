<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$c = \App\Models\Contract::withoutGlobalScopes()->find(6);
echo "=== CONTRACT 6 RECORD ===\n";
echo "Name: " . $c->name . "\n";
echo "Client Name: " . $c->client_name . "\n";
echo "Payment Type: " . $c->payment_type . "\n";
echo "Driver Payment Method (Column): " . var_export($c->driver_payment_method, true) . "\n";
echo "Client Payment Method (Column): " . var_export($c->client_payment_method, true) . "\n";

echo "\n=== DRIVER PRICING RULES ===\n";
$driverRules = is_string($c->driver_pricing_rules) ? json_decode($c->driver_pricing_rules, true) : $c->driver_pricing_rules;
echo json_encode($driverRules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== CLIENT PRICING RULES ===\n";
$clientRules = is_string($c->client_pricing_rules) ? json_decode($c->client_pricing_rules, true) : $c->client_pricing_rules;
echo json_encode($clientRules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
