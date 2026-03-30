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

    public const DATA_COLLECTION_FIELD_RESIDENCE_CITY = 'residence_city';

    public const DATA_COLLECTION_FIELD_COUNTRY = 'country';

    public const DATA_COLLECTION_FIELD_CITY = 'city';

    public const DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM = 'russian_region_confirm';

    public const DATA_COLLECTION_FIELD_AGE_RANGE = 'age_range';

    public const REGION_STATUS_RESOLVED = 'resolved';

    public const REGION_STATUS_CLARIFICATION_PENDING = 'clarification_pending';

    public const REGION_STATUS_AMBIGUOUS = 'ambiguous';

    public const REGION_STATUS_UNKNOWN = 'unknown';

    public const REGION_STATUS_OUT_OF_SCOPE = 'out_of_scope';

    public const REGION_SOURCE_AI = 'ai';

    public const REGION_SOURCE_CONFIRMED_BY_CONTACT = 'confirmed_by_contact';

    public const REGION_SOURCE_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'gender',
        'age_years',
        'age_range',
        'birth_date',
        'country',
        'city',
        'region',
        'region_status',
        'region_source',
        'pending_region_candidates',
        'data_collection_status',
        'data_collection_current_field',
        'data_collection_started_at',
        'data_collection_completed_at',
        'data_collection_attempts_count',
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
        'data_collection_attempts_count' => 'integer',
        'pending_region_candidates' => 'array',
        'is_auto_reply_enabled' => 'boolean',
    ];

    /**
     * @return array<string, string>
     */
    public static function ageRangeOptions(): array
    {
        return [
            'under_18' => 'До 18 лет',
            '18_23' => '18 - 23 года',
            '24_29' => '24 - 29 лет',
            '30_39' => '30 - 39 лет',
            'over_40' => 'Больше 40 лет',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function genderOptions(): array
    {
        return [
            'male' => 'Мужской',
            'female' => 'Женский',
            'unknown' => 'Непонятно',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function russianRegionOptions(): array
    {
        $regions = config('bots.data_collection.russian_region.allowed_regions', []);

        if (! is_array($regions)) {
            return [];
        }

        $options = [];

        foreach ($regions as $region) {
            if (! is_string($region)) {
                continue;
            }

            $trimmed = trim($region);

            if ($trimmed === '') {
                continue;
            }

            $options[$trimmed] = $trimmed;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function regionStatusOptions(): array
    {
        return [
            self::REGION_STATUS_RESOLVED => 'Определён',
            self::REGION_STATUS_CLARIFICATION_PENDING => 'Нужно уточнение',
            self::REGION_STATUS_AMBIGUOUS => 'Неоднозначно',
            self::REGION_STATUS_UNKNOWN => 'Не определён',
            self::REGION_STATUS_OUT_OF_SCOPE => 'Не Россия',
        ];
    }

    public static function formatGender(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        return self::genderOptions()[$value] ?? (string) $value;
    }

    public static function formatAgeRange(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        return self::ageRangeOptions()[$value] ?? (string) $value;
    }

    public static function formatRegionStatus(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        return self::regionStatusOptions()[$value] ?? (string) $value;
    }

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
            'data_collection_attempts_count' => 0,
        ])->save();
    }

    public function completeDataCollection(): void
    {
        $this->forceFill([
            'data_collection_status' => self::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
            'data_collection_completed_at' => now(),
            'data_collection_attempts_count' => 0,
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
