<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EsimRechargeConfirmationMail extends EsimActivationQrMail
{
    public function __construct(
        string $customerName,
        ?string $msisdn,
        ?string $iccid,
        ?string $orderReference,
        public readonly string $bundleName,
        string $dashboardUrl = 'https://thetravela.com/dashboard',
        ?string $qrCodeData = null,
    ) {
        parent::__construct($customerName, $msisdn, $iccid, $orderReference, $dashboardUrl, $qrCodeData);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Travela — your data bundle recharge is complete');
    }

    public function content(): Content
    {
        $name = e($this->customerName);
        $number = e($this->msisdn);
        $bundle = e($this->bundleName);
        $reference = e($this->orderReference);
        $url = e($this->dashboardUrl);
        $qr = $this->qrCodeData ? '<p>Your new eSIM installation QR code is attached. Scan it to install your eSIM.</p>' : '';
        return new Content(htmlString: "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:24px;color:#112116'>
            <h2>Your data bundle is ready</h2><p>Hi {$name}, your data bundle has been successfully added to your Travela number.</p>
            <p><strong>Phone number:</strong> {$number}</p><p><strong>Bundle:</strong> {$bundle}</p>
            <p><strong>Order reference:</strong> {$reference}</p>{$qr}
            <p>Bundle validity starts from the successful recharge.</p><p><a href='{$url}'>View your bundle in your dashboard</a></p></div>");
    }
}
