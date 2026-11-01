<?php

namespace App\Services;

use App\Models\PartnerApplication;
use App\Models\PartnerReferral;
use App\Models\User;
use App\Support\PartnerIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PartnerReferralService
{
    public function ensureFor(PartnerApplication $application): PartnerReferral
    {
        abort_unless($application->status === 'approved', 403, 'Partner approval is required.');
        $existing = PartnerReferral::where('partner_application_id', $application->id)->first();
        if ($existing) return $existing;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                return DB::transaction(fn () => PartnerReferral::create([
                    'partner_application_id' => $application->id,
                    'code' => 'TRV-'.Str::upper(Str::random(8)),
                ]));
            } catch (UniqueConstraintViolationException) {
                $existing = PartnerReferral::where('partner_application_id', $application->id)->first();
                if ($existing) return $existing;
            }
        }
        throw new RuntimeException('Could not allocate a unique partner code.');
    }

    public function active(string $code): ?PartnerReferral
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^TRV-[A-Z0-9]{8}$/', $code)) return null;
        return PartnerReferral::where('code', $code)->whereHas('application', fn ($query) => $query->where('status', 'approved'))->first();
    }

    public function forCheckout(?string $code, User $customer): ?PartnerReferral
    {
        if ($code === null || trim($code) === '') return null;
        $referral = $this->active($code);
        if (! $referral || $referral->application->email_hash === PartnerIdentity::emailHash($customer->email)) {
            throw ValidationException::withMessages(['referral_code' => ['This partner code is unavailable for this order. Remove it or use another code.']]);
        }
        return $referral;
    }

    public function url(PartnerReferral $referral): string
    {
        $base = rtrim((string) config('partners.shop_url'), '/');
        $parts = parse_url($base);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Configure a valid partner shop URL.');
        }
        return $base.'/r/'.$referral->code;
    }
}
