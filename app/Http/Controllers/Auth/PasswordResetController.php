<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function request()
    {
        return response()->view('auth.password-reset', ['token' => null, 'email' => ''])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function email(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $key = 'password-reset:'.hash('sha256', mb_strtolower($data['email']).'|'.$request->ip());
        if (! RateLimiter::tooManyAttempts($key, 1)) {
            RateLimiter::hit($key, 60);
            if (User::where('email', $data['email'])->where('status', 'active')->exists()) {
                try {
                    Password::sendResetLink($data);
                } catch (\Throwable $exception) {
                    Log::error('Password reset notification failed. Check the mail transport configuration.', ['exception_type' => $exception::class]);
                }
            }
        }

        return back()->with('status', 'Jika email terdaftar pada akun aktif, petunjuk pemulihan akan dikirim. Periksa email atau hubungi owner jika belum diterima.');
    }

    public function edit(Request $request, string $token)
    {
        return response()->view('auth.password-reset', ['token' => $token, 'email' => (string) $request->query('email', '')])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:1024', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        if (! User::where('email', $data['email'])->where('status', 'active')->exists()) {
            return back()->withErrors(['email' => 'Tautan pemulihan tidak valid atau kedaluwarsa.'])->onlyInput('email');
        }
        $result = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
            event(new PasswordReset($user));
        });
        if ($result !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'Tautan pemulihan tidak valid atau kedaluwarsa.'])->onlyInput('email');
        }

        return redirect()->route('login')->with('status', 'Password diperbarui. Silakan masuk kembali.');
    }
}
