<?php
namespace App\Http\Requests;

use App\Support\PartnerApplicationDocuments;
use Illuminate\Validation\Rule;

class RevisePartnerApplicationRequest extends StorePartnerApplicationRequest {
    protected function prepareForValidation(): void {
        parent::prepareForValidation();
        if (is_string($this->input('retained_documents'))) $this->merge(['retained_documents'=>json_decode($this->input('retained_documents'), true)]);
    }
    public function rules(): array {
        $rules = parent::rules(); unset($rules['submission_key']);
        $rules['request_key'] = ['required','uuid'];
        $rules['expected_version'] = ['required','integer','min:0'];
        $rules['retained_documents'] = ['present','array','max:5'];
        $rules['retained_documents.*'] = ['string','distinct',Rule::in(PartnerApplicationDocuments::KINDS)];
        $rules['documents'] = ['sometimes','array:id_front,id_back,selfie,registration,director_id'];
        $app = $this->attributes->get('partner_application');
        $retained = PartnerApplicationDocuments::retained($app, is_array($this->input('retained_documents')) ? $this->input('retained_documents') : []);
        $required = PartnerApplicationDocuments::required(is_string($this->input('application.type')) ? $this->input('application.type') : null);
        foreach (PartnerApplicationDocuments::KINDS as $kind) $rules['documents.'.$kind] = [Rule::requiredIf(in_array($kind,$required,true) && !isset($retained[$kind])), 'file','mimes:jpg,jpeg,png,pdf','max:10240'];
        $rules['application.profile.email'][] = function ($attribute,$value,$fail) use ($app) {
            if (!is_string($value) || mb_strtolower(trim($value)) !== mb_strtolower(trim($app->application_payload['profile']['email']))) $fail('The sign-in email cannot be changed when resubmitting an application.');
        };
        return $rules;
    }
}
