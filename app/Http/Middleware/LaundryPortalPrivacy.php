<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LaundryPortalPrivacy
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        foreach (['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'"] as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
