<?php

namespace App\Services;

use App\Mail\PartnerTeamInvitationMail;
use App\Models\{PartnerApplication, PartnerTeamMember};
use App\Support\PartnerIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Crypt, DB, Log, Mail};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerTeamService
{
    public function business(PartnerApplication $app): bool
    {
        return $app->status==='approved' && in_array(data_get($app->application_payload,'type'),['hotel','tour_operator','event_organiser','airline'],true);
    }
    public function summary(PartnerTeamMember $member): array
    {
        return ['id'=>(string)$member->id,'name'=>$member->details['name'],'email'=>$member->details['email'],'role'=>$member->role,'status'=>$member->status,'version'=>$member->version,
            'deliveryStatus'=>$member->delivery_status,'invitationExpiresAt'=>$member->invitation_expires_at?->toIso8601String(),
            'lastSentAt'=>$member->last_sent_at?->toIso8601String(),'acceptedAt'=>$member->accepted_at?->toIso8601String(),'createdAt'=>$member->created_at->toIso8601String()];
    }
    public function invitation(string $token): ?PartnerTeamMember
    {
        if(!preg_match('/^[A-Za-z0-9]{64}$/',$token))return null;
        $member=PartnerTeamMember::where('invitation_hash',hash('sha256',$token))->where('status','invited')->where('invitation_expires_at','>',now())->first();
        $app=$member?PartnerApplication::find($member->partner_application_id):null;
        return $app && $this->business($app) ? $member : null;
    }
    public function loginMember(string $emailHash,?string $token): ?PartnerTeamMember
    {
        $member=$token ? $this->invitation($token) : PartnerTeamMember::where('active_email_hash',$emailHash)->where('status','active')->first();
        $app=$member?PartnerApplication::find($member->partner_application_id):null;
        return $member && $member->email_hash===$emailHash && $app && $this->business($app) ? $member : null;
    }
    public function validGrant(?PartnerTeamMember $member,PartnerApplication $app,string $emailHash,int $version): bool
    {
        return $member && $member->partner_application_id==$app->id && $this->business($app) && $member->email_hash===$emailHash
            && $member->active_email_hash===$emailHash && $member->version===$version && in_array($member->role,['manager','front_desk'],true)
            && ($member->status==='active' || $member->status==='invited' && $member->invitation_hash && $member->invitation_expires_at?->isFuture());
    }
    public function accept(PartnerTeamMember $member): PartnerTeamMember
    {
        if($member->status==='invited'){
            $member->status='active';$member->accepted_at=now();$member->invitation_hash=null;$member->invitation_expires_at=null;$member->delivery_status='sent';$member->version++;$member->save();
            $this->event($member,'staff',$member->id,'accepted');
        }
        return $member;
    }
    public function invite(PartnerApplication $app,array $data,int $sessionId): array
    {
        $this->portalUrl();
        try{
            [$member,$token]=DB::transaction(function()use($app,$data,$sessionId){
                $app=PartnerApplication::whereKey($app->id)->lockForUpdate()->firstOrFail();abort_unless($this->business($app),403);
                $existing=PartnerTeamMember::where('partner_application_id',$app->id)->where('request_key',strtolower($data['request_key']))->first();
                if($existing)return [$existing,null];
                $hash=PartnerIdentity::emailHash($data['email']);
                if($hash===$app->email_hash)throw ValidationException::withMessages(['email'=>'The owner already has full access. Invite another email.']);
                if(PartnerTeamMember::where('active_email_hash',$hash)->exists())throw ValidationException::withMessages(['email'=>'This email already has an active or pending team membership.']);
                if(PartnerTeamMember::where('partner_application_id',$app->id)->whereIn('status',['active','invited'])->count()>=50)throw ValidationException::withMessages(['team'=>'A business can have up to 50 active or invited staff.']);
                $token=Str::random(64);
                $member=PartnerTeamMember::create(['partner_application_id'=>$app->id,'request_key'=>strtolower($data['request_key']),'email_hash'=>$hash,'active_email_hash'=>$hash,
                    'details'=>['name'=>trim($data['name']),'email'=>mb_strtolower(trim($data['email']))],'role'=>$data['role'],'status'=>'invited',
                    'invitation_hash'=>hash('sha256',$token),'invitation_expires_at'=>now()->addDays(7),'last_sent_at'=>now(),'delivery_status'=>'pending']);
                $member->refresh();
                $this->event($member,'owner',$sessionId,'invited');return [$member,$token];
            },3);
        }catch(UniqueConstraintViolationException){throw ValidationException::withMessages(['email'=>'This invitation already exists. Refresh the team list.']);}
        if($token)$this->deliver($app,$member,$token);
        return $this->summary($member->fresh());
    }
    public function change(PartnerApplication $app,int $id,array $data,int $sessionId): array
    {
        $token=null;
        $member=DB::transaction(function()use($app,$id,$data,$sessionId,&$token){
            $app=PartnerApplication::whereKey($app->id)->lockForUpdate()->firstOrFail();abort_unless($this->business($app),403);
            $member=PartnerTeamMember::whereKey($id)->where('partner_application_id',$app->id)->lockForUpdate()->firstOrFail();
            abort_unless($member->version===(int)$data['expected_version'],409,'This membership changed. Refresh before continuing.');
            abort_if($member->status==='revoked',409,'This membership has been removed.');
            $action=$data['action'];
            if($action==='role'){
                abort_unless($member->status==='active',422,'Accept the invitation before changing roles, or remove it and invite again.');
                if($member->role===$data['role'])return $member;
                $member->role=$data['role'];
            }elseif($action==='resend'){
                abort_unless($member->status==='invited',409,'Only pending invitations can be resent.');
                abort_if($member->last_sent_at?->gt(now()->subMinute()),429,'Wait 60 seconds before resending.');
                $this->portalUrl();$token=Str::random(64);$member->invitation_hash=hash('sha256',$token);$member->invitation_expires_at=now()->addDays(7);$member->last_sent_at=now();$member->delivery_status='pending';
            }else{
                $member->status='revoked';$member->active_email_hash=null;$member->invitation_hash=null;$member->invitation_expires_at=null;$member->revoked_at=now();
            }
            $member->version++;$member->save();
            DB::table('partner_sessions')->where('partner_team_member_id',$member->id)->delete();
            DB::table('partner_login_challenges')->where('partner_team_member_id',$member->id)->delete();
            $this->event($member,'owner',$sessionId,$action,['role'=>$member->role]);return $member;
        },3);
        if($token)$this->deliver($app,$member,$token);
        return $this->summary($member->fresh());
    }
    private function portalUrl(): string
    {
        $base=rtrim((string)config('partners.portal_url'),'/');$parts=parse_url($base);
        if(!$parts || !in_array($parts['scheme']??'',['http','https'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']))throw ValidationException::withMessages(['team'=>'Configure the reachable partner portal URL before sending invitations.']);
        return $base;
    }
    private function deliver(PartnerApplication $app,PartnerTeamMember $member,string $token): void
    {
        $status='sent';
        try{Mail::to($member->details['email'])->send(new PartnerTeamInvitationMail($this->portalUrl().'/team/accept#'.$token,(string)data_get($app->application_payload,'profile.displayName','Your business'),$member->role));}
        catch(\Throwable $e){$status='failed';Log::warning('Partner team invitation delivery failed.',['exception_type'=>get_class($e)]);}
        PartnerTeamMember::whereKey($member->id)->where('version',$member->version)->where('status','invited')->update(['delivery_status'=>$status]);
    }
    private function event(PartnerTeamMember $member,string $type,int $actor,string $action,?array $details=null): void
    {
        DB::table('partner_team_events')->insert(['partner_team_member_id'=>$member->id,'actor_type'=>$type,'actor_id'=>$actor,'action'=>$action,'details'=>$details===null?null:Crypt::encryptString(json_encode($details)),'created_at'=>now()]);
    }
}
