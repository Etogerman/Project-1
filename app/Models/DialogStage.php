<?php

namespace App\Models;

use App\Services\Colors\ColorRegistry;
use App\Support\Colors\AbColorPalette;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DialogStage extends Model
{
    use HasFactory;

    public const COLOR_GRAY = 'gray';

    public const COLOR_INFO = 'info';

    public const COLOR_PRIMARY = 'primary';

    public const COLOR_SUCCESS = 'success';

    public const COLOR_WARNING = 'warning';

    public const COLOR_DANGER = 'danger';

    public const KEY_NEW_DIALOG = 'new_dialog';

    public const KEY_PHONE_RECEIVED = 'phone_received';

    public const KEY_QUESTIONNAIRE_COMPLETED = 'questionnaire_completed';

    public const KEY_TRANSFERRED_TO_MPL = 'transferred_to_mpl';

    public const KEY_TRANSFERRED_TO_MPP = 'transferred_to_mpp';

    public const SYSTEM_ROLE_NEW_DIALOG = 'new_dialog';

    public const SYSTEM_ROLE_PHONE_RECEIVED = 'phone_received';

    public const SYSTEM_ROLE_QUESTIONNAIRE_COMPLETED = 'questionnaire_completed';

    public const BEHAVIOR_POLICY_STANDARD = 'standard';

    public const BEHAVIOR_POLICY_BLACKLIST = 'blacklist';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'color',
        'color_source',
        'color_value',
        'sort_order',
        'system_role',
        'is_seeded',
        'behavior_policy',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_seeded' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (DialogStage $stage): void {
            $stage->guardStableIdentity();
            $stage->guardName();
            $stage->guardKey();
            $stage->guardColor();
            $stage->guardSystemRole();
            $stage->guardBehaviorPolicy();
        });

        static::deleting(function (DialogStage $stage): void {
            if ($stage->isSystemDerivedStage()) {
                throw ValidationException::withMessages([
                    'stage' => 'Автоматическую системную стадию нельзя удалить.',
                ]);
            }

            if ($stage->dialogs()->exists()) {
                throw ValidationException::withMessages([
                    'stage' => 'Стадию с диалогами можно удалить только через перенос диалогов в другую стадию.',
                ]);
            }
        });
    }

    /**
     * @return array<string, array{name:string,color:string,sort_order:int,system_role:?string,is_seeded:bool,behavior_policy:string}>
     */
    public static function seededStages(): array
    {
        return [
            self::KEY_NEW_DIALOG => [
                'name' => 'Новый диалог',
                'color' => self::COLOR_GRAY,
                'sort_order' => 10,
                'system_role' => self::SYSTEM_ROLE_NEW_DIALOG,
                'is_seeded' => true,
                'behavior_policy' => self::BEHAVIOR_POLICY_STANDARD,
            ],
            self::KEY_PHONE_RECEIVED => [
                'name' => 'Телефон получен',
                'color' => self::COLOR_INFO,
                'sort_order' => 20,
                'system_role' => self::SYSTEM_ROLE_PHONE_RECEIVED,
                'is_seeded' => true,
                'behavior_policy' => self::BEHAVIOR_POLICY_STANDARD,
            ],
            self::KEY_QUESTIONNAIRE_COMPLETED => [
                'name' => 'Данные собраны',
                'color' => self::COLOR_SUCCESS,
                'sort_order' => 30,
                'system_role' => self::SYSTEM_ROLE_QUESTIONNAIRE_COMPLETED,
                'is_seeded' => true,
                'behavior_policy' => self::BEHAVIOR_POLICY_STANDARD,
            ],
            self::KEY_TRANSFERRED_TO_MPL => [
                'name' => 'МПЛ взял в работу',
                'color' => self::COLOR_WARNING,
                'sort_order' => 40,
                'system_role' => null,
                'is_seeded' => true,
                'behavior_policy' => self::BEHAVIOR_POLICY_STANDARD,
            ],
            self::KEY_TRANSFERRED_TO_MPP => [
                'name' => 'Передан в МПП',
                'color' => self::COLOR_PRIMARY,
                'sort_order' => 50,
                'system_role' => null,
                'is_seeded' => true,
                'behavior_policy' => self::BEHAVIOR_POLICY_STANDARD,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function colorOptions(): array
    {
        return [
            self::COLOR_GRAY => 'Серый',
            self::COLOR_INFO => 'Голубой',
            self::COLOR_PRIMARY => 'Синий',
            self::COLOR_SUCCESS => 'Зелёный',
            self::COLOR_WARNING => 'Жёлтый',
            self::COLOR_DANGER => 'Красный',
        ];
    }

    /**
     * @return array<string, array{background:string,soft:string,border:string}>
     */
    public static function colorPalette(): array
    {
        return [
            self::COLOR_GRAY => [
                'background' => '#64748b',
                'soft' => 'rgba(100, 116, 139, 0.16)',
                'border' => '#475569',
            ],
            self::COLOR_INFO => [
                'background' => '#0ea5e9',
                'soft' => 'rgba(14, 165, 233, 0.16)',
                'border' => '#0284c7',
            ],
            self::COLOR_PRIMARY => [
                'background' => '#2563eb',
                'soft' => 'rgba(37, 99, 235, 0.16)',
                'border' => '#1d4ed8',
            ],
            self::COLOR_SUCCESS => [
                'background' => '#16a34a',
                'soft' => 'rgba(22, 163, 74, 0.16)',
                'border' => '#15803d',
            ],
            self::COLOR_WARNING => [
                'background' => '#d97706',
                'soft' => 'rgba(217, 119, 6, 0.18)',
                'border' => '#b45309',
            ],
            self::COLOR_DANGER => [
                'background' => '#dc2626',
                'soft' => 'rgba(220, 38, 38, 0.16)',
                'border' => '#b91c1c',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function systemRoleOptions(): array
    {
        return [
            self::SYSTEM_ROLE_NEW_DIALOG => 'Новый диалог',
            self::SYSTEM_ROLE_PHONE_RECEIVED => 'Телефон получен',
            self::SYSTEM_ROLE_QUESTIONNAIRE_COMPLETED => 'Данные собраны',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function behaviorPolicyOptions(): array
    {
        return [
            self::BEHAVIOR_POLICY_STANDARD => 'Обычная',
            self::BEHAVIOR_POLICY_BLACKLIST => 'ЧС',
        ];
    }

    public function dialogs(): HasMany
    {
        return $this->hasMany(Dialog::class, 'stage_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isSystemDerivedStage(): bool
    {
        return $this->system_role !== null;
    }

    public function typeLabel(): string
    {
        return $this->isSystemDerivedStage() ? 'Автоматическая' : 'Ручная';
    }

    public function behaviorPolicyLabel(): string
    {
        return self::behaviorPolicyOptions()[$this->behavior_policy] ?? 'Обычная';
    }

    public function isBlacklistBehavior(): bool
    {
        return $this->behavior_policy === self::BEHAVIOR_POLICY_BLACKLIST;
    }

    protected function guardStableIdentity(): void
    {
        if (! $this->exists) {
            return;
        }

        if ($this->isDirty('key')) {
            throw ValidationException::withMessages([
                'key' => 'Код стадии нельзя менять после создания.',
            ]);
        }

        if ($this->isDirty('system_role')) {
            throw ValidationException::withMessages([
                'system_role' => 'Системную роль стадии нельзя менять после создания.',
            ]);
        }
    }

    protected function guardName(): void
    {
        $name = trim((string) $this->name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Нужно указать название стадии.',
            ]);
        }

        $this->name = $name;
    }

    protected function guardKey(): void
    {
        $key = trim((string) $this->key);

        if ($key === '') {
            $key = $this->generateUniqueKey($this->name);
        }

        $key = Str::lower($key);

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'key' => 'Код стадии должен начинаться с латинской буквы и содержать только латинские буквы, цифры и подчёркивания.',
            ]);
        }

        $exists = static::query()
            ->where('key', $key)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => 'Стадия с таким кодом уже существует.',
            ]);
        }

        $this->key = $key;
    }

    protected function guardColor(): void
    {
        $colorInput = is_string($this->color) ? trim($this->color) : null;

        $normalized = app(ColorRegistry::class)->normalizeForStorage(
            source: $this->isDirty('color') ? null : $this->color_source,
            value: $this->isDirty('color') ? $colorInput : $this->color_value,
            legacy: $colorInput,
        );

        $this->color_source = $normalized['color_source'];
        $this->color_value = $normalized['color_value'];
        $this->color = $normalized['legacy_color'];
    }

    protected function guardSystemRole(): void
    {
        $systemRole = trim((string) $this->system_role);
        $this->system_role = $systemRole !== '' ? $systemRole : null;

        if ($this->system_role !== null && ! array_key_exists($this->system_role, self::systemRoleOptions())) {
            throw ValidationException::withMessages([
                'system_role' => 'Нужно выбрать допустимую системную роль стадии.',
            ]);
        }
    }

    protected function guardBehaviorPolicy(): void
    {
        $behaviorPolicy = trim((string) $this->behavior_policy);
        $this->behavior_policy = $behaviorPolicy !== '' ? $behaviorPolicy : self::BEHAVIOR_POLICY_STANDARD;

        if (! array_key_exists($this->behavior_policy, self::behaviorPolicyOptions())) {
            throw ValidationException::withMessages([
                'behavior_policy' => 'Нужно выбрать допустимое поведение стадии.',
            ]);
        }
    }

    private function generateUniqueKey(string $name): string
    {
        $base = Str::slug($name, '_', 'ru');
        $base = preg_replace('/[^a-z0-9_]+/', '', Str::lower($base)) ?: 'stage';

        if (preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'stage_'.$base;
        }

        $base = Str::limit($base, 56, '');
        $candidate = $base;
        $suffix = 2;

        while (static::query()->where('key', $candidate)->exists()) {
            $candidate = Str::limit($base, 56, '').'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    public function getColorValueAttribute(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return is_string($this->color) && trim($this->color) !== ''
            ? $this->color
            : AbColorPalette::DEFAULT_PRESET_KEY;
    }
}
