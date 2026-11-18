<?php

namespace App\Console\Commands;

use App\Services\Drive\DriveFilingRunner;
use Illuminate\Console\Command;

class FileToDrive extends Command
{
    protected $signature = 'drive:file-pending {--limit=50}';
    protected $description = 'Append signed entries waiting in the Drive outbox to their Google Sheets (does nothing while Drive filing is off)';

    public function handle(DriveFilingRunner $runner): int
    {
        if (! DriveFilingRunner::enabled()) {
            $this->line('Drive filing is switched off (PBP_DRIVE_ENABLED=false). Entries stay "Signed – not yet filed".');

            return self::SUCCESS;
        }
        $r = $runner->run((int) $this->option('limit'));
        $this->info("Filed {$r['filed']}, failed {$r['failed']}.");

        return self::SUCCESS;
    }
}
