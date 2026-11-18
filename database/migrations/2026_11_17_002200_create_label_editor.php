<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On-label editor (additive only):
 *  - label_prints.layout: the exact layout printed (lines, positions, sizes, barcode box), layout_edited.
 *  - label_templates: "Save as template for this product" (admin / supervisor). Append-only: a new
 *    save adds a row and the newest row is used. The database refuses deletes and changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_prints', function (Blueprint $t) {
            $t->json('layout')->nullable();
            $t->boolean('layout_edited')->default(false);
        });
        Schema::create('label_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->string('label_type', 10);                 // master / case
            $t->json('layout');
            $t->foreignId('saved_by')->constrained('users');
            $t->timestamps();
            $t->index(['product_id', 'label_type']);
        });
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $mysql = $driver !== 'sqlite';
            $del = 'Records cannot be deleted. Void the record with a reason instead.';
            $upd = 'This record is append-only and cannot be changed.';
            DB::unprepared($mysql
                ? "CREATE TRIGGER pbp_nodel_label_templates BEFORE DELETE ON `label_templates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$del}'"
                : "CREATE TRIGGER pbp_nodel_label_templates BEFORE DELETE ON \"label_templates\" BEGIN SELECT RAISE(ABORT, '{$del}'); END");
            DB::unprepared($mysql
                ? "CREATE TRIGGER pbp_noupd_label_templates BEFORE UPDATE ON `label_templates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$upd}'"
                : "CREATE TRIGGER pbp_noupd_label_templates BEFORE UPDATE ON \"label_templates\" BEGIN SELECT RAISE(ABORT, '{$upd}'); END");
        }
    }

    public function down(): void
    {
        foreach (['pbp_nodel_label_templates', 'pbp_noupd_label_templates'] as $tr) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$tr}");
        }
        Schema::dropIfExists('label_templates');
        Schema::table('label_prints', fn (Blueprint $t) => $t->dropColumn(['layout', 'layout_edited']));
    }
};
