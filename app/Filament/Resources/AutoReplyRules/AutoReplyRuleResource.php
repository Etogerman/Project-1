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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
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
use Illuminate\Support\HtmlString;
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
                Grid::make(1)
                    ->schema([
                        Checkbox::make('is_active')
                            ->label('Автоответ активен')
                            ->default(true)
                            ->live()
                            ->extraAttributes(['class' => 'ac-auto-reply-status-inline ac-auto-reply-status-inline--simple']),
                    ])
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'xl' => 2])
                    ->extraAttributes(['class' => 'ac-auto-reply-sections-grid'])
                    ->schema([
                        Section::make('Триггеры и условия')
                            ->extraAttributes(['class' => 'ac-auto-reply-form-section ac-auto-reply-form-section--flat ac-auto-reply-form-section--minimal ac-auto-reply-form-section--triggers'])
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
                                    ->label('Текст для срабатывания')
                                    ->required(fn (Get $get): bool => static::usesKeywordScope($get('match_scope')))
                                    ->hidden(fn (Get $get): bool => ! static::usesKeywordScope($get('match_scope')))
                                    ->maxLength(255),
                                Select::make('contact_phone_condition')
                                    ->label('Условие по телефону')
                                    ->options(AutoReplyRule::phoneConditionOptions())
                                    ->placeholder('Неважно')
                                    ->native(false),
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
                                Select::make('telegram_button_type')
                                    ->label('Кнопка')
                                    ->options(AutoReplyRule::telegramButtonTypeOptions())
                                    ->placeholder('Без кнопки')
                                    ->native(false)
                                    ->hidden(fn (Get $get): bool => ! static::channelSupportsTelegram((int) $get('channel_id'))),
                                Select::make('max_button_type')
                                    ->label('Кнопка')
                                    ->options(AutoReplyRule::maxButtonTypeOptions())
                                    ->placeholder('Без кнопки')
                                    ->native(false)
                                    ->hidden(fn (Get $get): bool => ! static::channelSupportsMax((int) $get('channel_id'))),
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
            'channelLabel' => static::resolveChannelLabel($get('channel_id')),
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
            static::resolveChannelLabel($get('channel_id')),
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
            static::resolveChannelLabel($get('channel_id')) ?? 'Канал не выбран',
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

        if (filled($channelLabel = static::resolveChannelLabel($get('channel_id')))) {
            $lines[] = 'Канал: '.$channelLabel;
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

    protected static function resolveButtonLabel(Get $get): ?string
    {
        $channelId = (int) ($get('channel_id') ?? 0);

        if (static::channelSupportsTelegram($channelId)) {
            $buttonType = (string) ($get('telegram_button_type') ?? '');

            return filled($buttonType)
                ? (AutoReplyRule::telegramButtonTypeOptions()[$buttonType] ?? $buttonType)
                : null;
        }

        if (static::channelSupportsMax($channelId)) {
            $buttonType = (string) ($get('max_button_type') ?? '');

            return filled($buttonType)
                ? (AutoReplyRule::maxButtonTypeOptions()[$buttonType] ?? $buttonType)
                : null;
        }

        $telegramButtonType = (string) ($get('telegram_button_type') ?? '');

        if (filled($telegramButtonType)) {
            return AutoReplyRule::telegramButtonTypeOptions()[$telegramButtonType] ?? $telegramButtonType;
        }

        $maxButtonType = (string) ($get('max_button_type') ?? '');

        return filled($maxButtonType)
            ? (AutoReplyRule::maxButtonTypeOptions()[$maxButtonType] ?? $maxButtonType)
            : null;
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
                TextColumn::make('channel_display')
                    ->label('Канал')
                    ->state(fn (AutoReplyRule $record): string => static::formatChannelLabel($record->channel))
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
                    ->state(fn (AutoReplyRule $record): ?string => $record->telegram_button_type ?? $record->max_button_type)
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? (AutoReplyRule::telegramButtonTypeOptions()[$state]
                            ?? AutoReplyRule::maxButtonTypeOptions()[$state]
                            ?? $state)
                        : '—')
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
                SelectFilter::make('channel_id')
                    ->label('Канал')
                    ->options(static::getChannelOptions()),
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
                    ->extraModalWindowAttributes([
                        'class' => 'ac-auto-reply-form-modal',
                        'style' => 'width: 90vw; max-width: 90vw;',
                    ])
                    ->using(function (array $data, AutoReplyRule $record): AutoReplyRule {
                        try {
                            return static::saveAutoReplyRule($data, $record);
                        } catch (\Illuminate\Validation\ValidationException $exception) {
                            static::notifyValidationFailure($exception);

                            throw $exception;
                        }
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

    public static function notifyValidationFailure(\Illuminate\Validation\ValidationException $exception): void
    {
        $message = collect($exception->errors())
            ->flatten()
            ->map(fn (mixed $value): string => is_string($value) ? trim($value) : '')
            ->first(fn (string $value): bool => $value !== '');

        \Filament\Notifications\Notification::make()
            ->title('Правило не сохранено')
            ->body($message !== null && $message !== ''
                ? $message
                : 'Проверьте данные формы и попробуйте ещё раз.')
            ->danger()
            ->send();
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
