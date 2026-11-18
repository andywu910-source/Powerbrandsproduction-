<?php

namespace App\Console\Commands;

use App\Services\ChecklistService;
use Illuminate\Console\Command;

class GenerateChecklists extends Command
{
    protected $signature = 'checklists:generate';
    protected $description = 'Create checklists that are due for the current day/week/month and mark late ones overdue (safe to re-run)';

    public function handle(ChecklistService $svc): int
    {
        $r = $svc->generate();
        $this->info("Created {$r['created']}, marked overdue {$r['overdue']}.");

        return self::SUCCESS;
    }
}
