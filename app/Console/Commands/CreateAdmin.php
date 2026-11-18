<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates (or re-arms) the first admin account. Never prints a password.
 *
 * Password source, in order:
 *   1. config('pbp.admin_initial_password_hash') (PBP_ADMIN_INITIAL_PASSWORD_HASH,
 *      a bcrypt hash prepared off-server) — the plaintext never touches the server.
 *   2. Otherwise a random password is generated and written ONLY to --password-file
 *      (chmod 600). The command refuses to run without one of these.
 */
class CreateAdmin extends Command
{
    protected $signature = 'pbp:create-admin
        {--email= : Admin email (default: config pbp.admin_email)}
        {--name= : Admin name (default: config pbp.admin_name)}
        {--password-file= : Where to write a generated password (chmod 600)}
        {--force : Reset the password if the user already exists}';

    protected $description = 'Create the first admin user (must change password on first login)';

    public function handle(): int
    {
        $email = Str::lower($this->option('email') ?: config('pbp.admin_email'));
        $name = $this->option('name') ?: config('pbp.admin_name');
        $hash = config('pbp.admin_initial_password_hash');
        $file = $this->option('password-file');

        $existing = User::where('email', $email)->first();
        if ($existing && ! $this->option('force')) {
            $this->info("Admin {$email} already exists; nothing changed (use --force to reset its password).");

            return self::SUCCESS;
        }

        if ($hash) {
            if (! str_starts_with($hash, '$2y$') || ! Hash::isHashed($hash)) {
                $this->error('PBP_ADMIN_INITIAL_PASSWORD_HASH is not a valid bcrypt hash.');

                return self::FAILURE;
            }
        } elseif ($file) {
            $plain = Str::password(20, symbols: false);
            $dir = dirname($file);
            if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
                $this->error("Cannot create directory {$dir}.");

                return self::FAILURE;
            }
            $old = umask(0077);
            $ok = file_put_contents($file, $plain.PHP_EOL);
            umask($old);
            if ($ok === false) {
                $this->error("Cannot write {$file}.");

                return self::FAILURE;
            }
            chmod($file, 0600);
            $hash = Hash::make($plain);
            unset($plain);
        } else {
            $this->error('No password source: set PBP_ADMIN_INITIAL_PASSWORD_HASH or pass --password-file=PATH.');

            return self::FAILURE;
        }

        $user = $existing ?? new User;
        DB::transaction(function () use ($user, $name, $email, $hash) {
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'role' => User::ROLE_ADMIN,
            'must_change_password' => true,
            'is_active' => true,
            'password' => 'placeholder', // replaced below without re-hashing
        ])->save();

        DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
        });

        $this->info(($existing ? 'Reset' : 'Created')." admin {$email} (must change password on first login).");
        if ($file && ! config('pbp.admin_initial_password_hash')) {
            $this->info("Initial password written to {$file}.");
        }

        return self::SUCCESS;
    }
}
