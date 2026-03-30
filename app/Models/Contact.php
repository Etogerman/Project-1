<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contact extends Model
{
    use HasFactory;

    public const DATA_COLLECTION_STATUS_ACTIVE = 'active';

    public const DATA_COLLECTION_STATUS_COMPLETED = 'completed';

    public const DATA_COLLECTION_FIELD_FIRST_NAME = 'first_name';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'age_years',
        'birth_date',
        'country',
        'city',
        'data_collection_status',
        'data_collection_current_field',
        'data_collection_started_at',
        'data_collection_completed_at',
        'is_auto_reply_enabled',
        'assigned_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'birth_date' => 'date',
        'data_collection_started_at' => 'datetime',
        'data_collection_completed_at' => 'datetime',
        'is_auto_reply_enabled' => 'boolean',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function primaryIdentity(): HasOne
    {
        return $this->hasOne(ContactIdentity::class)->oldestOfMany();
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(ContactPhoneNumber::class)
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    public function isAssigned(): bool
    {
        return filled($this->assigned_user_id);
    }

    public function isAutoReplyEnabled(): bool
    {
        return (bool) $this->is_auto_reply_enabled;
    }

    public function isAssignedTo(User $user): bool
    {
        return (int) $this->assigned_user_id === $user->id;
    }

    public function isInDataCollection(): bool
    {
        return $this->data_collection_status === self::DATA_COLLECTION_STATUS_ACTIVE
            && filled($this->data_collection_current_field);
    }

    public function startDataCollection(string $field): void
    {
        $this->forceFill([
            'data_collection_status' => self::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => $field,
            'data_collection_started_at' => $this->data_collection_started_at ?? now(),
            'data_collection_completed_at' => null,
        ])->save();
    }

    public function completeDataCollection(): void
    {
        $this->forceFill([
            'data_collection_status' => self::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
            'data_collection_completed_at' => now(),
        ])->save();
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $operatorFullName = trim(implode(' ', array_filter([
                    $this->first_name,
                    $this->last_name,
                ], fn (mixed $value): bool => filled($value))));

                if ($operatorFullName !== '') {
                    return $operatorFullName;
                }

                if (filled($this->name)) {
                    return (string) $this->name;
                }

                $identity = $this->relationLoaded('primaryIdentity')
                    ? $this->primaryIdentity
                    : $this->primaryIdentity()->first();

                if (filled($identity?->external_username)) {
                    return '@'.ltrim((string) $identity->external_username, '@');
                }

                if (filled($identity?->external_user_id)) {
                    return (string) $identity->external_user_id;
                }

                return sprintf('Контакт #%d', $this->id);
            },
        );
    }

    protected function effectiveAgeYears(): Attribute
    {
        return Attribute::make(
            get: function (): ?int {
                if ($this->birth_date instanceof \Illuminate\Support\Carbon) {
                    if ($this->birth_date->isFuture()) {
                        return null;
                    }

                    return $this->birth_date->age;
                }

                return $this->age_years !== null ? (int) $this->age_years : null;
            },
        );
    }
}
