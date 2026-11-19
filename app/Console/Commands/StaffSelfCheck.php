<?php

namespace App\Console\Commands;

use App\Exceptions\GateFailed;
use App\Models\ComplianceEntry;
use App\Models\ComplianceItem;
use App\Models\ScheduleEmployee;
use App\Models\Signature;
use App\Models\User;
use App\Services\SignatureService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Post-install check for staff sign-off. Uses throwaway users and a throwaway checklist item inside ONE database
 * transaction that is always rolled back, so nothing it creates survives (no users, sign-offs or audit rows).
 * No emails are sent. Pages are requested in-process with an in-memory session.
 */
class StaffSelfCheck extends Command
{
    protected $signature = 'pbp:staff-selfcheck';

    protected $description = 'Staff sign-off checks with throwaway users (rolled back, changes nothing)';

    private int $bad = 0;

    private function ok(bool $pass, string $what): void
    {
        $this->line(($pass ? 'OK    ' : 'FAIL  ').$what);
        $this->bad += $pass ? 0 : 1;
    }

    /** @return array{0:int,1:string} status code and Location header (empty if none) of an in-process GET as this user. */
    private function probe(User $u, string $path): array
    {
        config(['session.driver' => 'array']);
        Auth::guard('web')->setUser($u);
        $r = app(Kernel::class)->handle(Request::create($path, 'GET'));

        return [$r->getStatusCode(), (string) $r->headers->get('Location'), (string) $r->getContent()];
    }

    private function status(User $u, string $path): int
    {
        return $this->probe($u, $path)[0];
    }

    public function handle(): int
    {
        $this->ok(Schema::hasColumn('signatures', 'signer_name') && Schema::hasColumn('signatures', 'void_reason'), 'signatures table has the signer name and void columns');
        $this->ok(Schema::hasTable('signoff_revisions'), 'the sign-off history table (signoff_revisions) exists');
        foreach (['compliance.confirm'] as $n) {
            $this->ok(Route::getRoutes()->getByName($n) !== null, "route $n exists (re-confirm a revision)");
        }
        foreach (['users.store', 'users.update', 'users.reset-password', 'settings.update', 'signatures.void'] as $n) {
            $r = Route::getRoutes()->getByName($n);
            $mw = $r ? implode(',', $r->gatherMiddleware()) : '';
            $this->ok($r !== null && str_contains($mw, 'role:admin'), "route $n is admin-only");
        }

        $users0 = User::count();
        $sigs0 = Signature::count();
        $audit0 = DB::table('audit_log')->count();
        DB::beginTransaction();
        try {
            $mk = fn (string $tag, string $role, bool $change = false) => User::create([
                'name' => "Selfcheck $tag", 'email' => 'selfcheck-'.$tag.'-'.bin2hex(random_bytes(4)).'@example.invalid',
                'role' => $role, 'password' => bin2hex(random_bytes(8)), 'must_change_password' => $change, 'is_active' => true,
            ]);
            $admin = $mk('admin', User::ROLE_ADMIN);
            $s1 = $mk('staff1', User::ROLE_OPERATOR);
            $s2 = $mk('staff2', User::ROLE_OPERATOR);
            $fresh = $mk('fresh', User::ROLE_OPERATOR, true);
            foreach ([$s1, $s2, $fresh] as $i => $u) {
                ScheduleEmployee::create(['name' => 'Selfcheck '.$i, 'user_id' => $u->id, 'active' => true]);
            }

            $this->ok($s1->signsAsSelf() && ! $admin->signsAsSelf(), 'staff accounts sign as themselves; admins do not');
            $this->ok($this->status($fresh, '/dashboard') === 302, 'first sign-in: a user who must change the password is sent to the change-password page');
            $this->ok($this->status($s1, '/dashboard') === 200, 'staff can open the dashboard');
            foreach (['/batch-log', '/production', '/checklists'] as $p) {
                $c = $this->status($s1, $p);
                $this->ok(in_array($c, [200, 404], true), "staff can open $p (got $c; 404 = tab switched off)");
            }
            // Where staff sign off check-ins depends on how this site is set up (.env):
            //  - Compliance Checklists tab on  -> /compliance/weekly must open (200)
            //  - tab switched off, hub on      -> the app sends /compliance/* to the Compliance hub (302 to /logs): that exact redirect is
            //                                     accepted, and the hub itself must open for staff (200)
            //  - both off                      -> 404
            $cmpOn = \App\Support\Modules::enabled('compliance');
            $hubOn = \App\Support\LogHub::hubVisible(Request::create('/logs', 'GET'));
            [$c, $loc] = $this->probe($s1, '/compliance/weekly');
            if ($cmpOn) {
                $this->ok($c === 200, "staff can open /compliance/weekly (Compliance Checklists tab is on; got $c)");
            } elseif ($hubOn) {
                $this->ok($c === 302 && rtrim(parse_url($loc, PHP_URL_PATH) ?: '', '/') === rtrim(parse_url(route('logs.index'), PHP_URL_PATH), '/'),
                    "/compliance/weekly is switched off on this site and correctly sends staff to the Compliance hub (got $c to ".(parse_url($loc, PHP_URL_PATH) ?: 'nowhere').')');
                $this->ok($this->status($s1, '/logs') === 200, 'staff can open the Compliance hub (/logs), where check-ins are signed off on this site');
            } else {
                $this->ok($c === 404, "/compliance/weekly is switched off on this site (got $c; expected 404)");
            }
            if ($hubOn) {
                $def = \App\Models\LogDefinition::where('active', true)->where('manager_only', false)->get()
                    ->first(fn ($d) => collect($d->fields)->contains(fn ($f) => ($f['type'] ?? null) === 'signature' && empty($f['removed'])));
                if ($def) {
                    [$c, , $body] = $this->probe($s1, '/logs/'.$def->slug);
                    $this->ok($c === 200, "staff can open the hub log {$def->code} that has a signature field (got $c)");
                    $this->ok(str_contains($body, e($s1->name)) && ! str_contains($body, e($s2->name)), 'in the hub, the signature picker offers a staff login only their own name');
                    // hub entry signed through the same service the form uses
                    $entry = \App\Models\LogEntry::create(['log_definition_id' => $def->id, 'data' => [], 'occurred_on' => now()->toDateString(), 'entered_by_user_id' => $s1->id]);
                    $hs = app(SignatureService::class)->sign($entry, 'performed', $s1->id, $s1);
                    $this->ok($hs->user_id === $s1->id && $hs->signer_name === $s1->name, 'staff can sign a Compliance hub entry in their own name');
                    try {
                        app(SignatureService::class)->sign($entry, 'performed', $s2->id, $s1);
                        $this->ok(false, 'staff cannot sign a hub entry as another person');
                    } catch (GateFailed) {
                        $this->ok(true, 'staff cannot sign a hub entry as another person');
                    }
                } else {
                    $this->line('NOTE  no hub log with a signature field is visible to staff; hub signing was not checked');
                }
            }
            foreach (['/users', '/setup/settings', '/setup/process'] as $p) {
                $this->ok($this->status($s1, $p) === 403, "staff cannot open $p (admin only)");
            }
            $this->ok($this->status($admin, '/users') === 200, 'admin can open /users');

            $item = ComplianceItem::create(['frequency' => 'weekly', 'title' => 'Selfcheck item', 'sort_order' => 9999, 'hidden' => true]);
            $e = ComplianceEntry::create(['item_id' => $item->id, 'period_start' => now()->startOfWeek()->toDateString(), 'done' => true, 'completed_by_user_id' => $s1->id, 'completed_on' => now()->toDateString(), 'updated_by_user_id' => $s1->id]);
            $svc = app(SignatureService::class);
            $sig = $svc->sign($e, 'completed', $s1->id, $s1);
            $this->ok($sig->user_id === $s1->id && $sig->signer_name === $s1->name && $sig->signed_at !== null, 'staff can sign off their own work (user id, name and time stored)');
            try {
                $svc->sign($e, 'completed', $s2->id, $s1);
                $this->ok(false, 'staff cannot sign as another person');
            } catch (GateFailed) {
                $this->ok(true, 'staff cannot sign as another person');
            }
            foreach ([
                'edit the signer' => 'UPDATE signatures SET user_id = '.(int) $s2->id.' WHERE id = '.(int) $sig->id,
                'edit the stored name' => "UPDATE signatures SET signer_name = 'Someone Else' WHERE id = ".(int) $sig->id,
                'edit the time' => "UPDATE signatures SET signed_at = '2000-01-01 00:00:00' WHERE id = ".(int) $sig->id,
                'delete it' => 'DELETE FROM signatures WHERE id = '.(int) $sig->id,
            ] as $what => $sql) {
                try {
                    DB::statement($sql);
                    $this->ok(false, "the database refuses to $what");
                } catch (\Throwable) {
                    $this->ok(true, "the database refuses to $what");
                }
            }
            // Revise and re-confirm (a copy of the signed item, so the void test below still has its own sign-off)
            $item2 = ComplianceItem::create(['frequency' => 'weekly', 'title' => 'Selfcheck item 2', 'sort_order' => 9998, 'hidden' => true]);
            $e2 = ComplianceEntry::create(['item_id' => $item2->id, 'period_start' => now()->startOfWeek()->toDateString(), 'done' => true, 'completed_by_user_id' => $s1->id, 'completed_on' => now()->toDateString(), 'updated_by_user_id' => $s1->id]);
            $sig2 = $svc->sign($e2, 'completed', $s1->id, $s1);
            $rev = \App\Models\SignoffRevision::create(['signable_type' => $e2->getMorphClass(), 'signable_id' => $e2->id, 'signature_id' => $sig2->id, 'kind' => 'revised', 'user_id' => $s1->id, 'user_name' => $s1->name, 'changes' => json_encode(['Note' => [null, 'x']]), 'reason' => 'selfcheck']);
            $re = $svc->sign($e2, 'reconfirmed', $s1->id, $s1);
            $this->ok($re->meaning === 'reconfirmed' && $sig2->fresh()->invalidated_at === null, 'a revised item can be re-confirmed; the original sign-off stays valid and untouched');
            foreach (['edit a history row' => 'UPDATE signoff_revisions SET reason = \'x\' WHERE id = '.(int) $rev->id, 'delete a history row' => 'DELETE FROM signoff_revisions WHERE id = '.(int) $rev->id] as $what => $sql) {
                try {
                    DB::statement($sql);
                    $this->ok(false, "the database refuses to $what");
                } catch (\Throwable) {
                    $this->ok(true, "the database refuses to $what");
                }
            }
            // Jack H (staff login named Jack) can sign off; the Jack exclusion otherwise stays
            $jack = User::create(['name' => 'Jack Selfcheck', 'email' => 'selfcheck-jack-'.bin2hex(random_bytes(4)).'@example.invalid', 'role' => User::ROLE_OPERATOR, 'password' => bin2hex(random_bytes(8)), 'must_change_password' => false, 'is_active' => true]);
            ScheduleEmployee::create(['name' => 'Selfcheck Jack', 'user_id' => $jack->id, 'active' => true]);
            $this->ok(\App\Support\Compliance::excluded($jack) && \App\Support\Compliance::canSignOff($jack) && ! \App\Support\Compliance::pickable()->contains('id', $jack->id), 'a staff login named Jack can sign off, and is still not on the Assignee / Completed-by pick lists');
            $this->ok($svc->sign($e2, 'completed', $jack->id, $jack)->user_id === $jack->id, 'Jack can sign in his own name');

            // The one allowed change: the void mark (what the admin void does), once.
            $n = DB::update('UPDATE signatures SET invalidated_at = ?, voided_by_user_id = ?, void_reason = ? WHERE id = ? AND invalidated_at IS NULL', [now(), $admin->id, 'selfcheck', $sig->id]);
            $this->ok($n === 1, 'an admin void mark can be set on a sign-off');
            try {
                DB::statement("UPDATE signatures SET void_reason = 'changed' WHERE id = ".(int) $sig->id);
                $this->ok(false, 'a voided sign-off cannot be changed again');
            } catch (\Throwable) {
                $this->ok(true, 'a voided sign-off cannot be changed again');
            }
        } catch (\Throwable $t) {
            $this->ok(false, 'self-check ran to the end ('.get_class($t).': '.$t->getMessage().')');
        } finally {
            DB::rollBack();
        }
        $this->ok(User::count() === $users0 && Signature::count() === $sigs0 && DB::table('audit_log')->count() === $audit0, 'everything the check created was rolled back (no users, sign-offs or audit rows left)');

        return $this->bad ? 1 : 0;
    }
}
