<?php

namespace App\Console\Commands;

use App\Services\ScaleBlock;
use Illuminate\Console\Command;

/** Daily: switch the scale block on once every active scale has a last inspection date (never off). */
class ScaleBlockCheck extends Command
{
    protected $signature = 'pbp:scale-block-check';

    protected $description = 'Turn the scale inspection block on automatically when every scale has an inspection date';

    public function handle(): int
    {
        $this->line(ScaleBlock::maybeEnable('daily check') ? 'Scale block auto-enabled.' : ScaleBlock::statusLine());

        return self::SUCCESS;
    }
}
