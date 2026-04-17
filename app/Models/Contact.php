<?php

namespace App\Models;

use App\Services\Contacts\ResolveContactDisplayNameAction;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contact extends Model
{
    use HasFactory;

    public const DUPLICATE_REVIEW_STATUS_NONE = 'none';

    public const DUPLICATE_REVIEW_STATUS_PENDING = 'pending';

    public const DUPLICATE_REVIEW_STATUS_RESOLVED = 'resolved';

    public const DATA_COLLECTION_STATUS_ACTIVE = 'active';

    public const DATA_COLLECTION_STATUS_COMPLETED = 'completed';

    public const DATA_COLLECTION_FIELD_FIRST_NAME = 'first_name';

    public const DATA_COLLECTION_FIELD_RESIDENCE_CITY = 'residence_city';

    public const DATA_COLLECTION_FIELD_COUNTRY = 'country';

    public const DATA_COLLECTION_FIELD_CITY = 'city';

    public const DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM = 'russian_region_confirm';

    public const DATA_COLLECTION_FIELD_AGE_RANGE = 'age_range';

    public const FIRST_NAME_SOURCE_AUTO = 'auto';

    public const FIRST_NAME_SOURCE_CONTACT_CONFIRMED = 'contact_confirmed';

    public const FIRST_NAME_SOURCE_MANUAL = 'manual';

    public const REGION_STATUS_RESOLVED = 'resolved';

    public const REGION_STATUS_CLARIFICATION_PENDING = 'clarification_pending';

    public const REGION_STATUS_AMBIGUOUS = 'ambiguous';

    public const REGION_STATUS_UNKNOWN = 'unknown';

    public const REGION_STATUS_OUT_OF_SCOPE = 'out_of_scope';

    public const REGION_SOURCE_AI = 'ai';

    public const REGION_SOURCE_CONFIRMED_BY_CONTACT = 'confirmed_by_contact';

    public const REGION_SOURCE_MANUAL = 'manual';

    public const DISTANCE_TO_MOSCOW_STATUS_RESOLVED = 'resolved';

    public const DISTANCE_TO_MOSCOW_STATUS_PENDING = 'pending';

    public const DISTANCE_TO_MOSCOW_STATUS_UNKNOWN = 'unknown';

    public const DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE = 'out_of_scope';

    public const DISTANCE_TO_MOSCOW_STATUS_FAILED = 'failed';

    public const BITRIX24_SYNC_STATUS_NOT_SYNCED = 'not_synced';

    public const BITRIX24_SYNC_STATUS_PENDING = 'pending';

    public const BITRIX24_SYNC_STATUS_SYNCED = 'synced';

    public const BITRIX24_SYNC_STATUS_FAILED = 'failed';

    public const BITRIX24_SYNC_STATUS_PENDING_REVIEW = 'pending_review';

    public const BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED = 'not_synced';

    public const BITRIX24_DEAL_SYNC_STATUS_PENDING = 'pending';

    public const BITRIX24_DEAL_SYNC_STATUS_SYNCED = 'synced';

    public const BITRIX24_DEAL_SYNC_STATUS_FAILED = 'failed';

    public const BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW = 'pending_review';

    public const BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED = 'not_synced';

    public const BITRIX24_HISTORY_SYNC_STATUS_PENDING = 'pending';

    public const BITRIX24_HISTORY_SYNC_STATUS_SYNCED = 'synced';

    public const BITRIX24_HISTORY_SYNC_STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'first_name_source',
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
        'distance_to_moscow_km',
        'distance_to_moscow_status',
        'distance_to_moscow_calculated_at',
        'pending_region_candidates',
        'data_collection_status',
        'data_collection_current_field',
        'data_collection_last_prompted_field',
        'data_collection_started_at',
        'data_collection_current_field_started_at',
        'data_collection_completed_at',
        'data_collection_attempts_count',
        'is_auto_reply_enabled',
        'assigned_user_id',
        'merged_into_contact_id',
        'merged_at',
        'merge_reason',
        'merge_trigger_phone',
        'duplicate_review_status',
        'bitrix24_contact_id',
        'bitrix24_sync_status',
        'bitrix24_last_synced_at',
        'bitrix24_linked_at',
        'bitrix24_sync_pending',
        'bitrix24_sync_fingerprint',
        'bitrix24_deal_id',
        'bitrix24_deal_sync_status',
        'bitrix24_deal_last_synced_at',
        'bitrix24_deal_linked_at',
        'bitrix24_deal_sync_pending',
        'bitrix24_history_sync_status',
        'bitrix24_history_last_synced_at',
        'bitrix24_history_sync_pending',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'birth_date' => 'date',
        'distance_to_moscow_km' => 'integer',
        'distance_to_moscow_calculated_at' => 'datetime',
        'data_collection_started_at' => 'datetime',
        'data_collection_current_field_started_at' => 'datetime',
        'data_collection_completed_at' => 'datetime',
        'data_collection_attempts_count' => 'integer',
        'pending_region_candidates' => 'array',
        'is_auto_reply_enabled' => 'boolean',
        'merged_at' => 'datetime',
        'bitrix24_last_synced_at' => 'datetime',
        'bitrix24_linked_at' => 'datetime',
        'bitrix24_sync_pending' => 'boolean',
        'bitrix24_deal_last_synced_at' => 'datetime',
        'bitrix24_deal_linked_at' => 'datetime',
        'bitrix24_deal_sync_pending' => 'boolean',
        'bitrix24_history_last_synced_at' => 'datetime',
        'bitrix24_history_sync_pending' => 'boolean',
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
     * @return list<string>
     */
    public static function allowedFirstNameSources(): array
    {
        return [
            self::FIRST_NAME_SOURCE_AUTO,
            self::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            self::FIRST_NAME_SOURCE_MANUAL,
        ];
    }

    /**
     * @return array<string, array{label:string,tone:string}>
     */
    public static function firstNameSourceBadgeOptions(): array
    {
        return [
            self::FIRST_NAME_SOURCE_AUTO => [
                'label' => 'Авто',
                'tone' => 'gray',
            ],
            self::FIRST_NAME_SOURCE_CONTACT_CONFIRMED => [
                'label' => 'Клиент назвал',
                'tone' => 'info',
            ],
            self::FIRST_NAME_SOURCE_MANUAL => [
                'label' => 'Оператор',
                'tone' => 'success',
            ],
        ];
    }

    public static function formatFirstNameSourceBadgeLabel(?string $value): ?string
    {
        return self::firstNameSourceBadgeOptions()[$value]['label'] ?? null;
    }

    public static function firstNameSourceBadgeTone(?string $value): ?string
    {
        return self::firstNameSourceBadgeOptions()[$value]['tone'] ?? null;
    }

    public static function formatFirstNameSourceTimelineLabel(?string $value): ?string
    {
        return match ($value) {
            self::FIRST_NAME_SOURCE_AUTO => 'Авто (из мессенджера)',
            self::FIRST_NAME_SOURCE_CONTACT_CONFIRMED => 'Клиент назвал',
            self::FIRST_NAME_SOURCE_MANUAL => 'Оператор',
            default => null,
        };
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

    /**
     * @return array<string, string>
     */
    public static function distanceToMoscowStatusOptions(): array
    {
        return [
            self::DISTANCE_TO_MOSCOW_STATUS_RESOLVED => 'Рассчитано',
            self::DISTANCE_TO_MOSCOW_STATUS_PENDING => 'Ожидает расчёта',
            self::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN => 'Не удалось определить',
            self::DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE => 'Не Россия',
            self::DISTANCE_TO_MOSCOW_STATUS_FAILED => 'Ошибка расчёта',
        ];
    }

    public static function formatDistanceToMoscowStatus(?string $value): string
    {
        if (! filled($value)) {
            return '—';
        }

        return self::distanceToMoscowStatusOptions()[$value] ?? (string) $value;
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_contact_id');
    }

    public function mergedChildren(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_contact_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function primaryIdentity(): HasOne
    {
        return $this->hasOne(ContactIdentity::class)->oldestOfMany();
    }

    public function latestConversationMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'latest_message_id');
    }

    public function latestInboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'latest_inbound_message_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function dialogs(): HasMany
    {
        return $this->hasMany(Dialog::class);
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(ContactTimelineEvent::class)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(ContactPhoneNumber::class)
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    public function duplicateReviews(): HasMany
    {
        return $this->hasMany(ContactDuplicateReview::class);
    }

    public function openDuplicateReviews(): HasMany
    {
        return $this->duplicateReviews()
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function recentMergedChildren(): HasMany
    {
        return $this->mergedChildren()
            ->orderByDesc('merged_at')
            ->orderByDesc('id');
    }

    public function startTags(): HasMany
    {
        return $this->hasMany(ContactStartTag::class)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');
    }
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withPivot([
                'assigned_at',
                'assigned_by_user_id',
            ])
            ->withTimestamps()
            ->orderBy('name');
    }

    public function mergeLogsAsPrimary(): HasMany
    {
        return $this->hasMany(ContactMergeLog::class, 'primary_contact_id');
    }

    public function mergeLogAsSecondary(): HasOne
    {
        return $this->hasOne(ContactMergeLog::class, 'secondary_contact_id');
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

    public function isMerged(): bool
    {
        return filled($this->merged_into_contact_id);
    }

    public function isRoot(): bool
    {
        return ! $this->isMerged();
    }

    public function isInDataCollection(): bool
    {
        return $this->data_collection_status === self::DATA_COLLECTION_STATUS_ACTIVE
            && filled($this->data_collection_current_field);
    }

    public function isBitrix24Linked(): bool
    {
        return filled($this->bitrix24_contact_id);
    }

    public function isBitrix24SyncPending(): bool
    {
        return (bool) $this->bitrix24_sync_pending;
    }

    public function isBitrix24DealLinked(): bool
    {
        return filled($this->bitrix24_deal_id);
    }

    public function isBitrix24DealSyncPending(): bool
    {
        return (bool) $this->bitrix24_deal_sync_pending;
    }

    public function isBitrix24HistorySyncPending(): bool
    {
        return (bool) $this->bitrix24_history_sync_pending;
    }

    public function startDataCollection(string $field): void
    {
        $now = now();
        $isActiveSession = $this->data_collection_status === self::DATA_COLLECTION_STATUS_ACTIVE
            && filled($this->data_collection_current_field);

        $this->forceFill([
            'data_collection_status' => self::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => $field,
            'data_collection_last_prompted_field' => null,
            'data_collection_started_at' => $isActiveSession
                ? ($this->data_collection_started_at ?? $now)
                : $now,
            'data_collection_current_field_started_at' => $now,
            'data_collection_completed_at' => null,
            'data_collection_attempts_count' => 0,
        ])->save();
    }

    public function completeDataCollection(): void
    {
        $this->forceFill([
            'data_collection_status' => self::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
            'data_collection_last_prompted_field' => null,
            'data_collection_current_field_started_at' => null,
            'data_collection_completed_at' => now(),
            'data_collection_attempts_count' => 0,
        ])->save();
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => app(ResolveContactDisplayNameAction::class)->handle($this),
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
