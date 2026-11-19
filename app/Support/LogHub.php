<?php

namespace App\Support;

use App\Models\Batch;
use App\Models\LogDefinition;
use App\Models\LogEntry;
use App\Models\LogEntryLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Compliance hub rules: definitions from config/pbp_logs.php, due / overdue status, validation and link keys.
 *
 * Due status (site time zone, weeks start Monday like Compliance Checklists):
 *  - daily / weekly / monthly / quarterly / annual: "done" when the current period has an entry (by the entry's own
 *    date); otherwise "due" by the end of the current period, or "overdue" when the previous period was missed too
 *    (a log added during the previous period is not counted as missed).
 *  - per_batch: every recent batch (in production, waiting for QC or released in the last 60 days) needs an entry
 *    for its batch number. Missing for a released batch = overdue; missing for a batch still in production = due today.
 *  - per_event: never due ("as needed").
 */
class LogHub
{
    public const PERIODIC = ['daily', 'weekly', 'monthly', 'quarterly', 'annual'];
    public const LINK_TYPES = ['metrc' => 'metrc_uid', 'batch' => 'batch', 'equipment' => 'equipment', 'room' => 'room', 'employee' => 'employee', 'signature' => 'employee'];
    public const METRC_LEN = 24;
    public const BATCH_WINDOW_DAYS = 60;

    public static function tz(): string
    {
        return (string) config('pbp.site_timezone', 'America/New_York');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz())->startOfDay();
    }

    public static function fieldKey(string $label): string
    {
        return Str::limit(Str::slug(str_replace(['#', '%', '&', '+', '°'], [' no ', ' pct ', ' and ', ' plus ', ''], $label), '_'), 60, '') ?: 'field';
    }

    /** Normalised field list for one config log: unique keys, defaults filled in. */
    public static function normaliseFields(array $fields): array
    {
        $out = [];
        $seen = [];
        foreach ($fields as $f) {
            $label = rtrim(trim($f[0]), ':');
            $key = self::fieldKey($label);
            $base = $key;
            for ($i = 2; isset($seen[$key]); $i++) {
                $key = $base.'_'.$i;
            }
            $seen[$key] = true;
            $type = $f[1] ?? 'text';
            $out[] = array_filter([
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'req' => (bool) ($f['req'] ?? false),
                'link' => $f['link'] ?? (self::LINK_TYPES[$type] ?? null),
                'options' => $f['options'] ?? null,
                'meaning' => $type === 'signature' ? ($f['meaning'] ?? 'performed') : null,
                'differs_from' => isset($f['differs_from']) ? self::fieldKey(rtrim($f['differs_from'], ':')) : null,
                'new' => (bool) ($f['new'] ?? false),
                'renamed_from' => $f['renamed_from'] ?? null,
                'req_if' => isset($f['req_if']) ? [self::fieldKey(rtrim($f['req_if'][0], ':')), (string) $f['req_if'][1]] : null,
                'removed' => (bool) ($f['removed'] ?? false),
            ], fn ($v) => $v !== null && $v !== false);
        }

        return $out;
    }

    /** Create / update every definition from config/pbp_logs.php (safe to re-run; never deletes; 'retired' logs become inactive). */
    public static function syncDefinitions(): void
    {
        // During an upgrade, migrate can run while bootstrap/cache/config.php is still the OLD release's
        // cached config (no pbp_logs key). Read the file directly then, so the definitions are never skipped.
        $cfg = config('pbp_logs') ?: require config_path('pbp_logs.php');
        $managerGroups = (array) ($cfg['manager_groups'] ?? []);
        foreach ((array) ($cfg['logs'] ?? []) as $i => $log) {
            $attrs = [
                'slug' => Str::slug(str_replace('&', ' ', $log['code'])),
                'name' => $log['name'],
                'group_key' => $log['group'],
                'finding' => $log['finding'] ?? null,
                'frequency' => $log['frequency'],
                'manager_only' => in_array($log['group'], $managerGroups, true),
                'confirmed' => (bool) ($log['confirmed'] ?? true),
                'partial' => (bool) ($log['partial'] ?? false),
                'fields' => self::normaliseFields($log['fields']),
                'drive_url' => $log['drive'] ?? null,
                'note' => $log['note'] ?? null,
                'sort_order' => $i + 1,
                'active' => empty($log['retired']),
            ];
            $d = LogDefinition::where('code', $log['code'])->first();
            if ($d) {
                $d->fill($attrs)->save();
            } else {
                LogDefinition::create(['code' => $log['code']] + $attrs);
            }
        }
    }

    /**
     * Is the Compliance hub shown for this request? Needs 'logs' in PBP_MODULES. With PBP_LOGS_PREVIEW_ONLY=true
     * it is shown only to a session opened by a preview link (php artisan logs:preview-link).
     */
    public static function hubVisible(?\Illuminate\Http\Request $r = null): bool
    {
        if (! \App\Support\Modules::enabled('logs')) {
            return false;
        }
        if (! config('pbp.logs_preview_only')) {
            return true;
        }
        $r ??= request();

        return \App\Models\LogsPreviewToken::enabled() && $r->hasSession()
            && $r->session()->has(\App\Models\LogsPreviewToken::SESSION_KEY);
    }

    /** Definitions this user may see, in config order. */
    public static function visible(User $u): Collection
    {
        return LogDefinition::where('active', true)->orderBy('sort_order')->get()->filter(fn ($d) => $d->visibleTo($u))->values();
    }

    // ------------------------------------------------------------------ periods

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} start and end (dates) of the period holding $day. */
    public static function period(string $freq, CarbonImmutable $day): array
    {
        $day = $day->startOfDay();

        return match ($freq) {
            'daily' => [$day, $day],
            'weekly' => [$day->startOfWeek(CarbonImmutable::MONDAY), $day->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)],
            'monthly' => [$day->startOfMonth(), $day->endOfMonth()->startOfDay()],
            'quarterly' => [$day->firstOfQuarter(), $day->lastOfQuarter()->startOfDay()],
            'annual' => [$day->startOfYear(), $day->endOfYear()->startOfDay()],
        };
    }

    private static function step(string $freq, CarbonImmutable $d, int $n): CarbonImmutable
    {
        return match ($freq) {
            'daily' => $d->addDays($n),
            'weekly' => $d->addWeeks($n),
            'monthly' => $d->addMonthsNoOverflow($n),
            'quarterly' => $d->addMonthsNoOverflow(3 * $n),
            'annual' => $d->addYears($n),
        };
    }

    // ------------------------------------------------------------------ status

    /**
     * @return array{state: string, due_on: ?CarbonImmutable, label: string, last: ?string, missing: string[]}
     *   state: done | due | overdue | as_needed
     */
    public static function status(LogDefinition $d, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $live = LogEntry::where('log_definition_id', $d->id)->whereNull('voided_at');
        $last = (clone $live)->max('occurred_on');
        $last = $last ? CarbonImmutable::parse($last)->toDateString() : null;

        if ($d->frequency === 'per_event') {
            return ['state' => 'as_needed', 'due_on' => null, 'label' => 'As needed', 'last' => $last, 'missing' => []];
        }

        if ($d->frequency === 'per_batch') {
            return self::batchStatus($d, $today, $last);
        }

        [$ps, $pe] = self::period($d->frequency, $today);
        $inCurrent = self::within(clone $live, $ps, $pe)->exists();
        if ($inCurrent) {
            [, $nextEnd] = self::period($d->frequency, self::step($d->frequency, $ps, 1));

            return ['state' => 'done', 'due_on' => $nextEnd, 'label' => 'Done', 'last' => $last, 'missing' => []];
        }
        [$pps, $ppe] = self::period($d->frequency, self::step($d->frequency, $ps, -1));
        $createdOn = CarbonImmutable::parse($d->created_at)->timezone(self::tz())->startOfDay();
        $newLog = $createdOn->greaterThan($pps);
        $inPrev = self::within(clone $live, $pps, $ppe)->exists();
        if (! $inPrev && ! $newLog) {
            return ['state' => 'overdue', 'due_on' => $ppe, 'label' => 'Overdue', 'last' => $last, 'missing' => []];
        }

        return ['state' => 'due', 'due_on' => $pe, 'label' => $pe->equalTo($today) ? 'Due today' : 'Due '.$pe->format('M j'), 'last' => $last, 'missing' => []];
    }

    /** Entries dated $from..$to inclusive (works whether the date column holds 'Y-m-d' or 'Y-m-d 00:00:00'). */
    private static function within($q, CarbonImmutable $from, CarbonImmutable $to)
    {
        return $q->where('occurred_on', '>=', $from->toDateString())->where('occurred_on', '<', $to->addDay()->toDateString());
    }

    private static function batchStatus(LogDefinition $d, CarbonImmutable $today, ?string $last): array
    {
        $batches = Batch::query()->whereIn('status', ['in_process', 'in_qc', 'released'])
            ->where('created_at', '>=', $today->subDays(self::BATCH_WINDOW_DAYS)->utc())
            ->orderBy('id')->get(['id', 'batch_number', 'status']);
        if ($batches->isEmpty()) {
            return ['state' => 'done', 'due_on' => null, 'label' => 'No open batches', 'last' => $last, 'missing' => []];
        }
        $have = LogEntryLink::query()->where('kind', 'batch')
            ->whereIn('log_entry_id', LogEntry::where('log_definition_id', $d->id)->whereNull('voided_at')->select('id'))
            ->pluck('value')->all();
        $missing = $batches->reject(fn ($b) => in_array(mb_strtoupper($b->batch_number), $have, true));
        if ($missing->isEmpty()) {
            return ['state' => 'done', 'due_on' => null, 'label' => 'All batches logged', 'last' => $last, 'missing' => []];
        }
        $overdue = $missing->contains(fn ($b) => $b->status === 'released');
        $n = $missing->count();

        return ['state' => $overdue ? 'overdue' : 'due', 'due_on' => $today, 'label' => ($overdue ? 'Overdue' : 'Due today').": {$n} batch".($n === 1 ? '' : 'es'),
            'last' => $last, 'missing' => $missing->pluck('batch_number')->all()];
    }

    /** Status for many definitions + strip counts. */
    public static function board(Collection $defs, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $weekEnd = $today->startOfWeek(CarbonImmutable::MONDAY)->addDays(6);
        $rows = $defs->map(function ($d) use ($today, $weekEnd) {
            $s = self::status($d, $today);
            $s['today'] = $s['state'] === 'due' && $s['due_on'] && $s['due_on']->equalTo($today);
            $s['week'] = in_array($s['state'], ['due', 'overdue'], true) && $s['due_on'] && $s['due_on']->lessThanOrEqualTo($weekEnd);

            return ['def' => $d, 'status' => $s];
        });

        return [
            'rows' => $rows,
            'counts' => [
                'overdue' => $rows->where('status.state', 'overdue')->count(),
                'today' => $rows->where('status.today', true)->count(),
                'week' => $rows->where('status.week', true)->count(),
                'all' => $rows->count(),
            ],
        ];
    }

    // ------------------------------------------------------------------ validation + links

    public static function normaliseMetrc(?string $v): ?string
    {
        $v = $v === null ? null : strtoupper(preg_replace('/\s+/', '', $v));

        return $v === '' ? null : $v;
    }

    /** Fields asked for on a new entry ('removed' fields are only shown on past entries that have a value). */
    public static function formFields(LogDefinition $d): array
    {
        return array_values(array_filter($d->fields, fn ($f) => empty($f['removed'])));
    }

    /** Label of the field a req_if points at, and whether it is a checkbox. */
    public static function reqIfTarget(LogDefinition $d, array $f): array
    {
        $t = collect($d->fields)->firstWhere('key', $f['req_if'][0]);

        return [$t['label'] ?? $f['req_if'][0], ($t['type'] ?? null) === 'bool'];
    }

    /** Validation rules for the entry form (inputs are f[key]). */
    public static function rules(LogDefinition $d): array
    {
        $rules = [];
        foreach (self::formFields($d) as $f) {
            $base = ! empty($f['req']) ? ['required'] : (! empty($f['req_if']) ? ['nullable', 'required_if:f.'.$f['req_if'][0].','.$f['req_if'][1]] : ['nullable']);
            $type = match ($f['type']) {
                'date' => ['date'],
                'time' => ['date_format:H:i'],
                'datetime' => ['date'],
                'number' => ['numeric'],
                'yn' => ['in:Y,N'],
                'bool' => ['boolean'],
                'select' => [\Illuminate\Validation\Rule::in($f['options'] ?? [])],
                'metrc' => ['string', 'size:'.self::METRC_LEN, 'alpha_num'],
                'url' => ['url', 'max:2000'],
                'textarea' => ['string', 'max:5000'],
                'signature' => ['integer', 'exists:users,id'],
                'file' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
                default => ['string', 'max:255'],
            };
            $rules['f.'.$f['key']] = array_merge($base, $type);
        }

        return $rules;
    }

    public static function messages(LogDefinition $d): array
    {
        $m = [];
        foreach ($d->fields as $f) {
            if (! empty($f['req_if'])) {
                [$tl, $isBool] = self::reqIfTarget($d, $f);
                $m['f.'.$f['key'].'.required_if'] = $isBool ? "{$f['label']} is required when “{$tl}” is ticked." : "{$f['label']} is required when {$tl} is {$f['req_if'][1]}.";
            }
            if ($f['type'] === 'metrc') {
                $m['f.'.$f['key'].'.size'] = "{$f['label']} must be exactly ".self::METRC_LEN.' characters (a Metrc UID).';
                $m['f.'.$f['key'].'.alpha_num'] = "{$f['label']} may only contain letters and numbers.";
            }
        }

        return $m;
    }

    public static function attributes(LogDefinition $d): array
    {
        return collect($d->fields)->mapWithKeys(fn ($f) => ['f.'.$f['key'] => $f['label']])->all();
    }

    public static function normaliseLink(string $kind, string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return $kind === 'employee' ? mb_strtolower($value) : mb_strtoupper($kind === 'metrc_uid' ? str_replace(' ', '', $value) : $value);
    }

    /** Link keys found in an entry's data: link-typed fields, plus Metrc UIDs written inside free text. */
    public static function extractLinks(LogDefinition $d, array $data): array
    {
        $links = [];
        foreach ($d->fields as $f) {
            $v = $data[$f['key']] ?? null;
            if ($f['type'] === 'signature') {
                $v = is_array($v) ? ($v['name'] ?? null) : null;
            }
            if (! is_string($v) || trim($v) === '') {
                continue;
            }
            if (! empty($f['link'])) {
                $links[] = ['kind' => $f['link'], 'value' => self::normaliseLink($f['link'], $v), 'field_key' => $f['key']];
            } elseif (in_array($f['type'], ['text', 'textarea'], true) && preg_match_all('/\b1A[0-9A-Z]{22}\b/i', $v, $m)) {
                foreach ($m[0] as $uid) {
                    $links[] = ['kind' => 'metrc_uid', 'value' => strtoupper($uid), 'field_key' => $f['key']];
                }
            }
        }

        return collect($links)->unique(fn ($l) => $l['kind'].'|'.$l['value'])->values()->all();
    }

    /**
     * Other live entries sharing a link key with $entry, grouped by the shared key.
     * Entries in logs the user cannot see are left out.
     * @return Collection<int, array{kind: string, value: string, entries: Collection}>
     */
    public static function related(LogEntry $entry, User $u, int $perKey = 10): Collection
    {
        $visible = self::visible($u)->pluck('id')->all();

        return $entry->links->map(function ($l) use ($entry, $visible, $perKey) {
            $ids = LogEntryLink::where('kind', $l->kind)->where('value', $l->value)->where('log_entry_id', '!=', $entry->id)->pluck('log_entry_id')->unique();
            $entries = LogEntry::with(['definition', 'enteredBy'])->whereIn('id', $ids)->whereNull('voided_at')
                ->whereIn('log_definition_id', $visible)->orderByDesc('occurred_on')->orderByDesc('id')->limit($perKey)->get();

            return ['kind' => $l->kind, 'value' => $l->value, 'entries' => $entries];
        })->filter(fn ($g) => $g['entries']->isNotEmpty())->unique(fn ($g) => $g['kind'].'|'.$g['value'])->values();
    }

    /** Display text for one stored field value. */
    public static function display(array $f, $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($f['type']) {
            'bool' => $v ? '✓' : '—',
            'signature' => is_array($v) ? ($v['name'] ?? '') : '',
            'file' => is_array($v) ? ($v['filename'] ?? 'file') : '',
            'date' => CarbonImmutable::parse($v)->format('M j, Y'),
            'datetime' => CarbonImmutable::parse($v)->format('M j, Y g:i A'),
            default => (string) $v,
        };
    }
}
