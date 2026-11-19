<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PagesAndUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_sections_render_for_operator(): void
    {
        $op = User::factory()->create();
        $this->actingAs($op)->get('/dashboard')->assertOk()->assertSee('Production')->assertSee('Batch Log')->assertDontSee('>Users<', false);
        config(['pbp.modules_off' => 'logs']);
        foreach (['/soon/labels', '/soon/logs'] as $url) {
            $this->actingAs($op)->get($url)->assertOk()->assertSee('coming soon', false);
        }
        $this->actingAs($op)->get('/soon/labels')->assertSee('case / carton labels');
    }

    public function test_non_admins_cannot_manage_users(): void
    {
        foreach ([User::factory()->create(), User::factory()->supervisor()->create()] as $u) {
            $this->actingAs($u)->get('/users')->assertForbidden();
            $this->actingAs($u)->post('/users', ['name' => 'x', 'email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
        }
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_admin_can_create_user_with_generated_password(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('Add a user');

        $res = $this->actingAs($admin)->post('/users', ['name' => 'New Op', 'email' => 'New@Example.com', 'role' => 'supervisor']);
        $res->assertRedirect(route('users.index'))->assertSessionHas('temp_password');

        $u = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('supervisor', $u->role);
        $this->assertTrue($u->must_change_password);
        $this->assertTrue(Hash::check(session('temp_password'), $u->password));
    }

    public function test_admin_rejects_invalid_role(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'superuser'])
            ->assertSessionHasErrors('role');
    }

    public function test_admin_can_update_role_and_reset_password(): void
    {
        $admin = User::factory()->admin()->create();
        $u = User::factory()->create();

        $this->actingAs($admin)->get("/users/{$u->id}/edit")->assertOk();
        $this->actingAs($admin)->put("/users/{$u->id}", ['name' => 'Renamed', 'email' => $u->email, 'role' => 'supervisor', 'is_active' => '1'])
            ->assertRedirect(route('users.index'));
        $this->assertSame('supervisor', $u->fresh()->role);

        $this->actingAs($admin)->post("/users/{$u->id}/reset-password", ['password' => 'Brandnew1234'])
            ->assertRedirect(route('users.index'))->assertSessionMissing('temp_password');
        $u->refresh();
        $this->assertTrue($u->must_change_password);
        $this->assertTrue(Hash::check('Brandnew1234', $u->password));
    }

    public function test_admin_cannot_demote_or_disable_self(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => 'A', 'email' => $admin->email, 'role' => 'operator', 'is_active' => '1'])
            ->assertSessionHasErrors('role');
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_health_check(): void
    {
        $this->get('/up')->assertOk();
    }
}
