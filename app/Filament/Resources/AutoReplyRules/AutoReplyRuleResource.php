<?php

namespace App\Filament\Resources\AutoReplyRules;

use App\Filament\Resources\AutoReplyRules\Pages\ManageAutoReplyRules;
use App\Models\AutoReplyCategory;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Channel;
use App\Models\Tag;
use App\Services\Bots\SyncAutoReplyRuleTagConditionsAction;
use App\Services\Bots\SyncAutoReplyRuleTagEffectsAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class AutoReplyRuleResource extends Resource
{
    protected const BUTTON_KIND_REQUEST_PHONE = 'request_phone';

    protected const BUTTON_KIND_LINK = 'link';

    protected const CATEGORY_FILTER_WITHOUT = '__without_category__';

    protected static ?string $model = AutoReplyRule::class;

    protected static ?string $recordTitleAttribute = 'keyword';

    protected static ?string $modelLabel = 'Старое правило автоответа';

    protected static ?string $pluralModelLabel = 'Архив старых автоответов';

    protected static ?string $navigationLabel = 'Архив автоответов';

    protected static string|UnitEnum|null $navigationGroup = 'Автоматизация';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $relations = ['channel', 'channels', 'tagEffects.tag', 'tagConditions.tag'];

        if (static::hasAutoReplyCategorySchema()) {
            $relations[] = 'category';
        }

        return parent::getEloquentQuery()->with($relations);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(1)
                    ->schema([
                        Checkbox::make('is_active')
                            ->label('Автоответ активен')
                            ->default(true)
                            ->live()
                            ->extraAttributes(['class' => 'ac-auto-reply-status-inline ac-auto-reply-status-inline--simple']),
                        TextInput::make('name')
                            ->label('Название')
                            ->placeholder('Например: Старт Telegram')
                            ->helperText('Если оставить пустым, будет отображаться как «Автоответ #ID».')
                            ->maxLength(255),
                        Select::make('auto_reply_category_id')
                            ->label('Категория')
                            ->options(static::getAutoReplyCategoryOptions())
                            ->placeholder('Без категории')
                            ->searchable()
                            ->preload()
                            ->noOptionsMessage('Категории не найдены')
                            ->visible(static::hasAutoReplyCategorySchema())
                            ->native(false),
                    ])
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'xl' => 2])
                    ->extraAttributes(['class' => 'ac-auto-reply-sections-grid'])
                    ->schema([
                        Section::make('Триггеры и условия')
                            ->extraAttributes(['class' => 'ac-auto-reply-form-section ac-auto-reply-form-section--flat ac-auto-reply-form-section--minimal ac-auto-reply-form-section--triggers'])
                            ->schema([
                                Select::make('channel_ids')
                                    ->label('Каналы')
                                    ->multiple()
                                    ->options(static::getChannelOptions())
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateHydrated(function (Select $component, ?AutoReplyRule $record): void {
                                        if (! $record instanceof AutoReplyRule) {
                                            return;
                                        }

                                        $record->loadMissing('channels');

                                        $component->state(
                                            $record->channels
                                                ->pluck('id')
                                                ->map(fn (mixed $channelId): int => (int) $channelId)
                                                ->all(),
                                        );
                                    })
                                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                        $availableButtonKinds = array_keys(
                                            static::getButtonKindOptions(static::normalizeChannelIds($state)),
                                        );
                                        $currentButtonKind = filled($get('button_kind') ?? null)
                                            ? trim((string) $get('button_kind'))
                                            : null;

                                        if ($currentButtonKind !== null && ! in_array($currentButtonKind, $availableButtonKinds, true)) {
                                            $set('button_kind', null);
                                            $set('button_text', null);
                                            $set('button_url', null);
                                        }
                                    })
                                    ->native(false),
                                Select::make('match_scope')
                                    ->label('Область срабатывания')
                                    ->options(AutoReplyRule::matchScopeOptions())
                                    ->default(AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD)
                                    ->required()
                                    ->live()
                                    ->native(false),
                                TextInput::make('keyword')
                                    ->label('Текст для срабатывания')
                                    ->required(fn (Get $get): bool => static::usesKeywordScope($get('match_scope')))
                                    ->hidden(fn (Get $get): bool => ! static::usesKeywordScope($get('match_scope')))
                                    ->maxLength(255),
                                Select::make('contact_phone_condition')
                                    ->label('Условие по телефону')
                                    ->options(AutoReplyRule::phoneConditionOptions())
                                    ->placeholder('Неважно')
                                    ->native(false),
                                TextInput::make('priority')
                                    ->label('Приоритет')
                                    ->numeric()
                                    ->default(10)
                                    ->required()
                                    ->helperText('Меньшее число выполняется раньше. При равном приоритете раньше выполняется правило с меньшим ID.'),
                                Select::make('required_tag_ids')
                                    ->label('Обязательные теги')
                                    ->options(fn (?AutoReplyRule $record): array => static::getTagConditionOptions($record))
                                    ->multiple()
                                    ->allowHtml()
                                    ->noOptionsMessage('Все доступные теги уже выбраны')
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
                                    }),
                                Select::make('excluded_tag_ids')
                                    ->label('Исключающие теги')
                                    ->options(fn (?AutoReplyRule $record): array => static::getTagConditionOptions($record))
                                    ->multiple()
                                    ->allowHtml()
                                    ->noOptionsMessage('Все доступные теги уже выбраны')
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
                                    }),
                            ])
                            ->columns(1),
                        Section::make('Текст ответа')
                            ->extraAttributes(['class' => 'ac-auto-reply-form-section ac-auto-reply-form-section--flat ac-auto-reply-form-section--reply'])
                            ->schema([
                                Textarea::make('reply_text')
                                    ->hiddenLabel()
                                    ->required()
                                    ->rows(8)
                                    ->maxLength(2000)
                                    ->columnSpanFull(),
                                Select::make('button_kind')
                                    ->label('Кнопка')
                                    ->options(fn (Get $get): array => static::getButtonKindOptions(static::normalizeChannelIds($get('channel_ids'))))
                                    ->placeholder('Без кнопки')
                                    ->native(false)
                                    ->live()
                                    ->afterStateHydrated(function (Select $component, ?AutoReplyRule $record): void {
                                        if (! $record instanceof AutoReplyRule) {
                                            return;
                                        }

                                        $component->state(static::resolveSharedButtonStateFromRecord($record)['button_kind']);
                                    })
                                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                                        if (($state ?? null) !== static::BUTTON_KIND_LINK) {
                                            $set('button_text', null);
                                            $set('button_url', null);
                                        }
                                    }),
                                TextInput::make('button_text')
                                    ->label('Текст кнопки')
                                    ->hidden(fn (Get $get): bool => ($get('button_kind') ?? null) !== static::BUTTON_KIND_LINK)
                                    ->required(fn (Get $get): bool => ($get('button_kind') ?? null) === static::BUTTON_KIND_LINK)
                                    ->afterStateHydrated(function (TextInput $component, ?AutoReplyRule $record): void {
                                        if (! $record instanceof AutoReplyRule) {
                                            return;
                                        }

                                        $component->state(static::resolveSharedButtonStateFromRecord($record)['button_text']);
                                    }),
                                TextInput::make('button_url')
                                    ->label('Ссылка кнопки')
                                    ->url()
                                    ->hidden(fn (Get $get): bool => ($get('button_kind') ?? null) !== static::BUTTON_KIND_LINK)
                                    ->required(fn (Get $get): bool => ($get('button_kind') ?? null) === static::BUTTON_KIND_LINK)
                                    ->afterStateHydrated(function (TextInput $component, ?AutoReplyRule $record): void {
                                        if (! $record instanceof AutoReplyRule) {
                                            return;
                                        }

                                        $component->state(static::resolveSharedButtonStateFromRecord($record)['button_url']);
                                    }),
                            ])
                            ->columns(1),
                        Section::make('Дополнительные действия')
                            ->extraAttributes(['class' => 'ac-auto-reply-form-section ac-auto-reply-form-section--flat ac-auto-reply-form-section--minimal ac-auto-reply-form-section--effects'])
                            ->schema([
                                Select::make('assign_tag_ids')
                                    ->label('Назначить теги')
                                    ->options(fn (?AutoReplyRule $record): array => static::getTagEffectOptions($record))
                                    ->multiple()
                                    ->allowHtml()
                                    ->noOptionsMessage('Все доступные теги уже выбраны')
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
                                    }),
                                Select::make('remove_tag_ids')
                                    ->label('Снять теги')
                                    ->options(fn (?AutoReplyRule $record): array => static::getTagEffectOptions($record))
                                    ->multiple()
                                    ->allowHtml()
                                    ->noOptionsMessage('Все доступные теги уже выбраны')
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
                                    }),
                            ])
                            ->columns(1),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected static function buildRuleBuilderHeader(Get $get): HtmlString
    {
        $highlights = implode('', array_map(
            fn (string $item): string => '<span class="ac-auto-reply-summary-pill">'.e($item).'</span>',
            static::buildHeaderHighlights($get),
        ));

        return new HtmlString(
            '<div class="ac-auto-reply-hero">'
                .'<div class="ac-auto-reply-hero-eyebrow">Конструктор правила</div>'
                .'<div class="ac-auto-reply-hero-title">Изменить правило</div>'
                .'<p class="ac-auto-reply-hero-description">Сначала задайте условия срабатывания, затем текст ответа и действия, которые выполнятся после успешной отправки.</p>'
                .'<div class="ac-auto-reply-hero-summary">'
                    .'<div class="ac-auto-reply-hero-summary-label">Ключевые параметры</div>'
                    .'<div class="ac-auto-reply-hero-pills">'.$highlights.'</div>'
                .'</div>'
            .'</div>'
        );
    }

    protected static function buildTriggerSummary(Get $get): HtmlString
    {
        $lines = static::buildTriggerSummaryLines($get);

        $items = implode('', array_map(
            fn (string $line): string => '<li class="flex items-start gap-2"><span class="mt-1 h-1.5 w-1.5 rounded-full bg-gray-400"></span><span>'.e($line).'</span></li>',
            $lines,
        ));

        return new HtmlString(
            '<div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">'
                .'<div class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Логика срабатывания</div>'
                .'<ul class="space-y-2">'.$items.'</ul>'
            .'</div>'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function buildRulePreviewViewData(Get $get): array
    {
        return [
            'channelLabel' => static::resolveChannelSummary($get('channel_ids')),
            'isActive' => (bool) $get('is_active'),
            'summaryLines' => static::buildTriggerSummaryLines($get),
            'replyText' => trim((string) ($get('reply_text') ?? '')),
            'buttonLabel' => static::resolveButtonLabel($get),
            'assignTags' => static::resolveTagLabels(static::normalizeSelectedIds($get('assign_tag_ids'))),
            'removeTags' => static::resolveTagLabels(static::normalizeSelectedIds($get('remove_tag_ids'))),
        ];
    }

    protected static function buildHeaderSummaryLine(Get $get): string
    {
        $parts = array_filter([
            static::resolveChannelSummary($get('channel_ids')),
            static::resolveScopeSummary($get),
            static::resolvePhoneConditionSummary($get('contact_phone_condition')),
            (bool) $get('is_active') ? 'активно' : 'выключено',
        ], fn (?string $value): bool => filled($value));

        return implode(' · ', $parts);
    }

    /**
     * @return array<int, string>
     */
    protected static function buildHeaderHighlights(Get $get): array
    {
        return array_values(array_filter([
            static::resolveChannelSummary($get('channel_ids')) ?? 'Каналы не выбраны',
            'Триггер: '.static::resolveScopeSummary($get),
            'Телефон: '.static::resolvePhoneConditionSummary($get('contact_phone_condition')),
            (bool) $get('is_active') ? 'Активно' : 'Выключено',
        ], fn (?string $value): bool => filled($value)));
    }

    /**
     * @return array<int, string>
     */
    protected static function buildTriggerSummaryLines(Get $get): array
    {
        $lines = [];

        if (filled($channelLabel = static::resolveChannelSummary($get('channel_ids')))) {
            $lines[] = 'Каналы: '.$channelLabel;
        }

        $lines[] = static::resolveScopeLine($get);

        if (filled($phoneSummary = static::resolvePhoneConditionSummary($get('contact_phone_condition')))) {
            $lines[] = 'Условие по телефону: '.$phoneSummary;
        }

        $requiredTags = static::resolveTagLabels(static::normalizeSelectedIds($get('required_tag_ids')));

        if ($requiredTags !== []) {
            $lines[] = 'Обязательные теги: '.implode(', ', $requiredTags);
        }

        $excludedTags = static::resolveTagLabels(static::normalizeSelectedIds($get('excluded_tag_ids')));

        if ($excludedTags !== []) {
            $lines[] = 'Исключающие теги: '.implode(', ', $excludedTags);
        }

        return $lines;
    }

    protected static function resolveScopeSummary(Get $get): string
    {
        $matchScope = (string) ($get('match_scope') ?? '');

        if ($matchScope === '') {
            return 'условие не выбрано';
        }

        if (! static::usesKeywordScope($matchScope)) {
            return mb_strtolower(AutoReplyRule::matchScopeOptions()[$matchScope] ?? $matchScope);
        }

        $keyword = trim((string) ($get('keyword') ?? ''));

        if ($keyword === '') {
            return 'параметр не задан';
        }

        return $keyword;
    }

    protected static function resolveKeywordFieldLabel(mixed $matchScope): string
    {
        return static::usesKeywordScope($matchScope)
            ? 'Параметр для срабатывания'
            : 'Параметр';
    }

    protected static function resolveScopeLine(Get $get): string
    {
        $matchScope = (string) ($get('match_scope') ?? AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD);
        $scopeLabel = AutoReplyRule::matchScopeOptions()[$matchScope] ?? $matchScope;

        if (! static::usesKeywordScope($matchScope)) {
            return 'Срабатывание: '.$scopeLabel.'.';
        }

        $keyword = trim((string) ($get('keyword') ?? ''));

        if ($keyword === '') {
            return 'Срабатывание: '.$scopeLabel.', параметр пока не заполнен.';
        }

        return 'Срабатывание: '.$scopeLabel.' — '.$keyword.'.';
    }

    protected static function resolvePhoneConditionSummary(mixed $state): string
    {
        if (! filled($state)) {
            return 'без ограничения';
        }

        return mb_strtolower(AutoReplyRule::phoneConditionOptions()[(string) $state] ?? (string) $state);
    }

    protected static function resolveChannelLabel(mixed $channelId): ?string
    {
        $channelId = (int) $channelId;

        if ($channelId <= 0) {
            return null;
        }

        return static::getChannelOptions()[$channelId] ?? null;
    }

    protected static function resolveChannelSummary(mixed $channelIds): ?string
    {
        $labels = array_values(array_filter(array_map(
            fn (int $channelId): ?string => static::resolveChannelLabel($channelId),
            static::normalizeChannelIds($channelIds),
        )));

        if ($labels === []) {
            return null;
        }

        return implode(', ', $labels);
    }

    protected static function resolveButtonLabel(Get $get): ?string
    {
        $buttonKind = filled($get('button_kind') ?? null)
            ? trim((string) $get('button_kind'))
            : null;

        if (! filled($buttonKind)) {
            return null;
        }

        $label = static::resolveButtonKindLabel($buttonKind);

        if ($label === null) {
            return null;
        }

        if ($buttonKind === static::BUTTON_KIND_LINK && filled($get('button_text') ?? null)) {
            $label .= ': '.trim((string) $get('button_text'));
        }

        return $label;
    }

    /**
     * @param  array<int, int>  $tagIds
     * @return array<int, string>
     */
    protected static function resolveTagLabels(array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        $options = static::getTagOptions();

        return array_values(array_filter(array_map(
            fn (int $tagId): ?string => $options[$tagId] ?? null,
            $tagIds,
        )));
    }

    /**
     * @return array<int, int>
     */
    protected static function normalizeSelectedIds(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $value): int => (int) $value,
            $state,
        ), fn (int $value): bool => $value > 0));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('display_name')
                    ->label('Название')
                    ->state(fn (AutoReplyRule $record): string => $record->display_name)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('name', 'like', "%{$search}%");
                    })
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label('Категория')
                    ->placeholder('—')
                    ->visible(static::hasAutoReplyCategorySchema())
                    ->toggleable(),
                TextColumn::make('channels_display')
                    ->label('Каналы')
                    ->state(fn (AutoReplyRule $record): string => static::formatChannelsLabel($record))
                    ->toggleable(),
                TextColumn::make('priority')
                    ->label('Приоритет')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('keyword')
                    ->label('Условие')
                    ->state(fn (AutoReplyRule $record): string => static::formatRuleCondition($record))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
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
                    ->tooltip(fn (AutoReplyRule $record): string => (string) $record->reply_text)
                    ->toggleable(),
                TextColumn::make('button_type')
                    ->label('Кнопка')
                    ->placeholder('—')
                    ->state(fn (AutoReplyRule $record): string => static::formatButtonSummary($record))
                    ->toggleable(),
                TextColumn::make('tag_effects_summary')
                    ->label('Теги')
                    ->state(fn (AutoReplyRule $record): string => static::formatTagEffectsSummary($record))
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn (AutoReplyRule $record): string => static::formatTagEffectsSummary($record))
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->label('Активно')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('auto_reply_category_id')
                    ->label('Категория')
                    ->options(static::getAutoReplyCategoryFilterOptions())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! filled($value)) {
                            return $query;
                        }

                        if ($value === static::CATEGORY_FILTER_WITHOUT) {
                            return $query->whereNull('auto_reply_category_id');
                        }

                        return $query->where('auto_reply_category_id', (int) $value);
                    })
                    ->visible(static::hasAutoReplyCategorySchema())
                    ->native(false),
                SelectFilter::make('channel_id')
                    ->label('Канал')
                    ->options(static::getChannelOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $channelId = (int) ($data['value'] ?? 0);

                        if ($channelId <= 0) {
                            return $query;
                        }

                        return $query->whereHas('channels', function (Builder $channelQuery) use ($channelId): void {
                            $channelQuery->whereKey($channelId);
                        });
                    }),
                SelectFilter::make('tag')
                    ->label('Тег')
                    ->options(static::getTagFilterOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $tagId = (int) ($data['value'] ?? 0);

                        if ($tagId <= 0) {
                            return $query;
                        }

                        return $query->where(function (Builder $ruleQuery) use ($tagId): void {
                            $ruleQuery
                                ->whereHas('tagEffects', fn (Builder $effectsQuery): Builder => $effectsQuery->where('tag_id', $tagId))
                                ->orWhereHas('tagConditions', fn (Builder $conditionsQuery): Builder => $conditionsQuery->where('tag_id', $tagId));
                        });
                    }),
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
            ->emptyStateHeading('В архиве старых автоответов пока нет правил')
            ->emptyStateDescription('Действующие автоответы настраиваются во вкладке «Автоответчик» конструктора.')
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAutoReplyRules::route('/'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function getAutoReplyCategoryOptions(): array
    {
        if (! static::hasAutoReplyCategorySchema()) {
            return [];
        }

        return AutoReplyCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $label, mixed $value): array => [(int) $value => (string) $label])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    protected static function getAutoReplyCategoryFilterOptions(): array
    {
        return [
            static::CATEGORY_FILTER_WITHOUT => 'Без категории',
        ] + static::getAutoReplyCategoryOptions();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateAutoReplyRuleData(array $data, ?AutoReplyRule $record = null): array
    {
        $data['name'] = filled($data['name'] ?? null)
            ? trim((string) $data['name'])
            : null;
        $data['auto_reply_category_id'] = static::hasAutoReplyCategorySchema() && filled($data['auto_reply_category_id'] ?? null)
            ? (int) $data['auto_reply_category_id']
            : null;
        $data['channel_id'] = filled($data['channel_id'] ?? null)
            ? (int) $data['channel_id']
            : null;
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
        $data['priority'] = filled($data['priority'] ?? null)
            ? (int) $data['priority']
            : 10;
        $data['normalized_keyword'] = static::usesKeywordScope($data['match_scope'] ?? null)
            ? AutoReplyRule::normalizeKeyword($data['keyword'] ?? null)
            : null;

        $allowedKeys = [
            'name',
            'auto_reply_category_id',
            'channel_id',
            'keyword',
            'normalized_keyword',
            'match_scope',
            'contact_phone_condition',
            'reply_text',
            'telegram_button_type',
            'max_button_type',
            'is_active',
            'priority',
        ];

        return Arr::only($data, $allowedKeys);
    }

    protected static function hasAutoReplyCategorySchema(): bool
    {
        return SchemaFacade::hasTable('auto_reply_categories')
            && SchemaFacade::hasColumn('auto_reply_rules', 'auto_reply_category_id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveAutoReplyRule(array $data, ?AutoReplyRule $record = null): AutoReplyRule
    {
        $tagEffects = static::extractTagEffectIds($data);
        $tagConditions = static::extractTagConditionIds($data);
        $channelIds = static::normalizeChannelIds($data['channel_ids'] ?? []);
        $sharedButtonConfig = static::normalizeSharedButtonConfigForPersistence($data, $channelIds);
        $channelSettings = static::buildChannelSettingsFromSharedButtonConfig($channelIds, $sharedButtonConfig);
        $legacyBridgeData = static::buildLegacyBridgeData($channelIds, $sharedButtonConfig);
        $ruleData = static::mutateAutoReplyRuleData(
            array_replace(
                Arr::except($data, ['assign_tag_ids', 'remove_tag_ids', 'required_tag_ids', 'excluded_tag_ids', 'channel_ids', 'button_kind', 'button_text', 'button_url']),
                ['channel_ids' => $channelIds],
                $legacyBridgeData,
            ),
            $record,
        );

        /** @var AutoReplyRule $rule */
        $rule = DB::transaction(function () use ($record, $ruleData, $channelIds, $tagEffects, $tagConditions, $channelSettings): AutoReplyRule {
            static::lockRuleChannelsForUpdate($channelIds);
            static::validateUniqueRuleSignature($ruleData, $channelIds, $tagConditions, $record);

            if ($record instanceof AutoReplyRule) {
                $record->update($ruleData);
                $rule = $record;
            } else {
                $rule = AutoReplyRule::query()->create($ruleData);
            }

            static::syncRuleChannels($rule, $channelSettings);

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

        return $rule->fresh(['channel', 'channels', 'tagEffects.tag', 'tagConditions.tag']) ?? $rule;
    }

    /**
     * @param  list<int>  $channelIds
     */
    protected static function lockRuleChannelsForUpdate(array $channelIds): void
    {
        $lockIds = collect($channelIds)
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->filter(fn (int $channelId): bool => $channelId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($lockIds === []) {
            return;
        }

        Channel::query()
            ->whereKey($lockIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * @param  array<string, mixed>  $ruleData
     * @param  list<int>  $channelIds
     * @param  array{requiredTagIds:list<int>, excludedTagIds:list<int>}  $tagConditions
     */
    protected static function validateUniqueRuleSignature(
        array $ruleData,
        array $channelIds,
        array $tagConditions,
        ?AutoReplyRule $record = null,
    ): void {
        $matchScope = filled($ruleData['match_scope'] ?? null)
            ? (string) $ruleData['match_scope']
            : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;
        $normalizedKeyword = filled($ruleData['normalized_keyword'] ?? null)
            ? (string) $ruleData['normalized_keyword']
            : null;
        $contactPhoneCondition = filled($ruleData['contact_phone_condition'] ?? null)
            ? (string) $ruleData['contact_phone_condition']
            : null;

        if ($channelIds === [] || ! static::usesKeywordScope($matchScope) || $normalizedKeyword === null) {
            return;
        }

        $conflictingRules = AutoReplyRule::query()
            ->with('tagConditions')
            ->where('match_scope', $matchScope)
            ->where('normalized_keyword', $normalizedKeyword)
            ->whereHas('channels', function (Builder $query) use ($channelIds): void {
                $query->whereIn('channels.id', $channelIds);
            })
            ->when(
                $contactPhoneCondition !== null,
                fn (Builder $query): Builder => $query->where(function (Builder $phoneQuery) use ($contactPhoneCondition): void {
                    $phoneQuery->whereNull('contact_phone_condition')
                        ->orWhere('contact_phone_condition', $contactPhoneCondition);
                }),
            );

        if ($record instanceof AutoReplyRule && $record->exists) {
            $conflictingRules->whereKeyNot($record->getKey());
        }

        $hasConflict = $conflictingRules
            ->get()
            ->contains(fn (AutoReplyRule $existingRule): bool => static::tagConditionsCanOverlap(
                $tagConditions['requiredTagIds'],
                $tagConditions['excludedTagIds'],
                $existingRule,
            ));

        if (! $hasConflict) {
            return;
        }

        throw ValidationException::withMessages([
            'keyword' => 'В выбранных каналах уже есть правило с таким текстом, областью срабатывания и совместимыми условиями.',
        ]);
    }

    /**
     * @param  list<int>  $requiredTagIds
     * @param  list<int>  $excludedTagIds
     */
    protected static function tagConditionsCanOverlap(
        array $requiredTagIds,
        array $excludedTagIds,
        AutoReplyRule $existingRule,
    ): bool {
        $existingRule->loadMissing('tagConditions');

        $existingRequiredTagIds = $existingRule->tagConditions
            ->where('condition', AutoReplyRuleTagCondition::CONDITION_REQUIRED)
            ->pluck('tag_id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->all();
        $existingExcludedTagIds = $existingRule->tagConditions
            ->where('condition', AutoReplyRuleTagCondition::CONDITION_EXCLUDED)
            ->pluck('tag_id')
            ->map(fn (mixed $tagId): int => (int) $tagId)
            ->all();

        return array_intersect($requiredTagIds, $existingExcludedTagIds) === []
            && array_intersect($excludedTagIds, $existingRequiredTagIds) === [];
    }

    public static function notifyValidationFailure(ValidationException $exception): void
    {
        $message = collect($exception->errors())
            ->flatten()
            ->map(fn (mixed $value): string => is_string($value) ? trim($value) : '')
            ->first(fn (string $value): bool => $value !== '');

        Notification::make()
            ->title('Правило не сохранено')
            ->body($message !== null && $message !== ''
                ? $message
                : 'Проверьте данные формы и попробуйте ещё раз.')
            ->danger()
            ->send();
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

    protected static function formatChannelsLabel(AutoReplyRule $record): string
    {
        $record->loadMissing('channels');

        $labels = $record->channels
            ->map(fn (Channel $channel): string => static::formatChannelLabel($channel))
            ->all();

        return $labels === []
            ? '—'
            : implode(', ', $labels);
    }

    protected static function formatButtonSummary(AutoReplyRule $record): string
    {
        $record->loadMissing('channels');

        $parts = $record->channels
            ->map(function (Channel $channel) use ($record): ?string {
                $buttonType = $record->getButtonTypeForChannel($channel);

                if (! filled($buttonType)) {
                    return null;
                }

                $label = match ($buttonType) {
                    AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT => 'Запросить номер телефона',
                    AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD => 'Ссылка',
                    default => $buttonType,
                };

                if ($label === null) {
                    return null;
                }

                if ($buttonType === AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD && filled($record->getButtonTextForChannel($channel))) {
                    $label .= ': '.$record->getButtonTextForChannel($channel);
                }

                return sprintf('%s — %s', $channel->name, $label);
            })
            ->filter()
            ->values()
            ->all();

        return $parts === []
            ? '—'
            : implode('; ', $parts);
    }

    protected static function channelSupportsTelegram(int $channelId): bool
    {
        if ($channelId <= 0) {
            return false;
        }

        return Channel::query()
            ->whereKey($channelId)
            ->where('platform', Channel::PLATFORM_TELEGRAM)
            ->where('connection_type', Channel::CONNECTION_TYPE_BOT)
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
            ->where('connection_type', Channel::CONNECTION_TYPE_BOT)
            ->exists();
    }

    protected static function channelSupportsAutoReplyButtons(Channel $channel): bool
    {
        if (! $channel->isBotConnection()) {
            return false;
        }

        return in_array($channel->platform, [
            Channel::PLATFORM_TELEGRAM,
            Channel::PLATFORM_MAX,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    protected static function getButtonKindOptions(array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        $buttonChannels = Channel::query()
            ->whereIn('id', $channelIds)
            ->get()
            ->filter(fn (Channel $channel): bool => static::channelSupportsAutoReplyButtons($channel));

        if ($buttonChannels->isEmpty()) {
            return [];
        }

        $platforms = $buttonChannels
            ->pluck('platform')
            ->filter(fn (mixed $platform): bool => is_string($platform) && $platform !== '')
            ->unique()
            ->values()
            ->all();

        if ($platforms === []) {
            return [];
        }

        $options = [
            static::BUTTON_KIND_REQUEST_PHONE => 'Запросить номер телефона',
        ];

        $supportedLinkPlatforms = [
            Channel::PLATFORM_TELEGRAM,
            Channel::PLATFORM_MAX,
        ];

        if (array_diff($platforms, $supportedLinkPlatforms) === []) {
            $options[static::BUTTON_KIND_LINK] = 'Ссылка';
        }

        return $options;
    }

    protected static function resolveButtonKindLabel(?string $buttonKind): ?string
    {
        if (! filled($buttonKind)) {
            return null;
        }

        return match ($buttonKind) {
            static::BUTTON_KIND_REQUEST_PHONE => 'Запросить номер телефона',
            static::BUTTON_KIND_LINK => 'Ссылка',
            default => $buttonKind,
        };
    }

    /**
     * @return array<int, int>
     */
    protected static function normalizeChannelIds(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): int => (int) $value,
            $state,
        ), fn (int $value): bool => $value > 0)));
    }

    /**
     * @return array{button_kind:?string,button_text:?string,button_url:?string}
     */
    protected static function resolveSharedButtonStateFromRecord(AutoReplyRule $record): array
    {
        $record->loadMissing('channels');

        $firstChannel = $record->channels
            ->first(fn (Channel $channel): bool => filled($record->getButtonTypeForChannel($channel)))
            ?? $record->channels->first();

        if (! $firstChannel instanceof Channel) {
            return [
                'button_kind' => null,
                'button_text' => null,
                'button_url' => null,
            ];
        }

        $buttonType = $record->getButtonTypeForChannel($firstChannel);

        return [
            'button_kind' => match ($buttonType) {
                AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT => static::BUTTON_KIND_REQUEST_PHONE,
                AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD => static::BUTTON_KIND_LINK,
                default => null,
            },
            'button_text' => $buttonType === AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD
                ? $record->getButtonTextForChannel($firstChannel)
                : null,
            'button_url' => $buttonType === AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD
                ? $record->getButtonUrlForChannel($firstChannel)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $channelIds
     * @return array{button_kind:?string,button_text:?string,button_url:?string}
     */
    protected static function normalizeSharedButtonConfigForPersistence(array $data, array $channelIds): array
    {
        if ($channelIds === []) {
            throw ValidationException::withMessages([
                'channel_ids' => 'Выберите хотя бы один канал.',
            ]);
        }

        $buttonKind = filled($data['button_kind'] ?? null)
            ? trim((string) $data['button_kind'])
            : null;
        $buttonText = filled($data['button_text'] ?? null)
            ? trim((string) $data['button_text'])
            : null;
        $buttonUrl = filled($data['button_url'] ?? null)
            ? trim((string) $data['button_url'])
            : null;

        $channels = Channel::query()
            ->whereIn('id', $channelIds)
            ->get()
            ->keyBy('id');

        if ($channels->count() !== count($channelIds)) {
            throw ValidationException::withMessages([
                'channel_ids' => 'Один из выбранных каналов больше недоступен.',
            ]);
        }

        $availableButtonKinds = static::getButtonKindOptions($channelIds);

        if ($availableButtonKinds === []) {
            $buttonKind = null;
            $buttonText = null;
            $buttonUrl = null;
        }

        if ($buttonKind !== null && ! array_key_exists($buttonKind, $availableButtonKinds)) {
            throw ValidationException::withMessages([
                'button_kind' => 'Выбранная кнопка недоступна для текущего набора каналов.',
            ]);
        }

        if ($buttonKind !== static::BUTTON_KIND_LINK) {
            $buttonText = null;
            $buttonUrl = null;
        }

        if ($buttonKind === static::BUTTON_KIND_LINK) {
            if (! filled($buttonText) || ! filled($buttonUrl)) {
                throw ValidationException::withMessages([
                    'button_kind' => 'Для кнопки-ссылки заполните текст и ссылку.',
                ]);
            }

            if (filter_var($buttonUrl, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withMessages([
                    'button_url' => 'Для кнопки-ссылки укажите корректную ссылку.',
                ]);
            }
        }

        return [
            'button_kind' => $buttonKind,
            'button_text' => $buttonText,
            'button_url' => $buttonUrl,
        ];
    }

    /**
     * @param  array<int, int>  $channelIds
     * @param  array{button_kind:?string,button_text:?string,button_url:?string}  $sharedButtonConfig
     * @return array<int, array{channel_id:int,button_type:?string,button_text:?string,button_url:?string}>
     */
    protected static function buildChannelSettingsFromSharedButtonConfig(array $channelIds, array $sharedButtonConfig): array
    {
        $buttonType = match ($sharedButtonConfig['button_kind']) {
            static::BUTTON_KIND_REQUEST_PHONE => AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT,
            static::BUTTON_KIND_LINK => AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD,
            default => null,
        };

        $channels = Channel::query()
            ->whereIn('id', $channelIds)
            ->get()
            ->keyBy('id');

        return array_map(
            function (int $channelId) use ($buttonType, $channels, $sharedButtonConfig): array {
                $channel = $channels->get($channelId);
                $channelButtonType = $channel instanceof Channel && static::channelSupportsAutoReplyButtons($channel)
                    ? $buttonType
                    : null;

                return [
                    'channel_id' => $channelId,
                    'button_type' => $channelButtonType,
                    'button_text' => $channelButtonType === AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD
                        ? $sharedButtonConfig['button_text']
                        : null,
                    'button_url' => $channelButtonType === AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD
                        ? $sharedButtonConfig['button_url']
                        : null,
                ];
            },
            $channelIds,
        );
    }

    /**
     * @param  array<int, int>  $channelIds
     * @param  array{button_kind:?string,button_text:?string,button_url:?string}  $sharedButtonConfig
     * @return array<string, mixed>
     */
    protected static function buildLegacyBridgeData(array $channelIds, array $sharedButtonConfig): array
    {
        $primaryChannelId = $channelIds[0] ?? null;
        $telegramButtonType = null;
        $maxButtonType = null;

        if ($sharedButtonConfig['button_kind'] === static::BUTTON_KIND_REQUEST_PHONE) {
            $channels = Channel::query()
                ->whereIn('id', $channelIds)
                ->get()
                ->keyBy('id');

            $primaryChannel = collect($channelIds)
                ->map(fn (int $channelId): ?Channel => $channels->get($channelId))
                ->filter(fn (?Channel $channel): bool => $channel instanceof Channel)
                ->first(fn (Channel $channel): bool => $channel->platform === Channel::PLATFORM_TELEGRAM
                    && $channel->isBotConnection());

            if (! $primaryChannel instanceof Channel) {
                $primaryChannel = collect($channelIds)
                    ->map(fn (int $channelId): ?Channel => $channels->get($channelId))
                    ->filter(fn (?Channel $channel): bool => $channel instanceof Channel)
                    ->first(fn (Channel $channel): bool => $channel->platform === Channel::PLATFORM_MAX
                        && $channel->isBotConnection());
            }

            if ($primaryChannel instanceof Channel) {
                $primaryChannelId = (int) $primaryChannel->id;

                if ($primaryChannel->platform === Channel::PLATFORM_TELEGRAM) {
                    $telegramButtonType = AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE;
                } elseif ($primaryChannel->platform === Channel::PLATFORM_MAX) {
                    $maxButtonType = AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE;
                }
            }
        }

        return [
            'channel_id' => $primaryChannelId,
            'telegram_button_type' => $telegramButtonType,
            'max_button_type' => $maxButtonType,
        ];
    }

    /**
     * @param  array<int, array{channel_id:int,button_type:?string,button_text:?string,button_url:?string}>  $channelSettings
     */
    protected static function syncRuleChannels(AutoReplyRule $rule, array $channelSettings): void
    {
        $syncPayload = [];

        foreach ($channelSettings as $settings) {
            $syncPayload[$settings['channel_id']] = [
                'button_type' => $settings['button_type'],
                'button_text' => $settings['button_text'],
                'button_url' => $settings['button_url'],
            ];
        }

        $rule->channels()->sync($syncPayload);
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
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => 'Текст или параметр для срабатывания',
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

        if ($record->usesExactTextOrParameterScope()) {
            return sprintf('Текст или параметр: %s', (string) $record->keyword);
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
                $tag->id => static::formatTagOptionLabel($tag),
            ])
            ->all();
    }

    protected static function getTagConditionOptions(?AutoReplyRule $record = null): array
    {
        $tagIds = $record instanceof AutoReplyRule
            ? $record->tagConditions()
                ->pluck('tag_id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->all()
            : [];

        return static::getTagOptionsIncludingIds($tagIds);
    }

    protected static function getTagEffectOptions(?AutoReplyRule $record = null): array
    {
        $tagIds = $record instanceof AutoReplyRule
            ? $record->tagEffects()
                ->pluck('tag_id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->all()
            : [];

        return static::getTagOptionsIncludingIds($tagIds);
    }

    /**
     * @param  list<int>  $tagIds
     * @return array<int, string>
     */
    protected static function getTagOptionsIncludingIds(array $tagIds): array
    {
        $tagIds = array_values(array_filter(array_unique($tagIds), fn (int $tagId): bool => $tagId > 0));

        return Tag::query()
            ->where(function (Builder $query) use ($tagIds): void {
                $query->where('is_active', true);

                if ($tagIds !== []) {
                    $query->orWhereIn('id', $tagIds);
                }
            })
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [
                $tag->id => static::formatTagOptionLabel($tag),
            ])
            ->all();
    }

    protected static function formatTagOptionLabel(Tag $tag): string
    {
        $palette = static::getTagOptionPalette($tag->color);

        return sprintf(
            '<span style="display:inline-flex;align-items:center;padding:0.18rem 0.58rem;border-radius:999px;background:%s;color:%s;border:1px solid %s;font-weight:600;line-height:1.2;">%s</span>',
            $palette['bg'],
            $palette['text'],
            $palette['border'],
            e($tag->name),
        );
    }

    /**
     * @return array{bg:string,text:string,border:string}
     */
    protected static function getTagOptionPalette(string $color): array
    {
        return match ($color) {
            Tag::COLOR_PRIMARY => [
                'bg' => '#dbeafe',
                'text' => '#1d4ed8',
                'border' => '#93c5fd',
            ],
            Tag::COLOR_SUCCESS => [
                'bg' => '#dcfce7',
                'text' => '#15803d',
                'border' => '#86efac',
            ],
            Tag::COLOR_WARNING => [
                'bg' => '#fef3c7',
                'text' => '#b45309',
                'border' => '#fcd34d',
            ],
            Tag::COLOR_DANGER => [
                'bg' => '#fee2e2',
                'text' => '#b91c1c',
                'border' => '#fca5a5',
            ],
            default => [
                'bg' => '#e5e7eb',
                'text' => '#374151',
                'border' => '#cbd5e1',
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    protected static function getTagFilterOptions(): array
    {
        return Tag::query()
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
