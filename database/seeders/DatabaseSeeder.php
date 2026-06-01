<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // MersalCompanySeeder::class,   // ✅ Seeding Mersal Company (Basic clean settings & admin account)
            
            // ── 🚀 GIANT PERFORMANCE & SCENARIO TEST ─────────────────
            // Uncomment the line below to seed 1,000 Employees, 1,000 Vehicles, 10 Contracts & 2,000 logs:
            PerformanceTestSeeder::class,
            
            // MasterScenarioSeeder::class, // Multi-Tenant full production testing data (stopped/commented out)
        ]);

        // ── Old Seeders (commented out) ──────────────────────────────
        // $this->call([
        //     AdminSeeder::class,              // Original admin users
        //     Phase1Seeder::class,             // Users, Clients, Contracts, Employees
        //     Phase2Seeder::class,             // Vehicles, Assignments, Daily Logs
        //     Phase3Seeder::class,             // Violations, Maintenance, Cash Settlements
        //     Phase4Seeder::class,             // Custody Items, Payroll Run + Slips
        //     PayrollVerificationSeeder::class, // Payroll verification data
        //     // CleanDemoSeeder::class,        // Clean demo seeder
        // ]);
    }
}
