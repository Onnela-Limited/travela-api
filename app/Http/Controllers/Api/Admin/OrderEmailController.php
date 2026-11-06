<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\UserEsim;
use App\Services\Esim\EsimActivationEmailService;
use App\Services\Esim\OrderEmailPreparationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OrderEmailController extends Controller
{
    public function show(Order $order, EsimActivationEmailService $service, OrderEmailPreparationService $preparation)
    {
        $assignments = UserEsim::with(['esim', 'user', 'order'])->where('order_id', $order->id)->get();
        $attempts = DB::table('esim_email_attempts')->where('order_id', $order->id)->latest('id')->limit(30)->get();
        $warning = null;
        // Refresh only the latest attempt per SIM; older attempts remain audit history.
        foreach ($attempts->unique('user_esim_id') as $attempt) {
            if (! $attempt->provider_id || ($attempt->checked_at && \Carbon\Carbon::parse($attempt->checked_at)->gt(now()->subSeconds(15)))) continue;
            $key = config('services.resend.key');
            if (! $key) { $warning = 'Delivery checks require a Resend API key with email read access.'; continue; }
            try {
                $response = Http::withToken($key)->acceptJson()->timeout(8)->get('https://api.resend.com/emails/'.rawurlencode($attempt->provider_id));
                if (! $response->successful() || ! is_string($response->json('last_event'))) {
                    $warning = 'Delivery status could not be refreshed. Check Resend email read access or try again later.';
                    continue;
                }
                $attempt->status = $response->json('last_event');
                $attempt->checked_at = now()->toDateTimeString();
                DB::table('esim_email_attempts')->where('id', $attempt->id)->update([
                    'status' => $attempt->status, 'checked_at' => $attempt->checked_at, 'updated_at' => now(),
                ]);
            } catch (\Throwable) {
                $warning = 'Delivery status could not be refreshed. Try again later.';
            }
        }
        $prepareReason = $preparation->unavailableReason($order);
        $retryReason = $preparation->verifiedRetryReason($order);
        $lastAttempt = $preparation->lastAttemptAt($order);
        $body = \App\Services\Esim\VodacomRechargePayload::unwrapResponse($order->recharge_response ?? []);
        $providerMessage = $body['message'] ?? $body['Message'] ?? $body['error'] ?? null;
        $errors = collect([data_get($order->metadata, 'recharge_error'), $providerMessage])
            ->merge(data_get($order->metadata, 'fulfillment.errors', []))
            ->merge($order->orderItems->map(fn ($item) => data_get($item->metadata, 'recharge.error')))
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => mb_substr($value, 0, 2000))->unique()->values();
        return response()->json(['success' => true, 'data' => [
            'can_prepare' => $prepareReason === null,
            'prepare_reason' => $prepareReason,
            'can_retry_verified' => $retryReason === null && $prepareReason !== null,
            'retry_reason' => $retryReason,
            'retry_token' => $preparation->retryToken($order),
            'recharge_status' => $order->recharge_status,
            'recharge_diagnostics' => [
                'reference' => $order->recharge_reference,
                'http_status' => $order->recharge_http_status ?: null,
                'last_attempt_at' => $lastAttempt?->toIso8601String(),
                'retry_available_at' => $lastAttempt?->copy()->addMinutes(10)->toIso8601String(),
                'errors' => $errors,
            ],
            'prepare_label' => $assignments->isEmpty() ? 'Assign number, recharge & email'
                : ($order->recharge_status === 'success' ? 'Email recharge confirmation' : 'Recharge bundle & email'),
            'assignments' => $assignments->map(fn ($a) => [
                'id' => $a->id, 'iccid' => $a->esim?->iccid, 'msisdn' => $a->esim?->msisdn,
                'recipient' => $a->user?->email,
                'can_resend' => $service->ineligibleReason($a) === null,
                'reason' => $service->ineligibleReason($a),
                'previously_sent_at' => $a->activation_email_sent_at,
            ]),
            'attempts' => $attempts->map(fn ($a) => [
                'id' => $a->id, 'assignment_id' => $a->user_esim_id, 'recipient' => $a->recipient,
                'status' => $a->status, 'sent_at' => $a->sent_at, 'created_at' => $a->created_at,
                'checked_at' => $a->checked_at, 'requested_by' => $a->requested_by,
            ]),
            'warning' => $warning,
        ]]);
    }

    public function resend(Request $request, Order $order, EsimActivationEmailService $service)
    {
        $data = $request->validate(['assignment_id' => ['required', 'integer']]);
        $assignment = UserEsim::where('order_id', $order->id)->findOrFail($data['assignment_id']);
        abort_unless($service->resend($assignment, $request->user()->id), 502,
            'The email could not be confirmed as sent. Check email delivery status before trying again.');
        return response()->json(['success' => true, 'message' => 'Email submitted. Check delivery status to confirm delivery.']);
    }

    public function prepare(Request $request, Order $order, OrderEmailPreparationService $preparation)
    {
        $data = $request->validate(['verified_missing_bundle' => ['sometimes', 'boolean'],
            'expected_retry_token' => ['required_if:verified_missing_bundle,true', 'nullable', 'string', 'size:64']]);
        return response()->json(['success' => true, 'data' => $preparation->prepareAndSend($order, $request->user()->id,
            (bool) ($data['verified_missing_bundle'] ?? false), $data['expected_retry_token'] ?? null)]);
    }
}
