<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'     => 'Admin',
                'email'    => 'admin@fleetops.com',
                'password' => Hash::make('password'),
                'role'     => 'admin',
            ],
            [
                'name'     => 'Operator',
                'email'    => 'operator@fleetops.com',
                'password' => Hash::make('password'),
                'role'     => 'operator',
            ],
            [
                'name'     => 'Accountant',
                'email'    => 'accountant@fleetops.com',
                'password' => Hash::make('password'),
                'role'     => 'accountant',
                ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(['email' => $user['email']], $user);
        }

        $this->command->info('✓ Admin users seeded (password: "password")');
    }
}
