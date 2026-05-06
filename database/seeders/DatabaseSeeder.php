<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            AdminSeeder::class,  // Original admin users
            Phase1Seeder::class, // Users, Clients, Contracts, Employees
            Phase2Seeder::class, // Vehicles, Assignments, Daily Logs
            Phase3Seeder::class, // Violations, Maintenance, Cash Settlements
            Phase4Seeder::class, // Custody Items, Payroll Run + Slips
        ]);

        $this->command->info('');
        $this->command->info('🚀 FleetOps full scenario seeded successfully!');
        $this->command->info('   Login: admin@fleetops.com / password');
    }
}
