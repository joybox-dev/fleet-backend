<?php

namespace App\Rules;

use App\Helpers\Iban as IbanReader;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A real IBAN: the right length for its country, and its own check digits agree with the rest.
 * Spaces and dashes are ignored, so it may be pasted the way a bank prints it.
 */
class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! IbanReader::isValid($value)) {
            $fail('رقم IBAN غير صحيح — تأكد منه حرفاً حرفاً (الآيبان الكويتي 30 خانة ويبدأ بـ KW).');
        }
    }
}
