<?php

use App\Models\ComplianceItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive + idempotent (Nov 18): Quarterly and Annual checklists, "standing" logs (confirmed each year, shown
 * under Annual), the weekly cycle-count rename (same row, so history stays linked) and the new items.
 * Never deletes or changes entries. Every item change goes through the model, so it is audited.
 */
return new class extends Migration
{
    public const RENAME_FROM = 'Physical vs Metrc spot check';

    public const RENAME_TO = ['Weekly 10% cycle count (SOP-INV-03)',
        'Count at least 10% of active packages on the Inventory Count Sheet (LOGS / Inventory Audit); record names, signatures, date, findings.'];

    /** [frequency, title, description, standing] */
    public const NEW = [
        ['weekly', 'Formulation Log / batch record check', 'Confirm every batch started or finished this week has a completed, signed Formulation Log (9 NYCRR 123.6(g)(3)).', false],
        ['monthly', 'Security equipment test & inspection (every 30 days)', 'Test and inspect locks, storage, alarms, and cameras; confirm video records and is retrievable for at least 60 days; confirm the surveillance room access list is current; keep records 5 years (9 NYCRR 125.3(c)(6), (c)(2)(vi), (c)(5)).', false],
        ['monthly', 'Retained Sample Log monthly review (SOP-QA-04)', 'Samples on hand, unopened, in original packaging, labeled, and kept at least 30 days past expiration (123.6(h)(3)).', false],
        ['monthly', 'Metrc & surveillance access review', 'Remove users no longer authorized (125.8(a)(7), 125.2(b)(8)(i)).', false],
        ['quarterly', 'Approved Ingredient List review (SOP-QA-05, Appendix 6-E)', 'Review quarterly and whenever a supplier changes a formulation.', false],
        ['annual', 'Annual comprehensive inventory audit', '125.8(d)(3); SOP-INV-03; Inventory Count Audit sheet.', false],
        ['annual', 'Annual training-needs review & 4-hr ongoing training per full-time employee', '125.5(f)(4).', false],
        ['annual', 'Mock recall drill & record', '125.2(b)(8)(vi).', false],
        ['annual', 'Complaint log maintained (24-hr OCM notice for serious adverse events/recalls, 125.9(a))', 'Standing log: confirm once a year that it is kept up to date.', true],
        ['annual', 'CAPA / deviation log maintained (123.6(g))', 'Standing log: confirm once a year that it is kept up to date.', true],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('compliance_items', 'standing')) {
            Schema::table('compliance_items', function (Blueprint $t) {
                $t->boolean('standing')->default(false)->after('hidden');   // standing / event log, confirmed each year
            });
        }
        $old = ComplianceItem::where('frequency', 'weekly')->where('title', self::RENAME_FROM)->first();
        if ($old && ! ComplianceItem::where('frequency', 'weekly')->where('title', self::RENAME_TO[0])->exists()) {
            $old->update(['title' => self::RENAME_TO[0], 'description' => self::RENAME_TO[1]]);
        }
        foreach (self::NEW as [$f, $title, $desc, $standing]) {
            if (ComplianceItem::where('frequency', $f)->where('title', $title)->exists()) {
                continue;
            }
            ComplianceItem::create(['frequency' => $f, 'title' => $title, 'description' => $desc, 'standing' => $standing, 'hidden' => false,
                'sort_order' => (int) ComplianceItem::where('frequency', $f)->max('sort_order') + 1]);
        }
    }

    public function down(): void
    {
        // Additive release: rollback restores the database backup; nothing to undo here.
    }
};
