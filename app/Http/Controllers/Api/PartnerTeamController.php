<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{PartnerApplication, PartnerTeamMember};
use App\Services\PartnerTeamService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class PartnerTeamController extends Controller
{
    private function json(array $data): JsonResponse { return response()->json($data)->header('Cache-Control','private, no-store'); }
    public function index(Request $request,PartnerTeamService $service): JsonResponse
    {
        $app=$request->attributes->get('partner_application');abort_unless($service->business($app),403);
        $request->validate(['page'=>['sometimes','integer','min:1','max:100000']]);
        $page=PartnerTeamMember::where('partner_application_id',$app->id)->latest('id')->paginate(20);
        return $this->json(['owner'=>['name'=>data_get($app->application_payload,'profile.displayName','Owner'),'email'=>data_get($app->application_payload,'profile.email','')],
            'members'=>$page->getCollection()->map(fn($m)=>$service->summary($m))->all(),
            'pagination'=>['page'=>$page->currentPage(),'lastPage'=>$page->lastPage(),'total'=>$page->total()]]);
    }
    public function store(Request $request,PartnerTeamService $service): JsonResponse
    {
        $data=$request->validate(['request_key'=>['required','uuid'],'name'=>['required','string','max:150'],'email'=>['required','email','max:254'],'role'=>['required',Rule::in(['manager','front_desk'])]]);
        return $this->json(['member'=>$service->invite($request->attributes->get('partner_application'),$data,$request->attributes->get('partner_session_id'))]);
    }
    public function change(Request $request,int $member,PartnerTeamService $service): JsonResponse
    {
        $data=$request->validate(['expected_version'=>['required','integer','min:0'],'action'=>['required',Rule::in(['role','revoke','resend'])],'role'=>['required_if:action,role','nullable',Rule::in(['manager','front_desk'])]]);
        return $this->json(['member'=>$service->change($request->attributes->get('partner_application'),$member,$data,$request->attributes->get('partner_session_id'))]);
    }
    public function invitation(Request $request,PartnerTeamService $service): JsonResponse
    {
        $data=$request->validate(['token'=>['required','string','regex:/^[A-Za-z0-9]{64}$/']]);
        $member=$service->invitation($data['token']);abort_unless($member,404,'This invitation is invalid, expired, or already accepted.');
        $app=PartnerApplication::findOrFail($member->partner_application_id);
        [$local,$domain]=explode('@',$member->details['email'],2);
        return $this->json(['invitation'=>['business'=>(string)data_get($app->application_payload,'profile.displayName','Your team'),
            'name'=>$member->details['name'],'emailMasked'=>mb_substr($local,0,1).'•••@'.$domain,'role'=>$member->role,'expiresAt'=>$member->invitation_expires_at->toIso8601String()]]);
    }
}
