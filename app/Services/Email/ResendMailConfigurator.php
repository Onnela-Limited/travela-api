<?php

namespace App\Services\Email;

class ResendMailConfigurator
{
    /**
     * Use the explicit sender without requiring Resend domain-management access.
     */
    public function resolvedFromAddress(): string
    {
        $configured = trim((string) config('mail.from.address', ''));

        return $configured !== '' ? $configured : $this->defaultFromAddress();
    }

    public function defaultFromAddress(): string
    {
        $domain = (string) config('services.resend.domain', 'thetravela.com');
        $local = (string) config('services.resend.from_local', 'noreply');

        return $local.'@'.$domain;
    }
}
