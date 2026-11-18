<?php

use Illuminate\Support\Facades\Schedule;

// Server cron (installed by upgrade-phase1.sh): * * * * * php artisan schedule:run
Schedule::command('checklists:generate')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('pbp:scale-block-check')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('drive:file-pending')->everyFiveMinutes()->withoutOverlapping();
