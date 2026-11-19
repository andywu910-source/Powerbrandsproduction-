<?php

namespace App\Services;

use App\Exceptions\GateFailed;
use App\Models\ChecklistInstance;
use App\Models\ChecklistInstanceItem;
use App\Models\ChecklistTemplate;
use App\Models\Deviation;
use App\Models\Equipment;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChecklistService
{
    public function __construct(private SignatureService $sig) {}

    public static function rolesFor(?string $role): array
    {
        return match ($role) {
            'supervisor' => ['roles' => [User::ROLE_ADMIN, User::ROLE_SUPERVISOR]],
            'qc' => ['qc' => true],
            default => ['roles' => [User::ROLE_ADMIN, User::ROLE_SUPERVISOR, User::ROLE_OPERATOR]],
        };
    }

    /** Period key + due/overdue (UTC) for a scheduled template at a moment. */
    public static function period(ChecklistTemplate $t, Carbon $now): ?array
    {
        $tz = config('pbp.site_timezone');
        $local = $now->copy()->timezone($tz);
        [$h, $m] = array_map('intval', explode(':', $t->due_time ?: '10:00') + [0, 0]);

        switch ($t->frequency) {
            case 'daily':
                $key = $local->format('Y-m-d');
                $due = $local->copy()->startOfDay()->setTime($h, $m);
                break;
            case 'weekly':
                $key = $local->format('o-\WW');
                $due = $local->copy()->startOfWeek(Carbon::MONDAY)->addDays(max(1, min(7, (int) ($t->weekday ?: 1))) - 1)->setTime($h, $m);
                break;
            case 'monthly':
                $key = $local->format('Y-m');
                $day = min(max(1, (int) ($t->day_of_month ?: 1)), $local->daysInMonth);
                $due = $local->copy()->startOfMonth()->addDays($day - 1)->setTime($h, $m);
                break;
            default:
                return null;
        }

        return ['key' => $key, 'due_at' => $due->copy()->utc(), 'overdue_at' => $due->copy()->addHours((int) $t->grace_hours)->utc()];
    }

    /** @return string[] scope keys for a template ('' when not scoped). */
    public static function scopes(ChecklistTemplate $t): array
    {
        return match ($t->scope_type) {
            'room' => Room::where('active', true)->orderBy('id')->pluck('id')->map(fn ($id) => "room:$id")->all(),
            'equipment' => Equipment::where('active', true)->orderBy('id')->pluck('id')->map(fn ($id) => "equipment:$id")->all(),
            // One per active scale in the scale register (the checklist records which scale was checked).
            'scale' => Equipment::where('active', true)->where('is_scale', true)->orderBy('id')->pluck('id')->map(fn ($id) => "equipment:$id")->all(),
            default => [''],
        };
    }

    /**
     * Run by the scheduler every 15 minutes. Safe to run any number of times: the
     * unique key (template, scope, period) means each checklist is created once.
     * @return array{created:int, overdue:int}
     */
    public function generate(?Carbon $now = null): array
    {
        $now ??= now();
        $created = 0;
        // The first time checklists run, today's / this week's / this month's lists would already
        // be late. Those first ones are created without a "late" deadline.
        $since = Setting::get('checklists_started_at');
        if (! $since) {
            Setting::put('checklists_started_at', $now->toIso8601String());
            $since = $now->toIso8601String();
        }
        $since = Carbon::parse($since);
        $templates = ChecklistTemplate::where('active', true)->where('frequency', '!=', 'on_demand')->whereNotNull('current_version_id')->get();
        foreach ($templates as $t) {
            $p = self::period($t, $now);
            if (! $p) {
                continue;
            }
            foreach (self::scopes($t) as $scope) {
                $exists = ChecklistInstance::where('template_id', $t->id)->where('scope_key', $scope)->where('period_key', $p['key'])->exists();
                if ($exists) {
                    continue;
                }
                try {
                    $overdue = $p['overdue_at'] && $p['overdue_at']->lt($since) ? null : $p['overdue_at'];
                    DB::transaction(fn () => $this->createInstance($t, $scope, $p['key'], $p['due_at'], $overdue));
                    $created++;
                } catch (QueryException $e) {
                    // Another run created it at the same moment (unique key). Nothing to do.
                }
            }
        }
        $overdue = ChecklistInstance::whereIn('status', ['due', 'in_progress'])->whereNotNull('overdue_at')->where('overdue_at', '<', $now)->update(['status' => 'overdue', 'updated_at' => now()]);

        return ['created' => $created, 'overdue' => $overdue];
    }

    /** Retired templates (Nov 19): open checklists can't be filled in or signed; finished ones can still be verified. */
    public static function assertNotRetired(ChecklistInstance $inst): void
    {
        if ($code = \App\Support\Retirements::replacedBy($inst->template)) {
            throw GateFailed::because(\App\Support\Retirements::replacementActive($code)
                ? "This checklist was retired. Record it in the Compliance hub instead ({$code})."
                : 'This checklist was retired and is no longer kept.');
        }
    }

    public function createInstance(ChecklistTemplate $t, string $scope, string $periodKey, ?Carbon $due, ?Carbon $overdue, ?int $batchId = null): ChecklistInstance
    {
        $inst = ChecklistInstance::create([
            'public_id' => PublicId::next('CHK'), 'template_id' => $t->id, 'version_id' => $t->current_version_id,
            'scope_key' => $scope, 'period_key' => $periodKey, 'due_at' => $due, 'overdue_at' => $overdue, 'status' => 'due', 'batch_id' => $batchId,
        ]);
        foreach ($t->currentVersion->items as $item) {
            ChecklistInstanceItem::create(['instance_id' => $inst->id, 'template_item_id' => $item->id]);
        }

        return $inst;
    }

    /** On-demand checklist (e.g. the changeover clean for a batch). */
    public function createOnDemand(ChecklistTemplate $t, string $scope, ?int $batchId = null): ChecklistInstance
    {
        if (! $t->active || ! $t->current_version_id) {
            throw GateFailed::because("The checklist '{$t->name}' is not active.");
        }

        return DB::transaction(function () use ($t, $scope, $batchId) {
            $n = PublicId::nextValue("od:{$t->code}:{$scope}");

            return $this->createInstance($t, $scope, 'run-'.$n, now(), null, $batchId);
        });
    }

    public static function evaluate(ChecklistInstanceItem $item, ?string $response): ?bool
    {
        $ti = $item->templateItem;
        if ($response === null || $response === '') {
            return null;
        }

        return match ($ti->response_type) {
            'pass_fail' => $response === 'pass',
            'yes_no' => $response === 'yes',
            'numeric' => is_numeric($response) && ($ti->min === null || (float) $response >= $ti->min) && ($ti->max === null || (float) $response <= $ti->max),
            default => true,
        };
    }

    /** @param array<int, array{response?:?string, note?:?string, deviation_id?:?int}> $answers keyed by instance item id */
    public function saveAnswers(ChecklistInstance $inst, array $answers, User $by): void
    {
        self::assertNotRetired($inst);
        if (! $inst->isOpen()) {
            throw GateFailed::because('This checklist is already signed. Use a correction to change an answer.');
        }
        DB::transaction(function () use ($inst, $answers, $by) {
            foreach ($inst->items()->with('templateItem')->get() as $item) {
                if (! array_key_exists($item->id, $answers)) {
                    continue;
                }
                $a = $answers[$item->id];
                $resp = isset($a['response']) && $a['response'] !== '' ? (string) $a['response'] : null;
                $changes = ['note' => isset($a['note']) && trim((string) $a['note']) !== '' ? trim((string) $a['note']) : null];
                if (! empty($a['deviation_id'])) {
                    $changes['deviation_id'] = Deviation::whereKey((int) $a['deviation_id'])->value('id');
                }
                if ($resp !== $item->response) {
                    $changes += ['response' => $resp, 'within_limit' => self::evaluate($item, $resp),
                        'answered_by' => $resp === null ? null : $by->id, 'answered_at' => $resp === null ? null : now()];
                }
                $item->fill($changes)->save();
            }
            if ($inst->status === 'due') {
                $inst->update(['status' => 'in_progress']);
            }
        });
    }

    /** Problems that stop a checklist being signed as complete. */
    public function completionProblems(ChecklistInstance $inst): array
    {
        $p = [];
        foreach ($inst->items()->with('templateItem', 'deviation')->get() as $item) {
            $ti = $item->templateItem;
            if ($ti->required && ($item->response === null || $item->response === '')) {
                $p[] = "Item {$ti->item_no} is not answered.";
            } elseif ($item->within_limit === false) {
                if ($ti->fail_requires === 'deviation') {
                    if (! $item->deviation || ! $item->deviation->isOpened()) {
                        $p[] = "Item {$ti->item_no} failed and needs a deviation opened and linked.";
                    }
                } elseif (blank($item->note) && ! $item->deviation_id) {
                    $p[] = "Item {$ti->item_no} failed: add a note saying what was done (or link a deviation).";
                }
            }
        }

        return $p;
    }

    public function complete(ChecklistInstance $inst, int $signerId, User $by, ?string $lateReason = null, ?int $performerPersonId = null): void
    {
        self::assertNotRetired($inst);
        if (! $inst->isOpen()) {
            throw GateFailed::because('This checklist is not open.');
        }
        if ($p = $this->completionProblems($inst)) {
            throw new GateFailed($p);
        }
        $late = $inst->overdue_at !== null && $inst->overdue_at->isPast();
        if ($late && trim((string) $lateReason) === '') {
            throw GateFailed::because('This checklist is overdue. Say why it is being completed late.');
        }
        $version = $inst->version;
        DB::transaction(function () use ($inst, $signerId, $by, $lateReason, $late, $version, $performerPersonId) {
            $this->sig->sign($inst, 'completed', $signerId, $by, self::rolesFor($version->completed_by_role));
            $inst->late_entry_reason = $late ? trim((string) $lateReason) : null;
            $inst->recorded_by_user_id = $by->id;
            $inst->performed_by_person_id = $performerPersonId;
            if ($version->requiresVerifier()) {
                $inst->status = 'awaiting_verification';
                $inst->save();
            } else {
                $this->finish($inst);
            }
        });
    }

    public function verify(ChecklistInstance $inst, int $signerId, User $by): void
    {
        if ($inst->status !== 'awaiting_verification') {
            throw GateFailed::because('This checklist is not waiting for a verifier.');
        }
        $completedBy = $inst->signatures()->where('meaning', 'completed')->whereNull('invalidated_at')->pluck('user_id')->all();
        DB::transaction(function () use ($inst, $signerId, $by, $completedBy) {
            $this->sig->sign($inst, 'verified', $signerId, $by, self::rolesFor($inst->version->verifier_role) + [
                'differs_from' => $completedBy, 'differs_message' => 'The verifier must be a different person from who completed the checklist.']);
            $this->finish($inst);
        });
    }

    private function finish(ChecklistInstance $inst): void
    {
        $inst->status = 'completed';
        $inst->completed_at = now();
        $inst->save();
        $rows = $inst->items()->with('templateItem', 'answeredBy')->get()->map(fn ($i) => [$i->templateItem->item_no, $i->templateItem->prompt, $i->response, $i->note, $i->answeredBy?->name])->all();
        DriveOutbox::enqueue($inst, 'completed', [$inst->template->name, $inst->period_key, $inst->scope_key, json_encode($rows)]);
    }

    /** Is the template one-per-scale (scale register linked)? */
    public static function isPerScale(string $code): bool
    {
        return ChecklistTemplate::where('code', $code)->value('scope_type') === 'scale';
    }

    /**
     * A completed check of this scale today (site time): today's scheduled one for the scale,
     * or an extra one started and signed today.
     */
    public static function completedTodayForScale(string $code, int $equipmentId): bool
    {
        $t = ChecklistTemplate::where('code', $code)->first();
        if (! $t) {
            return false;
        }
        $p = self::period($t, now());
        $tz = config('pbp.site_timezone');
        $start = now()->timezone($tz)->startOfDay()->utc();

        return ChecklistInstance::where('template_id', $t->id)->where('scope_key', "equipment:$equipmentId")->where('status', 'completed')
            ->where(fn ($q) => $q->where('period_key', $p['key'] ?? '-')->orWhere('completed_at', '>=', $start))->exists();
    }

    /** Latest completed instance of a template for today's period (site time). */
    public static function completedToday(string $code): bool
    {
        $t = ChecklistTemplate::where('code', $code)->first();
        if (! $t) {
            return false;
        }
        $p = self::period($t, now());

        return $p && ChecklistInstance::where('template_id', $t->id)->where('period_key', $p['key'])->where('status', 'completed')->exists();
    }
}
