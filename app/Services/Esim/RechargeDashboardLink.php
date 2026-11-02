<?php

namespace App\Services\Esim;

class RechargeDashboardLink
{
    public static function forNumber(string $msisdn, ?string $reference = null): string
    {
        $base = rtrim((string) config('app.frontend_url', 'https://thetravela.com'), '/');
        if (! $base || str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) $base = 'https://thetravela.com';
        return $base.'/dashboard?'.http_build_query([
            'await_balance' => '1', 'msisdn' => $msisdn, 'recharge' => $reference,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
