<?php

return [
    'bulk_discount_bps' => ['hotel' => 500, 'tour_operator' => 500, 'event_organiser' => 500, 'airline' => null],
    'portal_url' => env('PARTNER_PORTAL_URL', env('APP_ENV') === 'local' ? 'http://localhost:3001' : null),
    'minimum_payout_cents' => 1000,
    // Basis points: published standard rates. Airline contracts require an agreed rate.
    'commission_bps' => [
        'content_creator' => 1000, 'tour_guide' => 1000,
        'hotel' => 1200, 'tour_operator' => 1200, 'event_organiser' => 1200,
        'airline' => null,
    ],
    'shop_url' => env('PARTNER_SHOP_URL', env('APP_ENV') === 'local' ? 'http://localhost:3002' : 'https://thetravela.com'),
];
