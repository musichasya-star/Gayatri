<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== 'active' || ($request->session()->has('auth_version') && (int) $request->session()->get('auth_version') !== (int) $request->user()->auth_version)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Akun tidak aktif. Silakan hubungi owner atau manager.',
            ]);
        }

        $request->session()->put('auth_version', (int) $request->user()->auth_version);

        return $next($request);
    }
}
