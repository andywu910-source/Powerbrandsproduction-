<?php

use App\Support\RecordColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Receiving: raw-material lots, the append-only lot ledger and reservations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_lots', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 30)->unique();          // RCV-000001
            $t->timestamp('received_at');
            $t->foreignId('supplier_id')->constrained('suppliers');
            $t->foreignId('ingredient_id')->constrained('ingredients');
            $t->string('supplier_lot')->nullable();
            $t->string('grade')->nullable();
            $t->decimal('received_qty', 14, 4);
            $t->string('unit', 10);
            $t->string('metrc_manifest_no')->nullable();
            $t->string('metrc_package_uid')->nullable()->index();
            $t->boolean('condition_ok')->default(false);
            $t->boolean('seals_ok')->default(false);
            $t->text('condition_notes')->nullable();
            $t->date('expiration_date')->nullable();
            // Paperwork
            $t->decimal('potency_pct', 8, 3)->nullable();   // total THC % from the COA
            $t->string('coa_result', 10)->nullable();       // pass / fail
            $t->boolean('attestation_ok')->nullable();      // Appendix 6-D received & complete
            $t->boolean('source_records_ok')->nullable();
            $t->boolean('banned_ingredient_ok')->nullable();
            $t->boolean('inhaled_allowed_ok')->nullable();
            // quarantine / released / rejected / depleted / void
            $t->string('status', 20)->default('quarantine')->index();
            $t->text('qc_note')->nullable();
            $t->decimal('current_balance', 14, 4)->default(0); // cache; the ledger is the truth
            $t->string('source', 20)->default('receiving');  // receiving / distru
            $t->string('external_ref')->nullable();          // Distru batch/package id (Phase 2b)
            RecordColumns::add($t);
        });

        Schema::create('lot_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_lot_id')->constrained('material_lots');
            $t->string('entry_type', 12);  // receipt / consume / adjust / waste / return / sync
            $t->decimal('qty_delta', 14, 4);
            $t->decimal('balance_after', 14, 4);
            $t->string('ref_type', 80)->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('user_id')->constrained('users');
            $t->unsignedBigInteger('signature_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('lot_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_lot_id')->constrained('material_lots');
            $t->unsignedBigInteger('batch_id')->index();
            $t->unsignedBigInteger('batch_material_line_id')->nullable();
            $t->decimal('qty', 14, 4);
            $t->string('status', 12)->default('open'); // open / consumed / released
            $t->foreignId('user_id')->constrained('users');
            $t->timestamps();
            $t->index(['material_lot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_reservations');
        Schema::dropIfExists('lot_ledger');
        Schema::dropIfExists('material_lots');
    }
};
