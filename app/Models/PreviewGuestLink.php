<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One-time login link for the preview site (only the token's sha256 is stored). */
class PreviewGuestLink extends Model
{
    public const GUEST_EMAIL = 'preview@powerbrandsproductions.com';

    protected $fillable = ['token_hash', 'user_id', 'expires_at', 'used_at', 'used_ip'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{0: self, 1: string} the link and the plain token (shown once, never stored) */
    public static function issue(User $user, int $hours = 24): array
    {
        $token = Str::random(48);
        // A fresh link replaces any earlier unused one.
        static::whereNull('used_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
        $link = static::create(['token_hash' => static::hash($token), 'user_id' => $user->id, 'expires_at' => now()->addHours($hours)]);

        return [$link, $token];
    }
}
