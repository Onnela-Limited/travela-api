<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PartnerLoginCodeMail extends Mailable
{
    public function __construct(public readonly string $code, public readonly string $language = 'en') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->language === 'sw' ? 'Msimbo wa kuingia Travela Partners' : 'Your Travela Partners sign-in code');
    }

    public function content(): Content
    {
        $title = $this->language === 'sw' ? 'Ingia Travela Partners' : 'Sign in to Travela Partners';
        $note = $this->language === 'sw'
            ? 'Msimbo huu unaisha baada ya dakika 10 na unaweza kutumika mara moja tu. Usiushiriki na mtu yeyote. Kama hukuomba kuingia, puuza barua hii.'
            : 'This code expires in 10 minutes and can be used only once. Do not share it. If you did not request a sign-in code, ignore this email.';

        return new Content(htmlString: "<div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:24px'><h2>$title</h2><p style='font-size:32px;letter-spacing:8px;font-weight:bold'>$this->code</p><p>$note</p></div>");
    }
}
