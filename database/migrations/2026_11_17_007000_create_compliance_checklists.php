<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: Compliance Checklists (weekly + monthly). compliance_items = the template (admins edit it later),
 * compliance_entries = one row per item per period (week Monday / month 1st). Seeds the template items only.
 */
return new class extends Migration
{
    public const WEEKLY = [
        ['2-D Weekly Tag Audit', 'Walk every room (Vape Production, Cold Room, Production Room), confirm each package has a Metrc tag, log it.'],
        ['2-E Tag Replacement Log', 'Record any lost/damaged tags replaced this week.'],
        ['1-C Receiving Checklist', 'Confirm every delivery this week was logged and matched to Metrc.'],
        ['1-D Manifest Review Log', 'Confirm every manifest in/out this week was reviewed and logged.'],
        ['2-B Package Split Record', 'Confirm every split/repack this week is logged with source and new tags.'],
        ['1-A Destruction Log', 'Confirm all destructions have full 24-character Metrc tags, weights with units, reason, and photos filed.'],
        ['Retained Sample Log', 'Confirm a retained sample was logged for each new batch.'],
        ['Scale checks', 'Daily balance verification entries complete for Scout, Medium, Large scales.'],
        ['Cleaning & Sanitation Log', 'Confirm daily/after-use entries are complete.'],
        ['Temperature & Humidity Log', 'Confirm readings recorded for storage areas.'],
        ['Visitor Log', 'Confirm every visitor signed in/out.'],
        ['Waste Log', 'Confirm non-cannabis waste entries are complete.'],
        ['Physical vs Metrc spot check', 'Compare a sample of physical packages to Metrc and note discrepancies.'],
        ['Supervisor review', 'Initial each log reviewed this week.'],
    ];

    public const MONTHLY = [
        ['3-C Scale Calibration', 'Monthly calibration check for all scales (PB1-PB12), logged.'],
        ['Package Reconciliation', 'Reconcile physical inventory against Metrc for the month.'],
        ['Inventory Count Audit', 'Full or cycle count, discrepancies documented.'],
        ['Pest Control Log', 'Monthly service/inspection recorded and report filed.'],
        ['Monthly Safety Signage Inspection', 'Confirm all required postings are up and legible.'],
        ['Monthly Roster Reconciliation', 'Staff roster matches training records and access list.'],
        ['Training / RWT attestations', 'New hires and refreshers signed and filed.'],
        ['Chemical Inventory & SDS', 'Confirm every chemical on site has a current SDS and the inventory is up to date.'],
        ['Equipment Register', 'Update maintenance/calibration due dates; flag overdue items.'],
        ['Supplier Ingredient Attestation', 'Confirm attestations on file for every ingredient received this month.'],
        ['Sign Purchase & Posting Log', 'Any new signs purchased/posted are logged.'],
        ["Create next month's tab in every LOGS spreadsheet", null],
        ['Monthly review', 'Compliance lead (Leo) reviews all logs for gaps and signs off.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('compliance_items')) {
            Schema::create('compliance_items', function (Blueprint $t) {
                $t->id();
                $t->string('frequency', 10);                 // weekly | monthly
                $t->string('title', 160);
                $t->text('description')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->boolean('hidden')->default(false);       // hidden from new periods; history keeps it
                $t->timestamps();
                $t->index(['frequency', 'sort_order']);
            });
        }
        if (! Schema::hasTable('compliance_entries')) {
            Schema::create('compliance_entries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('item_id')->constrained('compliance_items');
                $t->date('period_start');                    // Monday of the week, or the 1st of the month
                $t->boolean('done')->default(false);
                $t->foreignId('assignee_user_id')->nullable()->constrained('users');
                $t->foreignId('completed_by_user_id')->nullable()->constrained('users');
                $t->date('completed_on')->nullable();
                $t->text('note')->nullable();
                $t->foreignId('updated_by_user_id')->nullable()->constrained('users');
                $t->timestamps();
                $t->unique(['item_id', 'period_start']);
            });
        }
        if (DB::table('compliance_items')->count() === 0) {
            $now = now();
            foreach (['weekly' => self::WEEKLY, 'monthly' => self::MONTHLY] as $f => $items) {
                foreach ($items as $i => [$title, $desc]) {
                    DB::table('compliance_items')->insert(['frequency' => $f, 'title' => $title, 'description' => $desc, 'sort_order' => $i + 1,
                        'hidden' => false, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_entries');
        Schema::dropIfExists('compliance_items');
    }
};
