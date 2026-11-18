<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Weekly Schedule settings: who edits, note sections, process steps, labor dollars. */
class Schedule
{
    /** Editor list as text: "role:admin, name:Jack, email:x@y". Settings (Schedule > People & setup) win over config. */
    public static function editorSpec(): string
    {
        $v = Setting::get('schedule_editors');

        return is_string($v) && trim($v) !== '' ? $v : (string) config('pbp.schedule.editors', 'role:admin');
    }

    public static function canEdit(?User $u): bool
    {
        if (! $u || ! $u->is_active || $u->isViewer()) {
            return false;
        }
        $name = mb_strtolower(trim((string) $u->name));
        $first = explode(' ', $name)[0] ?? '';
        foreach (preg_split('/[,\n]+/', self::editorSpec()) as $rule) {
            $rule = trim($rule);
            if ($rule === '' || ! str_contains($rule, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $rule, 2));
            $v = mb_strtolower($v);
            $hit = match (mb_strtolower($k)) {
                'role' => $u->role === $v,
                'name' => $v !== '' && ($name === $v || $first === $v),
                'email' => $v !== '' && mb_strtolower((string) $u->email) === $v,
                default => false,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /** Who the current list lets edit (for the setup page). */
    public static function editorsNow(): array
    {
        return User::where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => self::canEdit($u))->map(fn ($u) => $u->name)->values()->all();
    }

    public static function sections(): array
    {
        return self::list('schedule_sections', 'sections');
    }

    public static function steps(): array
    {
        return self::list('schedule_steps', 'steps');
    }

    private static function list(string $setting, string $cfg): array
    {
        $v = Setting::get($setting);
        $v = is_array($v) && $v ? $v : (array) config("pbp.schedule.$cfg", []);

        return array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $v), fn ($x) => $x !== '')));
    }

    public static function showLabor(): bool
    {
        return (bool) config('pbp.schedule.show_labor', false);
    }

    public static function monday(?string $date = null): CarbonImmutable
    {
        try {
            $d = $date ? CarbonImmutable::parse($date, config('pbp.site_timezone')) : CarbonImmutable::now(config('pbp.site_timezone'));
        } catch (\Throwable) {
            $d = CarbonImmutable::now(config('pbp.site_timezone'));
        }

        return $d->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('pbp.site_timezone'))->startOfDay();
    }

    public static function fmtHours(float $h): string
    {
        return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
    }
}
