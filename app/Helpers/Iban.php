<?php

namespace App\Helpers;

/**
 * Reading an IBAN: the form it is stored in, whether it is a real one, and — for a Kuwaiti
 * account — which bank it belongs to.
 *
 * An IBAN carries its own check: the two digits after the country code make the whole number
 * leave a remainder of 1 when divided by 97, so a mistyped digit is caught without asking any bank.
 */
class Iban
{
    /** Lengths are fixed per country; these are the ones a Kuwaiti employer meets. */
    private const LENGTHS = [
        'KW' => 30, 'SA' => 24, 'AE' => 23, 'BH' => 22, 'QA' => 29, 'OM' => 23,
        'JO' => 30, 'EG' => 29, 'LB' => 28, 'IQ' => 23, 'TR' => 26, 'GB' => 22,
    ];

    /**
     * A Kuwaiti IBAN names its bank in the four letters after the check digits — the first four
     * of the bank's SWIFT code.
     */
    private const KUWAIT_BANKS = [
        'NBOK' => 'بنك الكويت الوطني',
        'KFHO' => 'بيت التمويل الكويتي',
        'GULB' => 'بنك الخليج',
        'COMB' => 'البنك التجاري الكويتي',
        'ABKK' => 'البنك الأهلي الكويتي',
        'BRGN' => 'بنك برقان',
        'KWIB' => 'بنك الكويت الدولي',
        'BBYN' => 'بنك بوبيان',
        'WRBA' => 'بنك وربة',
        'BKME' => 'البنك الأهلي المتحد',
        'IBOK' => 'بنك الكويت الصناعي',
    ];

    /** As stored: no spaces or dashes, upper case, Western digits. Empty for nothing. */
    public static function normalize(?string $iban): string
    {
        $iban = strtr((string) $iban, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return strtoupper(preg_replace('/[\s\-‐-―]+/u', '', $iban) ?? '');
    }

    public static function isValid(?string $iban): bool
    {
        $iban = self::normalize($iban);

        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $expected = self::LENGTHS[substr($iban, 0, 2)] ?? null;
        if ($expected !== null && strlen($iban) !== $expected) {
            return false;
        }

        // Move the first four characters to the end, read letters as numbers (A = 10 … Z = 35),
        // and take the remainder by 97 a few digits at a time — the number is far too long for an int.
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $value = ctype_digit($char) ? $char : (string) (ord($char) - 55);
            $remainder = (int) ($remainder.$value) % 97;
        }

        return $remainder === 1;
    }

    /** The bank a Kuwaiti IBAN belongs to, or null when the IBAN does not say. */
    public static function bankName(?string $iban): ?string
    {
        $iban = self::normalize($iban);

        return str_starts_with($iban, 'KW') ? (self::KUWAIT_BANKS[substr($iban, 4, 4)] ?? null) : null;
    }

    /** For reading: groups of four. */
    public static function format(?string $iban): string
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }
}
