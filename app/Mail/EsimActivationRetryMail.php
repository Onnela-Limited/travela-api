<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EsimActivationRetryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $customerName,
        public readonly string $msisdn,
        public readonly string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Travela — try activating your eSIM again');
    }

    public function content(): Content
    {
        $name = e($this->customerName);
        $number = e('+'.ltrim($this->msisdn, '+'));
        $url = e($this->dashboardUrl);
        return new Content(htmlString: "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:24px;color:#112116'>
            <h2>Your eSIM activation is available again</h2>
            <p>Hi {$name}, our team has made the activation button available again for your Travela number <strong>{$number}</strong>.</p>
            <p>Open your dashboard on the phone where you want to install the eSIM, sign in if asked, and tap <strong>Activate eSIM</strong>.</p>
            <p>If the installation does not open, use <strong>Open QR Code</strong> on your dashboard to view the installation details.</p>
            <p>Your phone number, bundles, remaining data and expiry dates have not changed.</p>
            <p style='margin:24px 0'><a href='{$url}' style='display:inline-block;background:#17cf54;color:#112116;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold'>Open dashboard to retry activation</a></p>
            </div>");
    }
}
