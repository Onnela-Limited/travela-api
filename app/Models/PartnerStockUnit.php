<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerStockUnit extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['guest'];
    protected function casts(): array { return ['guest' => 'encrypted:array', 'handed_over_at' => 'datetime', 'version' => 'integer']; }
    public function bulkOrder() { return $this->belongsTo(PartnerBulkOrder::class, 'partner_bulk_order_id'); }
    public function esim() { return $this->belongsTo(Esim::class); }
    public function assignment() { return $this->belongsTo(UserEsim::class, 'user_esim_id'); }
    public function order() { return $this->belongsTo(Order::class); }
}
