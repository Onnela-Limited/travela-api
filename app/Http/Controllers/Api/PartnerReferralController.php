<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PartnerReferralService;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PartnerReferralController extends Controller
{
    public function show(Request $request, PartnerReferralService $service): JsonResponse
    {
        $referral = $service->ensureFor($request->attributes->get('partner_application'));
        return response()->json(['referral' => ['code' => $referral->code, 'url' => $service->url($referral)]])->header('Cache-Control', 'no-store');
    }

    public function qr(Request $request, PartnerReferralService $service): Response
    {
        $referral = $service->ensureFor($request->attributes->get('partner_application'));
        $options = new QROptions(['outputInterface' => QRGdImagePNG::class, 'outputBase64' => false, 'scale' => 10, 'eccLevel' => EccLevel::M]);
        $png = (new QRCode($options))->render($service->url($referral));
        return response($png)->withHeaders([
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.$referral->code.'.png"',
        ]);
    }

    public function resolve(string $code, PartnerReferralService $service): JsonResponse
    {
        $referral = $service->active($code);
        abort_unless($referral, 404, 'Partner code not found.');
        return response()->json(['referral' => ['code' => $referral->code]])->header('Cache-Control', 'no-store');
    }
}
