<?php

namespace Tests\Feature;

use App\Services\UiTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A login that chose English sends X-Ui-Language: en and gets the system's own text in English.
 * Anything else — Arabic, no header — gets exactly what it always got, and a value somebody typed is
 * never translated, whatever the language.
 */
class TheApiAnswersInEnglishWhenAskedTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_english_request_gets_the_error_in_english(): void
    {
        $this->withHeaders(['X-Ui-Language' => 'en'])
            ->postJson('/api/auth/login', ['email' => 'nobody@test.kw', 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Incorrect sign-in details.')
            ->assertJsonPath('message', 'Incorrect sign-in details.');
    }

    public function test_an_arabic_request_and_one_without_a_language_are_untouched(): void
    {
        foreach ([['X-Ui-Language' => 'ar'], []] as $headers) {
            $this->withHeaders($headers)
                ->postJson('/api/auth/login', ['email' => 'nobody@test.kw', 'password' => 'wrong-password'])
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', 'بيانات الدخول غير صحيحة.');
        }
    }

    public function test_a_built_string_is_matched_as_a_template_and_its_values_kept(): void
    {
        $translator = new UiTranslator;

        $this->assertSame('76 orders × 0.3 KWD = 22.8 KWD', $translator->translate('76 طلب × 0.3 د.ك = 22.8 د.ك'));
        $this->assertSame('Sheet 08/2026 approved — month net', $translator->translate('اعتماد كشف 08/2026 — صافي الشهر'));
        // %02d / %d / %s in the code's own sprintf match like any placeholder
        $this->assertSame('Keeta statement saved for 08/2026: 8,156.200 KWD.', $translator->translate('حُفظ كشف كيتا لشهر 08/2026: 8,156.200 د.ك.'));
        // a value that is itself an entry is translated inside the template
        $this->assertSame('Client pricing, vehicle type 2', $translator->translate('تسعير العميل، نوع المركبة 2'));
        // text with no entry comes back unchanged
        $this->assertSame('نص لا يعرفه القاموس', $translator->translate('نص لا يعرفه القاموس'));
    }

    public function test_only_text_fields_are_translated_never_typed_values(): void
    {
        $payload = (new UiTranslator)->translatePayload([
            'message' => 'تم الحذف.',
            'payment_method_label' => 'حسب مستوى كيتا',
            'client' => ['name' => 'طلبات', 'notes' => 'تم الحذف.'],
            'lines' => [['label' => 'خصم يدوي', 'description' => 'خصم يدوي']],
            'errors' => ['amount' => ['المبلغ مطلوب.']],
        ]);

        $this->assertSame('Deleted.', $payload['message']);
        $this->assertSame('By Keeta level', $payload['payment_method_label']);
        $this->assertSame('طلبات', $payload['client']['name']);
        $this->assertSame('تم الحذف.', $payload['client']['notes']);
        $this->assertSame('Manual deduction', $payload['lines'][0]['label']);
        $this->assertSame('خصم يدوي', $payload['lines'][0]['description']);
        $this->assertSame('The amount is required.', $payload['errors']['amount'][0]);
    }
}
