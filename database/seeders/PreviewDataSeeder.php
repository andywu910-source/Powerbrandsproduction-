<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Brand;
use App\Models\Equipment;
use App\Models\Mmp;
use App\Models\PreviewGuestLink;
use App\Models\Pqp;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductionWorkflow;
use App\Services\PublicId;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TEST DATA for the preview site. Never runs with APP_ENV=production. Safe to run again:
 * it only adds what is missing.
 *   - "Bodega Boyz" requires a barcode: 2 released batches, one with a barcode number, one without.
 *   - "Pure Haze" has no barcode: 1 released batch.
 *   - Every product: 10 count per case.
 *   - One admin account "Preview Guest" (preview@powerbrandsproductions.com) with a random,
 *     unusable password: it signs in only through a one-time guest link.
 */
class PreviewDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('PreviewDataSeeder never runs on the live site (APP_ENV=production).');
        }
        (new StarterDataSeeder)->run();

        $guest = User::firstOrNew(['email' => PreviewGuestLink::GUEST_EMAIL]);
        if (! $guest->exists) {
            $guest->fill(['name' => 'Preview Guest', 'role' => User::ROLE_ADMIN, 'is_active' => true, 'must_change_password' => false,
                'password' => Hash::make(Str::random(64))]);   // never shown, never usable
            $guest->save();
        }

        Equipment::firstOrCreate(['equip_id' => 'SC-01'], ['name' => 'Bench scale (test)', 'is_scale' => true, 'wm_seal_ref' => 'TEST-SEAL-1',
            'location' => 'Preroll room', 'capacity' => 6000, 'capacity_unit' => 'g', 'division' => 0.1,
            'calibration_due_on' => now()->addMonths(6)->toDateString(), 'last_inspected_on' => now()->subMonths(2)->toDateString(),
            'inspection_due_on' => now()->addMonths(10)->toDateString(), 'active' => true]);

        $bb = Brand::firstOrCreate(['name' => 'Bodega Boyz'], ['short_code' => 'BB']);
        $bb->update(['requires_barcode' => true, 'label_per_case_text' => 'Count per case box']);
        $ph = Brand::firstOrCreate(['name' => 'Pure Haze'], ['short_code' => 'PH']);
        $ph->update(['requires_barcode' => false, 'label_per_case_text' => 'Count per case box']);

        $wz = $this->product($bb, 'preview-bb-wzk-07', 'Watermelon Zkittlez 0.7g', 'WZK', 'Shortiez Preroll', 0.7);
        $gp = $this->product($bb, 'preview-bb-gpu-07', 'Grape Punch 0.7g', 'GPU', 'Shortiez Preroll', 0.7);
        $lm = $this->product($ph, 'preview-ph-lmn-10', 'Lemon Haze 1g', 'LMN', 'Preroll', 1.0);

        $this->batch($wz, Carbon::parse('2026-11-12 10:00'), 320, '1A4120300000596000001707');
        $this->batch($gp, Carbon::parse('2026-11-13 10:00'), 315, null);              // brand needs a barcode, batch has none
        $this->batch($lm, Carbon::parse('2026-11-14 10:00'), 200, null);              // brand without barcode
        // OCM retention: one batch with an expiration date (Retain until = +30 days), the others without.
        Batch::where('product_id', $wz->id)->whereNull('expiration_date')->get()->each(fn (Batch $b) => $b->update(['expiration_date' => '2027-11-12']));
        // Weekly Schedule test data (week of 11/16 from Andy's sheet): preview site only.
        if (\App\Support\Preview::enabled() && \Illuminate\Support\Facades\Schema::hasTable('schedule_weeks')) {
            ScheduleSampleSeeder::seed();
        }
        // Compliance Checklists sample (a couple checked this week + one past week): preview site only.
        if (\App\Support\Preview::enabled() && \Illuminate\Support\Facades\Schema::hasTable('compliance_entries')) {
            ComplianceSampleSeeder::seed();
        }
    }

    private function product(Brand $brand, string $code, string $name, string $short, string $type, float $g): Product
    {
        $p = Product::firstOrCreate(['code' => $code], ['name' => $name, 'brand_id' => $brand->id, 'short_code' => $short, 'family' => 'preroll',
            'product_type' => 'preroll', 'unit_net_qty' => $g, 'unit_net_uom' => 'g', 'case_pack_qty' => 10, 'label_type_text' => $type, 'active' => true]);
        $p->update(['case_pack_qty' => 10]);
        if (! $p->currentMmp()) {
            $leo = User::where('role', User::ROLE_ADMIN)->orderBy('id')->first();
            $pqp = Pqp::where('product_type', 'preroll')->where('status', 'approved')->first()
                ?? Pqp::create(['public_id' => PublicId::next('PQP'), 'product_type' => 'preroll', 'version' => 1, 'title' => 'Preroll plan (test)', 'status' => 'approved', 'approved_at' => now(), 'approved_by' => $leo?->id]);
            Mmp::create(['public_id' => PublicId::next('MMP'), 'product_id' => $p->id, 'version' => 1, 'pqp_id' => $pqp->id, 'title' => "$name recipe (test)",
                'basis_units' => 100, 'unit_fill_mass_g' => $g, 'yield_min_pct' => 95, 'yield_max_pct' => 102, 'status' => 'approved', 'approved_at' => now(), 'approved_by' => $leo?->id]);
        }

        return $p->fresh();
    }

    private function batch(Product $p, Carbon $day, int $units, ?string $barcode): void
    {
        if (Batch::where('product_id', $p->id)->exists()) {
            return;
        }
        $by = User::where('email', PreviewGuestLink::GUEST_EMAIL)->firstOrFail();
        Carbon::setTestNow($day);
        try {
            $b = app(ProductionWorkflow::class)->start($p, $units, null, $by, ['batch_number' => \App\Services\BatchNumberGenerator::peek($p) ?? 'TEST-'.$p->id]);
        } finally {
            Carbon::setTestNow();
        }
        $b->update(['status' => 'released', 'current_step' => 9, 'units_packed' => $units, 'yield_pct' => 100,
            'completed_at' => $day->copy()->addHours(6), 'qc_decision' => 'released', 'qc_reviewer_user_id' => $by->id,
            'qc_note' => 'TEST DATA (preview site)', 'barcode_number' => $barcode]);
    }
}
