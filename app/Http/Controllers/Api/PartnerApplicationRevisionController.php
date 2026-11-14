<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RevisePartnerApplicationRequest;
use App\Models\PartnerApplication;
use App\Support\PartnerApplicationDocuments;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{DB,Crypt,Storage};
use RuntimeException;
use Throwable;

class PartnerApplicationRevisionController extends Controller {
    public function show(Request $request): JsonResponse {
        $app = $request->attributes->get('partner_application');
        abort_unless(in_array($app->status,['info_requested','rejected'],true),409,'This application cannot currently be edited.');
        return response()->json(['revision'=>[
            'reference'=>$app->reference,'status'=>$app->status,'reviewVersion'=>$app->review_version,'reviewMessage'=>$app->review_message,
            'application'=>array_intersect_key($app->application_payload,array_flip(['type','language','profile','identity','audience','payout'])),
            'documents'=>array_keys(PartnerApplicationDocuments::retained($app,PartnerApplicationDocuments::KINDS)),
        ]])->header('Cache-Control','private, no-store');
    }
    public function store(RevisePartnerApplicationRequest $request): JsonResponse {
        $data = $request->validated();$stored=[];
        try {
            $app = DB::transaction(function() use ($request,$data,&$stored) {
                $app = PartnerApplication::whereKey($request->attributes->get('partner_application')->id)->lockForUpdate()->firstOrFail();
                if (DB::table('partner_application_revisions')->where('partner_application_id',$app->id)->where('request_key',$data['request_key'])->exists()) return $app;
                abort_unless(in_array($app->status,['info_requested','rejected'],true),409,'This application cannot currently be edited.');
                abort_unless($app->review_version === (int)$data['expected_version'],409,'This application changed. Check its latest status before editing again.');
                $before=$app->application_payload;$application=$data['application'];
                abort_unless(mb_strtolower(trim($application['profile']['email'])) === mb_strtolower(trim($before['profile']['email'])),422,'The sign-in email cannot be changed.');
                if (isset($application['profile']['firstName'],$application['profile']['lastName'])) {
                    $application['profile']['firstName']=trim($application['profile']['firstName']);$application['profile']['lastName']=trim($application['profile']['lastName']);
                    $application['profile']['displayName']=$application['profile']['legalName']=$application['profile']['firstName'].' '.$application['profile']['lastName'];
                }
                $documents=PartnerApplicationDocuments::retained($app,$data['retained_documents']);
                $documents=array_intersect_key($documents,array_flip(PartnerApplicationDocuments::required($application['type'])));
                foreach (PartnerApplicationDocuments::required($application['type']) as $kind) {
                    if ($file=$request->file('documents.'.$kind)) {
                        $path=$file->store('partner-applications/'.$app->reference,'local');
                        if ($path===false) throw new RuntimeException('Could not save document.');
                        $stored[]=$path;$documents[$kind]=$path;
                    }
                    abort_unless(isset($documents[$kind]),422,'Select the required document again.');
                }
                DB::table('partner_application_revisions')->insert([
                    'partner_application_id'=>$app->id,'request_key'=>$data['request_key'],'from_status'=>$app->status,'from_review_version'=>$app->review_version,
                    'previous_details'=>Crypt::encryptString(json_encode($before,JSON_THROW_ON_ERROR)),
                    'previous_documents'=>Crypt::encryptString(json_encode($app->documents,JSON_THROW_ON_ERROR)),'created_at'=>now(),
                ]);
                $app->update(['application_payload'=>$application,'documents'=>$documents,'status'=>'under_review',
                    'submitted_at'=>now(),'review_message'=>null,'reviewed_at'=>null,'review_version'=>$app->review_version+1]);
                return $app;
            });
        } catch (Throwable $failure) {
            Storage::disk('local')->delete($stored);throw $failure;
        }
        return response()->json(['application'=>['reference'=>$app->reference,'status'=>$app->status,'submittedAt'=>$app->submitted_at->toIso8601String()]])->header('Cache-Control','private, no-store');
    }
}
