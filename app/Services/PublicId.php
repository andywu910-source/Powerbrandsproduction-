<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Race-safe counters. IDs are never reused. */
class PublicId
{
    /** Next value of a named counter (locks the row inside a transaction). */
    public static function nextValue(string $key): int
    {
        return DB::transaction(function () use ($key) {
            $row = DB::table('id_sequences')->where('prefix', $key)->lockForUpdate()->first();
            if (! $row) {
                DB::table('id_sequences')->insertOrIgnore(['prefix' => $key, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('id_sequences')->where('prefix', $key)->lockForUpdate()->first();
            }
            $next = (int) $row->last_value + 1;
            DB::table('id_sequences')->where('prefix', $key)->update(['last_value' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    /** e.g. next('BPR') => BPR-000001 */
    public static function next(string $prefix): string
    {
        return $prefix.'-'.str_pad((string) self::nextValue($prefix), 6, '0', STR_PAD_LEFT);
    }
}
