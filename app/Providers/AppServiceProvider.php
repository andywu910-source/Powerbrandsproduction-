<?php

namespace App\Providers;

use App\Services\Drive\DriveFiler;
use App\Services\Drive\GoogleSheetsDriveFiler;
use App\Services\Drive\LogDriveFiler;
use App\Services\Inventory\DistruInventorySource;
use App\Services\Inventory\InventorySource;
use App\Services\Inventory\ReceivingInventorySource;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Stable short names stored in record_type / signable_type columns. */
    public const MORPHS = [
        'user' => \App\Models\User::class,
        'setting' => \App\Models\Setting::class,
        'person' => \App\Models\Person::class,
        'training' => \App\Models\TrainingRecord::class,
        'room' => \App\Models\Room::class,
        'equipment' => \App\Models\Equipment::class,
        'supplier' => \App\Models\Supplier::class,
        'ingredient' => \App\Models\Ingredient::class,
        'brand' => \App\Models\Brand::class,
        'product' => \App\Models\Product::class,
        'product_alias' => \App\Models\ProductAlias::class,
        'schedule_employee' => \App\Models\ScheduleEmployee::class,
        'schedule_week' => \App\Models\ScheduleWeek::class,
        'schedule_roster' => \App\Models\ScheduleRoster::class,
        'schedule_cell' => \App\Models\ScheduleCell::class,
        'schedule_job' => \App\Models\ScheduleJob::class,
        'schedule_note' => \App\Models\ScheduleNote::class,
        'compliance_item' => \App\Models\ComplianceItem::class,
        'compliance_entry' => \App\Models\ComplianceEntry::class,
        'log_definition' => \App\Models\LogDefinition::class,
        'log_entry' => \App\Models\LogEntry::class,
        'process_template' => \App\Models\ProcessTemplate::class,
        'process_stage' => \App\Models\ProcessTemplateStage::class,
        'pqp' => \App\Models\Pqp::class,
        'mmp' => \App\Models\Mmp::class,
        'mmp_line' => \App\Models\MmpLine::class,
        'mmp_step' => \App\Models\MmpStep::class,
        'material_lot' => \App\Models\MaterialLot::class,
        'batch' => \App\Models\Batch::class,
        'batch_step' => \App\Models\BatchStep::class,
        'batch_person' => \App\Models\BatchPerson::class,
        'batch_equipment' => \App\Models\BatchEquipment::class,
        'batch_calculation' => \App\Models\BatchCalculation::class,
        'batch_material_line' => \App\Models\BatchMaterialLine::class,
        'batch_mmp_step' => \App\Models\BatchMmpStep::class,
        'deviation' => \App\Models\Deviation::class,
        'checklist_template' => \App\Models\ChecklistTemplate::class,
        'checklist_version' => \App\Models\ChecklistTemplateVersion::class,
        'checklist' => \App\Models\ChecklistInstance::class,
        'checklist_item' => \App\Models\ChecklistInstanceItem::class,
        'scale_inspection' => \App\Models\ScaleInspection::class,
        'label_print' => \App\Models\LabelPrint::class,
        'label_template' => \App\Models\LabelTemplate::class,
    ];

    public function register(): void
    {
        $this->app->bind(InventorySource::class, fn () => config('pbp.inventory_source') === 'distru'
            ? new DistruInventorySource : new ReceivingInventorySource);
        $this->app->bind(DriveFiler::class, fn () => config('pbp.drive.driver') === 'google'
            ? new GoogleSheetsDriveFiler : new LogDriveFiler);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap(self::MORPHS);

        // Route-level limit on top of the per email+IP limit in LoginController.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
