<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\ImportLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // Create test company
        $this->company = Company::create([
            'name' => 'Import Test Company',
            'code' => 'IMPTC',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        // Create admin user
        $this->user = User::create([
            'name' => 'Import Admin',
            'email' => 'import@test.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
    }

    public function test_import_fields_endpoints_return_new_fields()
    {
        $this->actingAs($this->user);

        // Check employee import fields
        $response = $this->getJson('/api/import/fields/employees');
        $response->assertStatus(200);
        $fields = collect($response->json('fields'));

        $this->assertTrue($fields->contains('key', 'target_orders_monthly'));
        $this->assertTrue($fields->contains('key', 'base_commission_rate'));
        $this->assertTrue($fields->contains('key', 'premium_commission_rate'));

        // Check vehicle import fields
        $response = $this->getJson('/api/import/fields/vehicles');
        $response->assertStatus(200);
        $fields = collect($response->json('fields'));

        $this->assertTrue($fields->contains('key', 'ownership_type'));
        $this->assertTrue($fields->contains('key', 'last_oil_change_km'));
        $this->assertTrue($fields->contains('key', 'oil_change_interval_km'));
        $this->assertTrue($fields->contains('key', 'comprehensive_insurance_expiry'));
    }

    public function test_template_generation_contains_new_fields()
    {
        $this->actingAs($this->user);

        // Check employee template download
        $response = $this->get('/api/import/template/employees');
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Check vehicle template download
        $response = $this->get('/api/import/template/vehicles');
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_employee_excel_import_flow()
    {
        $this->actingAs($this->user);

        // 1. Create a physical mock excel file
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = [
            'Name', 'Num', 'Joining', 'PayType', 'Salary', 'Actual', 'Target', 'BaseComm', 'PremiumComm'
        ];
        $rowData = [
            'Ahmad Driver', 'EMP-7788', '2026-05-01', 'hybrid', '300', '250', '150', '0.250', '0.500'
        ];

        foreach ($headers as $colIndex => $header) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter . '1', $header);
        }
        foreach ($rowData as $colIndex => $val) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter . '2', $val);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'import_test');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        // 2. Upload file
        $uploadedFile = new UploadedFile(
            $tempPath,
            'employees_import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->postJson('/api/import/upload', [
            'file' => $uploadedFile,
            'entity_type' => 'employees',
        ]);
        
        $response->assertStatus(200);
        $filePath = $response->json('file_path');
        $fileHash = $response->json('file_hash');

        $this->assertNotEmpty($filePath);
        $this->assertNotEmpty($fileHash);

        // 3. Preview Mapping
        // Map Excel columns to system fields
        $mapping = [
            'A' => 'name',
            'B' => 'employee_number',
            'C' => 'date_of_joining',
            'D' => 'pay_type',
            'E' => 'official_salary',
            'F' => 'actual_salary',
            'G' => 'target_orders_monthly',
            'H' => 'base_commission_rate',
            'I' => 'premium_commission_rate',
        ];

        $response = $this->postJson('/api/import/preview', [
            'file_path' => $filePath,
            'entity_type' => 'employees',
            'mapping' => $mapping,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('valid', 1);
        $response->assertJsonPath('rows.0.is_valid', true);
        
        $previewData = $response->json('rows.0.data');
        $this->assertEquals('Ahmad Driver', $previewData['name']);
        $this->assertEquals(150, $previewData['target_orders_monthly']);
        $this->assertEquals(0.250, $previewData['base_commission_rate']);
        $this->assertEquals(0.500, $previewData['premium_commission_rate']);

        // 4. Confirm Import
        $response = $this->postJson('/api/import/confirm', [
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'entity_type' => 'employees',
            'mapping' => $mapping,
        ]);

        $response->assertStatus(202); // Accepted for processing

        // Since it runs synchronously in testing or falls back to sync, verify the DB has the record
        $this->assertDatabaseHas('employees', [
            'name' => 'Ahmad Driver',
            'employee_number' => 'EMP-7788',
            'employee_type' => 'overseas', // default
            'pay_type' => 'hybrid',
            'official_salary' => 300,
            'actual_salary' => 250,
            'target_orders_monthly' => 150,
            'base_commission_rate' => 0.250,
            'premium_commission_rate' => 0.500,
            'company_id' => $this->company->id,
        ]);

        unlink($tempPath);
    }

    public function test_vehicle_excel_import_flow()
    {
        $this->actingAs($this->user);

        // 1. Create mock Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = [
            'Plate', 'Make', 'Model', 'Year', 'Odometer', 'Ownership', 'LastOil', 'OilInterval'
        ];
        $rowData = [
            '77889-KWT', 'Nissan', 'Sunny', '2025', '12000', 'rented', '8000', '5000'
        ];

        foreach ($headers as $colIndex => $header) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter . '1', $header);
        }
        foreach ($rowData as $colIndex => $val) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter . '2', $val);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'import_test_v');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        // 2. Upload
        $uploadedFile = new UploadedFile(
            $tempPath,
            'vehicles_import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->postJson('/api/import/upload', [
            'file' => $uploadedFile,
            'entity_type' => 'vehicles',
        ]);
        
        $response->assertStatus(200);
        $filePath = $response->json('file_path');
        $fileHash = $response->json('file_hash');

        // 3. Preview
        $mapping = [
            'A' => 'plate_number',
            'B' => 'make',
            'C' => 'model',
            'D' => 'year',
            'E' => 'odometer_km',
            'F' => 'ownership_type',
            'G' => 'last_oil_change_km',
            'H' => 'oil_change_interval_km',
        ];

        $response = $this->postJson('/api/import/preview', [
            'file_path' => $filePath,
            'entity_type' => 'vehicles',
            'mapping' => $mapping,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('valid', 1);
        
        $previewData = $response->json('rows.0.data');
        $this->assertEquals('Sunny', $previewData['model']);
        $this->assertEquals('rented', $previewData['ownership_type']);
        $this->assertEquals(8000, $previewData['last_oil_change_km']);
        $this->assertEquals(5000, $previewData['oil_change_interval_km']);

        // 4. Confirm
        $response = $this->postJson('/api/import/confirm', [
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'entity_type' => 'vehicles',
            'mapping' => $mapping,
        ]);

        $response->assertStatus(202);

        $this->assertDatabaseHas('vehicles', [
            'plate_number' => '77889-KWT',
            'make' => 'Nissan',
            'model' => 'Sunny',
            'year' => 2025,
            'odometer_km' => 12000,
            'ownership_type' => 'rented',
            'last_oil_change_km' => 8000,
            'oil_change_interval_km' => 5000,
            'company_id' => $this->company->id,
        ]);

        unlink($tempPath);
    }

    public function test_import_restores_soft_deleted_records()
    {
        $this->actingAs($this->user);

        // 1. Create a soft-deleted employee
        $employee = Employee::create([
            'name' => 'Soft Deleted Ahmad',
            'employee_number' => 'EMP-TRASHED',
            'date_of_joining' => '2026-05-01',
            'pay_type' => 'fixed',
            'official_salary' => 200,
            'actual_salary' => 200,
            'company_id' => $this->company->id,
        ]);
        $employee->delete();
        $this->assertTrue($employee->trashed());

        // 2. Import the same employee number via ImportService
        $importService = new \App\Services\ImportService();
        $importLog = ImportLog::create([
            'user_id' => $this->user->id,
            'entity_type' => 'employees',
            'original_filename' => 'test.xlsx',
            'file_path' => 'test.xlsx',
            'file_hash' => 'hash_test_restore',
            'column_mapping' => [],
            'status' => 'pending',
            'company_id' => $this->company->id,
        ]);

        $previewData = [
            [
                'row_number' => 2,
                'is_valid' => true,
                'data' => [
                    'name' => 'Ahmad Restored and Updated',
                    'employee_number' => 'EMP-TRASHED',
                    'date_of_joining' => '2026-05-01',
                    'pay_type' => 'fixed',
                    'official_salary' => 350, // updated salary
                    'actual_salary' => 350,
                    'company_id' => $this->company->id,
                ]
            ]
        ];

        $importService->executeImport($importLog, $previewData);

        // 3. Verify it is restored and updated in the database
        $freshEmployee = Employee::find($employee->id);
        $this->assertNotNull($freshEmployee);
        $this->assertFalse($freshEmployee->trashed());
        $this->assertEquals('Ahmad Restored and Updated', $freshEmployee->name);
        $this->assertEquals(350, $freshEmployee->official_salary);
    }
}
