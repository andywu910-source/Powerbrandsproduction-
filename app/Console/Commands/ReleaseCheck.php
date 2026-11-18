<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only checks run by upgrade.sh after an upgrade (a failure rolls the upgrade back).
 * Changes nothing: no logins are recorded, the signed-in page check runs in-process with an
 * in-memory session.
 *   php artisan pbp:release-check --module=labels --setting-off=gate_refuse_uninspected_scales --absent-route=preview.guest --signed-in=/labels
 */
class ReleaseCheck extends Command
{
    protected $signature = 'pbp:release-check {--module=* : tabs that must be on} {--setting-off=* : settings that must be off}
        {--absent-route=* : route names that must not exist} {--signed-in=* : pages that must return 200 for a signed-in admin}
        {--column=* : table.column that must exist} {--batch-pick : end-to-end Labels batch search + pick (signed in)} {--editor : with --batch-pick: the label form has the on-label editor and the product line} {--ocm : Batch Log shows the OCM lab / retention sample columns} {--scale-block-off : the scale block must be off (or have switched itself on because every scale has a date)}
        {--statuses : Batch Log statuses Incoming / In production / In lab / Packaging-COA / Finished, colour-coded}
        {--preview-only : Fails unless this is the preview site (APP_ENV=staging, PBP_PREVIEW=true): keeps preview bundles off live}
        {--schedule : Weekly Schedule tab works (week, today, people pages; labor dollars hidden unless switched on)}
        {--compliance : Compliance Checklists tab works (weekly / monthly / history / items pages; Jack never pickable)}
        {--job-picker : Start a batch has the Brand › Product line › Flavor pickers and every active product resolves to exactly one choice}';

    protected $description = 'Post-upgrade checks (read-only)';

    public function handle(): int
    {
        $bad = 0;
        $ok = function (bool $pass, string $what) use (&$bad) {
            $this->line(($pass ? 'OK    ' : 'FAIL  ').$what);
            $bad += $pass ? 0 : 1;
        };
        foreach ($this->option('module') as $m) {
            $ok(Modules::enabled($m), "tab '$m' is on");
        }
        foreach ($this->option('setting-off') as $k) {
            Setting::flush();
            $ok(Setting::get($k) === false, "setting '$k' is off");
        }
        if ($this->option('scale-block-off')) {
            Setting::flush();
            $auto = (bool) Setting::get(\App\Services\ScaleBlock::AUTO_DONE);
            $ok(! \App\Services\ScaleBlock::on() || $auto, 'scale block is off'.($auto ? ' (switched itself on: every scale has an inspection date)' : '').' · '.\App\Services\ScaleBlock::statusLine());
        }
        foreach ($this->option('absent-route') as $n) {
            $ok(! Route::has($n), "route '$n' is not registered");
        }
        foreach ($this->option('column') as $tc) {
            [$t, $c] = array_pad(explode('.', $tc, 2), 2, '');
            $ok(Schema::hasColumn($t, $c), "column $tc exists");
        }
        if ($pages = $this->option('signed-in')) {
            $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
            if (! $admin) {
                $ok(false, 'an active admin exists for the signed-in page check');
            } else {
                $admin->must_change_password = false;   // in memory only (never saved)
                config(['session.driver' => 'array']);
                foreach ($pages as $path) {
                    Auth::guard('web')->setUser($admin);
                    $res = app(Kernel::class)->handle(Request::create($path, 'GET'));
                    $ok($res->getStatusCode() === 200, "$path returns 200 when signed in (got {$res->getStatusCode()})");
                }
            }
        }

        if ($this->option('batch-pick')) {
            $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
            $get = function (string $path) use ($admin) {
                $admin->must_change_password = false;   // in memory only
                config(['session.driver' => 'array']);
                Auth::guard('web')->setUser($admin);

                return app(Kernel::class)->handle(Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => str_contains($path, '/labels/batches') ? 'application/json' : 'text/html']));
            };
            $b = \App\Models\Batch::with('product.brand')->where('status', '!=', 'void')->orderByDesc('id')->first();
            if (! $admin) {
                $ok(false, 'an active admin exists for the batch picker check');
            } else {
                $res = $get('/labels');
                $ok($res->getStatusCode() === 200 && str_contains((string) $res->getContent(), 'id="batchDrop"'), 'Labels batch picker is the live dropdown');
            }
            if (! $admin) {
            } elseif (! $b) {
                $js = (array) json_decode((string) $get('/labels/batches?q=ab')->getContent(), true);
                $ok(($js['message'] ?? '') === 'The Batch Log has no batches yet', "empty Batch Log: search says '".($js['message'] ?? '?')."'");
                $this->line('SKIP  batch pick: no batches yet');
            } else {
                $word = (string) (collect(preg_split('/\s+/u', (string) $b->product?->name))->first(fn ($w) => mb_strlen($w) >= 3) ?? $b->batch_number);
                $res = $get('/labels/batches?q='.rawurlencode($word));
                $js = (array) json_decode((string) $res->getContent(), true);
                $ids = $res->getStatusCode() === 200 ? array_column((array) ($js['items'] ?? $js), 'id') : [];
                $ok($res->getStatusCode() === 200 && in_array($b->id, $ids, true), "batch search '$word' returns 200 and finds batch #{$b->id} (got {$res->getStatusCode()}, ".count($ids).' hits)');
                $part = mb_strtoupper(mb_substr($word, 0, 3));
                $js = (array) json_decode((string) $get('/labels/batches?q='.rawurlencode($part))->getContent(), true);
                $ok(in_array($b->id, array_column((array) ($js['items'] ?? []), 'id'), true), "partial, other-case search '$part' finds batch #{$b->id}");
                $res = $get('/labels/new?batch_id='.$b->id);
                $ok($res->getStatusCode() === 200, "picking batch #{$b->id} opens the label form (got {$res->getStatusCode()})");
                if ($this->option('editor')) {
                    $html = (string) $res->getContent();
                    $line = \App\Services\LabelService::productLine($b->product);
                    $ok(str_contains($html, 'id="labelEditor"') && str_contains($html, 'id="layoutInput"') && str_contains($html, 'data-line="0"'), 'the label form has the on-label editor');
                    $ok($line !== '' && str_contains($html, 'value="'.e($line).'"'), "product line is '$line'");
                    $bc = strtoupper(preg_replace('/\s+/', '', (string) $b->barcode_number));
                    $ok(str_contains($html, 'name="f[barcode_value]" value="'.e($bc).'"'), 'the label form has the Barcode box'.($bc !== '' ? ' prefilled from the Batch Log' : ' (batch has none; can be typed)'));
                }
                $res = $get('/labels/new?q='.rawurlencode((string) $b->product?->name));
                $ok($res->getStatusCode() === 200, "typing the product name and pressing Use this batch returns 200 (got {$res->getStatusCode()})");
                $res = $get('/labels/new?q='.rawurlencode($b->batch_number));
                $ok($res->getStatusCode() === 200, "typing the batch number returns 200 (got {$res->getStatusCode()})");
            }
        }

        if ($this->option('ocm')) {
            $ok(\App\Support\OcmSampling::perTest(315) === 5 && \App\Support\OcmSampling::retention(315) === 10 && \App\Support\OcmSampling::perTest(150001) === null, 'OCM Table 1: 315 units -> 5 per test, 10 retained; over 150,000 refused');
            $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
            if ($admin) {
                $admin->must_change_password = false;
                config(['session.driver' => 'array']);
                Auth::guard('web')->setUser($admin);
                $res = app(Kernel::class)->handle(Request::create('/batch-log?status=all', 'GET'));
                $html = (string) $res->getContent();
                preg_match('~<thead>(.*?)</thead>~s', $html, $hm);
                $ok($res->getStatusCode() === 200 && str_contains($html, 'Lab samples (per test)') && str_contains($html, 'Retention samples needed') && ! str_contains($hm[1] ?? '', 'Retain until'),
                    "Batch Log shows the lab / retention sample columns, Retain until hidden (got {$res->getStatusCode()})");
            }
        }

        if ($this->option('job-picker')) {
            $e = \App\Services\ProductPicker::entries();
            $active = \App\Models\Product::where('active', true)->count();
            $keys = array_map(fn ($x) => \App\Support\ProductLine::key($x['brand_id'].'|'.$x['line'].'|'.$x['flavor']), $e);
            $ids = array_column($e, 'id');
            $sorted = count(array_filter($e, fn ($x) => $x['sorted']));
            $ok(count($e) === $active && count(array_unique($ids)) === $active, "every active product is in the picker once ($active)");
            $ok(count(array_unique($keys)) === count($keys), 'every Brand › Product line › Flavor choice is one product');
            $this->line("      $sorted sorted into a product line, ".(count($e) - $sorted).' under "'.\App\Support\ProductLine::OTHER.'"');
            $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
            if ($admin) {
                $admin->must_change_password = false;
                config(['session.driver' => 'array']);
                Auth::guard('web')->setUser($admin);
                $res = app(Kernel::class)->handle(Request::create('/production', 'GET'));
                $html = (string) $res->getContent();
                $ok($res->getStatusCode() === 200 && str_contains($html, 'id="jobPicker"') && str_contains($html, 'id="jp-brand"') && str_contains($html, 'id="jp-line"') && str_contains($html, 'id="jp-flavor"'),
                    "Start a batch shows Brand › Product line › Flavor (got {$res->getStatusCode()})");
                if ($first = $e[0] ?? null) {
                    $res = app(Kernel::class)->handle(Request::create('/production?product_id='.$first['id'], 'GET'));
                    $html = (string) $res->getContent();
                    $ok($res->getStatusCode() === 200 && str_contains($html, 'value="'.e($first['flavor']).'"') && str_contains($html, 'id="selProduct"'),
                        'a product picked by link fills all three ('.$first['brand'].' › '.$first['line'].' › '.$first['flavor'].')');
                }
                $res = app(Kernel::class)->handle(Request::create('/batch-log', 'GET'));
                $html = (string) $res->getContent();
                preg_match('~<thead>(.*?)</thead>~s', $html, $m);
                $head = $m[1] ?? '';
                $ok($res->getStatusCode() === 200 && str_contains($head, 'Status') && ! str_contains($head, '>Packed') && ! str_contains($head, '>Finished') && ! str_contains($head, '>QC') && str_contains($html, 'pbTipPop'),
                    "Batch Log: no Packed / Finished / QC columns, info tips present (got {$res->getStatusCode()})");
            }
            $missing = \App\Models\Batch::where(fn ($q) => $q->whereNull('log_status')->orWhereNotIn('log_status', array_keys(\App\Models\Batch::LOG_STATUSES)))->count();
            $ok($missing === 0, "every batch has a Batch Log status ($missing without)");
            $counts = \App\Models\Batch::where('status', '!=', 'void')->selectRaw('log_status, COUNT(*) c')->groupBy('log_status')->pluck('c', 'log_status')->all();
            $this->line('      Batch Log status (not void): '.implode(', ', array_map(fn ($k, $l) => "$l ".($counts[$k] ?? 0), array_keys(\App\Models\Batch::LOG_STATUSES), \App\Models\Batch::LOG_STATUSES)));
        }

        if ($this->option('statuses')) {
            $ok(array_keys(\App\Models\Batch::LOG_STATUSES) === ['incoming', 'in_production', 'in_lab', 'packaging_coa', 'finished'], 'statuses: '.implode(' / ', \App\Models\Batch::LOG_STATUSES));
            $css = (string) @file_get_contents(public_path('css/pbp.css'));
            $ok(collect(\App\Models\Batch::LOG_STATUS_COLORS)->every(fn ($c, $k) => str_contains($css, ".lst-$k { color: $c; }")), 'status colours are in the stylesheet');
            $bad2 = \App\Models\Batch::where(fn ($q) => $q->whereNull('log_status')->orWhereNotIn('log_status', array_keys(\App\Models\Batch::LOG_STATUSES)))->count();
            $ok($bad2 === 0, "every batch has a valid status ($bad2 without)");
            $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
            if ($admin) {
                $admin->must_change_password = false;
                config(['session.driver' => 'array']);
                Auth::guard('web')->setUser($admin);
                $res = app(Kernel::class)->handle(Request::create('/batch-log', 'GET'));
                $html = (string) $res->getContent();
                preg_match('~<thead>(.*?)</thead>~s', $html, $hm);
                $ok($res->getStatusCode() === 200 && str_contains($html, 'lst-') && ! str_contains($hm[1] ?? '', 'Retain until'), "Batch Log: coloured statuses, no Retain until column (got {$res->getStatusCode()})");
                $res = app(Kernel::class)->handle(Request::create('/production', 'GET'));
                $ok($res->getStatusCode() === 200, "/production returns 200 (got {$res->getStatusCode()})");
            }
            $c = \App\Models\Batch::where('status', '!=', 'void')->selectRaw('log_status, COUNT(*) c')->groupBy('log_status')->pluck('c', 'log_status')->all();
            $this->line('      '.implode(', ', array_map(fn ($k, $l) => "$l ".($c[$k] ?? 0), array_keys(\App\Models\Batch::LOG_STATUSES), \App\Models\Batch::LOG_STATUSES)));
        }

        if ($this->option('preview-only')) {
            $ok(\App\Support\Preview::enabled(), 'this is the preview site (preview-only release)');
        }

        if ($this->option('schedule')) {
            $ok(\App\Support\Modules::enabled('schedule'), "tab 'schedule' is on");
            // Diagnostic only: key names and yes/no, never the values.
            $envLines = @file(base_path('.env'), FILE_IGNORE_NEW_LINES) ?: [];
            $keyLines = array_values(array_filter($envLines, fn ($l) => preg_match('/^\s*(export\s+)?PBP_MODULES\s*=/', $l)));
            $commented = count(array_filter($envLines, fn ($l) => preg_match('/^\s*#\s*PBP_MODULES\s*=/', $l)));
            $this->line('      .env: PBP_MODULES lines '.count($keyLines).', commented '.$commented.', lists schedule: '
                .(collect($keyLines)->contains(fn ($l) => stripos($l, 'schedule') !== false) ? 'yes' : 'no')
                .'; PBP_MODULES_OFF set: '.(trim((string) config('pbp.modules_off')) !== '' ? 'yes' : 'no')
                .'; config cached: '.(app()->configurationIsCached() ? 'yes' : 'no')
                .'; switched on by this release: '.(in_array('schedule', (array) config('pbp.modules_release_on', []), true) ? 'yes' : 'no'));
            foreach (['schedule_employees', 'schedule_weeks', 'schedule_roster', 'schedule_cells', 'schedule_jobs', 'schedule_notes'] as $t) {
                $ok(\Illuminate\Support\Facades\Schema::hasTable($t), "table $t exists");
            }
            $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
            if ($admin && \App\Support\Modules::enabled('schedule')) {
                $admin->must_change_password = false;
                config(['session.driver' => 'array']);
                Auth::guard('web')->setUser($admin);
                $w = \App\Models\ScheduleWeek::orderByDesc('week_start')->first();
                foreach (['/schedule'.($w ? '?week='.$w->week_start->toDateString() : ''), '/schedule/today', '/schedule/people'] as $path) {
                    $res = app(Kernel::class)->handle(Request::create($path, 'GET'));
                    $ok($res->getStatusCode() === 200, "$path returns 200 when signed in (got {$res->getStatusCode()})");
                }
                if ($w) {
                    $res = app(Kernel::class)->handle(Request::create('/schedule?week='.$w->week_start->toDateString(), 'GET'));
                    $html = (string) $res->getContent();
                    $ok(str_contains($html, 'id="schGrid"') && (\App\Support\Schedule::showLabor() || ! str_contains($html, 'Labor $')),
                        'week grid shows; labor dollars '.(\App\Support\Schedule::showLabor() ? 'shown (switched on)' : 'hidden'));
                }
            }
            $this->line('      schedule editors: '.(implode(', ', \App\Support\Schedule::editorsNow()) ?: 'nobody'));
            $this->line('      weeks: '.\App\Models\ScheduleWeek::count().', people: '.\App\Models\ScheduleEmployee::where('active', true)->count().' active');
        }

        if ($this->option('compliance')) {
            $ok(\App\Support\Modules::enabled('compliance'), "tab 'compliance' is on");
            $this->line('      switched on by this release: '.(in_array('compliance', (array) config('pbp.modules_release_on', []), true) ? 'yes' : 'no')
                .'; config cached: '.(app()->configurationIsCached() ? 'yes' : 'no'));
            foreach (['compliance_items', 'compliance_entries'] as $t) {
                $ok(\Illuminate\Support\Facades\Schema::hasTable($t), "table $t exists");
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('compliance_items')) {
                $cnt = fn ($f) => \App\Models\ComplianceItem::where('frequency', $f)->where('hidden', false)->count();
                [$wk, $mo, $qu, $an] = [$cnt('weekly'), $cnt('monthly'), $cnt('quarterly'), $cnt('annual')];
                $hasStanding = \Illuminate\Support\Facades\Schema::hasColumn('compliance_items', 'standing');
                $st = $hasStanding ? \App\Models\ComplianceItem::where('frequency', 'annual')->where('hidden', false)->where('standing', true)->count() : 0;
                $ok($wk >= 14 && $mo >= 13, "checklist items: weekly $wk, monthly $mo, quarterly $qu, annual ".($an - $st)." + $st standing (".($wk + $mo + $qu + $an).' visible)');
                if ($hasStanding) {
                    $ok(\App\Models\ComplianceItem::where('frequency', 'weekly')->where('title', 'Weekly 10% cycle count (SOP-INV-03)')->exists()
                        && ! \App\Models\ComplianceItem::where('title', 'Physical vs Metrc spot check')->exists(), "weekly 'Physical vs Metrc spot check' renamed in place to 'Weekly 10% cycle count (SOP-INV-03)'");
                }
                $jacks = User::where('is_active', true)->get()->filter(fn ($u) => \App\Support\Compliance::excluded($u))->count();
                $ok(\App\Support\Compliance::pickable()->every(fn ($u) => ! \App\Support\Compliance::excluded($u)), "Jack is not pickable ($jacks active user(s) excluded)");
                $admin = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->orderBy('id')->first();
                if ($admin && \App\Support\Modules::enabled('compliance')) {
                    $admin->must_change_password = false;
                    config(['session.driver' => 'array']);
                    Auth::guard('web')->setUser($admin);
                    foreach (['/compliance/weekly', '/compliance/monthly', '/compliance/quarterly', '/compliance/annual', '/compliance/history', '/compliance/setup'] as $path) {
                        $res = app(Kernel::class)->handle(Request::create($path, 'GET'));
                        $ok($res->getStatusCode() === 200, "$path returns 200 when signed in (got {$res->getStatusCode()})");
                    }
                }
                $this->line('      compliance entries: '.\App\Models\ComplianceEntry::count().' ('.\App\Models\ComplianceEntry::where('done', true)->count().' checked)');
            }
        }

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
