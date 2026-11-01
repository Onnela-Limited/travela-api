<?php

namespace App\Services;

use App\Models\PartnerApplication;
use App\Models\PartnerPayout;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerPayoutService
{
    public function __construct(private PartnerSalesService $sales) {}

    public function balances(PartnerApplication $application, bool $lock = false): array
    {
        $earned = 0;
        $query = $this->sales->orders($application)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $query->chunkById(250, function ($orders) use (&$earned) {
            foreach ($orders as $order) {
                $sale = $this->sales->sale($order);
                if ($sale['state'] === 'confirmed' && $sale['currency'] === 'USD' && $order->paid_at <= now()) {
                    $earned += $this->sales->cents($sale['commission']);
                }
            }
        });
        $payouts = PartnerPayout::where('partner_application_id', $application->id);
        $paid = (int) (clone $payouts)->where('status', 'paid')->sum('amount_cents');
        $reserved = (int) (clone $payouts)->whereIn('status', ['pending', 'processing'])->sum('amount_cents');
        return ['earned' => $earned, 'paid' => $paid, 'reserved' => $reserved,
            'available' => max(0, $earned - $paid - $reserved), 'shortfall' => max(0, $paid + $reserved - $earned)];
    }

    public function destination(PartnerApplication $application): array
    {
        $value = data_get($application->application_payload, 'payout', []);
        return Validator::make(is_array($value) ? $value : [], [
            'method' => ['required', Rule::in(['mpesa', 'airtel_money', 'mixx', 'halopesa', 'bank'])],
            'accountName' => ['required', 'string', 'max:150'], 'accountNumber' => ['required', 'string', 'max:100'],
            'bank' => ['required_if:method,bank', 'nullable', 'string', 'max:150'], 'branch' => ['nullable', 'string', 'max:150'],
            'nameConfirmed' => ['accepted'],
        ])->validate();
    }

    public function masked(array $destination): array
    {
        return ['method' => $destination['method'] ?? '', 'accountName' => $destination['accountName'] ?? '',
            'accountMasked' => '•••• '.mb_substr($destination['accountNumber'] ?? '', -4), 'bank' => $destination['bank'] ?? ''];
    }

    public function summary(PartnerPayout $payout): array
    {
        return ['id' => (string) $payout->id, 'reference' => $payout->reference, 'amount' => $this->sales->money($payout->amount_cents),
            'currency' => $payout->currency, 'status' => $payout->status, 'version' => $payout->version,
            'destination' => $this->masked($payout->destination), 'message' => $payout->message,
            'paymentReference' => $payout->payment_reference, 'transferCurrency' => $payout->transfer_currency,
            'transferAmount' => $payout->transfer_amount_cents === null ? null : $this->sales->money($payout->transfer_amount_cents),
            'createdAt' => $payout->created_at->toIso8601String(), 'paidAt' => $payout->paid_at?->toIso8601String()];
    }

    public function request(PartnerApplication $application, string $key, string $expectedAmount, int $settingsVersion = 0): PartnerPayout
    {
        return DB::transaction(function () use ($application, $key, $expectedAmount, $settingsVersion) {
            $application = PartnerApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_unless($application->status === 'approved', 403);
            $existing = PartnerPayout::where('partner_application_id', $application->id)->where('request_key', $key)->first();
            if ($existing) return $existing;
            abort_unless((int)($application->settings_version ?? 0)===$settingsVersion,409,'Payout details changed. Refresh and confirm the latest destination.');
            if (PartnerPayout::where('partner_application_id', $application->id)->whereIn('status', ['pending', 'processing'])->exists()) {
                throw ValidationException::withMessages(['payout' => 'A payout request is already pending. Wait for its decision before requesting again.']);
            }
            $balances = $this->balances($application, true);
            if ($this->sales->cents($expectedAmount) !== $balances['available']) {
                throw ValidationException::withMessages(['payout' => 'Your balance has changed. Refresh and check the amount before requesting.']);
            }
            if ($balances['available'] < (int) config('partners.minimum_payout_cents', 1000)) {
                throw ValidationException::withMessages(['payout' => 'Your available USD balance is below the minimum payout.']);
            }
            return PartnerPayout::create(['partner_application_id' => $application->id, 'request_key' => $key,
                'reference' => 'PP-'.Str::upper((string) Str::uuid()), 'amount_cents' => $balances['available'], 'currency' => 'USD',
                'destination' => $this->destination($application), 'status' => 'pending']);
        }, 3);
    }

    public function decide(int $id, User $admin, array $data): PartnerPayout
    {
        try {
            return DB::transaction(function () use ($id, $admin, $data) {
                // Same lock order for partner requests and admin decisions.
                $applicationId = PartnerPayout::findOrFail($id)->partner_application_id;
                $application = PartnerApplication::whereKey($applicationId)->lockForUpdate()->firstOrFail();
                $payout = PartnerPayout::whereKey($id)->lockForUpdate()->firstOrFail();
                abort_unless($payout->version === $data['expected_version'], 409, 'This payout has changed. Reload it before continuing.');
                $from = $payout->status;
                $decision = $data['decision'];
                abort_unless(in_array($from, ['pending', 'processing']), 409, 'This payout is already final.');
                if ($decision === 'processing') {
                    abort_unless($from === 'pending', 409, 'This payout is already claimed.');
                    abort_unless($application->status === 'approved', 422, 'Partner approval is required before payment.');
                    abort_if($this->balances($application, true)['shortfall'] > 0, 422, 'Earnings have changed. Reject and reconcile this request before making a transfer.');
                    $payout->processing_admin_id = $admin->id;
                    $payout->processing_admin_name = $admin->name;
                } elseif ($decision === 'paid') {
                    abort_unless($from === 'processing' && $payout->processing_admin_id === $admin->id, 409, 'Only the admin who claimed this payout can record the transfer.');
                    $sent = $this->sales->cents($data['transfer_amount']);
                    if ($data['transfer_currency'] === 'USD' && $sent !== $payout->amount_cents) {
                        throw ValidationException::withMessages(['transfer_amount' => 'The USD transfer must match the requested amount.']);
                    }
                    $payout->payment_reference = strtoupper(trim($data['payment_reference']));
                    $payout->transfer_currency = $data['transfer_currency'];
                    $payout->transfer_amount_cents = $sent;
                    $payout->paid_at = now();
                } else {
                    abort_unless($from === 'pending' || $payout->processing_admin_id === $admin->id, 409, 'Only the claiming admin can release a processing payout.');
                }
                $payout->status = $decision;
                $payout->message = trim($data['message'] ?? '') ?: null;
                $payout->version++;
                $payout->save();
                DB::table('partner_payout_events')->insert(['partner_payout_id' => $payout->id, 'actor_id' => $admin->id, 'actor_name' => $admin->name,
                    'from_status' => $from, 'to_status' => $decision, 'message' => $payout->message === null ? null : Crypt::encryptString($payout->message), 'created_at' => now()]);
                app(PartnerNotificationService::class)->record($application,'payout:'.$payout->id.':'.$payout->version,'payout','Payout update','Payout '.$payout->reference.' is now '.str_replace('_',' ',$payout->status).'. Review the recorded details in your portal.','/dashboard/payouts');
                return $payout;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['payment_reference' => 'This transfer reference has already been recorded. Check the payment history before continuing.']);
        }
    }
}
