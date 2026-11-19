<?php

namespace App\Http\Controllers;

use App\Models\LogsPreviewToken;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /logs/preview/{token}: one-time sign-in to the Compliance hub only.
 * GET shows a "Continue" button (so chat/email link previews can't use the link up);
 * the POST from that button is the single use. Off unless PBP_PREVIEW_LINKS=true.
 * Unknown, used, revoked and expired tokens all get the same plain 404.
 */
class LogsPreviewController extends Controller
{
    public function show(string $token)
    {
        $this->usable($token) ?? abort(404);

        return response()->view('logs.preview.continue', ['token' => $token])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function consume(Request $r, string $token)
    {
        $this->usable($token) ?? abort(404);

        $row = DB::transaction(function () use ($token, $r) {
            $row = LogsPreviewToken::where('token_hash', LogsPreviewToken::hash($token))->lockForUpdate()->first();
            if (! $row || $row->used_at || $row->revoked_at || ! $row->expires_at->isFuture()) {
                return null;
            }
            // Conditional update: only one request can flip used_at, even without row locks (sqlite).
            $won = LogsPreviewToken::whereKey($row->id)->whereNull('used_at')->whereNull('revoked_at')
                ->update(['used_at' => now(), 'used_ip' => $r->ip()]);

            return $won === 1 && $row->user?->is_active ? $row : null;
        });
        abort_unless($row, 404);

        // Fresh session: nothing from an earlier session in this browser carries over.
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        Auth::login($row->user);
        $r->session()->regenerate();
        $r->session()->put(LogsPreviewToken::SESSION_KEY, ['token_id' => $row->id, 'expires_at' => $row->expires_at->getTimestamp()]);
        Audit::log('login', $row->user, ['via' => 'compliance hub preview link', 'token_id' => $row->id,
            'scope' => '/logs only', 'expires_at' => $row->expires_at->toIso8601String()]);

        return redirect()->route('logs.index');
    }

    private function usable(string $token): ?bool
    {
        if (! LogsPreviewToken::enabled() || ! LogsPreviewToken::looksValid($token)) {
            return null;
        }
        $row = LogsPreviewToken::where('token_hash', LogsPreviewToken::hash($token))->first();

        return $row && ! $row->used_at && ! $row->revoked_at && $row->expires_at->isFuture() ? true : null;
    }
}
