<?php

namespace App\Services\Esim;

use App\Mail\EsimActivationQrMail;
use App\Models\Esim;
use App\Models\UserEsim;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EsimActivationEmailService
{
    public function ineligibleReason(UserEsim $assignment): ?string
    {
        $assignment->loadMissing(['esim', 'user', 'order']);
        if (! $assignment->order || $assignment->order->recharge_status !== 'success') {
            return 'The data recharge must complete successfully before sending the QR code.';
        }
        if ((int) $assignment->order->user_id !== (int) $assignment->user_id) {
            return 'The SIM assignment does not belong to this order customer.';
        }
        if ($assignment->esim?->sim_type !== Esim::SIM_TYPE_ESIM) {
            return 'QR email is only available for assigned eSIMs.';
        }
        if (trim((string) $assignment->esim->qr_code_data) === '') {
            return 'The assigned eSIM has no installation QR code.';
        }
        if (! filter_var(trim((string) $assignment->user?->email), FILTER_VALIDATE_EMAIL)) {
            return 'The customer needs a valid email address.';
        }
        return null;
    }

    public function sendIfEligible(UserEsim $assignment): bool
    {
        return $this->send($assignment, false);
    }

    public function resend(UserEsim $assignment, int $adminId): bool
    {
        return $this->send($assignment, true, $adminId);
    }

    private function send(UserEsim $assignment, bool $resend, ?int $adminId = null): bool
    {
        $lock = Cache::lock('esim-activation-email:'.$assignment->id, 120);
        if (! $lock->get()) {
            if ($resend) abort(409, 'An email for this SIM is already being sent. Check delivery status before retrying.');
            return false;
        }
        try {
            $assignment->refresh();
            if (! $resend && $assignment->activation_email_sent_at !== null) return false;
            $reason = $this->ineligibleReason($assignment);
            if ($reason) {
                if ($resend) abort(422, $reason);
                return false;
            }
            $latest = DB::table('esim_email_attempts')->where('user_esim_id', $assignment->id)->latest('id')->first();
            if ($latest && now()->diffInSeconds(\Carbon\Carbon::parse($latest->created_at), true) < 60) {
                if ($resend) abort(429, 'Please wait 60 seconds before resending this QR code.');
                return false;
            }
            $email = trim($assignment->user->email);
            $attemptId = DB::table('esim_email_attempts')->insertGetId([
                'order_id' => $assignment->order_id, 'user_esim_id' => $assignment->id,
                'requested_by' => $adminId, 'recipient' => $email, 'status' => 'sending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                $sent = Mail::to($email)->send(new EsimActivationQrMail(
                    customerName: trim((string) $assignment->user->name) ?: 'Traveller',
                    msisdn: $assignment->esim->msisdn,
                    iccid: $assignment->esim->iccid,
                    orderReference: $assignment->order->draft_id ?: $assignment->order->payment_reference,
                    dashboardUrl: $this->customerDashboardUrl(),
                    qrCodeData: trim($assignment->esim->qr_code_data),
                ));
            } catch (Throwable $e) {
                DB::table('esim_email_attempts')->where('id', $attemptId)->update(['status' => 'failed', 'updated_at' => now()]);
                Log::error('Failed to send eSIM activation email', ['attempt_id' => $attemptId, 'error' => $e->getMessage()]);
                return false;
            }
            // Laravel's Resend transport puts the provider UUID in this header,
            // rather than in the SMTP Message-ID. Never query Resend with an SMTP ID.
            $providerId = $sent?->getOriginalMessage()->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString();
            DB::table('esim_email_attempts')->where('id', $attemptId)->update([
                'provider_id' => $providerId, 'status' => $providerId ? 'sent' : 'untracked',
                'sent_at' => $sent ? now() : null, 'updated_at' => now(),
            ]);
            // A cancelled send must not suppress the automatic email forever.
            if ($sent !== null || Mail::isFake()) {
                $assignment->forceFill(['activation_email_sent_at' => now()])->save();
                return true;
            }
            return false;
        } finally {
            $lock->release();
        }
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
