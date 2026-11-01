<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\PartnerNotificationService;
use Illuminate\Http\Request;
class PartnerNotificationController extends Controller {
 public function index(Request $request,PartnerNotificationService $service){
  $request->validate(['page'=>['sometimes','integer','min:1','max:100000']]);$query=$service->scope($request->attributes->get('partner_application'),$request->attributes->get('partner_team_member'));
  $unread=(clone $query)->whereNull('read_at')->count();$page=$query->orderByDesc('id')->paginate(20);
  return response()->json(['notifications'=>$page->getCollection()->map(fn($r)=>$service->summary($r))->all(),'unread'=>$unread,'pagination'=>['page'=>$page->currentPage(),'lastPage'=>$page->lastPage(),'total'=>$page->total()]])->header('Cache-Control','private, no-store');
 }
 public function read(Request $request,int $notification,PartnerNotificationService $service){
  $query=$service->scope($request->attributes->get('partner_application'),$request->attributes->get('partner_team_member'))->where('id',$notification);abort_unless((clone $query)->exists(),404);
  (clone $query)->whereNull('read_at')->update(['read_at'=>now(),'updated_at'=>now()]);
  return response()->json(['notification'=>$service->summary($query->first())])->header('Cache-Control','private, no-store');
 }
}
