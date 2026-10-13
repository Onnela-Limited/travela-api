<?php

namespace App\Support;

class ActivationSchedule
{
    public const TIMEZONE = 'Africa/Dar_es_Salaam';
    public const REMINDER_TIME = '00:01';

    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }
}
