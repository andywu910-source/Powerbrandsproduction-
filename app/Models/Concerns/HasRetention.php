<?php

namespace App\Models\Concerns;

use App\Models\Setting;

/** Sets the "keep until" date (created + retention years, at least 5). Never purged. */
trait HasRetention
{
    public static function bootHasRetention(): void
    {
        static::creating(function ($m) {
            if (empty($m->retention_until)) {
                $years = max(5, (int) Setting::get('retention_years', 5));
                $m->retention_until = now()->addYears($years)->toDateString();
            }
        });
    }

    /** Push the keep-until date out if something (e.g. an expiry date) needs longer. */
    public function extendRetention(?\DateTimeInterface $date): void
    {
        if (! $date) {
            return;
        }
        $years = max(5, (int) Setting::get('retention_years', 5));
        $candidate = \Illuminate\Support\Carbon::instance($date)->addYears($years)->toDateString();
        if ($candidate > (string) $this->retention_until) {
            $this->retention_until = $candidate;
        }
    }
}
