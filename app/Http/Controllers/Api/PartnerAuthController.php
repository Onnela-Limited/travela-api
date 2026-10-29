<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PartnerLoginCodeMail;
use App\Models\PartnerApplication;
use App\Support\PartnerIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class PartnerAuthController extends Controller
{
    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'language' => ['nullable', 'in:en,sw']]);
        $email = mb_strtolower(trim($data['email']));
        $emailHash = PartnerIdentity::emailHash($email);
        $application = PartnerApplication::where('email_hash', $emailHash)->orderByDesc('submitted_at')->orderByDesc('id')->first();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challengeId = (string) Str::uuid();
        $codeHash = Hash::make($code);

        try {
            $issued = DB::transaction(function () use ($emailHash, $application, $challengeId, $codeHash) {
                $previous = DB::table('partner_login_challenges')->where('email_hash', $emailHash)->lockForUpdate()->first();
                if ($previous && Carbon::parse($previous->created_at)->gt(now()->subMinute())) {
                    return false;
                }
                DB::table('partner_login_challenges')->updateOrInsert(['email_hash' => $emailHash], [
                    'id' => $challengeId,
                    'partner_application_id' => $application?->id,
                    'code_hash' => $codeHash,
                    'attempts' => 0,
                    'expires_at' => now()->addMinutes(10),
                    'consumed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $issued = false;
        }
        if (! $issued) {
            return response()->json(['message' => 'Please wait before requesting another code.', 'retry_after' => 60], 429)
                ->header('Retry-After', '60')->header('Cache-Control', 'no-store');
        }

        if ($application) {
            try {
                Mail::to($email)->send(new PartnerLoginCodeMail($code, $data['language'] ?? 'en'));
            } catch (Throwable $exception) {
                DB::table('partner_login_challenges')->where('id', $challengeId)->update(['consumed_at' => now()]);
                // Never log the code, address, or provider response containing private data.
                Log::warning('Partner sign-in email delivery failed.', ['exception_type' => get_class($exception)]);
            }
        }

        // Same response whether or not an application exists for this email.
        return response()->json(['challenge_id' => $challengeId, 'expires_in' => 600, 'retry_after' => 60], 202)
            ->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $result = DB::transaction(function () use ($data) {
            $challenge = DB::table('partner_login_challenges')->where('id', $data['challenge_id'])->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at || $challenge->attempts >= 5 || Carbon::parse($challenge->expires_at)->lte(now())) {
                return null;
            }
            DB::table('partner_login_challenges')->where('id', $challenge->id)->increment('attempts');
            if (! Hash::check($data['code'], $challenge->code_hash)) {
                return null;
            }
            $application = $challenge->partner_application_id ? PartnerApplication::find($challenge->partner_application_id) : null;
            if (! $application || $application->email_hash !== $challenge->email_hash) {
                return null;
            }
            DB::table('partner_login_challenges')->where('id', $challenge->id)->update(['consumed_at' => now()]);
            $token = Str::random(64);
            DB::table('partner_sessions')->insert([
                'partner_application_id' => $application->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(12),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['session_token' => $token, 'expires_in' => 43200, 'partner' => $this->partnerPayload($application)];
        });

        return $result
            ? response()->json($result)->header('Cache-Control', 'no-store')
            : response()->json(['message' => 'The code is invalid, expired, or already used.'], 422)->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['partner' => $this->partnerPayload($request->attributes->get('partner_application'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::table('partner_sessions')->where('id', $request->attributes->get('partner_session_id'))->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function partnerPayload(PartnerApplication $application): array
    {
        $payload = $application->application_payload;
        return [
            'reference' => $application->reference,
            'status' => $application->status,
            'submittedAt' => $application->submitted_at->toIso8601String(),
            ...($application->review_version > 0 ? [
                'reviewMessage' => $application->review_message,
                'reviewedAt' => $application->reviewed_at?->toIso8601String(),
            ] : []),
            'type' => $payload['type'],
            'language' => $payload['language'],
            'profile' => array_intersect_key($payload['profile'], array_flip(['displayName', 'legalName', 'country', 'city', 'phone', 'email'])),
        ];
    }
}
