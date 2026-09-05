<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\DriverContractOverride;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComprehensivePayrollScenariosTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Payroll E2E Test Company',
            'code' => 'paye2ec',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'Test Client',
            'company_id' => $this->company->id,
        ]);
    }

    /**
     * Helper to run a test scenario.
     */
    private function runPayrollScenario(
        string $clientPaymentMethod,
        array $clientPricingRules,
        string $driverPaymentMethod,
        array $driverPricingRules,
        ?array $overrideData,
        array $ordersByDay,
        float $expectedDriverPayout,
        float $expectedClientRevenue,
        array $contractExtraFields = []
    ) {
        $this->actingAs($this->user);

        // 0. The retired path read a fixed driver's salary off employees.actual_salary whenever the
        //    pricing rule named no amount. The contract sheet reads the rule, which is how all 18
        //    real contracts are written — none of them leaves the amount off. So the scenarios say
        //    it in the rule; the figures they assert are unchanged.
        $salaryInRule = (float) ($contractExtraFields['actual_salary'] ?? 0);
        foreach ($driverPricingRules as $vehicleType => $rule) {
            if (in_array($rule['payment_method'] ?? '', ['fixed', 'hybrid'], true)
                && ! isset($rule['fixed_amount'])) {
                $driverPricingRules[$vehicleType]['fixed_amount'] = $salaryInRule;
            }
        }

        // 1. Create Contract
        $contractFields = array_merge([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-'.uniqid(),
            'name' => 'Scenario Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'client_payment_method' => $clientPaymentMethod,
            'driver_payment_method' => $driverPaymentMethod,
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'client_pricing_rules' => $clientPricingRules,
            'driver_pricing_rules' => $driverPricingRules,
            'is_validity_enabled' => false,
            'default_absence_divisor' => 26,
            'default_required_valid_days' => 26,
        ], $contractExtraFields);
        unset($contractFields['actual_salary']);
        $contract = Contract::create($contractFields);

        // 2. Create Employee
        $driver = Employee::create([
            'name' => 'Driver '.uniqid(),
            'employee_number' => 'EMP-'.rand(1000, 9999),
            'company_id' => $this->company->id,
            'status' => 'active',
            'date_of_joining' => '2026-11-01',
            'actual_salary' => $contractExtraFields['actual_salary'] ?? 0.000,
        ]);

        // 3. Create Vehicle
        $vehicle = Vehicle::create([
            'plate_number' => 'V-'.rand(1000, 9999),
            'make' => 'Toyota Bike',
            'status' => 'working',
            'company_id' => $this->company->id,
            'vehicle_type_id' => 1,
        ]);

        // 4. Create Assignments
        VehicleAssignment::create([
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'is_active' => true,
            'assigned_date' => '2026-11-01',
            'company_id' => $this->company->id,
        ]);

        $assignment = ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        // 5. Create Override if requested
        if ($overrideData) {
            DriverContractOverride::create(array_merge([
                'contract_assignment_id' => $assignment->id,
                'customization_reason' => 'E2E Testing',
                'effective_from' => '2026-11-01',
                'effective_to' => '2026-11-30',
                'company_id' => $this->company->id,
            ], $overrideData));
        }

        // 6. Create Daily Logs for November 2026 (30 Days)
        for ($d = 1; $d <= 30; $d++) {
            $date = sprintf('2026-11-%02d', $d);
            $dayOrders = $ordersByDay[$d] ?? 0;

            if (is_array($dayOrders)) {
                foreach ($dayOrders as $zone => $count) {
                    if ($count > 0) {
                        // Calculate client rate for this zone
                        $clientRate = 0.0;
                        if ($clientPaymentMethod === 'zones') {
                            if ($zone === 'Zone A') {
                                $clientRate = 1.200;
                            }
                            if ($zone === 'Zone B') {
                                $clientRate = 1.800;
                            }
                        }

                        DailyLog::create([
                            'company_id' => $this->company->id,
                            'employee_id' => $driver->id,
                            'vehicle_id' => $vehicle->id,
                            'contract_id' => $contract->id,
                            'log_date' => $date,
                            'orders_count' => $count,
                            'zone' => $zone,
                            'is_valid' => true,
                            'shift_valid' => true,
                            'created_by' => $this->user->id,
                            'income_amount' => $count * $clientRate,
                        ]);
                    }
                }
            } else {
                if ($dayOrders >= 0) {
                    // Calculate client rate
                    $clientRate = 0.0;
                    if ($clientPaymentMethod === 'tiers') {
                        // Tiers rate is 1.500 if total orders >= 201, else 1.000
                        $totalOrders = array_sum(array_map(function ($val) {
                            return is_array($val) ? array_sum($val) : $val;
                        }, $ordersByDay));
                        $clientRate = $totalOrders >= 201 ? 1.500 : 1.000;
                    } elseif ($clientPaymentMethod === 'hybrid') {
                        $clientRate = 0.500;
                    }

                    DailyLog::create([
                        'company_id' => $this->company->id,
                        'employee_id' => $driver->id,
                        'vehicle_id' => $vehicle->id,
                        'contract_id' => $contract->id,
                        'log_date' => $date,
                        'orders_count' => $dayOrders,
                        'is_valid' => true,
                        'shift_valid' => true,
                        'created_by' => $this->user->id,
                        'income_amount' => $dayOrders * $clientRate,
                    ]);
                }
            }
        }

        // 7. Read the contract sheet. These scenarios exist to pin the commission, incentive, zone
        //    and tier maths, which is unchanged — only the screen that used to display it is gone,
        //    so the assertion moves from the retired payroll slip to the sheet that replaced it.
        $response = $this->getJson("/api/payroll/contract-sheet/{$contract->id}?year=2026&month=11");
        $response->assertOk();

        // 8. Assert the driver's gross earnings for the contract.
        $row = collect($response->json('drivers'))->firstWhere('employee_id', $driver->id);
        $this->assertNotNull($row, 'driver missing from the contract sheet');
        $this->assertEqualsWithDelta(
            $expectedDriverPayout,
            (float) $row['gross_contract_earnings'],
            0.001,
            'gross contract earnings'
        );

        // 9. Assert Client Revenue
        if ($clientPaymentMethod === 'fixed') {
            $this->assertEqualsWithDelta($expectedClientRevenue, (float) ($contract->fixed_monthly ?? 300.000), 0.001);
        } else {
            $logsRevenue = (float) DailyLog::where('contract_id', $contract->id)->sum('income_amount');
            $fixedRevenue = 0.0;
            if ($clientPaymentMethod === 'hybrid') {
                $fixedRevenue = 200.000;
            }
            $this->assertEqualsWithDelta($expectedClientRevenue, $logsRevenue + $fixedRevenue, 0.001);
        }
    }

    // ==========================================
    // GROUP A: CLIENT PAYMENT METHOD = FIXED (300.000 KWD)
    // ==========================================

    public function test_scenario_a1_direct_fixed()
    {
        $orders = array_fill(1, 30, 10);

        $this->runPayrollScenario(
            'fixed',
            ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            null,
            $orders,
            200.000,
            300.000,
            [
                'fixed_monthly' => 300,
                'actual_salary' => 150,
                'default_monthly_target' => 250,
                'premium_commission_rate' => 1.000,
            ]
        );
    }

    public function test_scenario_a2_direct_tiers()
    {
        $orders = array_fill(1, 25, 8) + array_fill(26, 5, 10);

        $this->runPayrollScenario(
            'fixed',
            ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 0.500],
                    ['min' => 201, 'max' => 9999, 'price' => 0.750],
                ],
            ]],
            null,
            $orders,
            187.500,
            300.000,
            ['fixed_monthly' => 300]
        );
    }

    public function test_scenario_a3_direct_hybrid()
    {
        $orders = array_fill(1, 20, 7) + array_fill(21, 10, 8);

        $this->runPayrollScenario(
            'fixed',
            ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
            'hybrid',
            ['1' => ['payment_method' => 'hybrid']],
            null,
            $orders,
            144.000,
            300.000,
            [
                'fixed_monthly' => 300,
                'actual_salary' => 100,
                'default_order_commission' => 0.200,
            ]
        );
    }

    public function test_scenario_a4_override_fixed()
    {
        $orders = array_fill(1, 20, 7) + array_fill(21, 10, 9);

        $this->runPayrollScenario(
            'fixed',
            ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'fixed',
                'custom_fixed_salary' => 160,
                'custom_monthly_target' => 240,
                'custom_order_commission' => 0.400,
                'custom_monthly_bonus' => 40.000,
            ],
            $orders,
            156.000,
            300.000,
            ['fixed_monthly' => 300]
        );
    }

    public function test_scenario_a5_override_tiers()
    {
        $orders = array_fill(1, 30, 6);
        $orders[30] = 26;

        $this->runPayrollScenario(
            'fixed',
            ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'tiers',
                'custom_pricing_rules' => [
                    ['min' => 1, 'max' => 150, 'price' => 0.600],
                    ['min' => 151, 'max' => 9999, 'price' => 0.800],
                ],
            ],
            $orders,
            160.000,
            300.000,
            ['fixed_monthly' => 300]
        );
    }

    public function test_scenario_a6_override_hybrid()
    {
        $orders = array_fill(1, 30, 6);

        $this->runPayrollScenario(
            'fixed',
            ['1' => ['payment_method' => 'fixed', 'fixed_amount' => 300]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'hybrid',
                'custom_fixed_salary' => 110,
                'custom_order_commission' => 0.250,
            ],
            $orders,
            155.000,
            300.000,
            ['fixed_monthly' => 300]
        );
    }

    // ==========================================
    // GROUP B: CLIENT PAYMENT METHOD = TIERS (0-200 -> 1.000 KWD, 201+ -> 1.500 KWD)
    // ==========================================

    public function test_scenario_b1_direct_fixed()
    {
        $orders = array_fill(1, 30, 8); // 240 orders total

        $this->runPayrollScenario(
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 1.000],
                    ['min' => 201, 'max' => 9999, 'price' => 1.500],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            null,
            $orders,
            170.000,
            360.000,
            [
                'actual_salary' => 140,
                'default_monthly_target' => 220,
                'premium_commission_rate' => 1.500,
            ]
        );
    }

    public function test_scenario_b2_direct_tiers()
    {
        $orders = array_fill(1, 25, 8) + array_fill(26, 5, 10);

        $this->runPayrollScenario(
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 1.000],
                    ['min' => 201, 'max' => 9999, 'price' => 1.500],
                ],
            ]],
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 0.400],
                    ['min' => 201, 'max' => 9999, 'price' => 0.600],
                ],
            ]],
            null,
            $orders,
            150.000,
            375.000
        );
    }

    public function test_scenario_b3_direct_hybrid()
    {
        $orders = array_fill(1, 30, 7); // 210 orders total

        $this->runPayrollScenario(
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 1.000],
                    ['min' => 201, 'max' => 9999, 'price' => 1.500],
                ],
            ]],
            'hybrid',
            ['1' => ['payment_method' => 'hybrid']],
            null,
            $orders,
            153.000,
            315.000,
            [
                'actual_salary' => 90,
                'default_order_commission' => 0.300,
            ]
        );
    }

    public function test_scenario_b4_override_fixed()
    {
        $orders = array_fill(1, 30, 7); // 210 orders total

        $this->runPayrollScenario(
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 1.000],
                    ['min' => 201, 'max' => 9999, 'price' => 1.500],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'fixed',
                'custom_fixed_salary' => 150,
                'custom_monthly_target' => 230,
                'custom_order_commission' => 0.500,
            ],
            $orders,
            140.000,
            315.000
        );
    }

    public function test_scenario_b5_override_tiers()
    {
        $orders = array_fill(1, 20, 7) + array_fill(21, 10, 8); // 220 total

        $this->runPayrollScenario(
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 1.000],
                    ['min' => 201, 'max' => 9999, 'price' => 1.500],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'tiers',
                'custom_pricing_rules' => [
                    ['min' => 1, 'max' => 180, 'price' => 0.500],
                    ['min' => 181, 'max' => 9999, 'price' => 0.700],
                ],
            ],
            $orders,
            154.000,
            330.000
        );
    }

    public function test_scenario_b6_override_hybrid()
    {
        $orders = array_fill(1, 10, 6) + array_fill(11, 20, 6.5); // 190 total

        $this->runPayrollScenario(
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 1.000],
                    ['min' => 201, 'max' => 9999, 'price' => 1.500],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'hybrid',
                'custom_fixed_salary' => 100,
                'custom_order_commission' => 0.280,
            ],
            $orders,
            153.200,
            190.000
        );
    }

    // ==========================================
    // GROUP C: CLIENT PAYMENT METHOD = HYBRID (FIXED 200.000 KWD + 0.500 KWD/order)
    // ==========================================

    public function test_scenario_c1_direct_fixed()
    {
        $orders = array_fill(1, 20, 7) + array_fill(21, 10, 8); // 220 total

        $this->runPayrollScenario(
            'hybrid',
            ['1' => [
                'payment_method' => 'hybrid',
                'fixed_amount' => 200,
                'order_commission' => 0.500,
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            null,
            $orders,
            155.000,
            310.000,
            [
                'actual_salary' => 130,
                'default_monthly_target' => 200,
                'premium_commission_rate' => 1.250,
            ]
        );
    }

    public function test_scenario_c2_direct_tiers()
    {
        $orders = array_fill(1, 20, 6) + array_fill(21, 10, 8); // 200 total

        $this->runPayrollScenario(
            'hybrid',
            ['1' => [
                'payment_method' => 'hybrid',
                'fixed_amount' => 200,
                'order_commission' => 0.500,
            ]],
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 180, 'price' => 0.450],
                    ['min' => 181, 'max' => 9999, 'price' => 0.550],
                ],
            ]],
            null,
            $orders,
            110.000,
            300.000
        );
    }

    public function test_scenario_c3_direct_hybrid()
    {
        $orders = array_fill(1, 20, 5) + array_fill(21, 10, 7); // 170 total

        $this->runPayrollScenario(
            'hybrid',
            ['1' => [
                'payment_method' => 'hybrid',
                'fixed_amount' => 200,
                'order_commission' => 0.500,
            ]],
            'hybrid',
            ['1' => ['payment_method' => 'hybrid']],
            null,
            $orders,
            139.500,
            285.000,
            [
                'actual_salary' => 80,
                'default_order_commission' => 0.350,
            ]
        );
    }

    public function test_scenario_c4_override_fixed()
    {
        $orders = array_fill(1, 20, 6) + array_fill(21, 10, 7); // 190 total

        $this->runPayrollScenario(
            'hybrid',
            ['1' => [
                'payment_method' => 'hybrid',
                'fixed_amount' => 200,
                'order_commission' => 0.500,
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'fixed',
                'custom_fixed_salary' => 140,
                'custom_monthly_target' => 210,
                'custom_order_commission' => 0.450,
            ],
            $orders,
            131.000,
            295.000
        );
    }

    public function test_scenario_c5_override_tiers()
    {
        $orders = array_fill(1, 20, 6) + array_fill(21, 10, 6); // 180 total

        $this->runPayrollScenario(
            'hybrid',
            ['1' => [
                'payment_method' => 'hybrid',
                'fixed_amount' => 200,
                'order_commission' => 0.500,
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'tiers',
                'custom_pricing_rules' => [
                    ['min' => 1, 'max' => 170, 'price' => 0.480],
                    ['min' => 171, 'max' => 9999, 'price' => 0.600],
                ],
            ],
            $orders,
            108.000,
            290.000
        );
    }

    public function test_scenario_c6_override_hybrid()
    {
        $orders = array_fill(1, 20, 5) + array_fill(21, 10, 6); // 160 total

        $this->runPayrollScenario(
            'hybrid',
            ['1' => [
                'payment_method' => 'hybrid',
                'fixed_amount' => 200,
                'order_commission' => 0.500,
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'hybrid',
                'custom_fixed_salary' => 95,
                'custom_order_commission' => 0.320,
            ],
            $orders,
            146.200,
            280.000
        );
    }

    // ==========================================
    // GROUP D: CLIENT PAYMENT METHOD = ZONES (Zone A = 1.200 KWD, Zone B = 1.800 KWD)
    // ==========================================

    public function test_scenario_d1_direct_fixed()
    {
        $orders = array_fill(1, 15, ['Zone A' => 10]) + array_fill(16, 10, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            null,
            $orders,
            195.000,
            360.000,
            [
                'actual_salary' => 150,
                'default_monthly_target' => 240,
                'premium_commission_rate' => 4.500,
            ]
        );
    }

    public function test_scenario_d2_direct_zones()
    {
        $orders = array_fill(1, 16, ['Zone A' => 10]) + array_fill(17, 9, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    'Zone A' => 0.500,
                    'Zone B' => 0.700,
                ],
            ]],
            null,
            $orders,
            143.000,
            354.000
        );
    }

    public function test_scenario_d3_direct_tiers()
    {
        $orders = array_fill(1, 18, ['Zone A' => 10]) + array_fill(19, 7, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'tiers',
            ['1' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 0.450],
                    ['min' => 201, 'max' => 9999, 'price' => 0.650],
                ],
            ]],
            null,
            $orders,
            162.500,
            342.000
        );
    }

    public function test_scenario_d4_direct_hybrid()
    {
        $orders = array_fill(1, 14, ['Zone A' => 10]) + array_fill(15, 8, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'hybrid',
            ['1' => ['payment_method' => 'hybrid']],
            null,
            $orders,
            166.000,
            312.000,
            [
                'actual_salary' => 100,
                'default_order_commission' => 0.300,
            ]
        );
    }

    public function test_scenario_d5_direct_zones_tiers()
    {
        $orders = array_fill(1, 12, ['Zone A' => 10]) + array_fill(13, 9, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'zones_tiers',
            ['1' => [
                'payment_method' => 'zones_tiers',
                'zones_tiers' => [
                    [
                        'zone' => 'Zone A',
                        'tiers' => [
                            ['min' => 1, 'max' => 100, 'price' => 0.400],
                            ['min' => 101, 'max' => 9999, 'price' => 0.600],
                        ],
                    ],
                    [
                        'zone' => 'Zone B',
                        'tiers' => [
                            ['min' => 1, 'max' => 80, 'price' => 0.500],
                            ['min' => 81, 'max' => 9999, 'price' => 0.800],
                        ],
                    ],
                ],
            ]],
            null,
            $orders,
            144.000,
            306.000
        );
    }

    public function test_scenario_d6_override_fixed()
    {
        $orders = array_fill(1, 13, ['Zone A' => 10]) + array_fill(14, 10, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'fixed',
                'custom_fixed_salary' => 160,
                'custom_monthly_target' => 250,
                'custom_order_commission' => 0.400,
            ],
            $orders,
            152.000,
            336.000
        );
    }

    public function test_scenario_d7_override_zones()
    {
        $orders = array_fill(1, 14, ['Zone A' => 10]) + array_fill(15, 11, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'zones',
                'custom_pricing_rules' => [
                    'Zone A' => 0.550,
                    'Zone B' => 0.750,
                ],
            ],
            $orders,
            159.500,
            366.000
        );
    }

    public function test_scenario_d8_override_tiers()
    {
        $orders = array_fill(1, 15, ['Zone A' => 10]) + array_fill(16, 8, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'tiers',
                'custom_pricing_rules' => [
                    ['min' => 1, 'max' => 210, 'price' => 0.500],
                    ['min' => 211, 'max' => 9999, 'price' => 0.700],
                ],
            ],
            $orders,
            161.000,
            324.000
        );
    }

    public function test_scenario_d9_override_hybrid()
    {
        $orders = array_fill(1, 12, ['Zone A' => 10]) + array_fill(13, 9, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'hybrid',
                'custom_fixed_salary' => 110,
                'custom_order_commission' => 0.280,
            ],
            $orders,
            168.800,
            306.000
        );
    }

    public function test_scenario_d10_override_zones_tiers()
    {
        $orders = array_fill(1, 11, ['Zone A' => 10]) + array_fill(12, 8, ['Zone B' => 10]);

        $this->runPayrollScenario(
            'zones',
            ['1' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'zone-a', 'name' => 'Zone A', 'price' => 1.200],
                    ['id' => 'zone-b', 'name' => 'Zone B', 'price' => 1.800],
                ],
            ]],
            'fixed',
            ['1' => ['payment_method' => 'fixed']],
            [
                'override_type' => 'zones_tiers',
                'custom_pricing_rules' => [
                    [
                        'zone' => 'Zone A',
                        'tiers' => [
                            ['min' => 1, 'max' => 90, 'price' => 0.450],
                            ['min' => 91, 'max' => 9999, 'price' => 0.650],
                        ],
                    ],
                    [
                        'zone' => 'Zone B',
                        'tiers' => [
                            ['min' => 1, 'max' => 70, 'price' => 0.550],
                            ['min' => 71, 'max' => 9999, 'price' => 0.850],
                        ],
                    ],
                ],
            ],
            $orders,
            139.500,
            276.000
        );
    }
}
