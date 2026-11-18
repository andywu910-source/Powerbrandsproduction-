<?php

namespace Database\Seeders;

use App\Models\ComplianceEntry;
use App\Models\ComplianceItem;
use App\Models\User;
use App\Support\Compliance;
use App\Support\Preview;
use Illuminate\Database\Seeder;

/**
 * PREVIEW ONLY: a couple of items checked in the current week, one past week of history and one monthly item,
 * so Andy can try Compliance Checklists. Refuses anywhere but the preview site. Idempotent (only adds missing rows).
 */
class ComplianceSampleSeeder extends Seeder
{
    public function run(): void
    {
        self::seed();
    }

    public static function seed(): void
    {
        if (! Preview::enabled()) {
            throw new \RuntimeException('ComplianceSampleSeeder only runs on the preview site (APP_ENV=staging, PBP_PREVIEW=true).');
        }
        // Sample people so the Assignee / Completed by pickers have names to try (Jack shows he is never offered).
        // Random passwords nobody knows: these accounts cannot be signed in to.
        foreach ([['Leo (sample)', 'supervisor'], ['Jenny (sample)', 'supervisor'], ['Sae (sample)', 'operator'], ['Jack (sample)', 'operator']] as [$n, $role]) {
            $email = strtolower(strtok($n, ' ')).'.sample@preview.invalid';
            User::where('email', $email)->exists() || User::create(['name' => $n, 'email' => $email, 'password' => \Illuminate\Support\Str::random(48),
                'role' => $role, 'is_active' => true, 'must_change_password' => true]);
        }
        $people = Compliance::pickable();
        if ($people->isEmpty()) {
            return;
        }
        $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->get()->reject(fn ($u) => Compliance::excluded($u))->first() ?? $people->first();
        $other = $people->firstWhere('email', 'leo.sample@preview.invalid') ?? $people->first(fn ($u) => $u->id !== $admin->id) ?? $admin;
        $weekly = ComplianceItem::where('frequency', 'weekly')->where('hidden', false)->orderBy('sort_order')->get();
        $monthly = ComplianceItem::where('frequency', 'monthly')->where('hidden', false)->orderBy('sort_order')->get();
        $cur = Compliance::periodStart('weekly');
        $past = $cur->subWeek();
        $mon = Compliance::periodStart('monthly');

        $put = function ($item, $start, array $v) use ($admin) {
            if (! $item || ComplianceEntry::where('item_id', $item->id)->whereDate('period_start', $start->toDateString())->exists()) {
                return;
            }
            ComplianceEntry::create(['item_id' => $item->id, 'period_start' => $start->toDateString(), 'updated_by_user_id' => $admin->id] + $v);
        };
        // Current week: 2 checked, 1 assigned but open.
        $day = Compliance::today()->max($cur);
        $put($weekly[0] ?? null, $cur, ['done' => true, 'completed_by_user_id' => $admin->id, 'completed_on' => $day->toDateString(), 'assignee_user_id' => $admin->id]);
        $put($weekly[2] ?? null, $cur, ['done' => true, 'completed_by_user_id' => $other->id, 'completed_on' => $day->toDateString(), 'note' => 'Two deliveries, both matched in Metrc.']);
        $put($weekly[13] ?? null, $cur, ['done' => false, 'assignee_user_id' => $other->id]);
        // Last week: 11 of 14 done (history).
        foreach ($weekly->take(11) as $i => $it) {
            $put($it, $past, ['done' => true, 'completed_by_user_id' => ($i % 2 ? $other : $admin)->id, 'completed_on' => $past->addDays(min(4, $i % 5))->toDateString(),
                'note' => $i === 5 ? 'One destruction photo re-filed.' : null]);
        }
        // This month: 1 checked.
        $put($monthly[3] ?? null, $mon, ['done' => true, 'completed_by_user_id' => $admin->id, 'completed_on' => $mon->toDateString(), 'note' => 'Monthly service done; report in LOGS.']);
    }
}
