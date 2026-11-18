<?php

namespace App\Services\Drive;

use App\Models\DriveFiling;

interface DriveFiler
{
    /** Append the filing's row to its monthly sheet. Returns [spreadsheetId, rowRef]. Throws on failure. */
    public function file(DriveFiling $filing): array;
}
