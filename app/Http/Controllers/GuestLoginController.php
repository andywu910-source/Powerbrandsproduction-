<?php

namespace App\Http\Controllers;

use App\Models\PreviewGuestLink;
use App\Services\Audit;
use App\Support\Preview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Preview site only: a one-time link that signs in the "Preview Guest" admin for 7 days. */
class GuestLoginController extends Controller
{
    /** GET only shows a "Continue" button, so chat link previews can't use the link up. */
    public function show(Request $r, string $token)
    {
        abort_unless(Preview::guestLoginEnabled(), 404);
        abort_unless(strlen($token) >= 40 && strlen($token) <= 128, 404);
        $link = PreviewGuestLink::where('token_hash', PreviewGuestLink::hash($token))->first();
        abort_unless($link, 404);
        if ($link->used_at || $link->expires_at->isPast()) {
            return response()->view('auth.guest-link', ['state' => $link->used_at ? 'used' : 'expired'], 410);
        }

        return response()->view('auth.guest-continue', ['token' => $token]);
    }

    /** POST (the Continue click) is what signs in and uses up the link. */
    public function __invoke(Request $r, string $token)
    {
        // Second guard (the route itself is only registered on the preview site).
        abort_unless(Preview::guestLoginEnabled(), 404);
        abort_unless(strlen($token) >= 40 && strlen($token) <= 128, 404);

        $result = DB::transaction(function () use ($token, $r) {
            $link = PreviewGuestLink::where('token_hash', PreviewGuestLink::hash($token))->lockForUpdate()->first();
            if (! $link) {
                return 'missing';
            }
            if ($link->used_at) {
                return 'used';
            }
            if ($link->expires_at->isPast()) {
                return 'expired';
            }
            $user = $link->user;
            if (! $user || ! $user->is_active) {
                return 'missing';
            }
            $link->update(['used_at' => now(), 'used_ip' => $r->ip()]);

            return $user;
        });

        if (is_string($result)) {
            abort_if($result === 'missing', 404);

            return response()->view('auth.guest-link', ['state' => $result], 410);
        }

        // 7-day session for the guest (the preview .env also sets SESSION_LIFETIME=10080).
        config(['session.lifetime' => 10080, 'session.expire_on_close' => false]);
        Auth::login($result);
        $r->session()->regenerate();
        $result->forceFill(['last_login_at' => now()])->save();
        Audit::log('login', $result, ['via' => 'preview guest link']);

        return redirect('/batches');
    }
}
