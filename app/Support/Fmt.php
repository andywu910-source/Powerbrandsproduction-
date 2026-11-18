<?php

namespace App\Support;

use Carbon\Carbon;

/** Display helpers: times in the site's time zone (New York), friendly quantities. */
class Fmt
{
    public static function dt($v): string
    {
        return $v ? Carbon::parse($v)->timezone(config('pbp.site_timezone', 'America/New_York'))->format('M j, Y g:i A') : '';
    }

    public static function date($v): string
    {
        return $v ? Carbon::parse($v)->timezone(config('pbp.site_timezone', 'America/New_York'))->format('M j, Y') : '';
    }

    public static function qty($q, ?string $unit): string
    {
        return $q === null ? '' : Units::display((float) $q, $unit ?: 'g');
    }

    public static function pill(string $status): string
    {
        return match ($status) {
            'released', 'complete', 'completed', 'closed', 'approved', 'pass', 'verified', 'filed' => 'ok',
            'quarantine', 'open', 'due', 'in_progress', 'in_process', 'in_qc', 'draft', 'pending', 'awaiting_verification', 'approved_to_continue', 'recorded' => 'warn',
            'rejected', 'void', 'overdue', 'fail', 'failed' => 'bad',
            default => 'muted',
        };
    }
}
