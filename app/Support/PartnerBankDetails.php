<?php
namespace App\Support;

class PartnerBankDetails
{
    public const SWIFT_RULE = 'regex:/^[A-Z0-9]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/';

    public static function accountRule(mixed $payout): \Closure
    {
        return function ($attribute, $value, $fail) use ($payout) {
            if (!is_array($payout) || ($payout['method'] ?? null) !== 'bank') return;
            if (!is_string($value) || !preg_match('/\A[0-9]{1,100}\z/', $value)) {
                $fail('Enter digits only for the bank account number (up to 100 digits).');
            }
        };
    }

    public static function normalize(array $payout): array
    {
        foreach (['swiftCode', 'bankAddress'] as $field) {
            if (($payout['method'] ?? null) !== 'bank') $payout[$field] = '';
            elseif (is_string($payout[$field] ?? null)) $payout[$field] = trim($payout[$field]);
        }
        if (is_string($payout['swiftCode'] ?? null)) $payout['swiftCode'] = strtoupper($payout['swiftCode']);
        return $payout;
    }
}
