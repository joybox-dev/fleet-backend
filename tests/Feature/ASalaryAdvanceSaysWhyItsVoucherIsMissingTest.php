<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «The voucher path field is required» — an English sentence naming a database column, on an Arabic
 * screen — was what the owner saw, and it came back in two different situations he could not tell
 * apart: he had attached a voucher and the upload had silently failed, or he had not attached one.
 * The first was a broken upload category; this covers the message itself, so the second says what
 * to do about it.
 */
class ASalaryAdvanceSaysWhyItsVoucherIsMissingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $company = Company::create([
            'name' => 'Advance Co',
            'code' => 'advanceco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $company->id);

        $user = User::create([
            'name' => 'Advance Admin',
            'email' => 'admin@advance.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->driver = Employee::create([
            'name' => 'Advance Driver',
            'employee_number' => 'EMP-ADV-1',
            'company_id' => $company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);

        $this->actingAs($user);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $this->driver->id,
            'amount' => 100,
            'monthly_installment' => 25,
            'advance_date' => '2026-09-07',
            'reason' => 'سلفة',
        ], $overrides);
    }

    public function test_a_missing_voucher_is_refused_in_arabic_and_says_what_to_do(): void
    {
        $response = $this->postJson('/api/salary-advances', $this->payload())->assertStatus(422);

        $message = (string) $response->json('errors.voucher_path.0');
        $this->assertStringContainsString('سند الصرف', $message);
        $this->assertDoesNotMatchRegularExpression(
            '/voucher_path|field is required/i',
            $message,
            'the message must not name a database column at the person reading it'
        );
    }

    public function test_the_whole_journey_works_once_the_voucher_uploads(): void
    {
        // The upload the form does first — the step that used to fail with an invalid category.
        $path = $this->postJson('/api/upload', [
            'file' => UploadedFile::fake()->image('voucher.png'),
            'category' => 'advances',
        ])->assertCreated()->json('path');

        $this->postJson('/api/salary-advances', $this->payload(['voucher_path' => $path]))
            ->assertCreated();

        $advance = SalaryAdvance::where('employee_id', $this->driver->id)->first();
        $this->assertNotNull($advance);
        $this->assertSame($path, $advance->voucher_path);
        $this->assertSame(4, (int) $advance->total_installments);
        $this->assertEqualsWithDelta(100.0, (float) $advance->remaining_balance, 0.0005);
        $this->assertSame('active', $advance->status);
    }

    public function test_the_other_required_fields_answer_in_arabic_too(): void
    {
        foreach (['employee_id', 'amount', 'monthly_installment', 'advance_date'] as $field) {
            $payload = $this->payload(['voucher_path' => 'uploads/advances/v.png']);
            unset($payload[$field]);

            $message = (string) $this->postJson('/api/salary-advances', $payload)
                ->assertStatus(422)
                ->json("errors.{$field}.0");

            $this->assertDoesNotMatchRegularExpression(
                '/field is required/i',
                $message,
                "«{$field}» still answers in English"
            );
        }
    }
}
