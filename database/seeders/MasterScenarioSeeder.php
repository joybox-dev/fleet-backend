<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

/**
 * Master Scenario Seeder — Orchestrator
 * 
 * Calls sub-seeders in order:
 *   Phase 1: Companies, Users
 *   Phase 2: Clients, Contracts, Employees, Vehicles, Assignments
 *   Phase 3: April data (daily logs, violations, maintenance, advances)
 *   Phase 4: May data (daily logs, violations, maintenance, custody, leaves, guarantees, evaluations, expenses, cash)
 *
 * Login: mersal@fleetops.kw / abuhadram
 */
class MasterScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════╗');
        $this->command->info('║  FleetOps — Master Scenario Seeder      ║');
        $this->command->info('║  2 Companies · April + May 2026         ║');
        $this->command->info('╚══════════════════════════════════════════╝');
        $this->command->info('');

        $this->call(MasterPhase1Seeder::class);
        $this->call(MasterPhase2Seeder::class);
        $this->call(MasterPhase3Seeder::class);
        $this->call(MasterPhase4ASeeder::class);
        $this->call(MasterPhase4BSeeder::class);

        $this->command->info('');
        $this->command->info('🚀 Master Scenario seeded successfully!');
        $this->command->info('   Login: mersal@fleetops.kw / abuhadram');
    }
}
