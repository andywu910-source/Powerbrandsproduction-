<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Labels (case / carton labels): count per case on products, barcode rule and wording on
 * brands, a barcode number on each batch (Batch Log), and an append-only print log.
 * The database refuses deletes and changes on label_prints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedInteger('case_pack_qty')->default(10);   // "count per case" (Andy: default 10)
            $t->string('label_type_text')->nullable();            // 2nd label line, e.g. "Shortiez Preroll"
        });
        DB::table('products')->update(['case_pack_qty' => 10]);   // existing products: 10 per case
        Schema::table('brands', function (Blueprint $t) {
            $t->boolean('requires_barcode')->default(false);      // labels for this brand always carry the batch barcode
            $t->string('label_per_case_text', 60)->default('Count per case box');   // wording of the last label line
        });
        Schema::table('batches', function (Blueprint $t) {
            $t->string('barcode_number', 48)->nullable();         // printed as Code 128 on case labels (Batch Log)
        });

        Schema::create('label_prints', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 30)->unique();               // LBL-000001
            $t->foreignId('batch_id')->nullable()->constrained('batches');
            $t->foreignId('product_id')->nullable()->constrained('products');
            $t->string('template', 40);
            $t->unsignedInteger('quantity');
            $t->json('fields');                                   // the exact values printed
            $t->string('pdf_sha256', 64);
            $t->foreignId('printed_by')->constrained('users');
            $t->timestamps();
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $mysql = $driver !== 'sqlite';
            $del = 'Records cannot be deleted. Void the record with a reason instead.';
            $upd = 'This record is append-only and cannot be changed.';
            DB::unprepared($mysql
                ? "CREATE TRIGGER pbp_nodel_label_prints BEFORE DELETE ON `label_prints` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$del}'"
                : "CREATE TRIGGER pbp_nodel_label_prints BEFORE DELETE ON \"label_prints\" BEGIN SELECT RAISE(ABORT, '{$del}'); END");
            DB::unprepared($mysql
                ? "CREATE TRIGGER pbp_noupd_label_prints BEFORE UPDATE ON `label_prints` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$upd}'"
                : "CREATE TRIGGER pbp_noupd_label_prints BEFORE UPDATE ON \"label_prints\" BEGIN SELECT RAISE(ABORT, '{$upd}'); END");
        }
    }

    public function down(): void
    {
        foreach (['pbp_nodel_label_prints', 'pbp_noupd_label_prints'] as $tr) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$tr}");
        }
        Schema::dropIfExists('label_prints');
        Schema::table('batches', fn (Blueprint $t) => $t->dropColumn('barcode_number'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['case_pack_qty', 'label_type_text']));
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn(['requires_barcode', 'label_per_case_text']));
    }
};
