<?php

namespace App\Models;

use App\Models\Concerns\NoDelete;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of revisions / re-confirmations of a sign-off. Never updated, never deleted (the database refuses both). */
class SignoffRevision extends Model
{
    use NoDelete;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function signature() { return $this->belongsTo(Signature::class); }

    /** @return array<string, array{0:?string,1:?string}> */
    public function changeList(): array
    {
        return (array) json_decode((string) ($this->attributes['changes'] ?? ''), true);
    }
}
