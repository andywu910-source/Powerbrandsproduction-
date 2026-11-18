<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Model;

/** Editable settings. Defaults live in config/pbp_settings.php. */
class Setting extends Model
{
    use NoDelete;

    protected $guarded = ['id'];

    protected $casts = ['value' => 'array'];

    protected static array $memo = [];

    public static function get(string $key, mixed $fallback = null): mixed
    {
        if (! array_key_exists($key, static::$memo)) {
            $row = null;
            try {
                $row = static::query()->where('key', $key)->first();
            } catch (\Throwable) {
                // table not migrated yet
            }
            static::$memo[$key] = $row ? ($row->value['v'] ?? null) : config("pbp_settings.{$key}.default", $fallback);
        }

        return static::$memo[$key] ?? $fallback;
    }

    public static function put(string $key, mixed $value, ?User $by = null): void
    {
        $row = static::query()->firstOrNew(['key' => $key]);
        $old = $row->exists ? ($row->value['v'] ?? null) : config("pbp_settings.{$key}.default");
        $row->value = ['v' => $value];
        $row->updated_by = $by?->id;
        $row->save();
        static::$memo[$key] = $value;
        if ($old !== $value) {
            Audit::log('setting', $row, ['key' => $key, 'old' => $old, 'new' => $value]);
        }
    }

    public static function flush(): void
    {
        static::$memo = [];
    }

    /** @return int[] */
    public static function qcReviewerIds(): array
    {
        return array_values(array_map('intval', (array) static::get('qc_reviewer_user_ids', [])));
    }
}
