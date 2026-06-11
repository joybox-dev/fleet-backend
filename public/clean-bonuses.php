<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$deleted = DB::table('contract_bonuses')->delete();
$unlocked = DB::table('contracts')->update(['is_locked' => false]);

echo "Database cleaned: $deleted bonuses deleted, $unlocked contracts updated.\n";
