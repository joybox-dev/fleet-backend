<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * «المستندات» (client, 2026-10-27: «خليني اكتب اسم المستند وارفق الملف»): a name the user writes and
 * a file, for an employee, a vehicle or the company itself, with an optional expiry.
 */
class DocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Employee $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 10:00:00');

        $this->company = Company::create(['name' => 'Docs Co', 'code' => 'docs', 'enabled_modules' => Company::DEFAULT_MODULES, 'is_active' => true]);
        app()->instance('current_company_id', $this->company->id);

        $this->admin = $this->login('admin', 'admin');
        $this->driver = Employee::create([
            'name' => 'سائق', 'company_id' => $this->company->id, 'status' => 'active',
            'role_category' => 'driver', 'date_of_joining' => '2026-01-01', 'actual_salary' => 200,
        ]);
        $this->vehicle = Vehicle::create([
            'plate_number' => 'D-1', 'make' => 'Toyota', 'status' => 'working', 'company_id' => $this->company->id, 'vehicle_type_id' => 2,
        ]);
    }

    public function test_a_document_is_a_name_and_a_file_for_an_employee_a_vehicle_or_the_company(): void
    {
        $this->actingAs($this->admin)->postJson('/api/documents', ['owner_type' => 'company', 'title' => 'رخصة تجارية'])
            ->assertStatus(422)->assertJsonValidationErrors('file_path');
        $this->actingAs($this->admin)->postJson('/api/documents', ['owner_type' => 'employee', 'title' => 'إقامة', 'file_path' => 'documents/a.pdf'])
            ->assertStatus(422)->assertJsonValidationErrors('owner_id');

        $this->actingAs($this->admin)->postJson('/api/documents', [
            'owner_type' => 'company', 'title' => 'رخصة تجارية', 'file_path' => 'documents/license.pdf', 'expiry_date' => '2026-11-10',
        ])->assertCreated()->assertJsonPath('data.status', 'due')->assertJsonPath('data.days_left', 14);
        $this->actingAs($this->admin)->postJson('/api/documents', [
            'owner_type' => 'employee', 'owner_id' => $this->driver->id, 'title' => 'شهادة خبرة', 'file_path' => 'documents/exp.pdf',
        ])->assertCreated()->assertJsonPath('data.status', 'no_expiry')->assertJsonPath('data.owner_name', 'سائق');
        $id = $this->actingAs($this->admin)->postJson('/api/documents', [
            'owner_type' => 'vehicle', 'owner_id' => $this->vehicle->id, 'title' => 'دفتر المركبة', 'file_path' => 'documents/book.jpg', 'expiry_date' => '2026-10-01',
        ])->assertCreated()->assertJsonPath('data.status', 'expired')->json('data.id');

        $all = $this->actingAs($this->admin)->getJson('/api/documents')->assertOk()->json('data');
        $this->assertCount(3, $all);
        $this->assertSame(['دفتر المركبة'], array_column($this->actingAs($this->admin)->getJson('/api/documents?status=expired')->json('data'), 'title'));
        $this->assertSame(['شهادة خبرة'], array_column($this->actingAs($this->admin)->getJson("/api/documents?owner_type=employee&owner_id={$this->driver->id}")->json('data'), 'title'));
        $this->assertSame(['دفتر المركبة'], array_column($this->actingAs($this->admin)->getJson('/api/documents?search=D-1')->json('data'), 'title'));

        // Renewed: a new expiry; who it belongs to stays as recorded.
        $this->actingAs($this->admin)->putJson("/api/documents/{$id}", ['expiry_date' => '2027-10-01', 'owner_type' => 'company'])
            ->assertOk()->assertJsonPath('data.status', 'valid')->assertJsonPath('data.owner_type', 'vehicle');

        $this->actingAs($this->admin)->deleteJson("/api/documents/{$id}")->assertOk();
        $this->assertSoftDeleted('documents', ['id' => $id]);
    }

    public function test_an_owners_documents_open_to_whoever_works_on_that_owner(): void
    {
        Role::create(['name' => 'شؤون موظفين', 'company_id' => $this->company->id, 'allowed_modules' => ['employees.view', 'employees.edit']]);
        Role::create(['name' => 'أرشيف', 'company_id' => $this->company->id, 'allowed_modules' => ['documents.view']]);
        $hr = $this->login('hr', 'شؤون موظفين');
        $archive = $this->login('archive', 'أرشيف');

        Document::create(['owner_type' => 'company', 'title' => 'عقد إيجار', 'file_path' => 'x.pdf']);
        Document::create(['owner_type' => 'vehicle', 'owner_id' => $this->vehicle->id, 'title' => 'تأمين', 'file_path' => 'y.pdf']);

        // The employees role adds to an employee, sees employees' documents only, and nothing of the company's.
        $this->actingAs($hr)->postJson('/api/documents', ['owner_type' => 'employee', 'owner_id' => $this->driver->id, 'title' => 'إقامة', 'file_path' => 'z.pdf'])->assertCreated();
        $this->actingAs($hr)->postJson('/api/documents', ['owner_type' => 'company', 'title' => 'x', 'file_path' => 'x.pdf'])->assertForbidden();
        $this->assertSame(['إقامة'], array_column($this->actingAs($hr)->getJson('/api/documents')->json('data'), 'title'));
        $this->actingAs($hr)->getJson('/api/documents?owner_type=company')->assertForbidden();

        // The documents permission sees all three, and may not add without its create.
        $this->assertCount(3, $this->actingAs($archive)->getJson('/api/documents')->json('data'));
        $this->actingAs($archive)->postJson('/api/documents', ['owner_type' => 'company', 'title' => 'x', 'file_path' => 'x.pdf'])->assertForbidden();
    }

    public function test_employee_documents_moved_over_with_their_type_written_as_their_name(): void
    {
        $this->assertTrue(Schema::hasTable('employee_documents'));
        $migration = require database_path('migrations/2026_10_27_094104_create_documents_table.php');
        $migration->down();

        DB::table('employee_documents')->insert([
            'employee_id' => $this->driver->id, 'company_id' => $this->company->id, 'document_type' => 'residence',
            'document_number' => '2900', 'file_path' => 'res.pdf', 'expiry_date' => '2027-01-01', 'status' => 'valid',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // «Other» with its name written in the notes, as people did for want of a free name.
        DB::table('employee_documents')->insert([
            'employee_id' => $this->driver->id, 'company_id' => $this->company->id, 'document_type' => 'other',
            'notes' => 'استقاله', 'file_path' => 'resign.pdf', 'status' => 'valid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();

        $moved = Document::orderBy('id')->firstOrFail();
        $this->assertSame(['employee', $this->driver->id, 'إقامة', 'residence', '2900'], [$moved->owner_type, $moved->owner_id, $moved->title, $moved->kind, $moved->document_number]);
        $this->assertSame(['إقامة', 'استقاله'], $this->driver->documents()->orderBy('id')->pluck('title')->all());
    }

    private function login(string $handle, string $role): User
    {
        return User::create([
            'name' => ucfirst($handle), 'email' => "{$handle}@docs.test", 'password' => bcrypt('password'),
            'role' => $role, 'company_id' => $this->company->id,
        ]);
    }
}
