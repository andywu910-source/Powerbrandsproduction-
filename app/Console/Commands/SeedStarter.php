<?php

namespace App\Console\Commands;

use Database\Seeders\StarterDataSeeder;
use Illuminate\Console\Command;

class SeedStarter extends Command
{
    protected $signature = 'pbp:seed-starter';
    protected $description = 'Add starter settings, process templates, checklist templates and staff names if missing (never overwrites edits)';

    public function handle(): int
    {
        (new StarterDataSeeder)->setCommand($this)->run();
        $this->info('Starter data checked.');

        return self::SUCCESS;
    }
}
