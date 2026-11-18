<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('PowerBrands Productions');
    }

    public function test_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'op@example.com', 'password' => Hash::make('Secret12345')]);

        $this->post('/login', ['email' => 'op@example.com', 'password' => 'Secret12345'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_password_fails(): void
    {
        User::factory()->create(['email' => 'op@example.com']);
        $this->post('/login', ['email' => 'op@example.com', 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'op@example.com', 'password' => Hash::make('Secret12345'), 'is_active' => false]);
        $this->post('/login', ['email' => 'op@example.com', 'password' => 'Secret12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'op@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'op@example.com', 'password' => 'bad'.$i]);
        }
        $this->post('/login', ['email' => 'op@example.com', 'password' => 'bad'])
            ->assertStatus(302)->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
    }

    public function test_must_change_password_is_enforced(): void
    {
        $user = User::factory()->mustChangePassword()->create(['password' => Hash::make('TempPass12345')]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.edit'));
        $this->actingAs($user)->get('/password')->assertOk()->assertSee('Choose a new password');

        $this->actingAs($user)->put('/password', [
            'current_password' => 'TempPass12345',
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NewSecret123', $user->password));
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_change_password_validates(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Secret12345')]);
        $this->actingAs($user)->put('/password', [
            'current_password' => 'wrong', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors(['current_password', 'password']);
    }
}
