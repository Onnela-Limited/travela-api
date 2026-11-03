<?php

namespace App\Services\Esim;

use App\Mail\EsimActivationRetryMail;
use App\Models\Esim;
use App\Models\User;
use App\Models\UserEsim;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CustomerActivationRetryService
{
    public function allow(User $user, int $assignmentId, int $adminId, string $requestId): array
    {
        $lock = Cache::lock('customer-activation-retry:'.$assignmentId, 120);
        abort_unless($lock->get(), 409, 'An activation retry is already being enabled for this number.');
        $resultKey = 'customer-activation-retry-result:'.$adminId.':'.$requestId;
        try {
            $cached = Cache::get($resultKey);
            if (is_array($cached)) {
                abort_unless($cached['user_id'] === (int) $user->id && $cached['user_esim_id'] === $assignmentId,
                    409, 'This request was used for another number.');
                return $cached;
            }
            $lastKey = 'customer-activation-retry-last:'.$assignmentId;
            abort_if(Cache::has($lastKey), 429, 'Please wait 60 seconds before enabling activation or sending this email again.');

            $assignment = DB::transaction(function () use ($user, $assignmentId, $adminId, $requestId) {
                $row = UserEsim::with('esim')->where('user_id', $user->id)->lockForUpdate()->findOrFail($assignmentId);
                abort_unless($row->esim?->sim_type === Esim::SIM_TYPE_ESIM, 422, 'This action is only available for eSIMs.');
                abort_unless(trim((string) $row->esim->msisdn) !== '' && trim((string) $row->esim->qr_code_data) !== '',
                    422, 'This eSIM needs a phone number and installation details before activation can be retried.');
                abort_unless(filter_var($user->email, FILTER_VALIDATE_EMAIL), 422, 'The customer needs a valid email address.');
                $plan = app(UserEsimOrderLinkService::class)->planWindowForAssignment($row);
                abort_unless($plan['plan_started_at'] && ! $plan['plan_expired'], 422,
                    'This number needs an active data plan before activation can be reopened.');
                $previous = $row->device_activated_at?->toIso8601String();
                $row->forceFill(['device_activated_at' => null])->save();
                Log::info('Admin reopened customer eSIM installation', [
                    'requested_by' => $adminId, 'user_id' => $user->id, 'user_esim_id' => $row->id,
                    'request_id' => $requestId, 'previous_device_activated_at' => $previous,
                ]);
                return $row;
            });

            $result = ['activation_enabled' => true, 'user_id' => (int) $user->id, 'user_esim_id' => $assignmentId,
                'email_status' => 'unconfirmed', 'message' => 'The activation button is available again. The notification email could not be confirmed.'];
            // Save before contacting mail: retrying a lost response must not reset a later installation.
            Cache::put($resultKey, $result, now()->addDay());
            Cache::put($lastKey, true, 60);
            try {
                $base = rtrim((string) config('app.frontend_url', 'https://thetravela.com'), '/');
                if (! $base || str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) $base = 'https://thetravela.com';
                $url = $base.'/dashboard?'.http_build_query([
                    'retry_activation' => '1', 'user_esim_id' => $assignmentId, 'msisdn' => $assignment->esim->msisdn,
                ], '', '&', PHP_QUERY_RFC3986);
                $sent = Mail::to($user->email)->send(new EsimActivationRetryMail($user->name, $assignment->esim->msisdn, $url));
                if ($sent !== null || Mail::isFake()) {
                    $result['email_status'] = 'sent';
                    $result['message'] = 'The activation button is available again. A retry activation email was submitted to the customer.';
                }
            } catch (Throwable $e) {
                Log::error('eSIM retry activation email could not be confirmed', [
                    'user_esim_id' => $assignmentId, 'request_id' => $requestId, 'error' => $e->getMessage(),
                ]);
            }
            Cache::put($resultKey, $result, now()->addDay());
            return $result;
        } finally {
            $lock->release();
        }
    }
}
