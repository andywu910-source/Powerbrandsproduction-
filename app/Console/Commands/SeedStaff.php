<?php

namespace App\Console\Commands;

use App\Models\ScheduleEmployee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the staff logins Andy approved (Nov 19) and links each to their Weekly Schedule name.
 * - role "staff / operator" (users.role = operator), must change the password at first sign-in
 * - the starting password comes from stdin (--password-stdin) so it never sits in a process list or log;
 *   it is hashed directly, so the normal minimum-length rule (which still applies to password CHANGES) is bypassed here only
 * - an existing user (same email, any case) is skipped and reported; existing users are never changed
 * - no email or notification is sent
 *   php artisan pbp:seed-staff --check            (read-only: reports what it would do, exit 1 if a schedule name is missing)
 *   printf '%s' "$PW" | php artisan pbp:seed-staff --password-stdin
 */
class SeedStaff extends Command
{
    public const STAFF = [
        ['Keith L', 'flushingkizz@gmail.com', 'Keith'],
        ['Jeffery H', 'huanjeffwork@gmail.com', 'Jeff'],
        ['Angelo C', 'angelochen7@gmail.com', 'Angelo'],
        ['Joseph M', 'hpesoj9723@gmail.com', 'Joseph'],
        ['Jack H', 'huanjac5@gmail.com', 'Jack'],
        ['Manuel G', 'mannyrocks91@gmail.com', 'Manny'],
    ];

    protected $signature = 'pbp:seed-staff {--check : Report only, change nothing} {--password-stdin : Read the starting password from stdin}';

    protected $description = 'Create the approved staff logins (staff / operator), linked to their Weekly Schedule names';

    public function handle(): int
    {
        $check = (bool) $this->option('check');
        $pw = null;
        if (! $check) {
            if (! $this->option('password-stdin')) {
                $this->error('Pass --password-stdin (and pipe the starting password in), or use --check.');

                return 1;
            }
            $pw = rtrim((string) stream_get_contents(STDIN), "\r\n");
            if ($pw === '') {
                $this->error('No password was given on stdin.');

                return 1;
            }
        }

        $problems = 0;
        $plan = [];
        foreach (self::STAFF as [$name, $email, $schedName]) {
            $email = mb_strtolower(trim($email));
            $emp = ScheduleEmployee::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($schedName)])->orderBy('id')->first();
            $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($existing) {
                $this->line("SKIP   {$email}: a user with this email already exists (id {$existing->id}, {$existing->role}); left unchanged.");
                continue;
            }
            if (! $emp) {
                $this->line("FAIL   {$email}: no Weekly Schedule person named \"{$schedName}\".");
                $problems++;
                continue;
            }
            if ($emp->user_id) {
                $this->line("FAIL   {$email}: schedule person \"{$emp->name}\" is already linked to user id {$emp->user_id}.");
                $problems++;
                continue;
            }
            $plan[] = [$name, $email, $emp];
            $this->line(($check ? 'WOULD  ' : 'CREATE ')."{$email}: {$name}, staff / operator, linked to schedule \"{$emp->name}\".");
        }
        if ($check || $problems) {
            if ($problems && ! $check) {
                $this->error('Nothing was created because of the problems above.');
            }

            return $problems ? 1 : 0;
        }

        DB::transaction(function () use ($plan, $pw) {
            foreach ($plan as [$name, $email, $emp]) {
                $u = User::create([
                    'name' => $name, 'email' => $email, 'role' => User::ROLE_OPERATOR,
                    'password' => $pw, 'must_change_password' => true, 'is_active' => true,
                ]);   // password is cast 'hashed'; no validation rule applies here
                $emp->forceFill(['user_id' => $u->id])->save();
            }
        });
        $this->info(count($plan).' staff login(s) created. Each must choose a new password at first sign-in. No email was sent.');

        return 0;
    }
}
