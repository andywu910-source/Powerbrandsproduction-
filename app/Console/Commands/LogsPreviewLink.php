<?php

namespace App\Console\Commands;

use App\Models\LogsPreviewToken;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;

/**
 * Compliance hub review: issue a one-time link that signs in as an existing user, with access to
 * /logs only, for at most 24 hours. Needs PBP_PREVIEW_LINKS=true. The URL is the only thing on stdout.
 *   php artisan logs:preview-link andy@example.com --hours=24
 *   php artisan logs:preview-link --revoke          (ends every link and preview session now)
 */
class LogsPreviewLink extends Command
{
    protected $signature = 'logs:preview-link {user? : email or id of an existing, active user}
        {--hours=24 : how long the link (and the session it opens) lasts, 1 to 24}
        {--revoke : end every outstanding link and preview session now}';

    protected $description = 'Compliance hub: one-time, /logs-only sign-in link for an existing user (prints only the URL)';

    public function handle(): int
    {
        if ($this->option('revoke')) {
            $n = LogsPreviewToken::revokeAll();
            Audit::log('logs_preview_revoked', null, ['count' => $n]);
            $this->info("Revoked $n link(s). Any preview session ends on its next request.");

            return self::SUCCESS;
        }
        if (! LogsPreviewToken::enabled()) {
            $this->error('Preview links are off. Set PBP_PREVIEW_LINKS=true in .env and run php artisan config:cache.');

            return self::FAILURE;
        }
        $who = (string) $this->argument('user');
        if ($who === '') {
            $this->error('Give the email (or id) of the user the link signs in as.');

            return self::FAILURE;
        }
        $user = ctype_digit($who) ? User::find((int) $who) : User::where('email', $who)->first();
        if (! $user || ! $user->is_active) {
            $this->error('No active user with that email or id.');

            return self::FAILURE;
        }
        $hours = (int) $this->option('hours');
        if ($hours < 1 || $hours > LogsPreviewToken::MAX_HOURS) {
            $this->error('--hours must be between 1 and '.LogsPreviewToken::MAX_HOURS.'.');

            return self::FAILURE;
        }
        [$row, $token] = LogsPreviewToken::issue($user, $hours);
        Audit::log('logs_preview_link_issued', $user, ['token_id' => $row->id, 'expires_at' => $row->expires_at->toIso8601String()]);
        $this->line(rtrim((string) config('app.url'), '/').'/logs/preview/'.$token);

        return self::SUCCESS;
    }
}
