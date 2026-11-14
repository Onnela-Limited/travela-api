<?php

namespace App\Support;

use Illuminate\Support\Str;

class PartnerCountry
{
    private static function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim($value))));
    }

    public static function normalize(string $value): ?string
    {
        $key = self::key($value);
        $aliases = ['unitedstatesofamerica' => 'US','usa' => 'US','uk' => 'GB','greatbritain' => 'GB','unitedrepublicoftanzania' => 'TZ','tanzaniaunitedrepublicof' => 'TZ'];
        if (isset($aliases[$key])) return $aliases[$key];
        foreach (config('partner_countries') as $code => $labels) {
            if ($key === strtolower($code) || $key === self::key($labels['en']) || $key === self::key($labels['sw'])) return $code;
        }
        return null;
    }
}
