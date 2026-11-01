<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPayout extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['request_key', 'destination', 'message'];
    protected function casts(): array
    {
        return ['destination' => 'encrypted:array', 'message' => 'encrypted', 'amount_cents' => 'integer',
            'transfer_amount_cents' => 'integer', 'processing_admin_id' => 'integer', 'version' => 'integer', 'paid_at' => 'datetime'];
    }
}
