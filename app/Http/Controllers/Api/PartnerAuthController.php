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
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'language' => ['nullable', 'in:en,sw'], 'mode'=>['nullable','in:owner,staff'], 'invitation_token'=>['nullable','string','regex:/^[A-Za-z0-9]{64}$/']]);
        $email = mb_strtolower(trim($data['email']));
        $emailHash = PartnerIdentity::emailHash($email);
        $staff=($data['mode'] ?? 'owner')==='staff';
        $member=$staff ? app(\App\Services\PartnerTeamService::class)->loginMember($emailHash,$data['invitation_token'] ?? null) : null;
        $application=$staff ? ($member ? PartnerApplication::find($member->partner_application_id) : null) : PartnerApplication::where('email_hash',$emailHash)->orderByDesc('submitted_at')->orderByDesc('id')->first();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challengeId = (string) Str::uuid();
        $codeHash = Hash::make($code);

        try {
            $issued = DB::transaction(function () use ($emailHash, $application, $challengeId, $codeHash, $member) {
                $previous = DB::table('partner_login_challenges')->where('email_hash', $emailHash)->lockForUpdate()->first();
                if ($previous && Carbon::parse($previous->created_at)->gt(now()->subMinute())) {
                    return false;
                }
                DB::table('partner_login_challenges')->updateOrInsert(['email_hash' => $emailHash], [
                    'id' => $challengeId,
                    'partner_application_id' => $application?->id,
                    'partner_team_member_id'=>$member?->id, 'team_version'=>$member?->version,
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
        $data=$request->validate(['challenge_id'=>['required','uuid'],'code'=>['required','string','regex:/^[0-9]{6}$/']]);
        $initial=DB::table('partner_login_challenges')->where('id',$data['challenge_id'])->first();
        $result=DB::transaction(function()use($data,$initial){
            // Match team mutation lock order before consuming an OTP.
            $application=$initial?->partner_application_id ? PartnerApplication::whereKey($initial->partner_application_id)->lockForUpdate()->first() : null;
            $member=($initial->partner_team_member_id ?? null) ? \App\Models\PartnerTeamMember::whereKey($initial->partner_team_member_id)->lockForUpdate()->first() : null;
            $challenge=DB::table('partner_login_challenges')->where('id',$data['challenge_id'])->lockForUpdate()->first();
            if(!$challenge || $challenge->consumed_at || $challenge->attempts>=5 || Carbon::parse($challenge->expires_at)->lte(now()))return null;
            DB::table('partner_login_challenges')->where('id',$challenge->id)->increment('attempts');
            if(!Hash::check($data['code'],$challenge->code_hash) || !$application)return null;
            if($challenge->partner_team_member_id ?? null){
                $team=app(\App\Services\PartnerTeamService::class);
                if(!$team->validGrant($member,$application,$challenge->email_hash,(int)$challenge->team_version))return null;
                $member=$team->accept($member);
            }elseif($application->email_hash!==$challenge->email_hash)return null;
            DB::table('partner_login_challenges')->where('id',$challenge->id)->update(['consumed_at'=>now()]);
            $token=Str::random(64);
            DB::table('partner_sessions')->insert(['partner_application_id'=>$application->id,'partner_team_member_id'=>$member?->id,'token_hash'=>hash('sha256',$token),
                'expires_at'=>now()->addHours(12),'created_at'=>now(),'updated_at'=>now()]);
            return ['session_token'=>$token,'expires_in'=>43200,'partner'=>$this->partnerPayload($application,$member)];
        },3);
        return $result ? response()->json($result)->header('Cache-Control','no-store') : response()->json(['message'=>'The code is invalid, expired, or already used.'],422)->header('Cache-Control','no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['partner' => $this->partnerPayload($request->attributes->get('partner_application'),$request->attributes->get('partner_team_member'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::table('partner_sessions')->where('id', $request->attributes->get('partner_session_id'))->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function partnerPayload(PartnerApplication $application, ?\App\Models\PartnerTeamMember $member=null): array
    {
        $payload = $application->application_payload;
        if($member)return [
            'reference'=>$application->reference,'status'=>'approved','submittedAt'=>$application->submitted_at->toIso8601String(),
            'role'=>$member->role,'viewer'=>['name'=>$member->details['name'],'email'=>$member->details['email']],
            'referralCode'=>$member->role==='manager' ? app(\App\Services\PartnerReferralService::class)->ensureFor($application)->code : null,
            'type'=>$payload['type'],'language'=>$payload['language'] ?? 'en',
            'profile'=>['displayName'=>$payload['profile']['displayName'] ?? 'Business','legalName'=>'','country'=>'','city'=>'','phone'=>'','email'=>$member->details['email']],
        ];
        return [
            'role'=>'owner','viewer'=>['name'=>$payload['profile']['displayName'] ?? 'Owner','email'=>$payload['profile']['email'] ?? ''],
            'reference' => $application->reference,
            'status' => $application->status,
            'submittedAt' => $application->submitted_at->toIso8601String(),
            ...($application->review_version > 0 ? [
                'reviewMessage' => $application->review_message,
                'reviewedAt' => $application->reviewed_at?->toIso8601String(),
            ] : []),
            'referralCode' => $application->status === 'approved' ? app(\App\Services\PartnerReferralService::class)->ensureFor($application)->code : null,
            'type' => $payload['type'],
            'language' => $payload['language'],
            'profile' => array_intersect_key($payload['profile'], array_flip(['displayName', 'legalName', 'country', 'city', 'phone', 'email'])),
        ];
    }
}
