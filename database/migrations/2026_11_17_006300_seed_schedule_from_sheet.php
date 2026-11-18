<?php

use Database\Seeders\ScheduleSampleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data only (additive): the real Weekly Schedule roster (12 people) and the week of 11/16 from the
 * SCHEDULE tab of Andy's sheet. No test batches, no notes, no users. Idempotent (matched by name / week).
 * Skipped inside the test suite unless $force is set.
 */
return new class extends Migration
{
    public bool $force = false;

    public function up(): void
    {
        if (app()->runningUnitTests() && ! $this->force) {
            return;
        }
        ScheduleSampleSeeder::seedSheet();
    }

    public function down(): void
    {
        // nothing: schedule rows are kept (no-delete policy); a code rollback restores the database backup
    }
};
