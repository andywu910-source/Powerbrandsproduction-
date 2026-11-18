<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out users who have been deactivated, and forces a password change
 * when must_change_password is set (e.g. first login or admin reset).
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your account has been disabled.']);
        }

        if ($user && $user->must_change_password
            && ! $request->routeIs('password.edit', 'password.update', 'logout')) {
            return redirect()->route('password.edit')
                ->with('status', 'Please choose a new password to continue.');
        }

        return $next($request);
    }
}
