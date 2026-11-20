<?php
namespace App\Services;

use App\Models\{PartnerApplication, PartnerTeamMember};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Crypt, DB, Hash};
use Illuminate\Support\Str;

class PartnerAutomaticSignIn
{
    public function accounts(string $emailHash): array
    {
        $accounts=[];
        $owner=PartnerApplication::where('email_hash',$emailHash)->orderByDesc('submitted_at')->orderByDesc('id')->first();
        if($owner) $accounts[]=['id'=>'owner:'.$owner->id,'application_id'=>$owner->id,'member_id'=>null,'version'=>null];
        $member=app(PartnerTeamService::class)->loginMember($emailHash,null);
        if($member) $accounts[]=['id'=>'staff:'.$member->id,'application_id'=>$member->partner_application_id,'member_id'=>$member->id,'version'=>$member->version];
        return $accounts;
    }
    public function remember(string $challengeId,array $accounts): void
    {
        DB::table('partner_login_choices')->where('created_at','<',now()->subDay())->delete();
        DB::table('partner_login_choices')->insert(['challenge_id'=>$challengeId,'accounts'=>Crypt::encryptString(json_encode($accounts,JSON_THROW_ON_ERROR)),'created_at'=>now()]);
    }
    public function verify(string $challengeId,string $code): ?array
    {
        return $this->locked($challengeId,function($challenge,$choices,$accounts)use($code){
            if($challenge->consumed_at || $challenge->attempts>=5 || Carbon::parse($challenge->expires_at)->lte(now())) return null;
            DB::table('partner_login_challenges')->where('id',$challenge->id)->increment('attempts');
            if(!Hash::check($code,$challenge->code_hash) || !$accounts) return null;
            DB::table('partner_login_challenges')->where('id',$challenge->id)->update(['consumed_at'=>now()]);
            if(count($accounts)===1) return $this->session($accounts[0]);
            $token=Str::random(64);
            DB::table('partner_login_choices')->where('challenge_id',$challenge->id)->update(['selection_token_hash'=>hash('sha256',$token),'selection_expires_at'=>now()->addMinutes(5)]);
            return ['selection_token'=>$token,'accounts'=>array_map(fn($account)=>[
                'id'=>$account['id'],'name'=>(string)data_get($account['application']->application_payload,'profile.displayName','Partner account'),
                'reference'=>$account['application']->reference,'role'=>$account['member']?->role ?? 'owner',
            ],$accounts)];
        });
    }
    public function select(string $challengeId,string $token,string $accountId): ?array
    {
        return $this->locked($challengeId,function($challenge,$choices,$accounts)use($token,$accountId){
            if(!$challenge->consumed_at || !$choices->selection_token_hash || !$choices->selection_expires_at || Carbon::parse($choices->selection_expires_at)->lte(now()) || !hash_equals($choices->selection_token_hash,hash('sha256',$token))) return null;
            foreach($accounts as $account) if($account['id']===$accountId) {
                DB::table('partner_login_choices')->where('challenge_id',$challenge->id)->update(['selection_token_hash'=>null,'selection_expires_at'=>null]);
                return $this->session($account);
            }
            return null;
        });
    }
    private function locked(string $challengeId,callable $action): ?array
    {
        $initial=DB::table('partner_login_choices')->where('challenge_id',$challengeId)->first();
        if(!$initial) return null;
        $snapshots=json_decode(Crypt::decryptString($initial->accounts),true,512,JSON_THROW_ON_ERROR);
        return DB::transaction(function()use($challengeId,$snapshots,$action){
            // Same lock order as team changes: applications, members, then login challenges.
            $applications=PartnerApplication::whereIn('id',array_column($snapshots,'application_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $members=PartnerTeamMember::whereIn('id',array_filter(array_column($snapshots,'member_id')))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $challenge=DB::table('partner_login_challenges')->where('id',$challengeId)->lockForUpdate()->first();
            $choices=DB::table('partner_login_choices')->where('challenge_id',$challengeId)->lockForUpdate()->first();
            if(!$challenge || !$choices) return null;
            $accounts=[];
            foreach($snapshots as $snapshot) {
                $application=$applications->get($snapshot['application_id']);
                if(!$application) continue;
                $member=$snapshot['member_id'] ? $members->get($snapshot['member_id']) : null;
                if($snapshot['member_id']) {
                    if(!$member || $member->status!=='active' || !app(PartnerTeamService::class)->validGrant($member,$application,$challenge->email_hash,(int)$snapshot['version'])) continue;
                } elseif($application->email_hash!==$challenge->email_hash) continue;
                $accounts[]=['id'=>$snapshot['id'],'application'=>$application,'member'=>$member];
            }
            return $action($challenge,$choices,$accounts);
        },3);
    }
    private function session(array $account): array
    {
        $token=Str::random(64);
        DB::table('partner_sessions')->insert(['partner_application_id'=>$account['application']->id,'partner_team_member_id'=>$account['member']?->id,
            'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHours(12),'created_at'=>now(),'updated_at'=>now()]);
        return ['session_token'=>$token,'expires_in'=>43200,'application'=>$account['application'],'member'=>$account['member']];
    }
}
