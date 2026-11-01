<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerTeamMember extends Model
{
    protected $guarded=['id'];
    protected $hidden=['details','email_hash','active_email_hash','invitation_hash','request_key'];
    protected function casts(): array
    {
        return ['details'=>'encrypted:array','version'=>'integer','invitation_expires_at'=>'datetime','last_sent_at'=>'datetime','accepted_at'=>'datetime','revoked_at'=>'datetime'];
    }
}
