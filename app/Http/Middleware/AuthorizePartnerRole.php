<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizePartnerRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array($request->attributes->get('partner_role'),$roles,true),403,'Your team role cannot access this function.');
        return $next($request)->header('Cache-Control','private, no-store');
    }
}
