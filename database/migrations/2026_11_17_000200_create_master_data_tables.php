<?php

use App\Support\RecordColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Master data: people & training, rooms, equipment, suppliers, ingredients, products, PQPs, MMPs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->string('initials', 10);
            $t->string('job_title')->nullable();
            $t->foreignId('user_id')->nullable()->unique()->constrained('users');
            // Jack: appears in lists so others can record "performed by", never signs or gets paperwork.
            $t->boolean('no_documentation')->default(false);
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
        });

        Schema::create('training_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('person_id')->constrained('people');
            $t->string('category', 20);   // rwt / task / gmp / safety / other
            $t->string('topic');
            $t->date('completed_on');
            $t->date('expires_on')->nullable();
            $t->string('trainer')->nullable();
            $t->text('notes')->nullable();
            RecordColumns::add($t, false);
        });

        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->string('room_no', 40)->unique();
            $t->string('name');
            $t->string('use')->nullable();
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
        });

        Schema::create('equipment', function (Blueprint $t) {
            $t->id();
            $t->string('equip_id', 40)->unique();
            $t->string('name');
            $t->foreignId('room_id')->nullable()->constrained('rooms');
            $t->string('type')->nullable();
            $t->string('serial')->nullable();
            $t->boolean('is_scale')->default(false);
            $t->string('wm_seal_ref')->nullable();     // NYS Weights & Measures approval / seal
            $t->unsignedInteger('calibration_frequency_days')->nullable();
            $t->date('last_calibrated_on')->nullable();
            $t->date('calibration_due_on')->nullable();
            $t->date('maintenance_due_on')->nullable();
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
        });

        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('address')->nullable();
            $t->string('license_no')->nullable();
            $t->string('contact')->nullable();
            $t->boolean('approved')->default(false);
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
        });

        Schema::create('ingredients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            // cannabis_active / terpene / carrier / hardware / packaging / label / other
            $t->string('kind', 20);
            $t->string('unit', 10)->default('g');      // g or ea
            $t->string('grade')->nullable();
            $t->boolean('is_cannabis')->default(false); // needs Metrc manifest + package UID
            $t->boolean('requires_coa')->default(false);
            $t->boolean('requires_attestation')->default(false); // terpenes / flavors (Appendix 6-D)
            $t->boolean('inhaled_allowed')->default(true);
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
        });

        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            // Used in batch numbers. Length/characters set in Settings (default exactly 2, A-Z and 2-9, no O/0/I/1).
            $t->string('short_code', 8)->nullable()->unique();
            $t->boolean('needs_review')->default(false);
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('code', 60)->unique();      // internal key (slug), not shown on paperwork
            $t->string('name');
            $t->foreignId('brand_id')->nullable()->constrained('brands');
            // Used in batch numbers (default exactly 3 chars). Unique within the brand, so
            // brand + product code is unique overall. Size/format variants get different codes.
            $t->string('short_code', 10)->nullable();
            // Product family decides the manufacturing steps (process template):
            // preroll / vape / flower / rosin / green_mantis
            $t->string('family', 20)->nullable()->index();
            $t->string('product_type', 30)->nullable();  // finer type, e.g. preroll, aio, cartridge, tincture
            $t->string('form_code', 10)->nullable();     // used in lot numbers, e.g. PR, VP
            $t->decimal('unit_net_qty', 12, 3)->nullable();
            $t->string('unit_net_uom', 10)->default('g'); // g / ml / ea (App\Support\Units)
            $t->string('hardware_sku')->nullable();
            $t->boolean('needs_review')->default(false)->index();
            $t->text('review_note')->nullable();
            $t->string('source', 40)->default('manual');  // manual / production_log
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
            $t->unique(['brand_id', 'short_code']);
        });

        // Every spelling of a product name seen in Andy's Production Log.
        Schema::create('product_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->string('alias');
            $t->string('alias_key')->unique();   // normalized lookup key
            $t->string('source', 40)->default('production_log');
            $t->timestamps();
        });

        // Family-specific manufacturing stages used by Production step 6 (admin-editable).
        Schema::create('process_templates', function (Blueprint $t) {
            $t->id();
            $t->string('family', 20)->unique();
            $t->string('name');
            $t->boolean('active')->default(true);
            RecordColumns::add($t, false);
        });

        Schema::create('process_template_stages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('process_template_id')->constrained('process_templates');
            $t->unsignedInteger('stage_no');
            $t->string('code', 30);       // grinding / infusion / filling / mixing / packaging
            $t->string('name');
            $t->boolean('active')->default(true);
            // Starter MMP steps for a new recipe: [{instruction, parameter, unit, limit_min, limit_max}]
            $t->json('default_steps')->nullable();
            $t->timestamps();
            $t->unique(['process_template_id', 'code']);
        });

        Schema::create('pqps', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 30)->unique();
            $t->string('product_type', 30);
            $t->unsignedInteger('version');
            $t->string('title');
            $t->date('effective_date')->nullable();
            $t->longText('body')->nullable();
            $t->string('status', 20)->default('draft'); // draft / approved / superseded / void
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users');
            RecordColumns::add($t);
            $t->unique(['product_type', 'version']);
        });

        // MMP = formulation + method. One per formulation and batch size; versioned.
        Schema::create('mmps', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 30)->unique();
            $t->foreignId('product_id')->constrained('products');
            $t->unsignedInteger('version');
            $t->foreignId('pqp_id')->nullable()->constrained('pqps');
            $t->string('title');
            $t->unsignedInteger('basis_units');                 // recipe written for this many units
            $t->decimal('unit_fill_mass_g', 12, 4)->nullable(); // fill per unit (vapes, prerolls)
            $t->string('potency_basis', 10)->default('pct');    // pct (% total THC) or mg (mg per unit)
            // Formulation details as Andy's log records them.
            // Prerolls: infusion type distillate / terp / hte / diamond / kief, infusion % (e.g. 16 or 22.5)
            $t->string('infusion_type', 20)->nullable();
            $t->decimal('infusion_pct', 8, 3)->nullable();
            $t->string('terp_brand')->nullable();
            $t->string('terp_flavor')->nullable();
            // Vapes: mix type (e.g. distillate + terps, distillate + HTE, live rosin) and mix as % or ratio (90/5/5)
            $t->string('mix_type', 40)->nullable();
            $t->string('mix_spec', 60)->nullable();
            $t->string('hardware_sku')->nullable();
            $t->decimal('target_potency', 10, 3)->nullable();
            $t->decimal('potency_min', 10, 3)->nullable();
            $t->decimal('potency_max', 10, 3)->nullable();
            $t->decimal('yield_expected_pct', 6, 2)->default(100);
            $t->decimal('yield_min_pct', 6, 2)->default(95);
            $t->decimal('yield_max_pct', 6, 2)->default(102);
            $t->text('packaging_desc')->nullable();
            $t->string('label_version')->nullable();
            $t->string('status', 20)->default('draft');  // draft / approved / superseded / void
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users');
            RecordColumns::add($t);
            $t->unique(['product_id', 'version']);
        });

        Schema::create('mmp_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mmp_id')->constrained('mmps');
            $t->unsignedInteger('line_no');
            $t->foreignId('ingredient_id')->constrained('ingredients');
            $t->string('line_type', 20);   // cannabis_active / terpene / carrier / hardware / packaging / label / other
            $t->decimal('qty_per_basis', 14, 4);
            $t->string('unit', 10);
            // by_mass / by_count / potency_adjusted / potency_balancer
            $t->string('scale_mode', 20)->default('by_mass');
            $t->decimal('tolerance_pct', 6, 3)->nullable();  // null = setting default for the line type
            $t->decimal('overage_pct', 6, 3)->default(0);
            $t->unsignedInteger('mmp_step_no')->nullable();
            $t->timestamps();
        });

        Schema::create('mmp_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mmp_id')->constrained('mmps');
            $t->unsignedInteger('step_no');
            $t->string('stage_code', 30)->nullable();  // which process-template stage it belongs to
            $t->text('instruction');
            $t->string('equipment')->nullable();
            $t->string('parameter')->nullable();    // e.g. "Fill weight"
            $t->string('unit', 20)->nullable();
            $t->decimal('limit_min', 14, 4)->nullable();
            $t->decimal('limit_max', 14, 4)->nullable();
            $t->boolean('requires_verifier')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mmp_steps', 'mmp_lines', 'mmps', 'pqps', 'process_template_stages', 'process_templates', 'product_aliases', 'products', 'brands', 'ingredients', 'suppliers', 'equipment', 'rooms', 'training_records', 'people'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
