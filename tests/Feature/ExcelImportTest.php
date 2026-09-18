<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ImportLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
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
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Name', 'Num', 'Joining', 'PayType', 'Salary', 'Actual', 'Target', 'BaseComm', 'PremiumComm',
        ];
        $rowData = [
            'Ahmad Driver', 'EMP-7788', '2026-05-01', 'hybrid', '300', '250', '150', '0.250', '0.500',
        ];

        foreach ($headers as $colIndex => $header) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter.'1', $header);
        }
        foreach ($rowData as $colIndex => $val) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter.'2', $val);
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
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Plate', 'Make', 'Model', 'Year', 'Odometer', 'Ownership', 'LastOil', 'OilInterval',
        ];
        $rowData = [
            '77889-KWT', 'Nissan', 'Sunny', '2025', '12000', 'rented', '8000', '5000',
        ];

        foreach ($headers as $colIndex => $header) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter.'1', $header);
        }
        foreach ($rowData as $colIndex => $val) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue($colLetter.'2', $val);
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
        $importService = new ImportService;
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
                ],
            ],
        ];

        $importService->executeImport($importLog, $previewData);

        // 3. Verify it is restored and updated in the database
        $freshEmployee = Employee::find($employee->id);
        $this->assertNotNull($freshEmployee);
        $this->assertFalse($freshEmployee->trashed());
        $this->assertEquals('Ahmad Restored and Updated', $freshEmployee->name);
        $this->assertEquals(350, $freshEmployee->official_salary);
    }

    /**
     * A plate or an employee number belongs to the company that uses it. The entry forms check
     * uniqueness per company, and the importer used to disagree with them: it refused a row whose
     * number some OTHER company already had.
     */
    public function test_a_plate_used_by_another_company_is_not_a_duplicate()
    {
        $this->actingAs($this->user);

        $otherCompany = Company::create([
            'name' => 'Other Company',
            'code' => 'OTHER',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        // company_id is not mass-assignable — the tenant trait fills it — so it is forced here.
        $theirs = Vehicle::forceCreate([
            'plate_number' => 'SHARED-PLATE',
            'make' => 'Nissan',
            'model' => 'Sunny',
            'year' => 2025,
            'company_id' => $otherCompany->id,
        ]);

        $log = $this->import('vehicles', [
            ['Plate', 'Make', 'Model'],
            ['SHARED-PLATE', 'Toyota', 'Camry'],
        ], ['A' => 'plate_number', 'B' => 'make', 'C' => 'model']);

        $this->assertSame(1, $log->rows_imported);
        $this->assertSame(0, $log->rows_failed);

        $ours = Vehicle::where('plate_number', 'SHARED-PLATE')->sole();
        $this->assertSame($this->company->id, (int) $ours->company_id);
        $this->assertSame('Toyota', $ours->make);
        $this->assertSame('Nissan', Vehicle::withoutGlobalScopes()->find($theirs->id)->make, 'the other company is untouched');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────
    // The client's two failed imports (2026-07-26, 2026-08-18) and what was built on the fix
    // ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * A spreadsheet on disk. A value given as a PHP number becomes a numeric cell and a
     * DateTime becomes a real Excel date, so a test can hold what a person's file holds.
     *
     * @param  array<int, array<int, mixed>>  $rows  the first row is the header
     * @param  array<int, int>  $formattedBlankRows  rows styled and validated but left empty, as the template's are
     */
    private function xlsx(array $rows, array $formattedBlankRows = []): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($c + 1).($r + 1);
                if ($value instanceof \DateTimeInterface) {
                    $sheet->setCellValue($coordinate, Date::PHPToExcel($value));
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif (is_string($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                } elseif ($value !== null) {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
        }
        foreach ($formattedBlankRows as $row) {
            $sheet->getStyle("A{$row}:F{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getCell("B{$row}")->getDataValidation()->setType(DataValidation::TYPE_LIST)->setFormula1('"a,b"');
        }

        $path = tempnam(sys_get_temp_dir(), 'import_case');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Upload a file and ask what it would do.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the upload response and the preview
     */
    private function preview(string $entity, array $rows, array $mapping, string $mode = 'create', array $formattedBlankRows = [], ?string $keptFile = null): array
    {
        $this->actingAs($this->user);
        $path = $keptFile ?? $this->xlsx($rows, $formattedBlankRows);

        $upload = $this->postJson('/api/import/upload', [
            'file' => new UploadedFile($path, 'staff.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'entity_type' => $entity,
            'mode' => $mode,
        ])->assertStatus(200)->json();

        $preview = $this->postJson('/api/import/preview', [
            'file_path' => $upload['file_path'],
            'entity_type' => $entity,
            'mapping' => $mapping,
            'mode' => $mode,
        ])->assertStatus(200)->json();

        if ($keptFile === null) {
            @unlink($path);
        }

        return [$upload, $preview];
    }

    /** The whole run — upload, preview, confirm — and the log it leaves. */
    private function import(string $entity, array $rows, array $mapping, string $mode = 'create', array $formattedBlankRows = [], ?string $keptFile = null): ImportLog
    {
        [$upload] = $this->preview($entity, $rows, $mapping, $mode, $formattedBlankRows, $keptFile);

        $logId = $this->postJson('/api/import/confirm', [
            'file_path' => $upload['file_path'],
            'file_hash' => $upload['file_hash'],
            'filename' => $upload['filename'],
            'entity_type' => $entity,
            'mapping' => $mapping,
            'mode' => $mode,
        ])->assertStatus(202)->json('import_log.id');

        return ImportLog::findOrFail($logId);
    }

    private const STAFF_MAPPING = ['A' => 'name', 'B' => 'employee_number', 'C' => 'employee_type', 'D' => 'pay_type', 'E' => 'official_salary'];

    /**
     * The client's own first import, as the log recorded it: name, number, type, pay type and
     * salary — and no joining date, which the template called optional and the table refused.
     * All 106 employees died on the same SQL error; the 93 empty template rows were reported as
     * failures on top of them.
     */
    public function test_the_clients_first_import_now_goes_through()
    {
        $log = $this->import('employees', [
            ['Name', 'Number', 'Type', 'Pay', 'Salary'],
            ['Ahmed Anwar Mohammed Noman', 'TA099', 'overseas', 'fixed', 120],
            ['Ahmed Dafallah Ibrahim Dafallah', 'TA100', 'overseas', 'fixed', 120],
        ], self::STAFF_MAPPING, 'create', [4, 5, 6, 200]);

        $this->assertSame('completed', $log->status);
        $this->assertSame(2, $log->rows_total, 'the empty template rows are not records');
        $this->assertSame(2, $log->rows_imported);
        $this->assertSame(0, $log->rows_failed);
        $this->assertSame('staff.xlsx', $log->original_filename, 'the name the person gave the file');

        $employee = Employee::where('employee_number', 'TA099')->sole();
        $this->assertNull($employee->date_of_joining);
        $this->assertSame('active', $employee->status);
        $this->assertSame('driver', $employee->role_category);
        $this->assertEquals(120, $employee->official_salary);
    }

    public function test_a_row_with_no_text_is_not_a_record_but_a_row_missing_its_number_is_an_error()
    {
        [, $preview] = $this->preview('employees', [
            ['Name', 'Number', 'Type', 'Pay', 'Salary'],
            ['Real Driver', 'E-1', 'overseas', 'fixed', 100],
            // The old template's sample line, left in place: numbers and dropdown values, no text.
            [null, null, 'overseas', 'fixed', 0],
            ['Nameless Number', null, 'overseas', 'fixed', 100],
        ], self::STAFF_MAPPING);

        $this->assertSame(2, $preview['total']);
        $this->assertSame(1, $preview['valid']);
        $this->assertSame([2, 4], array_column($preview['rows'], 'row_number'), 'the file\'s own row numbers');
        $this->assertSame(['«رقم الموظف» مطلوب.'], $preview['rows'][1]['errors']['employee_number']);
    }

    public function test_what_went_wrong_is_said_in_arabic_and_never_as_sql()
    {
        [, $preview] = $this->preview('employees', [
            ['Name', 'Number', 'Type', 'Pay', 'Salary', 'Joined'],
            ['Bad Row', 'E-9', 'visitor', 'fixed', 'a lot', 'someday'],
        ], self::STAFF_MAPPING + ['F' => 'date_of_joining']);

        $errors = $preview['rows'][0]['errors'];
        $this->assertStringContainsString('«نوع الموظف» قيمته غير مقبولة', $errors['employee_type'][0]);
        $this->assertStringContainsString('overseas | local_transfer', $errors['employee_type'][0]);
        $this->assertSame('«الراتب الرسمي» يجب أن يكون رقماً، مثال: 120.500', $errors['official_salary'][0]);
        $this->assertStringContainsString('ليس تاريخاً مفهوماً', $errors['date_of_joining'][0]);

        // A failure nobody foresaw — here, the very error the client's 106 rows died of — reaches
        // the person as a sentence and the log as the cause.
        Exceptions::fake();
        Employee::creating(function () {
            throw new \RuntimeException("SQLSTATE[HY000]: General error: 1364 Field 'x' doesn't have a default value (SQL: insert into `employees` ...)");
        });
        $log = ImportLog::create(['user_id' => $this->user->id, 'entity_type' => 'employees', 'original_filename' => 'x.xlsx', 'file_path' => 'x.xlsx', 'file_hash' => 'h', 'column_mapping' => [], 'status' => 'pending']);
        (new ImportService)->executeImport($log, [[
            'row_number' => 2,
            'is_valid' => true,
            'data' => ['name' => 'Struck By The Database', 'employee_number' => 'E-10', 'official_salary' => 100, 'pay_type' => 'fixed'],
        ]]);

        $this->assertSame('failed', $log->fresh()->status);
        $message = $log->fresh()->errors[0]['errors']['exception'][0];
        $this->assertStringContainsString('تعذّر حفظ هذا الصف', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('insert into', $message);
        Exceptions::assertReported(\RuntimeException::class);
    }

    /**
     * Filling in 128 IBANs, or a column of expiry dates, is an update of people already on file:
     * the file carries the number and the columns to write, and nothing else may move.
     */
    public function test_update_mode_writes_only_the_cells_the_file_fills_in()
    {
        $onLeave = Employee::create([
            'name' => 'Stays As He Is', 'employee_number' => 'E-1', 'date_of_joining' => '2025-01-01', 'pay_type' => 'hybrid',
            'official_salary' => 300, 'actual_salary' => 250, 'status' => 'inactive', 'phone' => '55000001', 'nationality' => 'مصر',
        ]);
        Employee::create(['name' => 'Already Right', 'employee_number' => 'E-2', 'date_of_joining' => '2025-01-01', 'pay_type' => 'fixed', 'official_salary' => 100, 'phone' => '55000002']);

        $rows = [
            ['Number', 'Phone', 'Residence', 'Nationality'],
            ['E-1', '55009999', new \DateTime('2027-04-30'), null],   // a new phone and a date; the empty cell erases nothing
            ['e-2', '55000002', null, null],                          // says what is already on file
            ['E-3', '55000003', null, 'الهند'],                        // not on file: a new record needs its required fields
        ];
        $mapping = ['A' => 'employee_number', 'B' => 'phone', 'C' => 'residence_expiry', 'D' => 'nationality'];

        [, $preview] = $this->preview('employees', $rows, $mapping, 'upsert');
        $this->assertSame(['update', 'unchanged', 'create'], array_column($preview['rows'], 'action'));
        $this->assertSame(['create' => 0, 'restore' => 0, 'update' => 1, 'unchanged' => 1, 'duplicate' => 0], $preview['actions']);
        $this->assertSame(
            [['field' => 'phone', 'label' => 'الهاتف', 'from' => '55000001', 'to' => '55009999'], ['field' => 'residence_expiry', 'label' => 'انتهاء الإقامة', 'from' => null, 'to' => '2027-04-30']],
            $preview['rows'][0]['changes']
        );
        $this->assertFalse($preview['rows'][2]['is_valid']);
        $this->assertArrayHasKey('name', $preview['rows'][2]['errors']);

        $log = $this->import('employees', $rows, $mapping, 'upsert');
        $this->assertSame('upsert', $log->mode);
        $this->assertSame([0, 1, 1, 1], [$log->rows_imported, $log->rows_updated, $log->rows_skipped_duplicate, $log->rows_failed]);

        $fresh = $onLeave->fresh();
        $this->assertSame('55009999', $fresh->phone);
        $this->assertSame('2027-04-30', substr((string) $fresh->residence_expiry, 0, 10));
        $this->assertSame('inactive', $fresh->status, 'the default status never lands on a record that exists');
        $this->assertSame('hybrid', $fresh->pay_type);
        $this->assertEquals(250, $fresh->actual_salary, 'nor does the default salary of zero');
        $this->assertSame('مصر', $fresh->nationality, 'an empty cell erases nothing');
        $this->assertSame(0, Employee::where('employee_number', 'E-3')->count());
    }

    /**
     * What the update mode was built for: the client has 128 employees on file and no IBAN on any
     * of them. One file — number and IBAN — fills them in, and the bank is read off each IBAN.
     */
    public function test_ibans_are_filled_in_for_people_already_on_file()
    {
        $one = Employee::create(['name' => 'First', 'employee_number' => 'E-1', 'date_of_joining' => '2025-01-01', 'pay_type' => 'fixed', 'official_salary' => 120, 'status' => 'on_leave']);
        $two = Employee::create(['name' => 'Second', 'employee_number' => 'E-2', 'date_of_joining' => '2025-01-01', 'pay_type' => 'fixed', 'official_salary' => 120]);
        Employee::create(['name' => 'Third', 'employee_number' => 'E-3', 'date_of_joining' => '2025-01-01', 'pay_type' => 'fixed', 'official_salary' => 120]);

        $rows = [
            ['Number', 'IBAN'],
            ['E-1', 'kw74 nbok 0000 0000 0000 1000 3721 51'],   // as a bank letter prints it
            ['E-2', 'KW52KFHO0000000000001234567891'],          // one digit off
            ['E-3', 'KW74NBOK0000000000001000372151'],          // the same account as E-1
        ];
        $mapping = ['A' => 'employee_number', 'B' => 'iban'];

        [, $preview] = $this->preview('employees', $rows, $mapping, 'upsert');

        $this->assertSame([true, false, false], array_column($preview['rows'], 'is_valid'));
        $this->assertSame(
            [['field' => 'iban', 'label' => 'رقم IBAN', 'from' => null, 'to' => 'KW74NBOK0000000000001000372151'], ['field' => 'bank_name', 'label' => 'البنك', 'from' => null, 'to' => 'بنك الكويت الوطني']],
            $preview['rows'][0]['changes']
        );
        $this->assertStringContainsString('رقم IBAN غير صحيح', $preview['rows'][1]['errors']['iban'][0]);
        $this->assertStringContainsString('مكرر داخل الملف', $preview['rows'][2]['errors']['iban'][0]);

        $log = $this->import('employees', $rows, $mapping, 'upsert');
        $this->assertSame([1, 2], [$log->rows_updated, $log->rows_failed]);

        $this->assertSame('KW74NBOK0000000000001000372151', $one->fresh()->iban, 'stored without the spaces');
        $this->assertSame('بنك الكويت الوطني', $one->fresh()->bank_name);
        $this->assertSame('on_leave', $one->fresh()->status, 'nothing else moved');
        $this->assertNull($two->fresh()->iban);

        // Once E-1 holds it, a later file cannot give the same account to somebody else.
        [, $later] = $this->preview('employees', [['Number', 'IBAN'], ['E-2', 'KW74 NBOK 0000 0000 0000 1000 3721 51']], $mapping, 'upsert');
        $this->assertSame('«رقم IBAN» مسجَّل في النظام لسجل آخر: First.', $later['rows'][0]['errors']['iban'][0]);
    }

    public function test_an_update_that_names_a_deleted_record_says_that_it_is_deleted()
    {
        $gone = Employee::create(['name' => 'Left Last Year', 'employee_number' => 'E-7', 'date_of_joining' => '2024-01-01', 'pay_type' => 'fixed', 'official_salary' => 100]);
        $gone->delete();

        [, $preview] = $this->preview('employees', [['Number', 'Phone'], ['E-7', '55007777']], ['A' => 'employee_number', 'B' => 'phone'], 'upsert');

        $this->assertSame('restore', $preview['rows'][0]['action']);
        $this->assertFalse($preview['rows'][0]['is_valid']);
        $this->assertStringContainsString('محذوف من النظام', $preview['rows'][0]['errors']['_record'][0]);
        $this->assertTrue($gone->fresh()->trashed(), 'a preview writes nothing');
    }

    public function test_adding_only_still_leaves_the_records_it_finds_alone()
    {
        Employee::create(['name' => 'On File', 'employee_number' => 'E-1', 'date_of_joining' => '2025-01-01', 'pay_type' => 'fixed', 'official_salary' => 100]);

        $rows = [['Name', 'Number', 'Type', 'Pay', 'Salary'], ['Renamed In The File', 'E-1', 'overseas', 'fixed', 999], ['New Man', 'E-2', 'overseas', 'fixed', 110]];

        [, $preview] = $this->preview('employees', $rows, self::STAFF_MAPPING);
        $this->assertSame(['duplicate', 'create'], array_column($preview['rows'], 'action'));

        $log = $this->import('employees', $rows, self::STAFF_MAPPING);
        $this->assertSame([1, 0, 1, 0], [$log->rows_imported, $log->rows_updated, $log->rows_skipped_duplicate, $log->rows_failed]);
        $this->assertSame('On File', Employee::where('employee_number', 'E-1')->sole()->name);
    }

    public function test_one_person_cannot_be_written_twice()
    {
        Employee::create(['name' => 'Holder', 'employee_number' => 'E-1', 'date_of_joining' => '2025-01-01', 'pay_type' => 'fixed', 'official_salary' => 100, 'civil_id' => '290010100011']);

        [, $preview] = $this->preview('employees', [
            ['Name', 'Number', 'Type', 'Pay', 'Salary', 'Civil'],
            ['Same Civil Id', 'E-2', 'overseas', 'fixed', 100, '290010100011'],
            ['First Of Two', 'E-3', 'overseas', 'fixed', 100, '290010100022'],
            ['Second Of Two', 'E-3', 'overseas', 'fixed', 100, '290010100033'],
        ], self::STAFF_MAPPING + ['F' => 'civil_id']);

        $this->assertSame('«الرقم المدني» مسجَّل في النظام لسجل آخر: Holder.', $preview['rows'][0]['errors']['civil_id'][0]);
        $this->assertTrue($preview['rows'][1]['is_valid']);
        $this->assertSame('«رقم الموظف» مكرر داخل الملف — ورد قبل ذلك في الصف 3.', $preview['rows'][2]['errors']['employee_number'][0]);
    }

    /**
     * A person's file is not the template: salaries are numeric cells, dates are real Excel dates
     * shown the way the sheet's locale likes, digits may be Arabic, and a status is a word.
     */
    public function test_cells_are_read_as_a_person_writes_them()
    {
        [, $preview] = $this->preview('employees', [
            ['Name', 'Number', 'Type', 'Pay', 'Salary', 'Joined', 'Civil', 'Role', 'Status', 'Licence'],
            ['Typed By Hand', 'E-1', 'استقدام', 'ثابت', 1250.5, new \DateTime('2026-02-03'), 287051500123, 'إداري', 'إجازة', '٣١/١٢/٢٠٢٦'],
        ], self::STAFF_MAPPING + ['F' => 'date_of_joining', 'G' => 'civil_id', 'H' => 'role_category', 'I' => 'status', 'J' => 'driving_license_expiry']);

        $this->assertTrue($preview['rows'][0]['is_valid'], json_encode($preview['rows'][0]['errors'], JSON_UNESCAPED_UNICODE));
        $data = $preview['rows'][0]['data'];
        $this->assertSame('overseas', $data['employee_type']);
        $this->assertSame('fixed', $data['pay_type']);
        $this->assertSame('1250.5', $data['official_salary']);
        $this->assertSame('2026-02-03', $data['date_of_joining'], 'an Excel date, whatever format the cell shows it in');
        $this->assertSame('287051500123', $data['civil_id'], 'twelve digits, not 2.87E+11');
        $this->assertSame('admin', $data['role_category']);
        $this->assertSame('on_leave', $data['status']);
        $this->assertSame('2026-12-31', $data['driving_license_expiry']);
    }

    public function test_a_vehicle_type_is_written_by_its_name()
    {
        $small = VehicleType::create(['company_id' => $this->company->id, 'name' => 'Small Car', 'name_ar' => 'سيارة صغيرة']);
        VehicleType::create(['company_id' => $this->company->id, 'name' => 'Motorcycle', 'name_ar' => 'دراجة نارية']);

        $rows = [['Plate', 'Type', 'Rent', 'Odometer'], ['12/48213', 'سيارة صغيرة', 85, 61420], ['40/11925', 'small car', null, null], ['18/20417', 'شاحنة', null, null]];
        $mapping = ['A' => 'plate_number', 'B' => 'vehicle_type', 'C' => 'rental_price', 'D' => 'odometer_km'];

        [, $preview] = $this->preview('vehicles', $rows, $mapping);
        $this->assertSame([true, true, false], array_column($preview['rows'], 'is_valid'));
        $this->assertStringContainsString('الأنواع المتاحة: سيارة صغيرة، دراجة نارية', $preview['rows'][2]['errors']['vehicle_type'][0]);

        $log = $this->import('vehicles', $rows, $mapping);
        $this->assertSame([2, 1], [$log->rows_imported, $log->rows_failed]);

        $vehicle = Vehicle::where('plate_number', '12/48213')->sole();
        $this->assertSame($small->id, (int) $vehicle->vehicle_type_id);
        $this->assertEquals(85, $vehicle->rental_price);
        $this->assertSame(61420, (int) $vehicle->odometer_km);
        $this->assertSame($small->id, (int) Vehicle::where('plate_number', '40/11925')->sole()->vehicle_type_id, 'the English name works too');

        // Updating a vehicle's type shows the names, not the ids.
        [, $update] = $this->preview('vehicles', [['Plate', 'Type'], ['12/48213', 'دراجة نارية']], ['A' => 'plate_number', 'B' => 'vehicle_type'], 'upsert');
        $this->assertSame(['field' => 'vehicle_type_id', 'label' => 'نوع المركبة', 'from' => 'سيارة صغيرة', 'to' => 'دراجة نارية'], $update['rows'][0]['changes'][0]);
    }

    public function test_the_same_file_may_update_twice_but_not_add_twice()
    {
        // One file on disk, sent three times: a workbook written twice is not byte-identical
        // (it carries its own creation time), and the guard works on the bytes.
        $rows = [['Name', 'Number', 'Type', 'Pay', 'Salary'], ['Only Once', 'E-1', 'overseas', 'fixed', 100]];
        $path = $this->xlsx($rows);
        $this->import('employees', $rows, self::STAFF_MAPPING, 'create', [], $path);

        $again = fn (string $mode) => $this->postJson('/api/import/upload', [
            'file' => new UploadedFile($path, 'staff.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'entity_type' => 'employees',
            'mode' => $mode,
        ]);

        $this->assertSame(1, Employee::count());
        $again('create')->assertStatus(409);
        $again('upsert')->assertStatus(200);
        @unlink($path);
    }

    public function test_the_template_explains_itself_and_carries_no_sample_record()
    {
        $this->actingAs($this->user);

        $path = (new ImportService)->generateTemplate('employees');
        $book = IOFactory::load($path);
        @unlink($path);
        $this->beforeApplicationDestroyed(fn () => $book->disconnectWorksheets());

        $this->assertSame(['البيانات', 'تعليمات'], $book->getSheetNames());
        $data = $book->getSheet(0);
        $this->assertSame('الاسم (إنجليزي) *', $data->getCell('A1')->getValue(), 'a required column is starred');
        $this->assertSame('الاسم (عربي)', $data->getCell('B1')->getValue());
        $this->assertNull($data->getCell('A2')->getValue(), 'no sample line to be imported by mistake');
        $this->assertSame(0, $book->getActiveSheetIndex(), 'the importer reads the active sheet');

        // And a template sent back untouched is an empty file, not 199 failures.
        $fields = collect((new ImportService)->getFields('employees'));
        $this->assertTrue($fields->firstWhere('key', 'employee_number')['identifier']);
        $this->assertFalse($fields->firstWhere('key', 'name')['identifier']);
    }
}
