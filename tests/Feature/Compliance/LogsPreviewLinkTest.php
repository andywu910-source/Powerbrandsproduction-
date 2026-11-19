<?php

namespace Tests\Feature\Compliance;

use App\Models\AuditLog;
use App\Models\LogEntry;
use App\Models\LogsPreviewToken;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

class LogsPreviewLinkTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    private const UID = '1A4120300000596000002095';
    private const UID2 = '1A4120300000596000002106';

    protected function setUp(): void
    {
        parent::setUp();
        $t = Carbon::parse('2026-11-18 10:00', 'America/New_York');
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
        $this->buildPlant();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,logs', 'pbp.preview_links' => true,
            'app.url' => 'https://powerbrandsproductions.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Runs the artisan command and returns the plain token from the printed URL. */
    private function issue(string $who, int $hours = 24): string
    {
        $this->assertSame(0, Artisan::call('logs:preview-link', ['user' => $who, '--hours' => $hours]));
        $out = trim(Artisan::output());
        $this->assertMatchesRegularExpression('#^https://powerbrandsproductions\.com/logs/preview/[0-9a-f]{64}$#', $out);

        return substr($out, -64);
    }

    private function signOut(): void
    {
        auth()->guard('web')->logout();
        $this->flushSession();
    }

    public function test_link_is_single_use_stores_only_a_hash_and_is_audited(): void
    {
        $token = $this->issue($this->leo->email);
        $row = LogsPreviewToken::sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode(LogsPreviewToken::all()->toArray()).json_encode(AuditLog::all()->toArray()));
        $this->assertTrue($row->expires_at->equalTo(now()->addHours(24)));

        // GET only shows the Continue button; it does not use the link up.
        $this->get("/logs/preview/$token")->assertOk()->assertSee('Continue')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
        $this->assertNull($row->fresh()->used_at);

        $this->post("/logs/preview/$token")->assertRedirect(route('logs.index'));
        $this->assertAuthenticatedAs($this->leo);
        $this->assertNotNull($row->fresh()->used_at);
        $this->assertSame(['token_id' => $row->id, 'expires_at' => $row->expires_at->getTimestamp()], session(LogsPreviewToken::SESSION_KEY));
        $login = AuditLog::where('action', 'login')->latest('id')->first();
        $this->assertSame($this->leo->id, $login->user_id);
        $this->assertSame('compliance hub preview link', $login->details['via']);
        $this->assertTrue(AuditLog::where('action', 'logs_preview_link_issued')->where('record_id', $this->leo->id)->exists());
        $this->get('/logs')->assertOk()->assertSee('Preview access: Compliance hub only');

        // Second use, from a fresh browser: the same plain 404 as a token that never existed.
        $this->signOut();
        $this->get("/logs/preview/$token")->assertNotFound();
        $this->post("/logs/preview/$token")->assertNotFound();
        $this->assertGuest();
        $this->get('/logs/preview/'.str_repeat('a', 64))->assertNotFound();
        $this->post('/logs/preview/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/logs/preview/not-a-token')->assertNotFound();
        $this->get('/logs/preview/'.strtoupper($token))->assertNotFound();
    }

    public function test_expired_links_and_sessions_end_with_the_window(): void
    {
        // The window is capped at 24 hours.
        $this->assertSame(1, Artisan::call('logs:preview-link', ['user' => $this->leo->email, '--hours' => 25]));
        $this->assertSame(1, Artisan::call('logs:preview-link', ['user' => $this->leo->email, '--hours' => 0]));
        $this->assertSame(0, LogsPreviewToken::count());

        // Unused link past its window: 404.
        $stale = $this->issue($this->leo->email, 1);
        $this->travel(61)->minutes();
        $this->get("/logs/preview/$stale")->assertNotFound();
        $this->post("/logs/preview/$stale")->assertNotFound();
        $this->assertGuest();

        // Used link: the session it opened ends when the window does.
        $token = $this->issue($this->leo->email, 2);
        $this->post("/logs/preview/$token")->assertRedirect(route('logs.index'));
        $this->travel(119)->minutes();
        $this->get('/logs')->assertOk();
        $this->travel(2)->minutes();
        $this->get('/logs')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get('/logs')->assertRedirect(route('login'));
    }

    public function test_preview_session_reaches_logs_and_logout_only(): void
    {
        $token = $this->issue($this->op->email);
        $this->post("/logs/preview/$token")->assertRedirect(route('logs.index'));

        $this->get('/logs')->assertOk();
        $this->get('/logs/2-e')->assertOk();
        $this->get('/logs/related?kind=metrc_uid&value='.self::UID)->assertOk();
        $this->post('/logs/2-e', ['f' => [
            'date' => '2026-11-18', 'item' => 'Preview test', 'lost_damaged_tag_old_uid' => self::UID,
            'reason' => 'Lost Tag', 'new_tag_uid_metrc' => self::UID2, 'entered_by' => 'Maria Operator',
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->op->id, LogEntry::sole()->entered_by_user_id);

        foreach (['/', '/dashboard', '/batch-log', '/compliance', '/receiving', '/password', '/users', '/setup/settings', '/soon/logs'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->put('/password', ['password' => 'x'])->assertForbidden();
        $this->assertAuthenticatedAs($this->op);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_revoke_or_flag_off_ends_preview_sessions(): void
    {
        $token = $this->issue($this->leo->email);
        $this->post("/logs/preview/$token")->assertRedirect(route('logs.index'));
        $this->assertSame(0, Artisan::call('logs:preview-link', ['--revoke' => true]));
        $this->get('/logs')->assertRedirect(route('login'));
        $this->assertGuest();

        $token = $this->issue($this->leo->email);
        $this->post("/logs/preview/$token")->assertRedirect(route('logs.index'));
        config(['pbp.preview_links' => false]);
        $this->get('/logs')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_disabled_by_default_and_when_the_flag_is_off(): void
    {
        $this->assertFalse((bool) env('PBP_PREVIEW_LINKS', false));
        $this->assertFalse((require base_path('config/pbp.php'))['preview_links']);

        config(['pbp.preview_links' => false]);
        $this->assertSame(1, Artisan::call('logs:preview-link', ['user' => $this->leo->email]));
        $this->assertSame(0, LogsPreviewToken::count());

        // Even a real, unused token does nothing while the flag is off.
        [, $token] = LogsPreviewToken::issue($this->leo);
        $this->get("/logs/preview/$token")->assertNotFound();
        $this->post("/logs/preview/$token")->assertNotFound();
        $this->assertGuest();
        $this->assertNull(LogsPreviewToken::sole()->used_at);
    }

    public function test_inactive_or_unknown_users_get_no_link(): void
    {
        $this->assertSame(1, Artisan::call('logs:preview-link', ['user' => 'nobody@example.com']));
        $this->leo->forceFill(['is_active' => false])->save();
        $this->assertSame(1, Artisan::call('logs:preview-link', ['user' => $this->leo->email]));
        // Deactivated after the link was issued: the link no longer signs in.
        [, $token] = LogsPreviewToken::issue($this->jenny);
        $this->jenny->forceFill(['is_active' => false])->save();
        $this->post("/logs/preview/$token")->assertNotFound();
        $this->assertGuest();
    }

    public function test_normal_login_and_sessions_are_unchanged(): void
    {
        $this->issue($this->leo->email);   // an outstanding link changes nothing for anyone else

        $this->actingAs($this->op)->get('/dashboard')->assertOk()->assertDontSee('Preview access');
        $this->get('/batch-log')->assertOk();
        $this->get('/logs')->assertOk();
        $this->post('/logout');
        $this->signOut();

        $u = User::factory()->create(['email' => 'normal@example.com', 'password' => Hash::make('Secret12345')]);
        $this->post('/login', ['email' => 'normal@example.com', 'password' => 'Secret12345'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($u);
        $this->get('/dashboard')->assertOk();
        $this->get('/batch-log')->assertOk();
        $this->assertNull(session(LogsPreviewToken::SESSION_KEY));
        $this->get('/logs/preview/'.str_repeat('b', 64))->assertNotFound();
        $this->get('/dashboard')->assertOk();
    }

    private function sidebar(string $html): string
    {
        $a = strpos($html, '<aside');
        $b = strpos($html, '</aside>');
        $this->assertNotFalse($a);

        return substr($html, $a, $b - $a);
    }

    public function test_preview_only_mode_shows_the_hub_to_preview_sessions_only(): void
    {
        // Sidebar on a site that does not list logs at all (= the live site today).
        config(['pbp.modules' => 'production,receiving,checklists,batchlog']);
        $before = [];
        foreach ([$this->andy, $this->leo, $this->op, $this->viewer] as $u) {
            $before[$u->id] = $this->sidebar($this->actingAs($u)->get('/dashboard')->assertOk()->getContent());
            $this->assertStringContainsString(route('soon', 'logs'), $before[$u->id]);
            $this->assertStringNotContainsString(route('logs.index'), $before[$u->id]);
        }

        // logs listed + preview-only: everyone else sees exactly the same sidebar, and /logs does not exist for them.
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,logs', 'pbp.logs_preview_only' => true]);
        foreach ([$this->andy, $this->leo, $this->op, $this->viewer] as $u) {
            $this->actingAs($u);
            $this->assertSame($before[$u->id], $this->sidebar($this->get('/dashboard')->assertOk()->getContent()), $u->name);
            $this->get('/logs')->assertNotFound();
            $this->get('/logs/2-e')->assertNotFound();
            $this->get('/logs/related?kind=metrc_uid&value='.self::UID)->assertNotFound();
            $this->post('/logs/2-e', ['f' => ['date' => '2026-11-18']])->assertNotFound();
            $this->get('/soon/logs')->assertOk()->assertSee('Coming in Phase 2');
        }
        $this->assertSame(0, LogEntry::count());
        $this->post('/logout');
        $this->signOut();
        $this->get('/logs')->assertNotFound();
        $this->get('/soon/logs')->assertRedirect(route('login'));

        // The preview-link session gets the hub, with the Compliance item in the sidebar.
        $token = $this->issue($this->andy->email);
        $this->post("/logs/preview/$token")->assertRedirect(route('logs.index'));
        $page = $this->get('/logs')->assertOk();
        $side = $this->sidebar($page->getContent());
        $this->assertStringContainsString(route('logs.index'), $side);
        $this->assertStringNotContainsString(route('soon', 'logs'), $side);
        $this->get('/logs/2-e')->assertOk();
        $this->get('/logs/7-a')->assertOk();   // managers-only Quality group: Andy is an admin
        $this->get('/dashboard')->assertForbidden();

        // Preview links switched off: the preview session ends and the hub is hidden again.
        config(['pbp.preview_links' => false]);
        $this->get('/logs')->assertRedirect(route('login'));
        $this->actingAs($this->andy)->get('/logs')->assertNotFound();
    }

    public function test_preview_only_off_keeps_the_hub_for_everyone_when_logs_is_listed(): void
    {
        config(['pbp.logs_preview_only' => false]);
        $this->actingAs($this->op)->get('/logs')->assertOk();
        $side = $this->sidebar($this->get('/dashboard')->getContent());
        $this->assertStringContainsString(route('logs.index'), $side);
        $this->assertStringContainsString('Compliance checklists', $side);
        $this->get('/soon/logs')->assertRedirect(route('logs.index'));
    }
}
