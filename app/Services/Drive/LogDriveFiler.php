<?php

namespace App\Services\Drive;

use App\Models\DriveFiling;
use Illuminate\Support\Facades\Log;

/** Testing driver: writes the row to the Laravel log instead of Google. */
class LogDriveFiler implements DriveFiler
{
    public function file(DriveFiling $filing): array
    {
        Log::info('drive-filing', ['sheet' => $filing->sheet_key.'_'.$filing->period.'_v1', 'row' => $filing->row]);

        return ['log', 'log:'.$filing->id];
    }
}
