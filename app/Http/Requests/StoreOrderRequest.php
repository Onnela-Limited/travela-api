<?php

namespace App\Http\Requests;

use App\Models\Esim;
use App\Models\UserEsim;
use App\Support\OrderCheckout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'draft_id' => ['required', 'string', 'max:80'],
            'referral_code' => ['nullable', 'string', 'max:20', 'regex:/^TRV-[A-Z0-9]{8}$/i'],
            'user_id' => ['required', 'integer', 'exists:users,id'],

            'checkoutMode' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:10'],
            'countryName' => ['nullable', 'string', 'max:120'],
            'simType' => ['required', 'string', 'in:esim,physical'],
            'msisdn' => ['nullable', 'string', 'max:20'],
            'esim_id' => ['nullable', 'integer', 'exists:esims,id'],
            'user_esim_id' => ['nullable', 'integer', 'exists:user_esims,id'],

            'trip.destination_country' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'string', 'max:80'],
            'trip.arrival_date' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'date'],
            'trip.departure_date' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'date', 'after:trip.arrival_date'],
            'trip.duration_days' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'integer', 'min:1'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'in:bundle,service'],
            'items.*.bundle_id' => ['required_if:items.*.type,bundle', 'nullable', 'integer', 'exists:bundles,id'],
            'items.*.bundle_name' => ['required', 'string', 'max:120'],
            'items.*.data_amount' => ['nullable', 'integer', 'min:0'],
            'items.*.validity_days' => ['nullable', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.currency' => ['required', 'string', 'size:3'],

            'pricing.subtotal' => ['required', 'numeric', 'min:0'],
            'pricing.discount_amount' => ['required', 'numeric', 'min:0'],
            'pricing.discount_code' => ['nullable', 'string', 'max:40'],
            'pricing.total_amount' => ['required', 'numeric', 'min:0'],
            'pricing.currency' => ['required', 'string', 'size:3'],

            'kyc.passport_id' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'string', 'max:50'],
            'kyc.passport_country' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'string', 'max:10'],
            'kyc.nationality' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'string', 'max:80'],
            'kyc.gender' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'string', 'in:Male,Female,Other'],
            'kyc.reason_for_travel' => [Rule::requiredIf(fn () => ! $this->isTopUpRequest()), 'nullable', 'string', 'max:120'],

            'payment' => ['nullable', 'array'],
            'payment.status' => ['nullable', 'string', 'in:pending'],
            'payment.reference' => ['nullable', 'string', 'max:120'],
            'payment.method' => ['nullable', 'string', 'max:50'],
            'payment.paid_at' => ['nullable', 'date'],

            'order_metadata.source' => ['required', 'string', 'max:40'],
            'order_metadata.platform' => ['required', 'string', 'max:40'],
            'order_metadata.created_at' => ['required', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $kyc = $this->input('kyc', []);
        if (isset($kyc['gender']) && is_string($kyc['gender'])) {
            $g = strtolower(trim($kyc['gender']));
            $map = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
            $kyc['gender'] = $map[$g] ?? (strlen($kyc['gender']) ? ucfirst($g) : $kyc['gender']);
            $this->merge(['kyc' => $kyc]);
        }

        if ($this->filled('country') && is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }

        $trip = $this->input('trip', []);
        if (isset($trip['destination_country']) && is_string($trip['destination_country']) && strlen($trip['destination_country']) <= 3) {
            $trip['destination_country'] = strtoupper($trip['destination_country']);
            $this->merge(['trip' => $trip]);
        }

        $user = $this->user();
        if ($user && ! $user->isAdmin() && ! $user->isAgent()) {
            $this->merge(['user_id' => $user->id]);
        }

        $payment = $this->input('payment');
        if (is_array($payment)) {
            $payment['status'] = 'pending';
            unset($payment['paid_at']);
            $this->merge(['payment' => $payment]);
        }

        if (! $this->isTopUpRequest() || ! $user) {
            return;
        }
        $this->merge(['user_id' => $user->id, 'simType' => Esim::SIM_TYPE_ESIM]);

        $kyc = $user->kyc;
        if ($kyc) {
            $this->merge([
                'kyc' => [
                    'passport_id' => $kyc->passport_id,
                    'passport_country' => $kyc->passport_country,
                    'nationality' => $kyc->nationality,
                    'gender' => $kyc->gender,
                    'reason_for_travel' => $kyc->reason ?? 'Tourism',
                ],
            ]);
        }

        $arrival = $kyc?->arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');
        $departure = $kyc?->departure_date?->format('Y-m-d') ?? now()->addDay()->format('Y-m-d');
        $durationDays = max(1, (int) round((strtotime($departure.' 00:00:00') - strtotime($arrival.' 00:00:00')) / 86400));

        $this->merge([
            'trip' => [
                'destination_country' => strtoupper((string) ($this->input('country') ?: 'TZ')),
                'arrival_date' => $arrival,
                'departure_date' => $departure,
                'duration_days' => $durationDays,
            ],
        ]);

        if ($this->filled('user_esim_id')) {
            $assignment = UserEsim::query()
                ->where('user_id', $user->id)
                ->with('esim')
                ->find($this->input('user_esim_id'));

            if ($assignment?->esim) {
                $this->merge([
                    'msisdn' => $assignment->esim->msisdn,
                    'esim_id' => $assignment->esim_id,
                ]);
            }
        }
    }

    public function isTopUpRequest(): bool
    {
        return OrderCheckout::isTopUp([
            'checkoutMode' => $this->input('checkoutMode'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->isTopUpRequest()) {
                $user = $this->user();
                if (! $user) {
                    $validator->errors()->add('checkoutMode', 'You must be logged in to purchase a top-up.');

                    return;
                }

                if (! $user->kyc) {
                    $validator->errors()->add('kyc', 'Complete KYC before purchasing a data top-up.');
                }

                if (! $this->filled('user_esim_id') && ! $this->filled('msisdn')) {
                    $validator->errors()->add('user_esim_id', 'Select the SIM to top up.');
                }

                if ($this->filled('user_esim_id')) {
                    $owned = UserEsim::query()
                        ->where('user_id', $user->id)
                        ->whereKey($this->input('user_esim_id'))
                        ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
                        ->exists();

                    if (! $owned) {
                        $validator->errors()->add('user_esim_id', 'The selected SIM is not assigned to your account.');
                    }
                }

                if ($this->input('simType') && $this->input('simType') !== Esim::SIM_TYPE_ESIM) {
                    $validator->errors()->add('simType', 'Top-up orders must use an existing eSIM.');
                }

                return;
            }

            $simType = $this->input('simType');
            if (! $simType) {
                return;
            }

            if ($simType === Esim::SIM_TYPE_PHYSICAL) {
                foreach (['msisdn', 'esim_id', 'user_esim_id'] as $field) {
                    if ($this->filled($field)) {
                        $validator->errors()->add(
                            $field,
                            'Physical SIM orders cannot include a SIM assignment at checkout. An agent assigns the card at the counter after payment.',
                        );
                    }
                }
            }

            if ($this->filled('esim_id')) {
                $esim = Esim::find($this->input('esim_id'));
                if ($esim && $esim->sim_type !== $simType) {
                    $validator->errors()->add('esim_id', 'The selected SIM does not match simType.');
                }
            }

            if ($this->filled('msisdn')) {
                $esim = Esim::findByMsisdn((string) $this->input('msisdn'));
                if ($esim && $esim->sim_type !== $simType) {
                    $validator->errors()->add('msisdn', 'The selected SIM does not match simType.');
                }
            }
        });
    }
}
