<?php

namespace App\Support;

class PartnerIdentity
{
    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'));
    }
}
