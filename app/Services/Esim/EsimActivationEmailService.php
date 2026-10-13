<?php

namespace App\Services\Esim;

use App\Mail\EsimActivationQrMail;
use App\Models\Esim;
use App\Models\UserEsim;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EsimActivationEmailService
{
    /**
     * Send the activation welcome email once per assignment when LPA data is available.
     */
    public function sendIfEligible(UserEsim $assignment): bool
    {
        $assignment->refresh();
        $assignment->loadMissing(['esim', 'user', 'order']);

        if ($assignment->activation_email_sent_at !== null) {
            return false;
        }

        if ($assignment->order?->recharge_status !== 'success') {
            return false;
        }

        $esim = $assignment->esim;
        if (! $esim || $esim->sim_type !== Esim::SIM_TYPE_ESIM) {
            return false;
        }

        $qrCodeData = trim((string) ($esim->qr_code_data ?? ''));
        if ($qrCodeData === '') {
            return false;
        }

        $user = $assignment->user;
        $email = trim((string) ($user?->email ?? ''));
        if ($email === '') {
            return false;
        }

        $orderReference = $assignment->order?->draft_id
            ?: $assignment->order?->payment_reference;

        try {
            Mail::to($email)->send(new EsimActivationQrMail(
                customerName: $this->customerName($user?->name),
                msisdn: $esim->msisdn,
                iccid: $esim->iccid,
                orderReference: $orderReference,
                dashboardUrl: $this->customerDashboardUrl(),
                qrCodeData: $qrCodeData,
            ));
        } catch (Throwable $e) {
            Log::error('Failed to send eSIM activation welcome email', [
                'user_esim_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $assignment->forceFill(['activation_email_sent_at' => now()])->save();

        return true;
    }

    private function customerName(?string $name): string
    {
        $trimmed = trim((string) $name);

        return $trimmed !== '' ? $trimmed : 'Traveller';
    }

    private function customerDashboardUrl(): string
    {
        $base = rtrim((string) config('app.frontend_url', 'https://thetravela.com'), '/');

        if ($base === '' || str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) {
            $base = 'https://thetravela.com';
        }

        return $base.'/dashboard';
    }
}
