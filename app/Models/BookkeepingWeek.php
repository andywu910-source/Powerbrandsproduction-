<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class BookkeepingWeek extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'week_start' => 'date',
        'ledger_updated_on' => 'date',
        'ledger_updated_at' => 'datetime',
        'payments_recorded_at' => 'datetime',
    ];

    public function statements()
    {
        return $this->hasMany(BookkeepingStatement::class)->orderBy('id');
    }

    public function sends()
    {
        return $this->hasMany(BookkeepingSend::class)->orderByDesc('sent_at')->orderByDesc('id');
    }

    public function paymentsBy()
    {
        return $this->belongsTo(User::class, 'payments_recorded_by');
    }

    public function ledgerBy()
    {
        return $this->belongsTo(User::class, 'ledger_updated_by');
    }

    public function paymentsRecorded(): bool
    {
        return $this->payments_recorded_at !== null;
    }

    public static function containing(?string $date = null): self
    {
        $start = self::monday($date)->toDateString();
        $existing = static::query()->whereDate('week_start', $start)->first();
        if ($existing) {
            return $existing;
        }
        try {
            return static::query()->create(['week_start' => $start]);
        } catch (UniqueConstraintViolationException) {
            return static::query()->whereDate('week_start', $start)->firstOrFail();
        }
    }

    public static function monday(?string $date = null): Carbon
    {
        $tz = config('pbp.site_timezone', 'America/New_York');
        try {
            $day = $date ? Carbon::parse($date, $tz) : Carbon::now($tz);
        } catch (\Throwable) {
            $day = Carbon::now($tz);
        }

        return $day->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }
}
