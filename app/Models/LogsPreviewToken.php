<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One-time sign-in link that opens ONLY the Compliance hub (/logs) as an existing user.
 * The plain token is shown once by the artisan command; only its sha256 is stored.
 */
class LogsPreviewToken extends Model
{
    public const MAX_HOURS = 24;

    /** Session key that marks a preview-scoped session. */
    public const SESSION_KEY = 'logs_preview';

    protected $fillable = ['token_hash', 'user_id', 'expires_at', 'used_at', 'used_ip', 'revoked_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }

    public static function enabled(): bool
    {
        return (bool) config('pbp.preview_links');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 64 hex characters = 256 bits from the OS CSPRNG. */
    public static function looksValid(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $token);
    }

    /** @return array{0: self, 1: string} the stored row and the plain token (never stored) */
    public static function issue(User $user, int $hours = self::MAX_HOURS): array
    {
        $hours = max(1, min(self::MAX_HOURS, $hours));
        $token = bin2hex(random_bytes(32));
        $row = static::create([
            'token_hash' => static::hash($token),
            'user_id' => $user->id,
            'expires_at' => now()->addHours($hours),
        ]);

        return [$row, $token];
    }

    /** Still usable for an already signed-in preview session? (expiry and revoke are re-checked every request) */
    public function sessionAlive(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture();
    }

    /** Ends every link and every preview session at once. */
    public static function revokeAll(): int
    {
        return static::whereNull('revoked_at')->where('expires_at', '>', now())->update(['revoked_at' => now()]);
    }
}
