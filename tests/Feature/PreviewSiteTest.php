<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Brand;
use App\Models\PreviewGuestLink;
use App\Models\User;
use Database\Seeders\PreviewDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PreviewSiteTest extends TestCase
{
    use RefreshDatabase;

    /** Boot the app's routes again under the given environment + flags (routes/web.php decides at load time). */
    private function bootAs(string $env, bool $guest, bool $preview = true): void
    {
        $this->app['env'] = $env;
        config(['pbp.preview_guest_login' => $guest, 'pbp.preview' => $preview, 'app.url' => 'https://preview.powerbrandsproductions.com',
            'pbp.modules' => 'production,receiving,checklists,batchlog,labels']);
        $router = $this->app['router'];
        $router->setRoutes(new \Illuminate\Routing\RouteCollection);
        Route::middleware('web')->group(base_path('routes/web.php'));
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    public function test_guest_route_does_not_exist_on_the_live_site(): void
    {
        $token = str_repeat('a', 48);
        // Production env, even with the flags set by mistake: no route at all, and 404.
        $this->bootAs('production', true);
        $this->assertFalse(Route::has('preview.guest'));
        $this->get("/guest/$token")->assertNotFound();
        $this->get('/batches')->assertNotFound();
        $this->assertArrayNotHasKey('X-Robots-Tag', $this->get('/login')->headers->all());
        $this->get('/login')->assertDontSee('PREVIEW / TEST DATA');
        // Staging without the flag: still no route.
        $this->bootAs('staging', false);
        $this->assertFalse(Route::has('preview.guest'));
        $this->get("/guest/$token")->assertNotFound();
        // The default test env (as on live: no PREVIEW_GUEST_LOGIN in .env): none.
        $this->bootAs('testing', (bool) env('PREVIEW_GUEST_LOGIN', false), false);
        $this->get("/guest/$token")->assertNotFound();
    }

    public function test_controller_guard_404s_even_if_the_route_were_registered(): void
    {
        $this->bootAs('production', false);
        Route::middleware('web')->get('/guest/{token}', \App\Http\Controllers\GuestLoginController::class);
        $this->get('/guest/'.str_repeat('b', 48))->assertNotFound();
    }

    public function test_seed_issue_link_login_once_then_link_already_used(): void
    {
        $this->bootAs('staging', true);
        (new PreviewDataSeeder)->run();
        (new PreviewDataSeeder)->run();   // idempotent
        $this->assertSame(3, Batch::where('batch_number', 'not like', 'TEST-%')->count());
        $this->assertSame(10, Batch::where('batch_number', 'like', 'TEST-%')->count(), 'fake Schedule test batches (preview only)');
        $this->assertTrue(Brand::where('name', 'Bodega Boyz')->value('requires_barcode'));
        $this->assertFalse((bool) Brand::where('name', 'Pure Haze')->value('requires_barcode'));
        $this->assertSame(1, Batch::whereNotNull('barcode_number')->count());
        $guest = User::where('email', 'preview@powerbrandsproductions.com')->firstOrFail();
        $this->assertSame('admin', $guest->role);
        $this->assertSame('Preview Guest', $guest->name);

        Artisan::call('preview:guest-link');
        $url = trim(Artisan::output());
        $this->assertMatchesRegularExpression('#^https://preview\.powerbrandsproductions\.com/guest/[A-Za-z0-9]{48}$#', $url);
        $token = substr($url, strrpos($url, '/') + 1);
        $link = PreviewGuestLink::firstOrFail();
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertDatabaseMissing('preview_guest_links', ['token_hash' => $token]);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $link->expires_at->timestamp, 5);

        $this->get("/guest/$token")->assertOk()->assertSee('Continue to the preview');
        $this->get("/guest/$token")->assertOk(); // link previews do not use it up
        $this->assertGuest();
        $r = $this->withSession(["_token" => "csrf-t"])->post("/guest/$token", ["_token" => "csrf-t"])->assertRedirect('/batches');
        $r->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertAuthenticatedAs($guest);
        $this->assertNotNull($link->fresh()->used_at);
        $this->assertSame(10080, config('session.lifetime'));
        $this->assertTrue(AuditLog::where('action', 'login')->where('record_id', $guest->id)->exists());
        $this->get('/batches')->assertRedirect('/batch-log');
        $this->get('/batch-log')->assertOk()->assertSee('PREVIEW / TEST DATA')->assertSee('1A4120300000596000001707');

        auth()->logout();
        $this->get("/guest/$token")->assertStatus(410)->assertSee('Link already used');
        $this->withSession(["_token" => "csrf-t"])->post("/guest/$token", ["_token" => "csrf-t"])->assertStatus(410);
        $this->assertGuest();
        $this->get('/guest/'.str_repeat('z', 48))->assertNotFound();

        // A fresh link works; an expired one doesn't.
        Artisan::call('preview:guest-link');
        $t2 = substr(trim(Artisan::output()), -48);
        $this->travel(25)->hours();
        $this->get("/guest/$t2")->assertStatus(410)->assertSee('Link expired');
    }

    public function test_preview_walkthrough_barcode_then_label_then_blocked_batch(): void
    {
        $this->bootAs('staging', true);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);   // CSRF is only auto-skipped under APP_ENV=testing
        (new PreviewDataSeeder)->run();
        $guest = User::where('email', 'preview@powerbrandsproductions.com')->firstOrFail();
        $missing = Batch::whereHas('product', fn ($q) => $q->where('code', 'preview-bb-gpu-07'))->firstOrFail();
        $this->actingAs($guest)->get('/labels/new?batch='.$missing->batch_number)->assertSee('has no barcode number yet');
        $this->actingAs($guest)->post("/batch-log/{$missing->batch_number}/barcode", ['barcode_number' => 'PKG-TEST-0002'])->assertSessionHasNoErrors();
        $f = ['title' => 'Bodega Boyz Grape Punch 0.7g', 'type_text' => 'Shortiez Preroll', 'batch_label' => 'Batch #', 'batch_number' => $missing->batch_number,
            'total' => '315', 'count_label' => 'Count', 'cases' => '', 'case_label' => 'Case', 'per_case' => '10', 'per_case_label' => 'Count per case box'];
        $j = $this->actingAs($guest)->postJson('/labels/preview?batch='.$missing->batch_number, ['f' => $f])->json();
        $this->assertStringContainsString('32 Case (1 partial; 5 count)', $j['svg']);
        $this->assertSame([], $j['errors']);
        $pdf = $this->actingAs($guest)->post('/labels/print?batch='.$missing->batch_number, ['f' => $f, 'copies' => 32])->assertOk()->getContent();
        $this->assertStringContainsString('(PKG-TEST-0002) Tj', $pdf);
    }

    public function test_preview_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        (new PreviewDataSeeder)->run();
    }
}
