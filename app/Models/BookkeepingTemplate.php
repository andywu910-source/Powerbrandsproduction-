<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookkeepingTemplate extends Model
{
    protected $guarded = ['id'];

    public static function subjectFor(string $companyId = ''): string
    {
        return self::pick($companyId, 'subject') ?? (string) config('bookkeeping.template.subject');
    }

    public static function bodyFor(string $companyId = ''): string
    {
        return self::pick($companyId, 'body') ?? (string) config('bookkeeping.template.body');
    }

    public static function hasOverride(string $companyId): bool
    {
        return $companyId !== '' && static::query()->where('company_id', $companyId)->exists();
    }

    private static function pick(string $companyId, string $field): ?string
    {
        if ($companyId !== '') {
            $row = static::query()->where('company_id', $companyId)->first();
            if ($row) {
                return $row->{$field};
            }
        }
        $default = static::query()->where('company_id', '')->first();

        return $default?->{$field};
    }
}
