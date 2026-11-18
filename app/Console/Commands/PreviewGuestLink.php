<?php

namespace App\Console\Commands;

use App\Models\PreviewGuestLink as Link;
use App\Models\User;
use App\Support\Preview;
use Illuminate\Console\Command;

/**
 * Preview site only: issue a fresh one-time guest login link (valid 24 h; any earlier
 * unused link stops working). The full URL is the ONLY thing written to stdout, so the
 * caller can redirect it straight into a root-only file.
 */
class PreviewGuestLink extends Command
{
    protected $signature = 'preview:guest-link {--hours=24 : How long the link works}';

    protected $description = 'Preview site: issue a one-time guest login link (prints only the URL)';

    public function handle(): int
    {
        if (! Preview::guestLoginEnabled()) {
            $this->error('Guest links only work on the preview site (APP_ENV=staging and PREVIEW_GUEST_LOGIN=true).');

            return self::FAILURE;
        }
        $user = User::where('email', Link::GUEST_EMAIL)->where('is_active', true)->first();
        if (! $user) {
            $this->error('No active "Preview Guest" user. Run: php artisan db:seed --class=PreviewDataSeeder --force');

            return self::FAILURE;
        }
        [, $token] = Link::issue($user, max(1, min(168, (int) $this->option('hours'))));
        $this->line(rtrim((string) config('app.url'), '/').'/guest/'.$token);

        return self::SUCCESS;
    }
}
