<?php

namespace App\Filament\Resources\AutoReplyRules;

use App\Filament\Resources\AutoReplyRules\Pages\ManageAutoReplyRules;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Channel;
use App\Models\Tag;
use App\Services\Bots\SyncAutoReplyRuleTagConditionsAction;
use App\Services\Bots\SyncAutoReplyRuleTagEffectsAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class AutoReplyRuleResource extends Resource
{
    protected static ?string $model = AutoReplyRule::class;

    protected static ?string $recordTitleAttribute = 'keyword';

    protected static ?string $modelLabel = 'Правило автоответа';

    protected static ?string $pluralModelLabel = 'Правила автоответа';

    protected static ?string $navigationLabel = 'Правила автоответа';

    protected static string|UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['channel', 'tagEffects.tag', 'tagConditions.tag']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Правило автоответа')
                    ->description('Канал, условия срабатывания и содержимое ответа.')
                    ->extraAttributes(['class' => 'ac-auto-reply-form-section'])
                    ->schema([
                        Select::make('channel_id')
                            ->label('Канал')
                            ->options(static::getChannelOptions())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->native(false),
                        Select::make('match_scope')
                            ->label('Область срабатывания')
                            ->options(AutoReplyRule::matchScopeOptions())
                            ->default(AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD)
                            ->required()
                            ->live()
                            ->native(false),
                        TextInput::make('keyword')
                            ->label(fn (Get $get): string => static::keywordFieldLabel($get('match_scope')))
                            ->required(fn (Get $get): bool => static::usesKeywordScope($get('match_scope')))
                            ->hidden(fn (Get $get): bool => ! static::usesKeywordScope($get('match_scope')))
                            ->maxLength(255),
                        Select::make('contact_phone_condition')
                            ->label('Условие по телефону')
                            ->options(AutoReplyRule::phoneConditionOptions())
                            ->placeholder('Неважно')
                            ->helperText('Правило сработает только для контактов, соответствующих условию.')
                            ->native(false),
                        Textarea::make('reply_text')
                            ->label('Текст ответа')
                            ->required()
                            ->rows(6)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Select::make('telegram_button_type')
                            ->label('Кнопка')
                            ->options(AutoReplyRule::telegramButtonTypeOptions())
                            ->placeholder('Без кнопки')
                            ->native(false)
                            ->helperText('Доступно только для Telegram-каналов.')
                            ->hidden(fn (Get $get): bool => ! static::channelSupportsTelegram((int) $get('channel_id'))),
                        Select::make('max_button_type')
                            ->label('Кнопка')
                            ->options(AutoReplyRule::maxButtonTypeOptions())
                            ->placeholder('Без кнопки')
                            ->native(false)
                            ->helperText('Доступно только для MAX-каналов.')
                            ->hidden(fn (Get $get): bool => ! static::channelSupportsMax((int) $get('channel_id'))),
                        Toggle::make('is_active')
                            ->label('Активно')
                            ->default(true)
                            ->inline(false)
                            ->extraAttributes(['class' => 'ac-auto-reply-form-toggle']),
                    ])
                    ->columns(2),
                Section::make('Теги')
                    ->description('Эффекты применяются только после успешной отправки автоответа.')
                    ->extraAttributes(['class' => 'ac-auto-reply-form-section'])
                    ->schema([
                        Select::make('assign_tag_ids')
                            ->label('Назначить теги')
                            ->options(static::getTagOptions())
                            ->multiple()
                            ->native(false)
                            ->afterStateHydrated(function (Select $component, ?AutoReplyRule $record): void {
                                $record?->loadMissing('tagEffects');

                                $component->state(
                                    $record?->tagEffects
                                        ->where('effect', AutoReplyRuleTagEffect::EFFECT_ASSIGN)
                                        ->pluck('tag_id')
                                        ->map(fn (mixed $tagId): int => (int) $tagId)
                                        ->all() ?? [],
                                );
                            })
                            ->helperText('Эти теги будут назначены контакту только после успешной отправки автоответа.'),
                        Select::make('remove_tag_ids')
                            ->label('Снять теги')
                            ->options(static::getTagOptions())
                            ->multiple()
                            ->native(false)
                            ->afterStateHydrated(function (Select $component, ?AutoReplyRule $record): void {
                                $record?->loadMissing('tagEffects');

                                $component->state(
                                    $record?->tagEffects
                                        ->where('effect', AutoReplyRuleTagEffect::EFFECT_REMOVE)
                                        ->pluck('tag_id')
                                        ->map(fn (mixed $tagId): int => (int) $tagId)
                                        ->all() ?? [],
                                );
                            })
                            ->helperText('Эти теги будут сняты только после успешной отправки автоответа.'),
                    ])
                    ->columns(2),
                Section::make('Условия по тегам')
                    ->schema([
                        Select::make('required_tag_ids')
                            ->label('Обязательные теги')
                            ->options(static::getTagOptions())
                            ->multiple()
                            ->native(false)
                            ->afterStateHydrated(function (Select $component, ?AutoReplyRule $record): void {
                                $record?->loadMissing('tagConditions');

                                $component->state(
                                    $record?->tagConditions
                                        ->where('condition', AutoReplyRuleTagCondition::CONDITION_REQUIRED)
                                        ->pluck('tag_id')
                                        ->map(fn (mixed $tagId): int => (int) $tagId)
                                        ->all() ?? [],
                                );
                            })
                            ->helperText('Правило сработает только если у контакта есть все выбранные теги.'),
                        Select::make('excluded_tag_ids')
                            ->label('Исключающие теги')
                            ->options(static::getTagOptions())
                            ->multiple()
                            ->native(false)
                            ->afterStateHydrated(function (Select $component, ?AutoReplyRule $record): void {
                                $record?->loadMissing('tagConditions');

                                $component->state(
                                    $record?->tagConditions
                                        ->where('condition', AutoReplyRuleTagCondition::CONDITION_EXCLUDED)
                                        ->pluck('tag_id')
                                        ->map(fn (mixed $tagId): int => (int) $tagId)
                                        ->all() ?? [],
                                );
                            })
                            ->helperText('Правило не сработает, если у контакта есть хотя бы один из этих тегов.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('channel_display')
                    ->label('Канал')
                    ->state(fn (AutoReplyRule $record): string => static::formatChannelLabel($record->channel)),
                TextColumn::make('keyword')
                    ->label('Условие')
                    ->state(fn (AutoReplyRule $record): string => static::formatRuleCondition($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('match_scope')
                    ->label('Область')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state): string => AutoReplyRule::matchScopeOptions()[$state ?? AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD] ?? '—'),
                TextColumn::make('contact_phone_condition')
                    ->label('Условие по телефону')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('Неважно')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? (AutoReplyRule::phoneConditionOptions()[$state] ?? $state)
                        : 'Неважно'),
                TextColumn::make('reply_text')
                    ->label('Текст ответа')
                    ->limit(60)
                    ->wrap()
                    ->tooltip(fn (AutoReplyRule $record): string => (string) $record->reply_text),
                TextColumn::make('button_type')
                    ->label('Кнопка')
                    ->placeholder('—')
                    ->state(fn (AutoReplyRule $record): ?string => $record->telegram_button_type ?? $record->max_button_type)
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? (AutoReplyRule::telegramButtonTypeOptions()[$state]
                            ?? AutoReplyRule::maxButtonTypeOptions()[$state]
                            ?? $state)
                        : '—'),
                TextColumn::make('tag_effects_summary')
                    ->label('Теги')
                    ->state(fn (AutoReplyRule $record): string => static::formatTagEffectsSummary($record))
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn (AutoReplyRule $record): string => static::formatTagEffectsSummary($record)),
                TextColumn::make('is_active')
                    ->label('Активно')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel_id')
                    ->label('Канал')
                    ->options(static::getChannelOptions()),
                TernaryFilter::make('is_active')
                    ->label('Статус')
                    ->placeholder('Все')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только отключённые'),
            ])
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Фильтры')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerWidth(Width::Medium)
            ->columnManagerTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Столбцы')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Правила автоответа ещё не добавлены')
            ->emptyStateDescription('Создайте первое правило для точного совпадения текста.')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить правило')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->extraModalWindowAttributes(['class' => 'ac-auto-reply-form-modal'])
                    ->using(function (array $data, AutoReplyRule $record): AutoReplyRule {
                        return static::saveAutoReplyRule($data, $record);
                    }),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->tooltip('Удалить правило'),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAutoReplyRules::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateAutoReplyRuleData(array $data, ?AutoReplyRule $record = null): array
    {
        $data['match_scope'] = filled($data['match_scope'] ?? null)
            ? trim((string) $data['match_scope'])
            : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;
        $data['contact_phone_condition'] = filled($data['contact_phone_condition'] ?? null)
            ? trim((string) $data['contact_phone_condition'])
            : null;

        $data['keyword'] = static::usesKeywordScope($data['match_scope'] ?? null) && filled($data['keyword'] ?? null)
            ? trim((string) $data['keyword'])
            : null;
        $data['telegram_button_type'] = filled($data['telegram_button_type'] ?? null)
            ? trim((string) $data['telegram_button_type'])
            : null;
        $data['max_button_type'] = filled($data['max_button_type'] ?? null)
            ? trim((string) $data['max_button_type'])
            : null;
        $data['normalized_keyword'] = static::usesKeywordScope($data['match_scope'] ?? null)
            ? AutoReplyRule::normalizeKeyword($data['keyword'] ?? null)
            : null;

        static::guardAgainstConflictingRule($data, $record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveAutoReplyRule(array $data, ?AutoReplyRule $record = null): AutoReplyRule
    {
        $tagEffects = static::extractTagEffectIds($data);
        $tagConditions = static::extractTagConditionIds($data);
        $ruleData = static::mutateAutoReplyRuleData(
            Arr::except($data, ['assign_tag_ids', 'remove_tag_ids', 'required_tag_ids', 'excluded_tag_ids']),
            $record,
        );

        /** @var AutoReplyRule $rule */
        $rule = DB::transaction(function () use ($record, $ruleData, $tagEffects, $tagConditions): AutoReplyRule {
            if ($record instanceof AutoReplyRule) {
                $record->update($ruleData);
                $rule = $record;
            } else {
                $rule = AutoReplyRule::query()->create($ruleData);
            }

            app(SyncAutoReplyRuleTagEffectsAction::class)->handle(
                $rule,
                $tagEffects['assignTagIds'],
                $tagEffects['removeTagIds'],
            );
            app(SyncAutoReplyRuleTagConditionsAction::class)->handle(
                $rule,
                $tagConditions['requiredTagIds'],
                $tagConditions['excludedTagIds'],
            );

            return $rule;
        });

        return $rule->fresh(['channel', 'tagEffects.tag', 'tagConditions.tag']) ?? $rule;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function guardAgainstConflictingRule(array $data, ?AutoReplyRule $record = null): void
    {
        if (($data['match_scope'] ?? AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD) === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            static::guardAgainstDuplicateAnyInboundRule($data, $record);

            return;
        }

        static::guardAgainstDuplicateNormalizedKeyword($data, $record);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function guardAgainstDuplicateNormalizedKeyword(array $data, ?AutoReplyRule $record = null): void
    {
        $channelId = (int) ($data['channel_id'] ?? 0);
        $normalizedKeyword = AutoReplyRule::normalizeKeyword($data['keyword'] ?? null);

        if ($channelId <= 0 || ! filled($normalizedKeyword)) {
            return;
        }

        $matchScope = filled($data['match_scope'] ?? null)
            ? trim((string) $data['match_scope'])
            : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;

        $exists = AutoReplyRule::query()
            ->where('channel_id', $channelId)
            ->where('match_scope', $matchScope)
            ->where('normalized_keyword', $normalizedKeyword)
            ->when($record instanceof AutoReplyRule, fn ($query) => $query->whereKeyNot($record->id))
            ->exists();

        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            'keyword' => 'Для этого канала правило с таким условием уже существует.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function guardAgainstDuplicateAnyInboundRule(array $data, ?AutoReplyRule $record = null): void
    {
        $channelId = (int) ($data['channel_id'] ?? 0);
        $contactPhoneCondition = filled($data['contact_phone_condition'] ?? null)
            ? trim((string) $data['contact_phone_condition'])
            : null;

        if ($channelId <= 0) {
            return;
        }

        $exists = AutoReplyRule::query()
            ->where('channel_id', $channelId)
            ->where('match_scope', AutoReplyRule::MATCH_SCOPE_ANY_INBOUND)
            ->where(fn ($query) => filled($contactPhoneCondition)
                ? $query->where('contact_phone_condition', $contactPhoneCondition)
                : $query->whereNull('contact_phone_condition'))
            ->when($record instanceof AutoReplyRule, fn ($query) => $query->whereKeyNot($record->id))
            ->exists();

        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            'match_scope' => 'Для этого канала правило на любое входящее с таким условием уже существует.',
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function getChannelOptions(): array
    {
        return Channel::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Channel $channel): array => [$channel->id => static::formatChannelLabel($channel)])
            ->all();
    }

    protected static function formatChannelLabel(?Channel $channel): string
    {
        if (! $channel instanceof Channel) {
            return '—';
        }

        $platform = Channel::platformOptions()[$channel->platform] ?? $channel->platform;

        return sprintf('%s (%s)', $channel->name, $platform);
    }

    protected static function channelSupportsTelegram(int $channelId): bool
    {
        if ($channelId <= 0) {
            return false;
        }

        return Channel::query()
            ->whereKey($channelId)
            ->where('platform', Channel::PLATFORM_TELEGRAM)
            ->exists();
    }

    protected static function channelSupportsMax(int $channelId): bool
    {
        if ($channelId <= 0) {
            return false;
        }

        return Channel::query()
            ->whereKey($channelId)
            ->where('platform', Channel::PLATFORM_MAX)
            ->exists();
    }

    protected static function usesExactKeywordScope(?string $matchScope): bool
    {
        return (($matchScope !== null && $matchScope !== '')
            ? $matchScope
            : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD) === AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;
    }

    protected static function usesKeywordScope(?string $matchScope): bool
    {
        return (($matchScope !== null && $matchScope !== '')
            ? $matchScope
            : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD) !== AutoReplyRule::MATCH_SCOPE_ANY_INBOUND;
    }

    protected static function keywordFieldLabel(?string $matchScope): string
    {
        return match (($matchScope !== null && $matchScope !== '')
            ? $matchScope
            : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD) {
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER => 'Параметр для срабатывания',
            default => 'Текст для срабатывания',
        };
    }

    protected static function formatRuleCondition(AutoReplyRule $record): string
    {
        if ($record->usesAnyInboundScope()) {
            return 'Любое входящее';
        }

        if ($record->usesExactParameterScope()) {
            return sprintf('Параметр: %s', (string) $record->keyword);
        }

        if ($record->usesContainsTextScope()) {
            return sprintf('Содержит: %s', (string) $record->keyword);
        }

        return (string) $record->keyword;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{assignTagIds:list<int>, removeTagIds:list<int>}
     */
    protected static function extractTagEffectIds(array $data): array
    {
        return [
            'assignTagIds' => static::normalizeTagIds($data['assign_tag_ids'] ?? []),
            'removeTagIds' => static::normalizeTagIds($data['remove_tag_ids'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{requiredTagIds:list<int>, excludedTagIds:list<int>}
     */
    protected static function extractTagConditionIds(array $data): array
    {
        return [
            'requiredTagIds' => static::normalizeTagIds($data['required_tag_ids'] ?? []),
            'excludedTagIds' => static::normalizeTagIds($data['excluded_tag_ids'] ?? []),
        ];
    }

    /**
     * @param  list<int|string>|mixed  $tagIds
     * @return list<int>
     */
    protected static function normalizeTagIds(mixed $tagIds): array
    {
        return collect(Arr::wrap($tagIds))
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->filter(fn (int $tagId): bool => $tagId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function getTagOptions(): array
    {
        return Tag::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [
                $tag->id => $tag->name,
            ])
            ->all();
    }

    protected static function formatTagEffectsSummary(AutoReplyRule $record): string
    {
        $record->loadMissing('tagEffects.tag');

        $assignTags = $record->tagEffects
            ->where('effect', AutoReplyRuleTagEffect::EFFECT_ASSIGN)
            ->map(fn (AutoReplyRuleTagEffect $effect): string => $effect->tag?->name ?? ('#'.$effect->tag_id))
            ->sort()
            ->values()
            ->all();
        $removeTags = $record->tagEffects
            ->where('effect', AutoReplyRuleTagEffect::EFFECT_REMOVE)
            ->map(fn (AutoReplyRuleTagEffect $effect): string => $effect->tag?->name ?? ('#'.$effect->tag_id))
            ->sort()
            ->values()
            ->all();

        $parts = [];

        if ($assignTags !== []) {
            $parts[] = 'Назначить: '.implode(', ', $assignTags);
        }

        if ($removeTags !== []) {
            $parts[] = 'Снять: '.implode(', ', $removeTags);
        }

        return $parts === [] ? '—' : implode(' | ', $parts);
    }
}
