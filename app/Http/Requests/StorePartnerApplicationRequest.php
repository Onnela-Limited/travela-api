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
    }

    public function rules(): array
    {
        $business = in_array($this->input('application.type'), ['hotel', 'tour_operator', 'event_organiser', 'airline'], true);
        $document = ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'];

        return [
            'submission_key' => ['required', 'uuid'],
            'application' => ['required', 'array:type,language,profile,identity,audience,payout,consent'],
            'application.type' => ['required', Rule::in(['content_creator', 'tour_guide', 'hotel', 'tour_operator', 'event_organiser', 'airline'])],
            'application.language' => ['required', Rule::in(['en', 'sw'])],
            'application.profile' => ['required', 'array:displayName,legalName,country,city,phone,email'],
            'application.profile.displayName' => ['required', 'string', 'max:150'],
            'application.profile.legalName' => ['required', 'string', 'max:150'],
            'application.profile.country' => ['required', 'string', 'max:100'],
            'application.profile.city' => ['required', 'string', 'max:100'],
            'application.profile.phone' => ['required', 'string', 'regex:/^(?=(?:\D*\d){9})\+?[0-9\s().-]{9,25}$/'],
            'application.profile.email' => ['required', 'email', 'max:254'],
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
            'application.payout' => ['required', 'array:method,accountName,accountNumber,bank,branch,nameConfirmed'],
            'application.payout.method' => ['required', Rule::in(['mpesa', 'airtel_money', 'mixx', 'halopesa', 'bank'])],
            'application.payout.accountName' => ['required', 'string', 'max:150'],
            'application.payout.accountNumber' => ['required', 'string', 'max:100'],
            'application.payout.bank' => ['required_if:application.payout.method,bank', 'nullable', 'string', 'max:150'],
            'application.payout.branch' => ['nullable', 'string', 'max:150'],
            'application.payout.nameConfirmed' => ['accepted'],
            'application.consent' => ['required', 'array:terms,privacy,dataProcessing'],
            'application.consent.terms' => ['accepted'],
            'application.consent.privacy' => ['accepted'],
            'application.consent.dataProcessing' => ['accepted'],
            'documents' => ['required', 'array:id_front,id_back,selfie,registration,director_id'],
            'documents.id_front' => ['required', ...$document],
            'documents.id_back' => ['required', ...$document],
            'documents.selfie' => [Rule::requiredIf(! $business), ...$document],
            'documents.registration' => [Rule::requiredIf($business), ...$document],
            'documents.director_id' => [Rule::requiredIf($business), ...$document],
        ];
    }
}
