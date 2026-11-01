<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerBulkOrder extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['request_key', 'message'];
    protected function casts(): array
    {
        return ['message' => 'encrypted', 'paid_at' => 'datetime', 'quantity' => 'integer', 'version' => 'integer',
            'network_id' => 'integer', 'catalog_unit_cents' => 'integer', 'unit_cents' => 'integer', 'total_cents' => 'integer',
            'discount_bps' => 'integer', 'received_cents' => 'integer'];
    }
}
