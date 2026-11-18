<?php

namespace Tests\Feature\Phase1;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Setting;
use App\Models\User;
use App\Services\ScaleBlock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The scale block (refuse overdue / undated scales) is off at deploy and turns itself on once every scale has a date. */
class ScaleBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Andy', 'email' => 'andy@x.test', 'password' => 'pw-'.str_repeat('a', 20), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function scale(string $id, ?string $last = null, ?string $due = null, bool $active = true): Equipment
    {
        return Equipment::create(['equip_id' => $id, 'name' => "Scale $id", 'is_scale' => true, 'active' => $active,
            'last_inspected_on' => $last, 'inspection_due_on' => $due ?? ($last ? now()->addMonths(11)->toDateString() : null)]);
    }

    private function autoAudits(): int
    {
        return AuditLog::where('action', 'scale_block_auto_enabled')->count();
    }

    public function test_off_at_deploy_and_needs_at_least_one_scale(): void
    {
        $this->assertFalse(ScaleBlock::on());
        $this->assertFalse(ScaleBlock::maybeEnable(), 'no active scales: stays off');
        $this->assertSame('Block is off. It turns on automatically once every scale has an inspection date (0 of 0 done).', ScaleBlock::statusLine());
        $this->scale('SC-OLD', now()->subMonth()->toDateString(), null, false);   // inactive scales don't count
        $this->assertFalse(ScaleBlock::on());
    }

    public function test_turns_on_when_the_last_scale_gets_a_date_with_one_audit_entry(): void
    {
        $b = $this->scale('SC-02');
        $this->scale('SC-03');
        $a = $this->scale('SC-01', now()->subMonth()->toDateString());
        $this->assertFalse(ScaleBlock::on());
        $this->assertSame('Block is off. It turns on automatically once every scale has an inspection date (1 of 3 done).', ScaleBlock::statusLine());
        $this->actingAs($this->admin)->get('/setup')->assertOk()->assertSee('(1 of 3 done)');
        $this->actingAs($this->admin)->get('/setup/scales')->assertOk()->assertSee('(1 of 3 done)');

        $b->update(['last_inspected_on' => now()->subWeek()->toDateString(), 'inspection_due_on' => now()->addMonths(11)->toDateString()]);
        $this->assertFalse(ScaleBlock::on());
        $this->assertSame(0, $this->autoAudits());

        Equipment::where('equip_id', 'SC-03')->first()->update(['last_inspected_on' => now()->subDay()->toDateString()]);
        Setting::flush();
        $this->assertTrue(ScaleBlock::on());
        $log = AuditLog::where('action', 'scale_block_auto_enabled')->sole();
        $this->assertSame('Scale block auto-enabled', $log->details['message']);
        $this->assertSame(3, $log->details['scales']);
        $this->assertTrue(AuditLog::where('action', 'setting')->get()->contains(fn ($l) => $l->details['key'] === ScaleBlock::SETTING && $l->details['new'] === true));
        $this->assertStringStartsWith('Block is on', ScaleBlock::statusLine());

        // Saving again does nothing more.
        $a->update(['notes' => 'checked']);
        $this->assertSame(1, $this->autoAudits());
    }

    public function test_never_switches_itself_back_off(): void
    {
        $s = $this->scale('SC-01', now()->subMonth()->toDateString());
        $this->assertTrue(ScaleBlock::on());
        $this->scale('SC-02');                                           // a new undated scale
        $s->update(['inspection_due_on' => now()->subDay()->toDateString()]);   // overdue
        $this->artisan('pbp:scale-block-check')->assertSuccessful();
        Setting::flush();
        $this->assertTrue(ScaleBlock::on());
    }

    public function test_admin_can_turn_it_off_and_it_stays_off(): void
    {
        $s = $this->scale('SC-01', now()->subMonth()->toDateString());
        $this->assertTrue(ScaleBlock::on());
        $form = collect(config('pbp_settings'))->map(fn ($d, $k) => $d['type'] === 'list' ? implode(',', (array) Setting::get($k)) : Setting::get($k))->all();
        $form[ScaleBlock::SETTING] = '0';
        $form = array_filter($form, fn ($v) => $v !== false);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->actingAs($this->admin)->put('/setup/settings', $form)->assertSessionHasNoErrors()->assertRedirect();
        Setting::flush();
        $this->assertFalse(ScaleBlock::on());
        $s->update(['notes' => 'saved again']);
        $this->artisan('pbp:scale-block-check')->assertSuccessful();
        Setting::flush();
        $this->assertFalse(ScaleBlock::on(), 'an admin turned it off: no automatic switching');
        $this->assertSame('Block is off (turned off by an admin). Switch it on in Settings when ready.', ScaleBlock::statusLine());
    }

    public function test_daily_check_turns_it_on_and_is_scheduled(): void
    {
        $this->scale('SC-01');
        \Illuminate\Support\Facades\DB::table('equipment')->where('equip_id', 'SC-01')->update(['last_inspected_on' => now()->subMonth()->toDateString()]);   // no model event
        $this->assertFalse(ScaleBlock::on());
        $this->artisan('pbp:scale-block-check')->expectsOutput('Scale block auto-enabled.')->assertSuccessful();
        Setting::flush();
        $this->assertTrue(ScaleBlock::on());
        $this->assertSame('daily check', AuditLog::where('action', 'scale_block_auto_enabled')->sole()->details['trigger']);
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'pbp:scale-block-check'));
        $this->assertCount(1, $events);
        $this->assertSame('0 6 * * *', $events->first()->expression);
    }

    public function test_dashboard_warns_with_missing_and_overdue_scales_until_it_is_on(): void
    {
        $this->scale('SC-02');                                                                           // no date
        $this->scale('SC-01', now()->subYears(2)->toDateString(), now()->subMonth()->toDateString());   // overdue
        $r = $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $r->assertSee('Scale inspection block is off')->assertSee('(1 of 2 done)')->assertSee('No inspection date')->assertSee('SC-02 · Scale SC-02')
            ->assertSee('Overdue')->assertSee('SC-01 · Scale SC-01')->assertSee(route('scales.index'), false);

        Equipment::where('equip_id', 'SC-02')->first()->update(['last_inspected_on' => now()->subDay()->toDateString(), 'inspection_due_on' => now()->addMonths(11)->toDateString()]);
        Setting::flush();
        $this->assertTrue(ScaleBlock::on());
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertDontSee('Scale inspection block is off');
    }
}
