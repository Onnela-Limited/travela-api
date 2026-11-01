<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use Illuminate\Http\{Request,JsonResponse};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Validation\Rule;

class PartnerSettingsController extends Controller
{
 private function settings(PartnerApplication $app): array {
  $payload=$app->application_payload;
  $profile=array_intersect_key($payload['profile'],array_flip(['displayName','country','city','phone','email','legalName']));
  $payout=array_merge(['method'=>'mpesa','accountName'=>'','accountNumber'=>'','bank'=>'','branch'=>'','nameConfirmed'=>false],array_intersect_key($payload['payout']??[],array_flip(['method','accountName','accountNumber','bank','branch','nameConfirmed'])));
  $payout['bank']=$payout['bank']??'';$payout['branch']=$payout['branch']??'';$payout['nameConfirmed']=(bool)$payout['nameConfirmed'];
  return ['profile'=>$profile,'payout'=>$payout,'version'=>(int)($app->settings_version??0)];
 }
 private function response(PartnerApplication $app): JsonResponse {return response()->json(['settings'=>$this->settings($app)])->header('Cache-Control','private, no-store');}
 public function show(Request $request): JsonResponse {
  $app=$request->attributes->get('partner_application');abort_unless($app->status==='approved',403);return $this->response($app);
 }
 public function update(Request $request): JsonResponse {
  $data=$request->validate([
   'expected_version'=>['required','integer','min:0'],
   'profile'=>['required','array:displayName,country,city,phone'],
   'profile.displayName'=>['required','string','max:150'],'profile.country'=>['required','string','max:100'],'profile.city'=>['required','string','max:100'],
   'profile.phone'=>['required','string','regex:/^(?=(?:\D*\d){9})\+?[0-9\s().-]{9,25}$/'],
   'payout'=>['required','array:method,accountName,accountNumber,bank,branch,nameConfirmed'],
   'payout.method'=>['required',Rule::in(['mpesa','airtel_money','mixx','halopesa','bank'])],
   'payout.accountName'=>['required','string','max:150'],'payout.accountNumber'=>['required','string','max:100'],
   'payout.bank'=>['required_if:payout.method,bank','nullable','string','max:150'],'payout.branch'=>['nullable','string','max:150'],'payout.nameConfirmed'=>['accepted'],
  ]);
  $app=DB::transaction(function()use($request,$data){
   $app=PartnerApplication::whereKey($request->attributes->get('partner_application')->id)->lockForUpdate()->firstOrFail();
   abort_unless($app->status==='approved',403);
   abort_unless((int)$app->settings_version===(int)$data['expected_version'],409,'Settings changed. Reload and check the latest details before saving.');
   $before=$this->settings($app);$payload=$app->application_payload;
   $payload['profile']=array_merge($payload['profile'],$data['profile']);
   $payload['payout']=['method'=>$data['payout']['method'],'accountName'=>$data['payout']['accountName'],'accountNumber'=>$data['payout']['accountNumber'],
    'bank'=>$data['payout']['method']==='bank'?($data['payout']['bank']??''):'','branch'=>$data['payout']['method']==='bank'?($data['payout']['branch']??''):'','nameConfirmed'=>true];
   $app->application_payload=$payload;$app->settings_version=(int)$app->settings_version+1;$app->save();
   DB::table('partner_settings_events')->insert(['partner_application_id'=>$app->id,'actor_session_id'=>$request->attributes->get('partner_session_id'),'version'=>$app->settings_version,
    'changes'=>Crypt::encryptString(json_encode(['before'=>$before,'after'=>$this->settings($app)],JSON_THROW_ON_ERROR)),'created_at'=>now()]);
   return $app;
  },3);
  return $this->response($app);
 }
}
