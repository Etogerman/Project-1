<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bitrix24CallbackOwner extends Model
{
    use HasFactory;

    public const DEFAULT_LOCAL_OWNER_KEY = 'local-1';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bitrix24_profile_id',
        'owner_key',
        'display_name',
        'callback_base_url',
        'status',
        'last_seen_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function setCallbackBaseUrlAttribute(mixed $value): void
    {
        if (! is_scalar($value)) {
            $this->attributes['callback_base_url'] = '';

            return;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            $this->attributes['callback_base_url'] = '';

            return;
        }

        $this->attributes['callback_base_url'] = Bitrix24Profile::normalizeCallbackBaseUrl($trimmed) ?? $trimmed;
    }

    public function bitrix24Profile(): BelongsTo
    {
        return $this->belongsTo(Bitrix24Profile::class);
    }

    public function openLineRoutes(): HasMany
    {
        return $this->hasMany(Bitrix24OpenLineRoute::class, 'callback_owner_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function label(): string
    {
        $displayName = trim((string) $this->display_name);
        $ownerKey = trim((string) $this->owner_key);

        if ($displayName === '') {
            return $ownerKey;
        }

        if ($ownerKey === '' || $displayName === $ownerKey) {
            return $displayName;
        }

        return "{$displayName} ({$ownerKey})";
    }
}
