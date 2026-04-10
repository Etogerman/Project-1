<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var array<string, bool>|null
     */
    protected ?array $cachedRolePermissions = null;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_EMPLOYEE = 'employee';

    public const ROLE_SUPERADMIN = 'superadmin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'is_active',
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
                $user->is_admin = in_array($user->resolvedRole(), [
                    self::ROLE_SUPERADMIN,
                    self::ROLE_ADMIN,
                ], true);

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
        return match ($this->role) {
            self::ROLE_SUPERADMIN => self::ROLE_SUPERADMIN,
            self::ROLE_ADMIN => self::ROLE_ADMIN,
            default => self::ROLE_EMPLOYEE,
        };
    }

    public function isSuperadmin(): bool
    {
        return $this->resolvedRole() === self::ROLE_SUPERADMIN;
    }

    public function canManageRolePermissionRecovery(): bool
    {
        return $this->is_active && $this->isSuperadmin();
    }

    public function canManageSystem(): bool
    {
        return $this->is_active && in_array($this->resolvedRole(), [
            self::ROLE_SUPERADMIN,
            self::ROLE_ADMIN,
        ], true);
    }

    public function canViewWorkspaces(): bool
    {
        return $this->is_active && in_array($this->resolvedRole(), [
            self::ROLE_SUPERADMIN,
            self::ROLE_ADMIN,
            self::ROLE_EMPLOYEE,
        ], true);
    }

    public function canManageContactWorkspaceMutations(): bool
    {
        return $this->is_active && (bool) $this->is_admin;
    }

    public function canAddContactTimelineComments(): bool
    {
        return $this->canViewWorkspaces();
    }

    public function canDeleteContacts(): bool
    {
        return $this->hasRolePermission('contacts.delete');
    }

    public function canManageContactProfile(): bool
    {
        return $this->hasRolePermission('contacts.edit');
    }

    public function canManageContactOwnership(): bool
    {
        return $this->hasRolePermission('contacts.edit');
    }

    public function canEditExistingContactPhones(): bool
    {
        return $this->hasRolePermission('contacts.edit');
    }

    public function canDeleteExistingContactPhones(): bool
    {
        return $this->hasRolePermission('contacts.delete');
    }

    public function canReplyInDialogs(): bool
    {
        return $this->hasRolePermission('dialogs.edit');
    }

    public function canBeAssignedToContacts(): bool
    {
        return $this->canViewWorkspaces();
    }

    public function hasRolePermission(string $permissionKey): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return $this->rolePermissions()[$permissionKey] ?? false;
    }

    /**
     * @return array<string, bool>
     */
    protected function rolePermissions(): array
    {
        if ($this->cachedRolePermissions !== null) {
            return $this->cachedRolePermissions;
        }

        $this->cachedRolePermissions = DB::table('role_permissions')
            ->where('role', $this->resolvedRole())
            ->pluck('granted', 'permission_key')
            ->mapWithKeys(static fn (mixed $granted, mixed $permissionKey): array => [
                (string) $permissionKey => (bool) $granted,
            ])
            ->all();

        return $this->cachedRolePermissions;
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
