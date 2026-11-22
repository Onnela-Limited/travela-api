<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerReferral extends Model
{
    protected $guarded = ['id'];

    // Preserve the unique historical database key; publish its eight-character code everywhere.
    public function getCodeAttribute(string $value): string
    {
        return str_starts_with($value, 'TRV-') ? substr($value, 4) : $value;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PartnerApplication::class, 'partner_application_id');
    }
}
