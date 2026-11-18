<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Concerns\Audited;
use App\Models\Concerns\NoDelete;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Audited, HasFactory, NoDelete, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPERVISOR = 'supervisor';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_SUPERVISOR, self::ROLE_OPERATOR, self::ROLE_VIEWER];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'must_change_password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'signing_pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isSupervisor(): bool
    {
        return $this->role === self::ROLE_SUPERVISOR;
    }

    public function isOperator(): bool
    {
        return $this->role === self::ROLE_OPERATOR;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function roleLabel(): string
    {
        return config('pbp.roles.'.$this->role, ucfirst((string) $this->role));
    }

    public function isViewer(): bool
    {
        return $this->role === self::ROLE_VIEWER;
    }

    public function person()
    {
        return $this->hasOne(Person::class);
    }

    /** Supervisors and admins can do supervisor work. */
    public function isSupervisorOrAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN, self::ROLE_SUPERVISOR);
    }

    public function isQcReviewer(): bool
    {
        return in_array($this->id, Setting::qcReviewerIds(), true);
    }

    /** Why this person may not sign anything, or null if they may. */
    public function signingBlocker(): ?string
    {
        if (! $this->is_active) {
            return "{$this->name}'s account is disabled.";
        }
        if ($this->isViewer()) {
            return "{$this->name} has a read-only account and cannot sign.";
        }
        if ($this->person && $this->person->no_documentation) {
            return "{$this->name} is not assigned paperwork or signatures.";
        }

        return null;
    }

    /** People who can be picked as the signer. Excludes viewers, disabled accounts and Jack. */
    public static function signers()
    {
        return static::query()->where('is_active', true)->where('role', '!=', self::ROLE_VIEWER)
            ->whereNotIn('id', Person::query()->where('no_documentation', true)->whereNotNull('user_id')->pluck('user_id'))
            ->orderBy('name')->get();
    }

    public function initials(): string
    {
        if ($this->person && $this->person->initials) {
            return $this->person->initials;
        }
        $parts = preg_split('/\s+/', trim($this->name));

        return strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 3))));
    }
}
