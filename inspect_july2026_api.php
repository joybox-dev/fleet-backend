<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$req = Illuminate\Http\Request::create('/api/contracts/6/dashboard?year=2026&month=7', 'GET');
$contract = \App\Models\Contract::withoutGlobalScopes()->find(6);
$controller = new \App\Http\Controllers\Api\ContractDashboardController();
$res = $controller->show($req, $contract);
$content = json_decode($res->getContent(), true);

echo "=== CONTRACT 6 DASHBOARD JULY 2026 ===\n";
echo "Contract Name: " . ($content['contract']['name'] ?? 'N/A') . "\n";
echo "Driver Pricing Rules: " . json_encode($content['contract']['driver_pricing_rules'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "Assignments count: " . count($content['assignments'] ?? []) . "\n";
foreach ($content['assignments'] ?? [] as $a) {
    if (isset($a['employee']['name']) && str_contains($a['employee']['name'], 'موسى')) {
        echo "MOUSA ASSIGNMENT IN JULY 2026:\n";
        echo json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
