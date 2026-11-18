<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email/password session login.
 *
 * GOOGLE SIGN-IN (future): add GoogleController with redirect()/callback()
 * (e.g. laravel/socialite), restrict to config('pbp.google_allowed_domain'),
 * match users by email, then Auth::login($user). Add a "Sign in with Google"
 * button in resources/views/auth/login.blade.php (marked there).
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;      // per email+IP
    private const DECAY_SECONDS = 60;

    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ])->status(429);
        }

        $ok = Auth::attempt(
            ['email' => Str::lower($credentials['email']), 'password' => $credentials['password'], 'is_active' => true],
            $request->boolean('remember')
        );

        if (! $ok) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($user->must_change_password) {
            return redirect()->route('password.edit')
                ->with('status', 'Welcome! Please choose a new password to continue.');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    private function throttleKey(Request $request): string
    {
        return 'login:'.Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }
}
