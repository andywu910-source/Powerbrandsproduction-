<?php

namespace App\Services\Drive;

use App\Models\DriveFiling;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Works through the outbox. Does nothing while Drive filing is switched off. */
class DriveFilingRunner
{
    public function __construct(private DriveFiler $filer) {}

    public static function enabled(): bool
    {
        return (bool) config('pbp.drive.enabled');
    }

    /** @return array{filed:int, failed:int} */
    public function run(int $limit = 50): array
    {
        $out = ['filed' => 0, 'failed' => 0];
        if (! self::enabled()) {
            return $out;
        }
        $max = (int) config('pbp.drive.max_attempts', 10);
        $rows = DriveFiling::whereIn('status', ['pending', 'failed'])->where('attempts', '<', $max)->orderBy('id')->limit($limit)->get();
        foreach ($rows as $f) {
            try {
                [$sheet, $ref] = $this->filer->file($f);
                $f->update(['status' => 'filed', 'spreadsheet_id' => $sheet, 'row_ref' => $ref, 'filed_at' => now(), 'attempts' => $f->attempts + 1, 'last_error' => null]);
                $out['filed']++;
                $this->refreshRecordStatus($f);
            } catch (\Throwable $e) {
                $f->update(['status' => 'failed', 'attempts' => $f->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
                $out['failed']++;
            }
        }

        return $out;
    }

    private function refreshRecordStatus(DriveFiling $f): void
    {
        $class = Relation::getMorphedModel($f->record_type) ?? $f->record_type;
        if (! class_exists($class)) {
            return;
        }
        $record = $class::find($f->record_id);
        if (! $record || ! array_key_exists('filing_status', $record->getAttributes())) {
            return;
        }
        $left = DriveFiling::where('record_type', $f->record_type)->where('record_id', $f->record_id)->where('status', '!=', 'filed')->exists();
        $record->forceFill(['filing_status' => $left ? 'pending' : 'filed'])->saveQuietly();
    }
}
