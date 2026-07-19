<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MersalCompanySeeder::class,         // ✅ Seeding Mersal Company (Basic clean settings & admin account)
            ComprehensivePayrollSeeder::class,  // ✅ Seeding the 28 Payroll Scenarios data for inspection
        ]);
    }
}
