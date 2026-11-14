<?php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\{Request,JsonResponse};
use Illuminate\Support\Facades\DB;

class FailedPaymentCancellationController extends Controller {
    public function store(Request $request, int $order): JsonResponse {
        $cancelled=DB::transaction(function() use ($request,$order) {
            $current=Order::whereKey($order)->where('user_id',$request->user()->id)->lockForUpdate()->firstOrFail();
            $metadata=is_array($current->metadata)?$current->metadata:[];
            abort_if($current->paid_at || in_array(strtolower((string)$current->payment_status),['paid','completed'],true) || in_array(strtolower((string)$current->status),['paid','completed'],true),409,'This order has already been paid. Refresh its status.');
            abort_if(isset($metadata['partner_bulk_invoice_id']) || isset($metadata['partner_bulk_order_id']) || $current->source==='partner_bulk',409,'Partner bulk orders must be managed from the partner portal.');
            if (!empty($metadata['customer_cancelled_at'])) return $current;
            $state=strtolower(trim((string)($current->payment_status ?: $current->status)));
            abort_unless(in_array($state,['failed','payment_failed'],true),409,'Only a confirmed failed payment can be cancelled. Refresh its status.');
            abort_if($current->recharge_completed_at || $current->recharge_status==='success' || $current->userEsims()->exists(),409,'This order already has a SIM or bundle assigned. Contact Travela.');
            $metadata['customer_cancelled_at']=now()->toIso8601String();$metadata['cancelled_payment_status']=$state;
            $payload=is_array($current->payment_payload)?$current->payment_payload:[];
            if (isset($payload['response']) && is_array($payload['response'])) $payload['response']['paymentUrl']=null;
            $current->update(['status'=>'cancelled','payment_status'=>'cancelled','metadata'=>$metadata,'payment_payload'=>$payload]);
            return $current;
        });
        return response()->json(['order'=>['id'=>$cancelled->id,'draft_id'=>$cancelled->draft_id,'status'=>$cancelled->status,'payment_status'=>$cancelled->payment_status,'customer_cancelled_at'=>data_get($cancelled->metadata,'customer_cancelled_at')]])->header('Cache-Control','private, no-store');
    }
}
