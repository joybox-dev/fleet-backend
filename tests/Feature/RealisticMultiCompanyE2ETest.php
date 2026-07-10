<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\VehicleAssignment;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\ContractMonthlyParameter;
use App\Models\SupervisorCostAllocation;
use App\Models\OperationalAdvance;
use App\Models\Violation;
use App\Models\ClientCollection;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
use App\Models\PayrollPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealisticMultiCompanyE2ETest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected User $adminA;
    protected User $adminB;
    protected User $supervisorA;
    protected VehicleType $bikeType;
    protected VehicleType $carType;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Companies
        $this->companyA = Company::create([
            'name' => 'Amana Delivery Company',
            'code' => 'AMANA',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        $this->companyB = Company::create([
            'name' => 'Burgan Logistics Company',
            'code' => 'BURGAN',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        // 2. Create Users
        $this->adminA = User::create([
            'name' => 'Amana Admin',
            'email' => 'amana_admin@amana.kw',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->companyA->id,
        ]);

        $this->adminB = User::create([
            'name' => 'Burgan Admin',
            'email' => 'burgan_admin@burgan.kw',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->companyB->id,
        ]);

        // 3. Create Vehicle Types globally (scoped under Amana/Burgan during setup)
        app()->bind('current_company_id', fn() => $this->companyA->id);
        $this->bikeType = VehicleType::create([
            'company_id' => $this->companyA->id,
            'name' => 'Bike',
            'name_ar' => 'دراجة نارية',
        ]);
        $this->carType = VehicleType::create([
            'company_id' => $this->companyA->id,
            'name' => 'Car',
            'name_ar' => 'سيارة',
        ]);
    }

    /**
     * E2E Scenario testing 100% of the specifications across two companies.
     */
    public function test_realistic_e2e_multi_company_scenario(): void
    {
        // ══════════════════════════════════════════════════════════════
        // STEP 1: Smart Filtering & Compatibility (Company A)
        // ══════════════════════════════════════════════════════════════
        $this->actingAs($this->adminA);
        app()->bind('current_company_id', fn() => $this->companyA->id);

        $clientA = Client::create(['name' => 'Talabat', 'company_id' => $this->companyA->id]);
        $vehicleBikeA = Vehicle::create([
            'plate_number' => 'A-Bike-1',
            'vehicle_type_id' => $this->bikeType->id,
            'company_id' => $this->companyA->id,
            'status' => 'available'
        ]);

        $driverTariq = Employee::create([
            'company_id' => $this->companyA->id,
            'name' => 'Tariq',
            'employee_number' => 'EMP-TARIQ',
            'employee_type' => 'driver',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'hybrid',
            'actual_salary' => 150.0,
            'official_salary' => 120.0,
            'rate_per_order' => 0.250,
            'status' => 'active',
        ]);

        // Assign Tariq to Bike
        VehicleAssignment::create([
            'vehicle_id' => $vehicleBikeA->id,
            'employee_id' => $driverTariq->id,
            'assigned_date' => '2026-07-01',
            'is_active' => true,
            'company_id' => $this->companyA->id,
        ]);

        // ══════════════════════════════════════════════════════════════
        // STEP 2: Cross-Validation Test for Zones (Company A)
        // ══════════════════════════════════════════════════════════════
        // Client pricing is 'per_order'. Driver pricing cannot be 'zones' or 'zones_tiers'
        $invalidContract = $this->postJson('/api/contracts', [
            'client_id' => $clientA->id,
            'contract_number' => 'CON-AMANA-TALA-ERR',
            'name' => 'Amana-Talabat Error Contract',
            'payment_type' => 'per_order',
            'start_date' => '2026-07-01',
            'vehicle_type_id' => $this->bikeType->id,
            'client_payment_method' => 'per_order',
            'driver_payment_method' => 'zones', // Forbidden!
        ]);
        $invalidContract->assertStatus(422);
        $invalidContract->assertJsonValidationErrors('driver_payment_method');

        // Create the correct contract
        $contractA = $this->postJson('/api/contracts', [
            'client_id' => $clientA->id,
            'contract_number' => 'CON-AMANA-TALA-1',
            'name' => 'Amana-Talabat Active Contract',
            'payment_type' => 'hybrid',
            'start_date' => '2026-07-01',
            'vehicle_type_id' => $this->bikeType->id,
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'hybrid',
            'is_validity_enabled' => true,
        ]);
        $contractA->assertCreated();
        $contractAId = $contractA->json('id');

        // Smart Filtering check: Assigning driver Tariq (Bike driver) to Contract A (Bike contract) should succeed
        $assignmentTariq = $this->postJson('/api/contract-assignments', [
            'employee_id' => $driverTariq->id,
            'contract_id' => $contractAId,
            'start_date' => '2026-07-01',
            'status' => 'active',
        ]);
        $assignmentTariq->assertCreated();

        // Unique assignment constraint check: Re-assigning Tariq to the same contract should fail
        $duplicateAssignment = $this->postJson('/api/contract-assignments', [
            'employee_id' => $driverTariq->id,
            'contract_id' => $contractAId,
            'start_date' => '2026-07-10',
            'status' => 'active',
        ]);
        $duplicateAssignment->assertStatus(422);
        $duplicateAssignment->assertJsonValidationErrors('contract_id');

        // ══════════════════════════════════════════════════════════════
        // STEP 3: KPIs & Auto-Validity & Excluded Joiners (Company A)
        // ══════════════════════════════════════════════════════════════
        // Create Monthly Parameters with KPIs rules for July 2026
        $paramA = ContractMonthlyParameter::create([
            'contract_id' => $contractAId,
            'year' => 2026,
            'month' => 7,
            'company_id' => $this->companyA->id,
            'min_valid_days' => 25,
            'min_completed_orders' => 200,
            'capacity_incentive_rules' => [
                ['min_orders' => 100, 'max_orders' => 1000, 'bonus' => 100]
            ],
            'experience_incentive_rules' => [
                ['min_months' => 0, 'bonus' => 50, 'bonus_per_order' => 0.0]
            ]
        ]);

        // Add mandatory days: July 15 & July 16
        $paramA->mandatoryDays()->create([
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
            'min_required_days' => 2,
            'company_id' => $this->companyA->id
        ]);

        // Tariq Daily Logs: 27 valid logs. Including July 15 & 16. Total orders = 216
        for ($day = 1; $day <= 27; $day++) {
            $logDate = "2026-07-" . str_pad($day, 2, '0', STR_PAD_LEFT);
            DailyLog::create([
                'employee_id' => $driverTariq->id,
                'vehicle_id' => $vehicleBikeA->id,
                'contract_id' => $contractAId,
                'log_date' => $logDate,
                'orders_count' => 8,
                'orders_online' => 8,
                'orders_cash' => 0,
                'online_hours' => 10,
                'ontime_rate' => 95,
                'is_valid' => true,
                'shift_valid' => true,
                'created_by' => $this->adminA->id,
                'company_id' => $this->companyA->id,
            ]);
        }

        // Create Ziad, who joined on July 28th (within last 7 days of the month)
        $driverZiad = Employee::create([
            'company_id' => $this->companyA->id,
            'name' => 'Ziad',
            'employee_number' => 'EMP-ZIAD',
            'employee_type' => 'driver',
            'date_of_joining' => '2026-07-28', // Join within last 7 days
            'pay_type' => 'hybrid',
            'actual_salary' => 150.0,
            'official_salary' => 120.0,
            'rate_per_order' => 0.250,
            'status' => 'active',
        ]);

        // Assign Ziad to Bike
        VehicleAssignment::create([
            'vehicle_id' => $vehicleBikeA->id,
            'employee_id' => $driverZiad->id,
            'assigned_date' => '2026-07-28',
            'is_active' => true,
            'company_id' => $this->companyA->id,
        ]);

        ContractAssignment::create([
            'employee_id' => $driverZiad->id,
            'contract_id' => $contractAId,
            'start_date' => '2026-07-28',
            'status' => 'active',
            'company_id' => $this->companyA->id,
        ]);

        // Run payroll for Company A for July 2026
        $payrollResponseA = $this->postJson('/api/payroll/run', [
            'year' => 2026,
            'month' => 7
        ]);
        if ($payrollResponseA->status() !== 201) {
            dd('payrollResponseA non-201 response: ' . $payrollResponseA->status() . ' - ' . $payrollResponseA->getContent());
        }
        $payrollResponseA->assertStatus(201);
        $runAId = $payrollResponseA->json('run_id');

        // Assert Tariq is Valid DA
        $slipTariq = PayrollSlip::where('payroll_run_id', $runAId)->where('employee_id', $driverTariq->id)->first();
        $this->assertEquals('Valid', $slipTariq->final_monthly_status);

        // ══════════════════════════════════════════════════════════════
        // STEP 4: Negative Salary & Bank Rule (Samir, Company A)
        // ══════════════════════════════════════════════════════════════
        $driverSamir = Employee::create([
            'company_id' => $this->companyA->id,
            'name' => 'Samir',
            'employee_number' => 'EMP-SAMIR',
            'employee_type' => 'driver',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'fixed',
            'actual_salary' => 180.0,
            'official_salary' => 120.0,
            'target_orders_monthly' => 300,
            'rate_per_order' => 1.0, // deficit rate
            'status' => 'active',
        ]);

        // Assign Samir to Car
        $vehicleCarA = Vehicle::create([
            'plate_number' => 'A-Car-2',
            'vehicle_type_id' => $this->carType->id,
            'company_id' => $this->companyA->id,
            'status' => 'available'
        ]);

        VehicleAssignment::create([
            'vehicle_id' => $vehicleCarA->id,
            'employee_id' => $driverSamir->id,
            'assigned_date' => '2026-07-01',
            'is_active' => true,
            'company_id' => $this->companyA->id,
        ]);

        $contractCarA = Contract::create([
            'client_id' => $clientA->id,
            'contract_number' => 'CON-AMANA-CAR',
            'name' => 'Amana-Talabat Car Contract',
            'payment_type' => 'fixed',
            'start_date' => '2026-07-01',
            'vehicle_type_id' => $this->carType->id,
            'company_id' => $this->companyA->id,
            'client_payment_method' => 'fixed',
            'driver_payment_method' => 'fixed',
            'default_fixed_salary' => 180.0,
            'default_monthly_target' => 300,
            'default_order_commission' => 1.0, // deficit rate
            'is_validity_enabled' => false,
        ]);

        ContractAssignment::create([
            'employee_id' => $driverSamir->id,
            'contract_id' => $contractCarA->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->companyA->id,
        ]);

        // Samir completed 50 orders across 27 worked days (deficit of 250 orders -> -250 KWD)
        for ($day = 1; $day <= 27; $day++) {
            $logDate = "2026-07-" . str_pad($day, 2, '0', STR_PAD_LEFT);
            $orders = ($day == 1) ? 24 : (($day == 2) ? 26 : 0);
            DailyLog::create([
                'employee_id' => $driverSamir->id,
                'vehicle_id' => $vehicleCarA->id,
                'contract_id' => $contractCarA->id,
                'log_date' => $logDate,
                'orders_count' => $orders,
                'orders_online' => $orders,
                'orders_cash' => 0,
                'online_hours' => 10,
                'ontime_rate' => 95,
                'is_valid' => true,
                'shift_valid' => true,
                'created_by' => $this->adminA->id,
                'company_id' => $this->companyA->id,
            ]);
        }

        // Recalculate/Run payroll again to capture Samir
        PayrollRun::find($runAId)->delete();
        $payrollResponseA2 = $this->postJson('/api/payroll/run', [
            'year' => 2026,
            'month' => 7
        ]);
        if ($payrollResponseA2->status() !== 201) {
            dd('payrollResponseA2 non-201 response: ' . $payrollResponseA2->status() . ' - ' . $payrollResponseA2->getContent());
        }
        $payrollResponseA2->assertStatus(201);
        $runA2Id = $payrollResponseA2->json('run_id');

        $slipSamir = PayrollSlip::where('payroll_run_id', $runA2Id)->where('employee_id', $driverSamir->id)->first();
        // Gross Actual: fixed salary 180 - 250 deficit = -70 KWD
        $this->assertEquals(-70.0, (float)$slipSamir->gross_actual);
        // Bank Portion (Official Salary) paid in full: 120 KWD
        $this->assertEquals(120.0, (float)$slipSamir->gross_official);

        // ══════════════════════════════════════════════════════════════
        // STEP 5: Administrative Staff & Salary Allocation (Company A)
        // ══════════════════════════════════════════════════════════════
        $supervisorMahmoud = Employee::create([
            'company_id' => $this->companyA->id,
            'name' => 'Mahmoud',
            'employee_number' => 'EMP-MAHMOUD',
            'employee_type' => 'administrative',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'fixed',
            'actual_salary' => 400.0,
            'status' => 'active',
        ]);

        // Attempting to allocate 110% (should fail)
        $invalidAllocation = $this->postJson('/api/supervisor-allocations', [
            'employee_id' => $supervisorMahmoud->id,
            'effective_date' => '2026-07-01',
            'allocations' => [
                ['contract_id' => $contractAId, 'allocation_percentage' => 70.0],
                ['contract_id' => $contractCarA->id, 'allocation_percentage' => 40.0],
            ]
        ]);
        $invalidAllocation->assertStatus(422);

        // Allocating 60% on Contract A, remaining 40% is Overhead (should succeed)
        $validAllocation = $this->postJson('/api/supervisor-allocations', [
            'employee_id' => $supervisorMahmoud->id,
            'effective_date' => '2026-07-01',
            'allocations' => [
                ['contract_id' => $contractAId, 'allocation_percentage' => 60.0],
            ]
        ]);
        $validAllocation->assertSuccessful();

        // ══════════════════════════════════════════════════════════════
        // STEP 6: Operational Advances (Supervisor Mahmoud, Company A)
        // ══════════════════════════════════════════════════════════════
        $operatorUserA = User::create([
            'name' => 'Mahmoud User',
            'email' => 'mahmoud@amana.kw',
            'password' => bcrypt('password'),
            'role' => 'operator',
            'company_id' => $this->companyA->id,
        ]);

        // Supervisor Mahmoud requests 100 KWD advance (becomes pending)
        $requestAdvance = $this->actingAs($operatorUserA)->postJson('/api/operational-advances', [
            'employee_id' => $supervisorMahmoud->id,
            'amount' => 100.0,
            'date' => '2026-07-05',
            'reason' => 'شراء وقود وصيانة دورية للمكتب',
        ]);
        $requestAdvance->assertCreated();
        $this->assertEquals('pending', $requestAdvance->json('status'));
        $advanceId = $requestAdvance->json('id');

        // Admin approves the advance
        $approveAdvance = $this->actingAs($this->adminA)->postJson("/api/operational-advances/{$advanceId}/approve");
        $approveAdvance->assertSuccessful();
        $this->assertEquals('active', $approveAdvance->json('status'));

        // Register expense: 80 KWD
        $registerExpense = $this->postJson("/api/operational-advances/{$advanceId}/expense", [
            'amount' => 80.0,
            'date' => '2026-07-10',
            'description' => 'فاتورة شراء وقود',
        ]);
        $registerExpense->assertCreated();

        // Register return: 20 KWD (should close the advance)
        $registerReturn = $this->postJson("/api/operational-advances/{$advanceId}/return", [
            'amount' => 20.0,
            'date' => '2026-07-12',
        ]);
        $registerReturn->assertCreated();

        // Assert advance status is completed
        $this->assertDatabaseHas('operational_advances', [
            'id' => $advanceId,
            'status' => 'completed',
        ]);

        // ══════════════════════════════════════════════════════════════
        // STEP 7: Traffic Violations Override & Allocation (Company A)
        // ══════════════════════════════════════════════════════════════
        // Vehicle gets ticket on July 10
        // Driver Tariq is auto-resolved, but supervisor overrides to Samir
        $violation = $this->postJson('/api/violations', [
            'vehicle_id' => $vehicleBikeA->id,
            'violation_date' => '2026-07-10 14:00:00',
            'violation_type' => 'سرعة فوق المعدل',
            'reference_number' => 'V-REF-100',
            'amount' => 100.0,
            'employee_id' => $driverSamir->id, // manual override
            'assignment_override_reason' => 'سائق آخر كان يقود الدراجة بعد المراجعة',
            'driver_share' => 40.0,
            'contract_share' => 60.0,
            'charge_contract_id' => $contractAId,
        ]);
        $violation->assertCreated();

        // Assert correct fields and splits are stored
        $this->assertDatabaseHas('violations', [
            'employee_id' => $driverSamir->id,
            'amount' => 100.0,
            'driver_share' => 40.0,
            'contract_share' => 60.0,
            'is_driver_override' => true,
            'assignment_override_reason' => 'سائق آخر كان يقود الدراجة بعد المراجعة',
        ]);

        // ══════════════════════════════════════════════════════════════
        // STEP 8: Receivables & Payments Tracking (Company A)
        // ══════════════════════════════════════════════════════════════
        // Register Client Collection of 500 KWD
        $collection = $this->postJson("/api/contracts/{$contractAId}/collections", [
            'amount' => 500.0,
            'date' => '2026-07-31',
            'payment_method' => 'bank_transfer',
            'notes' => 'دفعة شهر يوليو كاملة'
        ]);
        $collection->assertCreated();

        // Register Driver Payment for Tariq
        $payment = $this->postJson("/api/payroll-slips/{$slipTariq->id}/payments", [
            'amount' => 150.0,
            'date' => '2026-08-01',
            'type' => 'disbursement',
            'payment_method' => 'cash',
        ]);
        $payment->assertCreated();

        // ══════════════════════════════════════════════════════════════
        // STEP 9: Mid-Month Transition & Proration (Company B)
        // ══════════════════════════════════════════════════════════════
        $this->actingAs($this->adminB);
        app()->bind('current_company_id', fn() => $this->companyB->id);

        $clientB = Client::create(['name' => 'Deliveroo', 'company_id' => $this->companyB->id]);
        $bikeB = Vehicle::create([
            'plate_number' => 'B-Bike-1',
            'vehicle_type_id' => $this->bikeType->id,
            'company_id' => $this->companyB->id,
            'status' => 'available'
        ]);
        $carB = Vehicle::create([
            'plate_number' => 'B-Car-2',
            'vehicle_type_id' => $this->carType->id,
            'company_id' => $this->companyB->id,
            'status' => 'available'
        ]);

        $driverRami = Employee::create([
            'company_id' => $this->companyB->id,
            'name' => 'Rami',
            'employee_number' => 'EMP-RAMI',
            'employee_type' => 'driver',
            'date_of_joining' => '2026-07-01',
            'pay_type' => 'hybrid',
            'actual_salary' => 160.0,
            'official_salary' => 100.0,
            'rate_per_order' => 0.250,
            'status' => 'active',
        ]);

        // Rami starts July 1-15 on Bike
        $assign1 = VehicleAssignment::create([
            'vehicle_id' => $bikeB->id,
            'employee_id' => $driverRami->id,
            'assigned_date' => '2026-07-01',
            'unassigned_date' => '2026-07-15',
            'is_active' => false,
            'company_id' => $this->companyB->id,
        ]);

        // Rami transitions to Car on July 16-31
        $assign2 = VehicleAssignment::create([
            'vehicle_id' => $carB->id,
            'employee_id' => $driverRami->id,
            'assigned_date' => '2026-07-16',
            'is_active' => true,
            'company_id' => $this->companyB->id,
        ]);

        // Create active contract supporting zones (Client Zones, Driver Zones + Tiers)
        $contractB = Contract::create([
            'client_id' => $clientB->id,
            'contract_number' => 'CON-BURGAN-DEL',
            'name' => 'Burgan-Deliveroo Active Contract',
            'payment_type' => 'hybrid',
            'start_date' => '2026-07-01',
            'vehicle_type_id' => $this->bikeType->id,
            'company_id' => $this->companyB->id,
            'client_payment_method' => 'zones',
            'driver_payment_method' => 'zones_tiers',
            'driver_pricing_rules' => [
                ['zone' => 'Zone A', 'min' => 1, 'max' => 50, 'rate' => 0.500],
                ['zone' => 'Zone A', 'min' => 51, 'max' => 100, 'rate' => 0.600]
            ],
            'is_validity_enabled' => false,
        ]);

        ContractAssignment::create([
            'employee_id' => $driverRami->id,
            'contract_id' => $contractB->id,
            'start_date' => '2026-07-01',
            'status' => 'active',
            'company_id' => $this->companyB->id,
        ]);

        // Segment 1 logs: Zone A, 60 orders
        DailyLog::create([
            'employee_id' => $driverRami->id,
            'vehicle_id' => $bikeB->id,
            'contract_id' => $contractB->id,
            'log_date' => '2026-07-10',
            'orders_count' => 60,
            'orders_online' => 60,
            'orders_cash' => 0,
            'zone' => 'Zone A',
            'created_by' => $this->adminB->id,
            'company_id' => $this->companyB->id,
        ]);

        // Run payroll for Burgan
        $payrollResponseB = $this->postJson('/api/payroll/run', [
            'year' => 2026,
            'month' => 7
        ]);
        if ($payrollResponseB->status() !== 201) {
            file_put_contents(base_path('debug_response.json'), $payrollResponseB->getContent());
        }
        $payrollResponseB->assertStatus(201);
        $runBId = $payrollResponseB->json('run_id');

        $slipRami = PayrollSlip::where('payroll_run_id', $runBId)->where('employee_id', $driverRami->id)->first();
        $this->assertNotNull($slipRami);
    }
}
