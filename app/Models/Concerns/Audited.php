<?php

namespace App\Models\Concerns;

use App\Services\Audit;

/** Every create / update is written to audit_log with old and new values. */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn ($m) => Audit::log('create', $m, ['new' => $m->auditable($m->getAttributes())]));
        static::updated(function ($m) {
            $changes = $m->auditable($m->getChanges());
            if (! $changes) {
                return;
            }
            $old = array_intersect_key($m->getOriginal(), $changes);
            Audit::log('update', $m, ['old' => $old, 'new' => $changes]);
        });
    }

    protected function auditable(array $attrs): array
    {
        unset($attrs['updated_at'], $attrs['created_at'], $attrs['password'], $attrs['remember_token'], $attrs['signing_pin_hash']);

        return $attrs;
    }
}
