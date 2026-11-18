<?php

namespace App\Support;

use App\Models\ComplianceEntry;
use App\Models\ComplianceItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Compliance Checklists helpers: periods (America/New_York), who may be picked, who may edit. */
class Compliance
{
    public const NOTE = "Internal checklists, not official OCM documents. Logs live in Andy's Drive at Compliance / LOGS.";

    public static function tz(): string
    {
        return (string) config('pbp.site_timezone', 'America/New_York');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz())->startOfDay();
    }

    /** Start of the period containing $date: Monday (Mon-Sun week) or the 1st of the month. */
    public static function periodStart(string $freq, ?string $date = null): CarbonImmutable
    {
        try {
            $d = $date ? CarbonImmutable::parse($date, self::tz())->startOfDay() : self::today();
        } catch (\Throwable) {
            $d = self::today();
        }

        return match ($freq) {
            'monthly' => $d->startOfMonth(),
            'quarterly' => $d->startOfQuarter(),
            'annual' => $d->startOfYear(),
            default => $d->startOfWeek(CarbonImmutable::MONDAY),
        };
    }

    public static function periodEnd(string $freq, CarbonImmutable $start): CarbonImmutable
    {
        return match ($freq) {
            'monthly' => $start->endOfMonth()->startOfDay(),
            'quarterly' => $start->endOfQuarter()->startOfDay(),
            'annual' => $start->endOfYear()->startOfDay(),
            default => $start->addDays(6),
        };
    }

    public static function step(string $freq, CarbonImmutable $start, int $n): CarbonImmutable
    {
        return match ($freq) {
            'monthly' => $start->addMonthsNoOverflow($n)->startOfMonth(),
            'quarterly' => $start->addMonthsNoOverflow(3 * $n)->startOfQuarter(),
            'annual' => $start->addYears($n)->startOfYear(),
            default => $start->addWeeks($n),
        };
    }

    public static function label(string $freq, CarbonImmutable $start): string
    {
        return match ($freq) {
            'monthly' => $start->format('F Y'),
            'quarterly' => 'Q'.$start->quarter.' '.$start->year.' ('.$start->format('M').' – '.$start->endOfQuarter()->format('M').')',
            'annual' => 'Year '.$start->year,
            default => 'Week of '.$start->format('M j, Y').' (Mon '.$start->format('n/j').' – Sun '.$start->addDays(6)->format('n/j').')',
        };
    }

    public static function isCurrent(string $freq, CarbonImmutable $start): bool
    {
        return $start->equalTo(self::periodStart($freq));
    }

    /** Jack is never assignable / never "completed by": first name Jack, plus the config list (names or emails). */
    public static function excluded(User $u): bool
    {
        $name = mb_strtolower(trim((string) $u->name));
        $first = preg_split('/\s+/', $name)[0] ?? '';
        if ($first === 'jack') {
            return true;
        }
        $list = config('pbp.compliance.excluded', []);
        $list = is_string($list) ? explode(',', $list) : (array) $list;
        foreach ($list as $x) {
            $x = mb_strtolower(trim((string) $x));
            if ($x !== '' && ($x === $name || $x === $first || $x === mb_strtolower((string) $u->email))) {
                return true;
            }
        }

        return false;
    }

    /** Active users that may be picked as Assignee / Completed by. */
    public static function pickable()
    {
        return User::where('is_active', true)->orderBy('name')->get()->reject(fn ($u) => self::excluded($u))->values();
    }

    public static function canCheck(?User $u): bool
    {
        return $u && $u->is_active && ! $u->isViewer();
    }

    /** Current period: anyone who can write. Past (and future) periods: read-only unless admin. */
    public static function canEdit(?User $u, string $freq, CarbonImmutable $start): bool
    {
        return self::canCheck($u) && (self::isCurrent($freq, $start) || $u->isAdmin());
    }

    /** Items shown for a period: visible items, plus hidden ones that already have an entry in that period. */
    public static function items(string $freq, CarbonImmutable $start)
    {
        $withEntry = ComplianceEntry::whereDate('period_start', $start->toDateString())->pluck('item_id');

        return ComplianceItem::where('frequency', $freq)->where(fn ($q) => $q->where('hidden', false)->orWhereIn('id', $withEntry))
            ->orderBy('standing')->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return array{done:int,total:int} */
    public static function progress(string $freq, CarbonImmutable $start): array
    {
        $items = self::items($freq, $start);
        $done = ComplianceEntry::whereIn('item_id', $items->pluck('id'))->whereDate('period_start', $start->toDateString())->where('done', true)->count();

        return ['done' => $done, 'total' => $items->count()];
    }
}
