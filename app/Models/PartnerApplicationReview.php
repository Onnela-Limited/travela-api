<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerApplicationReview extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['message' => 'encrypted', 'created_at' => 'datetime'];
    }
}
