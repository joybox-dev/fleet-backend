<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `POST /api/upload` files a document under a named category and refuses any name it does not
 * hold. Three screens posted a name it did not: the salary advance's signed voucher («advances»),
 * the daily log's odometer photo («odometers») and the driver guarantee attachment
 * («guarantees»). Every upload from them came back 422 «The selected category is invalid», and on
 * the advance form that meant no advance could be created at all — its voucher is required, so the
 * failed upload left `voucher_path` empty and the save was refused after it.
 *
 * The list is one constant now, and this test walks the categories the frontend actually posts, so
 * the next screen that uploads something fails here rather than in front of the owner.
 */
class EveryScreenThatUploadsHasACategoryTest extends TestCase
{
    use RefreshDatabase;

    /** Every category the frontend sends, with the screen that sends it. */
    private const SENT_BY_THE_UI = [
        'violations' => 'صورة المخالفة',
        'maintenance' => 'مرفق الصيانة',
        'receipts' => 'سند القبض',
        'expenses' => 'إيصال المصروف',
        'custody' => 'صورة العهدة',
        'documents' => 'مستندات الموظف',
        'handovers' => 'محضر تسليم المركبة',
        'advances' => 'سند صرف السلفة',
        'odometers' => 'صورة العداد',
        'guarantees' => 'مرفق الضمان المالي',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $company = Company::create([
            'name' => 'Upload Co',
            'code' => 'uploadco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $company->id);

        $this->user = User::create([
            'name' => 'Upload Admin',
            'email' => 'admin@upload.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->user);
    }

    public function test_every_category_the_screens_post_is_accepted(): void
    {
        foreach (self::SENT_BY_THE_UI as $category => $screen) {
            $response = $this->postJson('/api/upload', [
                'file' => UploadedFile::fake()->image('doc.png'),
                'category' => $category,
            ]);

            $response->assertCreated();
            $this->assertStringContainsString(
                "uploads/{$category}/",
                (string) $response->json('path'),
                "«{$screen}» يرفع تحت «{$category}» — يجب أن يُقبل ويُخزَّن تحت اسمه"
            );
        }
    }

    public function test_a_category_nobody_uses_is_still_refused(): void
    {
        $this->postJson('/api/upload', [
            'file' => UploadedFile::fake()->image('doc.png'),
            'category' => '../../etc',
        ])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_the_voucher_a_salary_advance_demands_can_actually_be_uploaded(): void
    {
        // The exact call the advance form makes before it saves. It used to 422 here, which is why
        // the advance itself could never be created.
        $path = $this->postJson('/api/upload', [
            'file' => UploadedFile::fake()->image('voucher.png'),
            'category' => 'advances',
        ])->assertCreated()->json('path');

        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
    }
}
