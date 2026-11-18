<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** m4-labels-live: what the live site must look like after the upgrade. */
class LiveReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuse_uninspected_scales_is_off_after_the_migrations(): void
    {
        Setting::flush();
        $this->assertFalse(Setting::get('gate_refuse_uninspected_scales'));
        $this->assertFalse(config('pbp_settings.gate_refuse_uninspected_scales.default'));
        $this->assertTrue(\App\Models\Setting::where('key', 'gate_refuse_uninspected_scales')->exists(), 'stored, not just the default');
        $this->assertFalse(Setting::where('key', 'gate_refuse_uninspected_scales')->first()->value['v']);
    }

    public function test_labels_is_on_by_default_and_brands_default_to_no_barcode(): void
    {
        $this->assertStringContainsString('labels', (string) config('pbp.modules'));
        $this->assertTrue(\App\Support\Modules::enabled('labels'));
        $this->assertFalse((bool) Brand::create(['name' => 'Any brand'])->fresh()->requires_barcode);
        $this->assertTrue(Schema::hasColumns('label_prints', ['label_type', 'partial']));
    }

    public function test_no_guest_route_and_no_sheet_sync_on_live(): void
    {
        $this->assertFalse(Route::has('preview.guest'));
        $this->get('/guest/'.str_repeat('x', 40))->assertNotFound();
        $this->assertFalse(class_exists(\App\Services\Sheets\SheetSync::class), 'no sheet sync code in the live release');
        $this->assertFalse(Schema::hasTable('sheet_sync_runs'));
    }

    public function test_release_check_command(): void
    {
        $admin = User::create(['name' => 'Andy', 'email' => 'a@x.test', 'password' => 'pw-'.str_repeat('z', 20), 'role' => User::ROLE_ADMIN, 'is_active' => true, 'must_change_password' => true]);
        $code = Artisan::call('pbp:release-check', ['--module' => ['labels'], '--setting-off' => ['gate_refuse_uninspected_scales'], '--scale-block-off' => true, '--absent-route' => ['preview.guest'],
            '--signed-in' => ['/labels'], '--column' => ['brands.requires_barcode', 'batches.barcode_number', 'label_prints.label_type']]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK    /labels returns 200 when signed in', $out);
        $this->assertTrue($admin->fresh()->must_change_password, 'the check never changes the user');
        $this->assertNull($admin->fresh()->last_login_at);

        Setting::put('gate_refuse_uninspected_scales', true);
        config(['pbp.modules' => 'production,receiving,checklists,batchlog']);
        $this->assertSame(1, Artisan::call('pbp:release-check', ['--module' => ['labels'], '--setting-off' => ['gate_refuse_uninspected_scales']]));
        $this->assertStringContainsString('FAIL  setting', Artisan::output());
    }
}
