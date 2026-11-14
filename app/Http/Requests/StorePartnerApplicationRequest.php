<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('application'))) {
            $this->merge(['application' => json_decode($this->input('application'), true)]);
        }
        $application = $this->input('application');
        if (is_array($application) && is_array($application['payout'] ?? null)) {
            $application['payout'] = \App\Support\PartnerMobileMoney::normalize(\App\Support\PartnerBankDetails::normalize($application['payout']));
            $this->merge(['application' => $application]);
        }
        if (is_array($application) && is_array($application['profile'] ?? null) && is_string($application['profile']['email'] ?? null)) {
            $application['profile']['email'] = mb_strtolower(trim($application['profile']['email']));
            $this->merge(['application' => $application]);
        }
        if (is_array($application) && is_array($application['profile'] ?? null) && is_string($application['profile']['country'] ?? null)) {
            $country = \App\Support\PartnerCountry::normalize($application['profile']['country']);
            if ($country !== null) {
                $application['profile']['country'] = config('partner_countries.'.$country.'.en');
                $this->merge(['application' => $application]);
            }
        }
    }

    public function rules(): array
    {
        $business = in_array($this->input('application.type'), ['hotel', 'tour_operator', 'event_organiser', 'airline'], true);
        $document = ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'];
        $profile = $this->input('application.profile');
        $named = is_array($profile) && (array_key_exists('firstName', $profile) || array_key_exists('lastName', $profile));

        return [
            'submission_key' => ['required', 'uuid'],
            'application' => ['required', 'array:type,language,profile,identity,audience,payout,consent'],
            'application.type' => ['required', Rule::in(['content_creator', 'tour_guide', 'hotel', 'tour_operator', 'event_organiser', 'airline'])],
            'application.language' => ['required', Rule::in(['en', 'sw'])],
            'application.profile' => ['required', 'array:firstName,lastName,displayName,legalName,country,city,phone,phoneCountry,email'],
            'application.profile.firstName' => [Rule::requiredIf($named), 'string', 'max:70'],
            'application.profile.lastName' => [Rule::requiredIf($named), 'string', 'max:70'],
            'application.profile.displayName' => [Rule::excludeIf($named), Rule::requiredIf(! $named), 'string', 'max:150'],
            'application.profile.legalName' => [Rule::excludeIf($named), Rule::requiredIf(! $named), 'string', 'max:150'],
            'application.profile.country' => ['required', 'string', Rule::in(array_column(config('partner_countries'), 'en'))],
            'application.profile.city' => ['required', 'string', 'max:100'],
            'application.profile.phoneCountry' => ['sometimes', 'required', Rule::in(array_keys(config('partner_phone')))],
            'application.profile.phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{5,14}$/', function ($attribute, $value, $fail) {
                if (! is_string($value) || ! preg_match('/^\+[1-9][0-9]{5,14}$/', $value)) return;
                $plans = config('partner_phone');
                $country = $this->input('application.profile.phoneCountry');
                if ($country !== null) $plans = is_string($country) && isset($plans[$country]) ? [$plans[$country]] : [];
                $digits = substr($value, 1);
                foreach ($plans as $plan) {
                    if (str_starts_with($digits, $plan['prefix']) && in_array(strlen($digits) - strlen($plan['prefix']), $plan['lengths'], true)) return;
                }
                $fail('Enter a complete phone number for the selected country code.');
            }],
            'application.profile.email' => ['required', 'email:rfc,strict', 'max:254', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/'],
            'application.identity' => ['required', 'array:idType,idNumber,brelaNo,tin,licenceNo'],
            'application.identity.idType' => ['required', Rule::in(['nida', 'passport'])],
            'application.identity.idNumber' => ['required', 'string', 'max:100'],
            'application.identity.brelaNo' => [Rule::requiredIf($business), 'nullable', 'string', 'max:100'],
            'application.identity.tin' => [Rule::requiredIf($business), 'nullable', 'string', 'max:100'],
            'application.identity.licenceNo' => ['nullable', 'string', 'max:100'],
            'application.audience' => ['required', 'array:handles,followerRange,rooms,monthlyGuests,annualTours'],
            'application.audience.handles' => ['present', 'array', 'max:6'],
            'application.audience.handles.*' => ['array:platform,handle'],
            'application.audience.handles.*.platform' => ['required', 'distinct', Rule::in(['instagram', 'tiktok', 'youtube', 'facebook', 'x', 'website'])],
            'application.audience.handles.*.handle' => ['nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
                $index = explode('.', $attribute)[3];
                if ($this->input("application.audience.handles.$index.platform") !== 'website' && ! preg_match('/^[A-Za-z0-9._]{2,30}$/', $value)) {
                    $fail('Enter a social handle using 2–30 letters, numbers, dots or underscores.');
                }
            }],
            'application.audience.followerRange' => ['nullable', 'string', 'max:50'],
            'application.audience.rooms' => ['nullable', 'integer', 'min:0'],
            'application.audience.monthlyGuests' => ['nullable', 'integer', 'min:0'],
            'application.audience.annualTours' => ['nullable', 'integer', 'min:0'],
            'application.payout' => ['required', 'array:method,country,accountName,accountNumber,bank,branch,swiftCode,bankAddress,otherDetails,nameConfirmed'],
            'application.payout.method' => ['required', Rule::in(['mpesa', 'airtel_money', 'mixx', 'halopesa', 'bank', 'other'])],
            'application.payout.accountName' => ['required', 'string', 'max:150'],
            'application.payout.country' => \App\Support\PartnerMobileMoney::countryRules(),
            'application.payout.accountNumber' => ['required_unless:application.payout.method,other', 'nullable', 'string', 'max:100', \App\Support\PartnerMobileMoney::accountRule($this->input('application.payout'))],
            'application.payout.bank' => ['required_if:application.payout.method,bank', 'nullable', 'string', 'max:150'],
            'application.payout.branch' => ['nullable', 'string', 'max:150'],
            'application.payout.swiftCode' => ['nullable', 'string', 'max:11', \App\Support\PartnerBankDetails::SWIFT_RULE],
            'application.payout.bankAddress' => ['nullable', 'string', 'max:500'],
            'application.payout.otherDetails' => ['exclude_unless:application.payout.method,other', 'required_if:application.payout.method,other', 'string', 'max:2000'],
            'application.payout.nameConfirmed' => ['accepted'],
            'application.consent' => ['required', 'array:terms,privacy,dataProcessing,accuracy'],
            'application.consent.terms' => ['accepted'],
            'application.consent.privacy' => ['accepted'],
            'application.consent.dataProcessing' => ['accepted'],
            'application.consent.accuracy' => ['accepted'],
            'documents' => ['required', 'array:id_front,id_back,selfie,registration,director_id'],
            'documents.id_front' => ['required', ...$document],
            'documents.id_back' => ['required', ...$document],
            'documents.selfie' => [Rule::requiredIf(! $business), ...$document],
            'documents.registration' => [Rule::requiredIf($business), ...$document],
            'documents.director_id' => [Rule::requiredIf($business), ...$document],
        ];
    }

    public function messages(): array
    {
        return [
            'application.consent.accuracy.accepted' => 'Confirm that the information you entered is accurate before submitting.',
            'documents.*.max' => 'Each document must be 10 MB or smaller.',
            'documents.*.mimes' => 'Use a JPG, PNG, or PDF document.',
        ];
    }

}
