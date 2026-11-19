<?php

namespace App\Http\Middleware;

use App\Models\LogsPreviewToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Also: when the hub is hidden (preview-only mode, or logs not in PBP_MODULES) /logs is a 404 for everyone else,
 * signed in or not.
 *
 * Sessions opened by a Compliance hub preview link may only use /logs routes and logout.
 * The session ends when the link's window ends, when the link is revoked, or when
 * PBP_PREVIEW_LINKS is switched off. Sessions without the flag are not touched.
 */
class LogsPreviewScope
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(LogsPreviewToken::SESSION_KEY)) {
            // Hidden hub (PBP_LOGS_PREVIEW_ONLY): 404 before the sign-in redirect, so guests can't tell /logs exists.
            if (str_starts_with((string) $request->route()?->getName(), 'logs.') && ! \App\Support\LogHub::hubVisible($request)) {
                abort(404);
            }

            return $next($request);
        }

        $meta = (array) $request->session()->get(LogsPreviewToken::SESSION_KEY);
        $row = LogsPreviewToken::find($meta['token_id'] ?? 0);
        $alive = LogsPreviewToken::enabled() && $row && $row->sessionAlive()
            && (int) ($meta['expires_at'] ?? 0) > now()->getTimestamp()
            && Auth::id() === $row->user_id;

        if (! $alive) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['email' => 'That preview sign-in has ended. Sign in normally, or ask for a new link.']);
        }

        $route = $request->route();
        $name = $route?->getName() ?? '';
        $allowed = $name === 'logout' || str_starts_with($name, 'logs.') || str_starts_with($name, 'logs-preview.');
        abort_unless($allowed, 403, 'Preview access covers the Compliance hub only.');

        return $next($request);
    }
}
