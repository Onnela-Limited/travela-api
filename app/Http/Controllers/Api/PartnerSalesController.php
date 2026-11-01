<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PartnerSalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerSalesController extends Controller
{
    public function show(Request $request, PartnerSalesService $service): JsonResponse
    {
        $application = $request->attributes->get('partner_application');
        abort_unless($application->status === 'approved', 403);
        return response()->json(['dashboard' => $service->overview($application)])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, PartnerSalesService $service): JsonResponse
    {
        $application = $request->attributes->get('partner_application');
        abort_unless($application->status === 'approved', 403);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $page = $service->orders($application)->latest('created_at')->latest('id')->paginate(20);
        return response()->json([
            'sales' => $page->getCollection()->map(fn ($order) => $service->sale($order))->all(),
            'pagination' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ])->header('Cache-Control', 'private, no-store');
    }
}
