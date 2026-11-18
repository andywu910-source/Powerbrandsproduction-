<?php

namespace App\Models\Concerns;

use App\Exceptions\RecordDeletionForbidden;

/** Compliance records are never deleted (the database also refuses). */
trait NoDelete
{
    public function delete()
    {
        throw new RecordDeletionForbidden(class_basename($this));
    }

    public function forceDelete()
    {
        throw new RecordDeletionForbidden(class_basename($this));
    }

    public static function destroy($ids)
    {
        throw new RecordDeletionForbidden(class_basename(static::class));
    }
}
