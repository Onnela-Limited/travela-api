<?php
namespace App\Services;
use App\Models\{PartnerApplication,PartnerTeamMember};
use App\Mail\PartnerUpdateMail;
use Illuminate\Support\Facades\{DB,Crypt,Mail,Cache,Log};
use Illuminate\Database\Query\Builder;

class PartnerNotificationService
{
 public function categories(string $role): array {return match($role){'owner'=>['application','payout','bulk','stock'],'manager'=>['bulk','stock'],'front_desk'=>['stock'],default=>[]};}
 public function scope(PartnerApplication $app,?PartnerTeamMember $member): Builder {
  return DB::table('partner_notifications')->where('partner_application_id',$app->id)->where('recipient_key',$member?'staff:'.$member->id:'owner')->whereIn('category',$this->categories($member?->role??'owner'));
 }
 public function record(PartnerApplication $app,string $event,string $category,string $title,string $body,string $path): void {
  $recipients=[['key'=>'owner','member'=>null,'email'=>data_get($app->application_payload,'profile.email')]];
  if(in_array($category,['bulk','stock'],true)){
   foreach(PartnerTeamMember::where('partner_application_id',$app->id)->where('status','active')->get() as $member){
    if(in_array($category,$this->categories($member->role),true))$recipients[]=['key'=>'staff:'.$member->id,'member'=>$member->id,'email'=>$member->details['email']];
   }
  }
  foreach($recipients as $recipient){
   $recipientPath=$category==='stock' ? '/dashboard/stock' : $path;
   if(DB::table('partner_notifications')->where('event_key',$event)->where('recipient_key',$recipient['key'])->exists())continue;
   $id=DB::table('partner_notifications')->insertGetId(['partner_application_id'=>$app->id,'partner_team_member_id'=>$recipient['member'],'event_key'=>$event,'recipient_key'=>$recipient['key'],'category'=>$category,
    'content'=>Crypt::encryptString(json_encode(['title'=>$title,'body'=>$body,'path'=>$recipientPath],JSON_THROW_ON_ERROR)),
    'email'=>Crypt::encryptString((string)$recipient['email']),'email_status'=>'pending','attempts'=>0,'next_attempt_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
   if($id){$notificationId=$id;
    // Persist with the business decision; deliver only after its outer transaction commits and the response finishes.
    DB::afterCommit(fn()=>app()->terminating(function()use($notificationId){try{$this->deliver((int)$notificationId);}catch(\Throwable $e){Log::warning('Partner update delivery deferred.',['notification_id'=>$notificationId,'exception_type'=>get_class($e)]);}}));
   }
  }
 }
 public function summary(object $row): array {return ['id'=>(string)$row->id,'category'=>$row->category,...json_decode(Crypt::decryptString($row->content),true,512,JSON_THROW_ON_ERROR),'createdAt'=>\Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(),'readAt'=>$row->read_at?\Illuminate\Support\Carbon::parse($row->read_at)->toIso8601String():null];}
 private function eligible(object $row): bool {
  $app=PartnerApplication::find($row->partner_application_id);if(!$app)return false;
  if(!$row->partner_team_member_id)return $row->recipient_key==='owner';
  $member=PartnerTeamMember::find($row->partner_team_member_id);
  return $member && $member->partner_application_id==$app->id && $member->status==='active' && app(PartnerTeamService::class)->business($app) && in_array($row->category,$this->categories($member->role),true);
 }
 public function deliver(int $id): bool {
  return Cache::lock('partner-notification:'.$id,120)->get(function()use($id){
   $row=DB::transaction(function()use($id){
    $row=DB::table('partner_notifications')->where('id',$id)->lockForUpdate()->first();
    if(!$row || in_array($row->email_status,['sent','skipped'],true) || $row->attempts>=5 || $row->next_attempt_at && \Illuminate\Support\Carbon::parse($row->next_attempt_at)->isFuture())return null;
    if(!$this->eligible($row)){DB::table('partner_notifications')->where('id',$id)->update(['email_status'=>'skipped','updated_at'=>now()]);return null;}
    DB::table('partner_notifications')->where('id',$id)->update(['email_status'=>'sending','attempts'=>$row->attempts+1,'next_attempt_at'=>now()->addMinutes(5),'updated_at'=>now()]);return $row;
   });
   if(!$row)return false;
   try{
    $base=rtrim((string)config('partners.portal_url'),'/');$parts=parse_url($base);
    if(!$parts || !in_array($parts['scheme']??'',['https','http'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']))throw new \RuntimeException('Invalid partner portal URL');
    $email=Crypt::decryptString($row->email);if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Invalid recipient');
    Mail::to($email)->send(new PartnerUpdateMail(json_decode(Crypt::decryptString($row->content),true,512,JSON_THROW_ON_ERROR),$base));
    DB::table('partner_notifications')->where('id',$id)->update(['email_status'=>'sent','sent_at'=>now(),'next_attempt_at'=>null,'updated_at'=>now()]);return true;
   }catch(\Throwable $e){DB::table('partner_notifications')->where('id',$id)->update(['email_status'=>'failed','next_attempt_at'=>now()->addMinutes([1,5,15,60,60][min(4,$row->attempts)]),'updated_at'=>now()]);Log::warning('Partner update email failed.',['notification_id'=>$id,'exception_type'=>get_class($e)]);return false;}
  })??false;
 }
 public function retry(): int {
  $ids=DB::table('partner_notifications')->whereIn('email_status',['pending','failed','sending'])->where('attempts','<',5)->where(fn($q)=>$q->whereNull('next_attempt_at')->orWhere('next_attempt_at','<=',now()))->orderBy('id')->limit(100)->pluck('id');$sent=0;
  foreach($ids as $id){try{if($this->deliver((int)$id))$sent++;}catch(\Throwable $e){Log::warning('Partner notification retry deferred.',['notification_id'=>$id,'exception_type'=>get_class($e)]);}}
  return $sent;
 }
}
