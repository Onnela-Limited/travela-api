<?php

namespace App\Http\Middleware;

use App\Models\PartnerApplication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePartnerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $session = is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/', $token)
            ? DB::table('partner_sessions')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first()
            : null;
        $application = $session ? PartnerApplication::find($session->partner_application_id) : null;
        if (! $application) {
            return response()->json(['message' => 'Unauthenticated.'], 401)->header('Cache-Control', 'no-store');
        }
        $request->attributes->set('partner_application', $application);
        $request->attributes->set('partner_session_id', $session->id);

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
