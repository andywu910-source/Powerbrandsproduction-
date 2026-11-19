<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BookkeepingSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['value' => 'array'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? ($row->value['v'] ?? null) : $default;
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['v' => $value], 'updated_by' => $userId]
        );
    }

    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }

    /** m/d/Y. The configured initial date until an admin marks the ledger updated. */
    public static function ledgerDateLabel(): string
    {
        $stored = static::get('ledger_updated_on');
        $date = is_string($stored) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $stored)
            ? $stored
            : (string) config('bookkeeping.ledger_initial_date', '2026-11-19');

        return Carbon::parse($date)->format('m/d/Y');
    }

    public static function ledgerSummary(): ?string
    {
        $summary = static::get('ledger_summary');

        return is_string($summary) && trim($summary) !== '' ? trim($summary) : null;
    }
}
