<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerApplication extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['submission_key', 'email_hash', 'application_payload', 'documents', 'review_message'];

    protected function casts(): array
    {
        return [
            'application_payload' => 'encrypted:array',
            'documents' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'review_version' => 'integer',
            'review_message' => 'encrypted',
        ];
    }
}
