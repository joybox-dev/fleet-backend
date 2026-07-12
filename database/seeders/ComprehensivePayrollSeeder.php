<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Company;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\ContractAssignment;
use App\Models\DriverContractOverride;
use App\Models\DailyLog;

class ComprehensivePayrollSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'mersal')->first();
        if (!$company) {
            $company = Company::create([
                'name' => 'Mersal Company',
                'name_ar' => 'شركة مرسال للتوصيل',
                'code' => 'mersal',
                'is_active' => true,
                'currency' => 'KWD',
                'enabled_modules' => Company::DEFAULT_MODULES,
            ]);
        }

        app()->instance('current_company_id', $company->id);

        $admin = DB::table('users')->where('email', 'mersal@fleetops.kw')->first();
        $adminId = $admin ? $admin->id : 1;

        $client = Client::firstOrCreate(['name' => 'E2E Payroll Client'], ['company_id' => $company->id]);

        // Create 28 drivers, contracts, assignments, and logs
        $scenarios = [
            // ==========================================
            // GROUP A: CLIENT PAYMENT METHOD = FIXED (300.000 KWD)
            // ==========================================
            'A1' => [
                'client_payment_method' => 'fixed',
                'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => null,
                'orders' => array_fill(1, 30, 10), // 300 total
                'client_rate' => 0.0,
                'extra' => [
                    'fixed_monthly' => 300,
                    'actual_salary' => 150,
                    'default_monthly_target' => 250,
                    'premium_commission_rate' => 1.000,
                ]
            ],
            'A2' => [
                'client_payment_method' => 'fixed',
                'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
                'driver_payment_method' => 'tiers',
                'driver_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 0.500],
                        ['min' => 201, 'max' => 9999, 'price' => 0.750],
                    ]
                ]],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, 8), [26 => 10, 27 => 10, 28 => 10, 29 => 10, 30 => 10]), // 250 total
                'client_rate' => 0.0,
                'extra' => ['fixed_monthly' => 300]
            ],
            'A3' => [
                'client_payment_method' => 'fixed',
                'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
                'driver_payment_method' => 'hybrid',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'hybrid']],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, 7), [21 => 8, 22 => 8, 23 => 8, 24 => 8, 25 => 8, 26 => 8, 27 => 8, 28 => 8, 29 => 8, 30 => 8]), // 220 total
                'client_rate' => 0.0,
                'extra' => [
                    'fixed_monthly' => 300,
                    'actual_salary' => 100,
                    'default_order_commission' => 0.200
                ]
            ],
            'A4' => [
                'client_payment_method' => 'fixed',
                'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'fixed',
                    'custom_fixed_salary' => 160,
                    'custom_monthly_target' => 240,
                    'custom_order_commission' => 0.400,
                    'custom_monthly_bonus' => 40.000,
                ],
                'orders' => array_replace(array_fill(1, 30, 7), [21 => 9, 22 => 9, 23 => 9, 24 => 9, 25 => 9, 26 => 9, 27 => 9, 28 => 9, 29 => 9, 30 => 9]), // 230 total
                'client_rate' => 0.0,
                'extra' => ['fixed_monthly' => 300]
            ],
            'A5' => [
                'client_payment_method' => 'fixed',
                'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'tiers',
                    'custom_pricing_rules' => [
                        ['min' => 1, 'max' => 150, 'price' => 0.600],
                        ['min' => 151, 'max' => 9999, 'price' => 0.800],
                    ]
                ],
                'orders' => array_replace(array_fill(1, 30, 6), [30 => 26]), // 200 total
                'client_rate' => 0.0,
                'extra' => ['fixed_monthly' => 300]
            ],
            'A6' => [
                'client_payment_method' => 'fixed',
                'client_pricing_rules' => ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'hybrid',
                    'custom_fixed_salary' => 110,
                    'custom_order_commission' => 0.250,
                ],
                'orders' => array_fill(1, 30, 6), // 180 total
                'client_rate' => 0.0,
                'extra' => ['fixed_monthly' => 300]
            ],

            // ==========================================
            // GROUP B: CLIENT PAYMENT METHOD = TIERS
            // ==========================================
            'B1' => [
                'client_payment_method' => 'tiers',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 1.000],
                        ['min' => 201, 'max' => 9999, 'price' => 1.500],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => null,
                'orders' => array_fill(1, 30, 8), // 240 total
                'client_rate' => 1.500,
                'extra' => [
                    'actual_salary' => 140,
                    'default_monthly_target' => 220,
                    'premium_commission_rate' => 1.500,
                ]
            ],
            'B2' => [
                'client_payment_method' => 'tiers',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 1.000],
                        ['min' => 201, 'max' => 9999, 'price' => 1.500],
                    ]
                ]],
                'driver_payment_method' => 'tiers',
                'driver_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 0.400],
                        ['min' => 201, 'max' => 9999, 'price' => 0.600],
                    ]
                ]],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, 8), [26 => 10, 27 => 10, 28 => 10, 29 => 10, 30 => 10]), // 250 total
                'client_rate' => 1.500,
                'extra' => []
            ],
            'B3' => [
                'client_payment_method' => 'tiers',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 1.000],
                        ['min' => 201, 'max' => 9999, 'price' => 1.500],
                    ]
                ]],
                'driver_payment_method' => 'hybrid',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'hybrid']],
                'override' => null,
                'orders' => array_fill(1, 30, 7), // 210 total
                'client_rate' => 1.500,
                'extra' => [
                    'actual_salary' => 90,
                    'default_order_commission' => 0.300,
                ]
            ],
            'B4' => [
                'client_payment_method' => 'tiers',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 1.000],
                        ['min' => 201, 'max' => 9999, 'price' => 1.500],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'fixed',
                    'custom_fixed_salary' => 150,
                    'custom_monthly_target' => 230,
                    'custom_order_commission' => 0.500,
                ],
                'orders' => array_fill(1, 30, 7), // 210 total
                'client_rate' => 1.500,
                'extra' => []
            ],
            'B5' => [
                'client_payment_method' => 'tiers',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 1.000],
                        ['min' => 201, 'max' => 9999, 'price' => 1.500],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'tiers',
                    'custom_pricing_rules' => [
                        ['min' => 1, 'max' => 180, 'price' => 0.500],
                        ['min' => 181, 'max' => 9999, 'price' => 0.700],
                    ]
                ],
                'orders' => array_replace(array_fill(1, 30, 7), [21 => 8, 22 => 8, 23 => 8, 24 => 8, 25 => 8, 26 => 8, 27 => 8, 28 => 8, 29 => 8, 30 => 8]), // 220 total
                'client_rate' => 1.500,
                'extra' => []
            ],
            'B6' => [
                'client_payment_method' => 'tiers',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 1.000],
                        ['min' => 201, 'max' => 9999, 'price' => 1.500],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'hybrid',
                    'custom_fixed_salary' => 100,
                    'custom_order_commission' => 0.280,
                ],
                'orders' => array_replace(array_fill(1, 30, 6), [21 => 7, 22 => 7, 23 => 7, 24 => 7, 25 => 7, 26 => 7, 27 => 7, 28 => 7, 29 => 7, 30 => 7]), // 190 total
                'client_rate' => 1.000,
                'extra' => []
            ],

            // ==========================================
            // GROUP C: CLIENT PAYMENT METHOD = HYBRID
            // ==========================================
            'C1' => [
                'client_payment_method' => 'hybrid',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'hybrid',
                    'fixed_amount' => 200,
                    'order_commission' => 0.500
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, 7), [21 => 8, 22 => 8, 23 => 8, 24 => 8, 25 => 8, 26 => 8, 27 => 8, 28 => 8, 29 => 8, 30 => 8]), // 220 total
                'client_rate' => 0.500,
                'extra' => [
                    'actual_salary' => 130,
                    'default_monthly_target' => 200,
                    'premium_commission_rate' => 1.250,
                ]
            ],
            'C2' => [
                'client_payment_method' => 'hybrid',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'hybrid',
                    'fixed_amount' => 200,
                    'order_commission' => 0.500
                ]],
                'driver_payment_method' => 'tiers',
                'driver_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 180, 'price' => 0.450],
                        ['min' => 181, 'max' => 9999, 'price' => 0.550],
                    ]
                ]],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, 6), [21 => 8, 22 => 8, 23 => 8, 24 => 8, 25 => 8, 26 => 8, 27 => 8, 28 => 8, 29 => 8, 30 => 8]), // 200 total
                'client_rate' => 0.500,
                'extra' => []
            ],
            'C3' => [
                'client_payment_method' => 'hybrid',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'hybrid',
                    'fixed_amount' => 200,
                    'order_commission' => 0.500
                ]],
                'driver_payment_method' => 'hybrid',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'hybrid']],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, 5), [21 => 7, 22 => 7, 23 => 7, 24 => 7, 25 => 7, 26 => 7, 27 => 7, 28 => 7, 29 => 7, 30 => 7]), // 170 total
                'client_rate' => 0.500,
                'extra' => [
                    'actual_salary' => 80,
                    'default_order_commission' => 0.350,
                ]
            ],
            'C4' => [
                'client_payment_method' => 'hybrid',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'hybrid',
                    'fixed_amount' => 200,
                    'order_commission' => 0.500
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'fixed',
                    'custom_fixed_salary' => 140,
                    'custom_monthly_target' => 210,
                    'custom_order_commission' => 0.450,
                ],
                'orders' => array_replace(array_fill(1, 30, 6), [21 => 7, 22 => 7, 23 => 7, 24 => 7, 25 => 7, 26 => 7, 27 => 7, 28 => 7, 29 => 7, 30 => 7]), // 190 total
                'client_rate' => 0.500,
                'extra' => []
            ],
            'C5' => [
                'client_payment_method' => 'hybrid',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'hybrid',
                    'fixed_amount' => 200,
                    'order_commission' => 0.500
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'tiers',
                    'custom_pricing_rules' => [
                        ['min' => 1, 'max' => 170, 'price' => 0.480],
                        ['min' => 171, 'max' => 9999, 'price' => 0.600],
                    ]
                ],
                'orders' => array_fill(1, 30, 6), // 180 total
                'client_rate' => 0.500,
                'extra' => []
            ],
            'C6' => [
                'client_payment_method' => 'hybrid',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'hybrid',
                    'fixed_amount' => 200,
                    'order_commission' => 0.500
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'hybrid',
                    'custom_fixed_salary' => 95,
                    'custom_order_commission' => 0.320,
                ],
                'orders' => array_replace(array_fill(1, 30, 5), [21 => 6, 22 => 6, 23 => 6, 24 => 6, 25 => 6, 26 => 6, 27 => 6, 28 => 6, 29 => 6, 30 => 6]), // 160 total
                'client_rate' => 0.500,
                'extra' => []
            ],

            // ==========================================
            // GROUP D: CLIENT PAYMENT METHOD = ZONES
            // ==========================================
            'D1' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 15, ['Zone A' => 10]), array_fill(16, 10, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => [
                    'actual_salary' => 150,
                    'default_monthly_target' => 240,
                    'premium_commission_rate' => 4.500,
                ]
            ],
            'D2' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'zones',
                'driver_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        'Zone A' => 0.500,
                        'Zone B' => 0.700,
                    ]
                ]],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 16, ['Zone A' => 10]), array_fill(17, 9, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => []
            ],
            'D3' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'tiers',
                'driver_pricing_rules' => ['1' => [
                    'payment_method' => 'tiers',
                    'tiers' => [
                        ['min' => 1, 'max' => 200, 'price' => 0.450],
                        ['min' => 201, 'max' => 9999, 'price' => 0.650],
                    ]
                ]],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 18, ['Zone A' => 10]), array_fill(19, 7, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => []
            ],
            'D4' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'hybrid',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'hybrid']],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 14, ['Zone A' => 10]), array_fill(15, 8, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => [
                    'actual_salary' => 100,
                    'default_order_commission' => 0.300,
                ]
            ],
            'D5' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'zones_tiers',
                'driver_pricing_rules' => ['1' => [
                    'payment_method' => 'zones_tiers',
                    'zones_tiers' => [
                        [
                            'zone' => 'Zone A',
                            'tiers' => [
                                ['min' => 1, 'max' => 100, 'price' => 0.400],
                                ['min' => 101, 'max' => 9999, 'price' => 0.600],
                            ]
                        ],
                        [
                            'zone' => 'Zone B',
                            'tiers' => [
                                ['min' => 1, 'max' => 80, 'price' => 0.500],
                                ['min' => 81, 'max' => 9999, 'price' => 0.800],
                            ]
                        ]
                    ]
                ]],
                'override' => null,
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 12, ['Zone A' => 10]), array_fill(13, 9, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => []
            ],
            'D6' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'fixed',
                    'custom_fixed_salary' => 160,
                    'custom_monthly_target' => 250,
                    'custom_order_commission' => 0.400,
                ],
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 13, ['Zone A' => 10]), array_fill(14, 10, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => []
            ],
            'D7' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'zones',
                    'custom_pricing_rules' => [
                        'Zone A' => 0.550,
                        'Zone B' => 0.750,
                    ]
                ],
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 14, ['Zone A' => 10]), array_fill(15, 11, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => []
            ],
            'D8' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'tiers',
                    'custom_pricing_rules' => [
                        ['min' => 1, 'max' => 210, 'price' => 0.500],
                        ['min' => 211, 'max' => 9999, 'price' => 0.700],
                    ]
                ],
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 15, ['Zone A' => 10]), array_fill(16, 8, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => []
            ],
            'D9' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'hybrid',
                    'custom_fixed_salary' => 110,
                    'custom_order_commission' => 0.280,
                ],
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 12, ['Zone A' => 10]), array_fill(13, 9, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => []
            ],
            'D10' => [
                'client_payment_method' => 'zones',
                'client_pricing_rules' => ['1' => [
                    'payment_method' => 'zones',
                    'zones' => [
                        ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                        ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                    ]
                ]],
                'driver_payment_method' => 'fixed',
                'driver_pricing_rules' => ['1' => ['payment_method' => 'fixed']],
                'override' => [
                    'override_type' => 'zones_tiers',
                    'custom_pricing_rules' => [
                        [
                            'zone' => 'Zone A',
                            'tiers' => [
                                ['min' => 1, 'max' => 90, 'price' => 0.450],
                                ['min' => 91, 'max' => 9999, 'price' => 0.650],
                            ]
                        ],
                        [
                            'zone' => 'Zone B',
                            'tiers' => [
                                ['min' => 1, 'max' => 70, 'price' => 0.550],
                                ['min' => 71, 'max' => 9999, 'price' => 0.850],
                            ]
                        ]
                    ]
                ],
                'orders' => array_replace(array_fill(1, 30, ['Zone A' => 0, 'Zone B' => 0]), array_replace(array_fill(1, 11, ['Zone A' => 10]), array_fill(12, 8, ['Zone B' => 10]))),
                'client_rate' => 0.0,
                'extra' => []
            ],
        ];

        // Ensure vehicle types exist
        $vTypes = [
            ['id' => 1, 'name' => 'Motorcycle', 'name_ar' => 'سيكل / دراجة نارية', 'company_id' => $company->id],
            ['id' => 2, 'name' => 'Small Car', 'name_ar' => 'سيارة صغيرة', 'company_id' => $company->id],
        ];
        foreach ($vTypes as $vt) {
            \App\Models\VehicleType::firstOrCreate(['id' => $vt['id']], $vt);
        }

        // Fresh vehicle
        $vehicle = Vehicle::create([
            'plate_number' => 'V-E2E-28',
            'make' => 'Yamaha Bike',
            'status' => 'working',
            'company_id' => $company->id,
            'vehicle_type_id' => 1,
        ]);

        foreach ($scenarios as $name => $cfg) {
            // 1. Create Contract
            $contractData = array_merge([
                'client_id' => $client->id,
                'contract_number' => 'CON-E2E-' . $name,
                'name' => 'Contract ' . $name,
                'payment_type' => 'per_order',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-30',
                'client_payment_method' => $cfg['client_payment_method'],
                'driver_payment_method' => $cfg['driver_payment_method'],
                'company_id' => $company->id,
                'currency' => 'KWD',
                'client_pricing_rules' => $cfg['client_pricing_rules'],
                'driver_pricing_rules' => $cfg['driver_pricing_rules'],
                'is_validity_enabled' => false,
                'default_absence_divisor' => 26,
                'default_required_valid_days' => 26,
                'default_required_work_days' => 26,
            ], $cfg['extra']);
            unset($contractData['actual_salary']);
            $contract = Contract::create($contractData);

            // 2. Create Employee
            $driver = Employee::create([
                'name' => 'Driver ' . $name,
                'employee_number' => 'EMP-E2E-' . $name,
                'company_id' => $company->id,
                'status' => 'active',
                'date_of_joining' => '2026-11-01',
                'actual_salary' => $cfg['extra']['actual_salary'] ?? 0.000,
            ]);

            // 3. Create Assignments
            VehicleAssignment::create([
                'employee_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'is_active' => true,
                'assigned_date' => '2026-11-01',
                'company_id' => $company->id,
            ]);

            $assignment = ContractAssignment::create([
                'employee_id' => $driver->id,
                'contract_id' => $contract->id,
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-30',
                'status' => 'active',
                'company_id' => $company->id,
            ]);

            // 4. Override
            if ($cfg['override']) {
                DriverContractOverride::create(array_merge([
                    'contract_assignment_id' => $assignment->id,
                    'customization_reason' => 'E2E Testing ' . $name,
                    'effective_from' => '2026-11-01',
                    'effective_to' => '2026-11-30',
                    'company_id' => $company->id,
                ], $cfg['override']));
            }

            // 5. Daily Logs
            for ($d = 1; $d <= 30; $d++) {
                $date = sprintf("2026-11-%02d", $d);
                $dayOrders = $cfg['orders'][$d] ?? 0;

                if (is_array($dayOrders)) {
                    $hasOrders = false;
                    foreach ($dayOrders as $zone => $count) {
                        if ($count > 0) {
                            $hasOrders = true;
                            $clientRate = 0.0;
                            if ($cfg['client_payment_method'] === 'zones') {
                                if ($zone === 'Zone A') $clientRate = 1.200;
                                if ($zone === 'Zone B') $clientRate = 1.800;
                            }
                            DailyLog::create([
                                'company_id' => $company->id,
                                'employee_id' => $driver->id,
                                'vehicle_id' => $vehicle->id,
                                'contract_id' => $contract->id,
                                'log_date' => $date,
                                'orders_count' => $count,
                                'zone' => $zone,
                                'is_valid' => true,
                                'shift_valid' => true,
                                'created_by' => $adminId,
                                'income_amount' => $count * $clientRate,
                            ]);
                        }
                    }
                    if (!$hasOrders) {
                        DailyLog::create([
                            'company_id' => $company->id,
                            'employee_id' => $driver->id,
                            'vehicle_id' => $vehicle->id,
                            'contract_id' => $contract->id,
                            'log_date' => $date,
                            'orders_count' => 0,
                            'zone' => 'Zone A',
                            'is_valid' => true,
                            'shift_valid' => true,
                            'created_by' => $adminId,
                            'income_amount' => 0.0,
                        ]);
                    }
                } else {
                    $count = (int)$dayOrders;
                    $clientRate = 0.0;
                    if ($count > 0) {
                        if ($cfg['client_payment_method'] === 'tiers') {
                            $totalOrders = array_sum(array_map(function($val) {
                                return is_array($val) ? array_sum($val) : $val;
                            }, $cfg['orders']));
                            $clientRate = $totalOrders >= 201 ? 1.500 : 1.000;
                        } elseif ($cfg['client_payment_method'] === 'hybrid') {
                            $clientRate = 0.500;
                        }
                    }
                    DailyLog::create([
                        'company_id' => $company->id,
                        'employee_id' => $driver->id,
                        'vehicle_id' => $vehicle->id,
                        'contract_id' => $contract->id,
                        'log_date' => $date,
                        'orders_count' => $count,
                        'is_valid' => true,
                        'shift_valid' => true,
                        'created_by' => $adminId,
                        'income_amount' => $count * $clientRate,
                    ]);
                }
            }
        }
    }
}
