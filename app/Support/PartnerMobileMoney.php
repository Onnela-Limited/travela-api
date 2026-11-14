<?php
namespace App\Support;

use Illuminate\Validation\Rule;

class PartnerMobileMoney
{
    public static function normalize(array $payout): array
    {
        $payout = PartnerOtherPayout::normalize($payout);
        if (in_array($payout['method'] ?? null, ['bank', 'other'], true)) unset($payout['country']);
        elseif (is_string($payout['country'] ?? null)) $payout['country'] = strtoupper(trim($payout['country']));
        return $payout;
    }

    public static function countryRules(): array
    {
        return ['sometimes', 'required', 'string', Rule::in(array_keys(config('partner_phone')))];
    }

    public static function accountRule(mixed $payout): \Closure
    {
        return function ($attribute, $value, $fail) use ($payout) {
            // Older accounts without a saved payout country retain their original destination.
            if (!is_array($payout) || in_array($payout['method'] ?? null, ['bank', 'other'], true) || !array_key_exists('country', $payout)) return;
            $country = $payout['country'];
            $plans = config('partner_phone');
            if (!is_string($country) || !isset($plans[$country])) return;
            $plan = $plans[$country];
            if (is_string($value) && preg_match('/^\\+[1-9][0-9]{5,14}$/', $value)) {
                $digits = substr($value, 1);
                if (str_starts_with($digits, $plan['prefix']) && in_array(strlen($digits) - strlen($plan['prefix']), $plan['lengths'], true)) return;
            }
            $fail('Enter a complete mobile money number for the selected country.');
        };
    }
}
