<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_from_hash_without_printing_password(): void
    {
        $hash = Hash::make('InitialPass123');
        config(['pbp.admin_initial_password_hash' => $hash]);

        $this->artisan('pbp:create-admin')
            ->expectsOutputToContain('Created admin andy@powerbrandsny.com')
            ->doesntExpectOutputToContain('InitialPass123')
            ->assertSuccessful();

        $u = User::where('email', 'andy@powerbrandsny.com')->firstOrFail();
        $this->assertSame('Andy Wu', $u->name);
        $this->assertTrue($u->isAdmin());
        $this->assertTrue($u->must_change_password);
        $this->assertTrue(Hash::check('InitialPass123', $u->password));

        // Idempotent: second run changes nothing.
        $this->artisan('pbp:create-admin')->expectsOutputToContain('already exists')->assertSuccessful();
    }

    public function test_generates_password_to_file(): void
    {
        config(['pbp.admin_initial_password_hash' => null]);
        $file = sys_get_temp_dir().'/pbp-test-'.uniqid().'/pw.txt';

        $this->artisan('pbp:create-admin', ['--email' => 'a@example.com', '--password-file' => $file])->assertSuccessful();

        $this->assertSame('600', substr(sprintf('%o', fileperms($file)), -3));
        $plain = trim(file_get_contents($file));
        $this->assertSame(20, strlen($plain));
        $this->assertTrue(Hash::check($plain, User::where('email', 'a@example.com')->value('password')));
        unlink($file); rmdir(dirname($file));
    }

    public function test_refuses_without_password_source(): void
    {
        config(['pbp.admin_initial_password_hash' => null]);
        $this->artisan('pbp:create-admin')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
