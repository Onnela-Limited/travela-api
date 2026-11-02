<?php

namespace App\Services\Esim;

use App\Mail\EsimRechargeConfirmationMail;
use App\Models\Bundle;
use App\Models\Esim;
use App\Models\User;
use App\Models\UserEsim;
use App\Support\BundleVisibility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CustomerBundleRechargeService
{
    public function __construct(private readonly VodacomSimManagerService $vodacom) {}

    public function submit(User $user, array $data, int $adminId): array
    {
        $lock = Cache::lock('customer-bundle-request:'.$data['reference'], 180);
        abort_unless($lock->get(), 409, 'This recharge is already being submitted.');
        try {
            $existing = DB::table('customer_bundle_recharges')->where('reference', $data['reference'])->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $user->id &&
                    (int) $existing->user_esim_id === (int) $data['user_esim_id'] &&
                    (int) $existing->bundle_id === (int) $data['bundle_id'], 409, 'This reference belongs to another recharge.');
                return $this->result($existing->id);
            }

            $assignment = UserEsim::with('esim')->where('user_id', $user->id)->findOrFail($data['user_esim_id']);
            $bundle = Bundle::findOrFail($data['bundle_id']);
            $sim = $assignment->esim;
            abort_unless($sim?->msisdn && $sim->network_id && ! $sim->partner_bulk_order_id, 422, 'This customer number is unavailable for recharge.');
            abort_unless($bundle->active && $bundle->sim_bundle_id &&
                (int) $bundle->network_id === (int) $sim->network_id && BundleVisibility::visibleTo($user, $bundle),
                422, 'This bundle is unavailable for the selected number.');
            abort_unless(filter_var($user->email, FILTER_VALIDATE_EMAIL), 422, 'The customer needs a valid email address.');

            // Persist before contacting the provider so a fast callback can find the request.
            $id = DB::table('customer_bundle_recharges')->insertGetId([
                'reference' => $data['reference'], 'user_id' => $user->id,
                'user_esim_id' => $assignment->id, 'bundle_id' => $bundle->id, 'requested_by' => $adminId,
                'msisdn' => Esim::normalizeMsisdn($sim->msisdn), 'network_id' => $sim->network_id,
                'product_id' => $bundle->sim_bundle_id, 'bundle_name' => $bundle->name,
                'customer_name' => $user->name, 'recipient' => $user->email,
                'data_mb' => BundleHistoryService::dataMb($bundle),
                'status' => 'pending', 'email_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                $response = $this->vodacom->post('/api/recharge', [], VodacomRechargePayload::normalize([
                    'msisdn' => $sim->msisdn, 'network_id' => $sim->network_id,
                    'product_id' => $bundle->sim_bundle_id, 'reference' => $data['reference'],
                ]));
                $body = $response->json();
                $body = is_array($body) ? $body : [];
                $status = $this->confirmedStatus($body, $response->status());
                DB::table('customer_bundle_recharges')->where('id', $id)->where('status', '!=', 'success')
                    ->update(array_merge(['status' => $status, 'updated_at' => now()], $status === 'success' ? ['completed_at' => now()] : []));
            } catch (Throwable $e) {
                // A timeout may follow a successful recharge: never automatically resubmit it.
                Log::warning('Customer bundle recharge result unconfirmed', ['reference' => $data['reference'], 'error' => $e->getMessage()]);
            }
            $this->sendNotification($id);
            return $this->result($id);
        } finally {
            $lock->release();
        }
    }

    public function callback(array $raw): bool
    {
        $body = VodacomRechargePayload::unwrapResponse($raw);
        $reference = $body['reference'] ?? null;
        $number = $body['msisdn'] ?? null;
        if (! is_string($reference)) return false;
        $row = DB::table('customer_bundle_recharges')->where('reference', $reference)->first();
        if (! $row) return false;
        // A customer-list recharge must never fall through to an older order on this number.
        if (! is_string($number) || Esim::normalizeMsisdn($number) !== $row->msisdn ||
            (isset($body['product_id']) && (int) $body['product_id'] !== (int) $row->product_id)) return true;
        DB::table('customer_bundle_recharges')->where('id', $row->id)->where('status', '!=', 'success')
            ->update(array_merge(['status' => $this->confirmedStatus($body, 200), 'updated_at' => now()],
                $this->confirmedStatus($body, 200) === 'success' ? ['completed_at' => now()] : []));
        $this->sendNotification($row->id);
        return true;
    }

    private function confirmedStatus(array $body, int $httpStatus): string
    {
        $interpreted = VodacomRechargePayload::interpretStatus($body, $httpStatus);
        if ($interpreted === 'failed') return 'failed';
        // Do not treat an empty or unrecognised 200 response as a confirmed recharge.
        return $interpreted === 'success' && VodacomRechargePayload::isSuccessStatus($body, $httpStatus)
            ? 'success' : 'pending';
    }

    private function sendNotification(int $id): void
    {
        $lock = Cache::lock('customer-bundle-email:'.$id, 120);
        if (! $lock->get()) return;
        try {
            $row = DB::table('customer_bundle_recharges')->find($id);
            if ($row->status !== 'success' || $row->email_status !== 'pending') return;
            // Claim once; duplicate callbacks cannot send again, even after a mail timeout.
            $claimed = DB::table('customer_bundle_recharges')->where('id', $id)->where('email_status', 'pending')
                ->update(['email_status' => 'sending', 'updated_at' => now()]);
            if (! $claimed) return;
            try {
                try { app(VodacomBalanceService::class)->requestBalancesForMsisdn($row->msisdn); }
                catch (Throwable $e) { Log::warning('Balance refresh after customer top-up failed', ['recharge_id' => $id, 'error' => $e->getMessage()]); }
                $sent = Mail::to($row->recipient)->send(new EsimRechargeConfirmationMail(
                    customerName: $row->customer_name, msisdn: $row->msisdn, iccid: null,
                    orderReference: $row->reference, bundleName: $row->bundle_name, dashboardUrl: RechargeDashboardLink::forNumber($row->msisdn, $row->reference),
                ));
                $accepted = $sent !== null || Mail::isFake();
                DB::table('customer_bundle_recharges')->where('id', $id)->update([
                    'email_status' => $accepted ? 'sent' : 'unconfirmed',
                    'email_sent_at' => $accepted ? now() : null, 'updated_at' => now(),
                ]);
            } catch (Throwable $e) {
                DB::table('customer_bundle_recharges')->where('id', $id)->update(['email_status' => 'unconfirmed', 'updated_at' => now()]);
                Log::error('Customer bundle notification could not be confirmed', ['recharge_id' => $id, 'error' => $e->getMessage()]);
            }
        } finally {
            $lock->release();
        }
    }

    private function result(int $id): array
    {
        $row = DB::table('customer_bundle_recharges')->find($id);
        $message = match ($row->status) {
            'success' => $row->email_status === 'sent'
                ? 'Bundle added successfully. The customer notification email was submitted.'
                : 'Bundle added successfully, but the notification email could not be confirmed. Do not recharge again to resend the email.',
            'failed' => 'The provider rejected the recharge. No notification email was sent.',
            default => 'Recharge awaiting confirmation. The customer will be emailed once the bundle is confirmed as added.',
        };
        return ['status' => $row->status, 'email_status' => $row->email_status, 'message' => $message, 'reference' => $row->reference];
    }
}
