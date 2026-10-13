<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use App\Services\Esim\QrCode\LpaQrCodeGenerator;
use Illuminate\Queue\SerializesModels;

class EsimActivationQrMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $customerName,
        public readonly ?string $msisdn,
        public readonly ?string $iccid,
        public readonly ?string $orderReference,
        public readonly string $dashboardUrl = 'https://thetravela.com/dashboard',
        public readonly ?string $qrCodeData = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Travela — your eSIM is ready',
        );
    }

    public function content(): Content
    {
        $name = e($this->customerName);
        $dashboardUrl = e($this->dashboardUrl);
        $msisdn = $this->msisdn ? e($this->formatMsisdn($this->msisdn)) : null;
        $iccid = $this->iccid ? e($this->iccid) : null;
        $orderReference = $this->orderReference ? e($this->orderReference) : null;

        $details = '';
        if ($msisdn) {
            $details .= "<p style='color:#666;font-size:15px;margin:0 0 8px;'><strong>Number:</strong> {$msisdn}</p>";
        }
        if ($iccid) {
            $details .= "<p style='color:#666;font-size:15px;margin:0 0 8px;'><strong>ICCID:</strong> {$iccid}</p>";
        }
        if ($orderReference) {
            $details .= "<p style='color:#666;font-size:15px;margin:0 0 8px;'><strong>Order reference:</strong> {$orderReference}</p>";
        }

        return new Content(
            htmlString: "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;'>
                    <h2 style='color:#112116;text-align:center;margin-bottom:8px;'>Welcome to Travela</h2>
                    <p style='color:#666;font-size:16px;line-height:1.5;text-align:center;'>
                        Hi {$name}, your eSIM number is already assigned and your data is ready. All you need to do is install your eSIM.
                    </p>
                    {$details}
                    <p style='color:#666;font-size:15px;line-height:1.6;'>
                        Scan the attached QR code to install your eSIM, or sign in to your dashboard for the QR code and step-by-step install instructions.
                        You can also tap <strong>Activate eSIM</strong> in the dashboard for a one-tap install on supported devices.
                    </p>
                    <p style='text-align:center;margin:28px 0;'>
                        <a href='{$dashboardUrl}' style='display:inline-block;background-color:#17cf54;color:#112116;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:bold;'>
                            Open dashboard
                        </a>
                    </p>
                    <p style='color:#666;font-size:14px;line-height:1.5;'>
                        On iPhone, open <strong>Settings → Cellular → Add eSIM</strong> and scan the QR code from your dashboard.
                        On Android, open <strong>Settings → Network &amp; internet → SIMs → Add eSIM</strong> and scan the code.
                    </p>
                    <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
                    <p style='color:#666;font-size:14px;line-height:1.5;'>
                        After installation, turn on the eSIM line and enable Data Roaming.
                        Your 30-day data validity starts from successful recharge, not from installation.
                    </p>
                    <p style='color:#999;font-size:12px;text-align:center;'>
                        This is an automated message from Travela. Please do not reply to this email.
                    </p>
                </div>
            ",
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        if (! $this->qrCodeData) return [];
        $dataUri = app(LpaQrCodeGenerator::class)->pngDataUri($this->qrCodeData);
        if (! $dataUri) {
            throw new \RuntimeException('Could not generate installation QR PNG. Ensure PHP GD is installed.');
        }
        $bytes = base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true);
        if ($bytes === false) throw new \RuntimeException('Invalid installation QR image.');
        return [Attachment::fromData(fn () => $bytes, 'travela-esim-qr.png')->withMime('image/png')];
    }

    private function formatMsisdn(string $msisdn): string
    {
        $trimmed = trim($msisdn);

        return str_starts_with($trimmed, '+') ? $trimmed : '+'.$trimmed;
    }
}
