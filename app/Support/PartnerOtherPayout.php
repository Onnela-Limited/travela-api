<?php
namespace App\Support;

class PartnerOtherPayout
{
    public static function normalize(array $payout): array
    {
        if (($payout['method'] ?? null) !== 'other') {
            unset($payout['otherDetails']);
            return $payout;
        }
        foreach (['accountNumber', 'bank', 'branch', 'swiftCode', 'bankAddress'] as $field) $payout[$field] = '';
        unset($payout['country']);
        if (is_string($payout['otherDetails'] ?? null)) $payout['otherDetails'] = trim($payout['otherDetails']);
        return $payout;
    }
}
