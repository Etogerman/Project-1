<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_EMPLOYEE = 'employee';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'is_active',
        'is_admin',
        'role',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'is_admin' => 'boolean',
        'role' => 'string',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('role')) {
                $user->is_admin = $user->resolvedRole() === self::ROLE_ADMIN;

                return;
            }

            if ($user->isDirty('is_admin') || blank($user->role)) {
                $user->role = $user->is_admin
                    ? self::ROLE_ADMIN
                    : self::ROLE_EMPLOYEE;
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_active;
    }

    public function resolvedRole(): string
    {
        return $this->role === self::ROLE_ADMIN
            ? self::ROLE_ADMIN
            : self::ROLE_EMPLOYEE;
    }

    public function canManageSystem(): bool
    {
        return $this->is_active && $this->resolvedRole() === self::ROLE_ADMIN;
    }

    public function canViewWorkspaces(): bool
    {
        return $this->is_active && in_array($this->resolvedRole(), [
            self::ROLE_ADMIN,
            self::ROLE_EMPLOYEE,
        ], true);
    }

    public function canManageContactWorkspaceMutations(): bool
    {
        return $this->is_active && (bool) $this->is_admin;
    }

    public function canManageContactProfile(): bool
    {
        return $this->canViewWorkspaces();
    }

    public function canManageContactOwnership(): bool
    {
        return $this->canViewWorkspaces();
    }

    public function canEditExistingContactPhones(): bool
    {
        return $this->canViewWorkspaces();
    }

    public function canReplyInDialogs(): bool
    {
        return $this->canViewWorkspaces();
    }

    public function canBeAssignedToContacts(): bool
    {
        return $this->canViewWorkspaces();
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value)
                ? mb_strtolower(trim($value))
                : $value,
        );
    }
}
