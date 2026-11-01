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
        $member=null;
        if ($session->partner_team_member_id ?? null) {
            $member=\App\Models\PartnerTeamMember::find($session->partner_team_member_id);
            if (!$member || $member->partner_application_id!=$application->id || $member->status!=='active' || !$member->active_email_hash
                || !in_array($member->role,['manager','front_desk'],true) || !app(\App\Services\PartnerTeamService::class)->business($application)) {
                return response()->json(['message'=>'Unauthenticated.'],401)->header('Cache-Control','no-store');
            }
        }
        $request->attributes->set('partner_role',$member?->role ?? 'owner');
        $request->attributes->set('partner_team_member',$member);
        $request->attributes->set('partner_application', $application);
        $request->attributes->set('partner_session_id', $session->id);

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
