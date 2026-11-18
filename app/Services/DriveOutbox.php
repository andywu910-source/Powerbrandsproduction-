<?php

namespace App\Services;

use App\Models\DriveFiling;
use Illuminate\Database\Eloquent\Model;

/**
 * Signed entries are queued here and appended to that log's monthly Google Sheet
 * (as new rows only). Until Drive confirms, the record shows "Signed – not yet filed".
 */
class DriveOutbox
{
    public static function enqueue(Model $record, string $event, array $row, ?string $sheetKey = null): DriveFiling
    {
        $sheetKey ??= self::sheetKeyFor($record);
        $filing = DriveFiling::create([
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
            'event' => $event,
            'sheet_key' => $sheetKey,
            'period' => now()->timezone(config('pbp.site_timezone'))->format('Y-m'),
            'row' => array_merge([
                now()->timezone(config('pbp.site_timezone'))->format('Y-m-d H:i:s'),
                method_exists($record, 'label') ? $record->label() : (string) $record->getKey(),
                $event,
            ], array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $row)),
            'status' => 'pending',
        ]);

        if (array_key_exists('filing_status', $record->getAttributes()) && $record->filing_status !== 'pending') {
            $record->forceFill(['filing_status' => 'pending'])->saveQuietly();
        }

        return $filing;
    }

    public static function sheetKeyFor(Model $record): string
    {
        return match (true) {
            $record instanceof \App\Models\ChecklistInstance => 'Checklist_'.($record->template?->code ?? 'unknown'),
            $record instanceof \App\Models\Batch => 'BPR',
            $record instanceof \App\Models\MaterialLot => 'Receiving',
            $record instanceof \App\Models\Deviation => 'Deviations',
            default => class_basename($record),
        };
    }
}
