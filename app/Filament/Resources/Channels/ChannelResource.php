<?php

namespace App\Filament\Resources\Channels;

use App\Filament\Resources\Channels\Pages\ManageChannels;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\ChannelConnectionType;
use App\Models\ChannelRuntimeState;
use App\Models\Message;
use App\Services\Bots\CheckChannelConnectionAction;
use App\Services\Bots\RegisterChannelWebhookAction;
use App\Services\Bots\SyncChannelBotMetadataAction;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\MessageChronology;
use App\Services\Scenarios\ScenarioRegistry;
use App\Services\Scenarios\SyncChannelScenarioBindingsAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static ?string $recordTitleAttribute = 'display_title';

    protected static ?string $modelLabel = 'Канал связи';

    protected static ?string $pluralModelLabel = 'Каналы связи';

    protected static ?string $navigationLabel = 'Каналы связи';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 14;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['runtimeState', 'connectionTypeDefinition']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основное')
                    ->extraAttributes(['class' => 'ac-channel-form-section ac-channel-form-section--profile'])
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->extraFieldWrapperAttributes(['class' => 'ac-channel-form-field'])
                            ->required()
                            ->maxLength(255),
                        Select::make('channel_connection_type_id')
                            ->label('Тип подключения')
                            ->extraFieldWrapperAttributes(['class' => 'ac-channel-form-field'])
                            ->options(fn (): array => ChannelConnectionType::activeOptions())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                if (! is_numeric($state)) {
                                    return;
                                }

                                $type = ChannelConnectionType::query()->find((int) $state);

                                if (! $type instanceof ChannelConnectionType) {
                                    return;
                                }

                                $set('platform', $type->platform);
                                $set('connection_type', $type->connection_kind);
                            })
                            ->placeholder('Определить по платформе и режиму')
                            ->native(false)
                            ->helperText('Канал выбирает тип подключения. Платформа и режим ниже синхронизируются для совместимости.'),
                        Select::make('platform')
                            ->label('Платформа')
                            ->extraFieldWrapperAttributes(['class' => 'ac-channel-form-field'])
                            ->options(Channel::platformOptions())
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false),
                        Select::make('connection_type')
                            ->label('Тип')
                            ->extraFieldWrapperAttributes(['class' => 'ac-channel-form-field'])
                            ->options(Channel::connectionTypeOptions())
                            ->default(Channel::CONNECTION_TYPE_BOT)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false),
                    ])
                    ->columnSpanFull()
                    ->columns(3),
                Section::make('Доступ и режим')
                    ->extraAttributes(['class' => 'ac-channel-form-section ac-channel-form-section--access'])
                    ->schema([
                        Select::make('auto_reply_mode')
                            ->label('Режим автоответа')
                            ->extraFieldWrapperAttributes(['class' => 'ac-channel-form-field'])
                            ->options(Channel::autoReplyModeOptions())
                            ->default(Channel::AUTO_REPLY_MODE_RULES_ONLY)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false),
                        Select::make('is_active')
                            ->label('Активность')
                            ->extraFieldWrapperAttributes(['class' => 'ac-channel-form-field'])
                            ->options([
                                '1' => 'Активен',
                                '0' => 'Отключён',
                            ])
                            ->default('1')
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->dehydrateStateUsing(fn (string|int|bool|null $state): bool => (string) $state === '1'),
                    ])
                    ->columnSpanFull()
                    ->columns(2),
                Section::make('Токен')
                    ->extraAttributes(['class' => 'ac-channel-form-section ac-channel-form-section--token'])
                    ->schema([
                        TextInput::make('credentials.token')
                            ->label('Токен')
                            ->password()
                            ->revealable()
                            ->extraFieldWrapperAttributes(['class' => 'ac-channel-form-field'])
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->afterStateHydrated(function (TextInput $component, string $operation): void {
                                if ($operation === 'edit') {
                                    $component->state(null);
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->dehydrated(fn (?string $state, string $operation): bool => $operation === 'create' || filled($state))
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Сводка канала')
                    ->extraAttributes(['class' => 'ac-channel-view-section ac-channel-view-section--summary'])
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),
                        TextEntry::make('name')
                            ->label('Название'),
                        TextEntry::make('platform')
                            ->label('Платформа')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Channel::platformOptions()[$state] ?? $state),
                        TextEntry::make('connection_type')
                            ->label('Тип')
                            ->badge()
                            ->state(fn (Channel $record): string => $record->getConnectionTypeLabel()),
                        TextEntry::make('auto_reply_mode')
                            ->label('Автоответ')
                            ->state(fn (Channel $record): string => $record->getAutoReplyModeLabel())
                            ->badge()
                            ->color(fn (Channel $record): string => static::getAutoReplyModeColor($record->auto_reply_mode)),
                        TextEntry::make('health_status')
                            ->label('Состояние')
                            ->state(fn (Channel $record): string => $record->getHealthStatusLabel())
                            ->badge()
                            ->color(fn (Channel $record): string => $record->getHealthStatusColor()),
                        TextEntry::make('runtime_auth_status')
                            ->label('Авторизация')
                            ->visible(fn (Channel $record): bool => $record->isAccountConnection())
                            ->state(fn (Channel $record): string => $record->runtimeState?->getAuthStatusLabel() ?? 'Не авторизован')
                            ->badge()
                            ->color(fn (Channel $record): string => $record->runtimeState?->getAuthStatusColor() ?? 'gray'),
                        TextEntry::make('runtime_authorization_state')
                            ->label('Шаг авторизации')
                            ->visible(fn (Channel $record): bool => $record->isAccountConnection())
                            ->state(fn (Channel $record): string => $record->runtimeState?->getAuthorizationStateLabel() ?? 'Не начато')
                            ->badge()
                            ->color(fn (Channel $record): string => $record->runtimeState?->getAuthorizationStateColor() ?? 'gray'),
                        TextEntry::make('runtime_sync_status')
                            ->label('Синхронизация')
                            ->visible(fn (Channel $record): bool => $record->isAccountConnection())
                            ->state(fn (Channel $record): string => $record->runtimeState?->getSyncStatusLabel() ?? 'Ожидает')
                            ->badge()
                            ->color(fn (Channel $record): string => $record->runtimeState?->getSyncStatusColor() ?? 'gray'),
                        TextEntry::make('bot_name')
                            ->label('Имя бота')
                            ->visible(fn (Channel $record): bool => $record->isBotConnection())
                            ->state(fn (Channel $record): string => filled($record->bot_name) ? (string) $record->bot_name : 'Не загружено'),
                        TextEntry::make('bot_username')
                            ->label('Username')
                            ->visible(fn (Channel $record): bool => $record->isBotConnection())
                            ->state(fn (Channel $record): string => $record->getBotUsernameLabel() ?? 'Не загружен')
                            ->url(fn (Channel $record): ?string => $record->getBotProfileUrl())
                            ->openUrlInNewTab(),
                        TextEntry::make('bot_external_id')
                            ->label('Внешний ID')
                            ->visible(fn (Channel $record): bool => $record->isBotConnection())
                            ->state(fn (Channel $record): string => filled($record->bot_external_id) ? (string) $record->bot_external_id : 'Не загружен')
                            ->copyable(),
                        IconEntry::make('is_active')
                            ->label('Активен')
                            ->boolean(),
                        TextEntry::make('webhook_secret_status')
                            ->label('Webhook')
                            ->visible(fn (Channel $record): bool => $record->isBotConnection())
                            ->state(fn (Channel $record): string => $record->getWebhookStatusLabel())
                            ->badge()
                            ->color(fn (Channel $record): string => $record->getWebhookStatusColor()),
                        TextEntry::make('last_webhook_received_at')
                            ->label('Последний webhook')
                            ->visible(fn (Channel $record): bool => $record->isBotConnection())
                            ->placeholder('Ещё не было')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_reply_sent_at')
                            ->label('Последний ответ')
                            ->visible(fn (Channel $record): bool => $record->isBotConnection())
                            ->placeholder('Ещё не было')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('runtime_last_gateway_heartbeat_at')
                            ->label('Последний heartbeat gateway')
                            ->visible(fn (Channel $record): bool => $record->isAccountConnection())
                            ->placeholder('Ещё не было')
                            ->state(fn (Channel $record) => $record->runtimeState?->last_gateway_heartbeat_at)
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('runtime_last_sync_started_at')
                            ->label('Старт синхронизации')
                            ->visible(fn (Channel $record): bool => $record->isAccountConnection())
                            ->placeholder('Ещё не было')
                            ->state(fn (Channel $record) => $record->runtimeState?->last_sync_started_at)
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('runtime_last_sync_completed_at')
                            ->label('Завершение синхронизации')
                            ->visible(fn (Channel $record): bool => $record->isAccountConnection())
                            ->placeholder('Ещё не было')
                            ->state(fn (Channel $record) => $record->runtimeState?->last_sync_completed_at)
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_error_at')
                            ->label('Последняя ошибка')
                            ->placeholder('Ошибок не было')
                            ->state(fn (Channel $record) => $record->isAccountConnection()
                                ? $record->runtimeState?->last_error_at
                                : $record->last_error_at)
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_error_message')
                            ->label('Текст ошибки')
                            ->state(fn (Channel $record): string => filled($record->isAccountConnection()
                                ? $record->runtimeState?->last_error_message
                                : $record->last_error_message)
                                ? (string) ($record->isAccountConnection()
                                    ? $record->runtimeState?->last_error_message
                                    : $record->last_error_message)
                                : 'Ошибок не было')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label('Создан')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('updated_at')
                            ->label('Обновлён')
                            ->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make('Последнее входящее событие')
                    ->extraAttributes(['class' => 'ac-channel-view-section ac-channel-view-section--latest-message'])
                    ->schema([
                        TextEntry::make('latest_message_saved_at')
                            ->label('Сохранено в системе')
                            ->placeholder('Сообщений ещё не было')
                            ->state(fn (Channel $record) => static::resolveLatestSavedMessage($record)?->created_at)
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('latest_message_received_at')
                            ->label('Получено')
                            ->placeholder('Не задано')
                            ->state(fn (Channel $record) => static::resolveLatestSavedMessage($record)?->received_at)
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('latest_message_external_user')
                            ->label('Внешний пользователь')
                            ->placeholder('—')
                            ->state(fn (Channel $record): ?string => static::resolveLatestSavedMessage($record)?->contactIdentity?->external_user_id)
                            ->copyable(),
                        TextEntry::make('latest_message_external_id')
                            ->label('Внешний message ID')
                            ->placeholder('Не задан')
                            ->state(fn (Channel $record): ?string => static::resolveLatestSavedMessage($record)?->external_message_id)
                            ->copyable(),
                        TextEntry::make('latest_message_provider_event_key')
                            ->label('Provider event key')
                            ->placeholder('Не задан')
                            ->state(fn (Channel $record): ?string => static::resolveLatestSavedMessage($record)?->provider_event_key)
                            ->copyable(),
                        TextEntry::make('latest_message_direction')
                            ->label('Направление')
                            ->placeholder('—')
                            ->state(fn (Channel $record): ?string => static::resolveLatestSavedMessage($record)?->direction)
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => static::formatMessageDirection($state))
                            ->color(fn (?string $state): string => static::getMessageDirectionColor($state)),
                        TextEntry::make('latest_message_auto_reply_sent_at')
                            ->label('Автоответ отправлен')
                            ->placeholder('Ответ ещё не отправлен')
                            ->state(fn (Channel $record) => static::resolveLatestSavedMessage($record)?->auto_reply_sent_at)
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('latest_message_reply_status')
                            ->label('Статус автоответа')
                            ->placeholder('Сообщений ещё не было')
                            ->state(fn (Channel $record): ?string => static::resolveLatestSavedMessageReplyStatus($record))
                            ->badge()
                            ->color(fn (Channel $record): string => static::getLatestSavedMessageReplyStatusColor($record)),
                        TextEntry::make('messages_count')
                            ->label('Сохранено сообщений')
                            ->state(fn (Channel $record): int => $record->messages()->count()),
                        TextEntry::make('latest_message_text')
                            ->label('Текст')
                            ->placeholder('—')
                            ->state(fn (Channel $record): ?string => static::resolveLatestSavedMessageDisplayText($record))
                            ->wrap()
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make('Лента сообщений')
                    ->extraAttributes(['class' => 'ac-channel-view-section ac-channel-view-section--feed'])
                    ->schema([
                        TextEntry::make('recent_messages_feed')
                            ->label('Последние сохранённые сообщения')
                            ->state(fn (Channel $record): HtmlString => static::renderRecentSavedMessages($record))
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Техжурнал')
                    ->extraAttributes(['class' => 'ac-channel-view-section ac-channel-view-section--feed'])
                    ->schema([
                        TextEntry::make('recent_activity_feed')
                            ->label('Последние события канала')
                            ->state(fn (Channel $record): HtmlString => static::renderRecentActivityLogs($record))
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->label('Канал')
                    ->description(fn (Channel $record): string => static::buildChannelTableSummary($record))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('bot_name')
                    ->label('Имя бота')
                    ->state(fn (Channel $record): string => filled($record->bot_name) ? (string) $record->bot_name : '—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('bot_username')
                    ->label('Username')
                    ->state(fn (Channel $record): string => $record->getBotUsernameLabel() ?? '—')
                    ->url(fn (Channel $record): ?string => $record->getBotProfileUrl())
                    ->openUrlInNewTab()
                    ->searchable(['bot_username'])
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('health_status')
                    ->label('Состояние')
                    ->state(fn (Channel $record): string => static::resolveConnectionStatusLabel(
                        $record,
                        static::resolveConnectionState($record),
                    ))
                    ->badge()
                    ->extraAttributes(['class' => 'ac-channel-table-badge'])
                    ->color(fn (Channel $record): string => static::resolveConnectionStatusColor(
                        $record,
                        static::resolveConnectionState($record),
                    ))
                    ->toggleable(),
                TextColumn::make('platform')
                    ->label('Платформа')
                    ->badge()
                    ->extraAttributes(['class' => 'ac-channel-table-badge'])
                    ->formatStateUsing(fn (string $state): string => Channel::platformOptions()[$state] ?? $state)
                    ->color('info')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('connection_type')
                    ->label('Тип')
                    ->badge()
                    ->extraAttributes(['class' => 'ac-channel-table-badge'])
                    ->state(fn (Channel $record): string => $record->getConnectionTypeLabel())
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('runtime_auth_status')
                    ->label('Авторизация')
                    ->state(fn (Channel $record): string => $record->isAccountConnection()
                        ? ($record->runtimeState?->getAuthStatusLabel() ?? 'Не авторизован')
                        : '—')
                    ->badge()
                    ->color(fn (Channel $record): string => $record->isAccountConnection()
                        ? ($record->runtimeState?->getAuthStatusColor() ?? 'gray')
                        : 'gray')
                    ->toggleable(),
                TextColumn::make('runtime_sync_status')
                    ->label('Синхронизация')
                    ->state(fn (Channel $record): string => $record->isAccountConnection()
                        ? ($record->runtimeState?->getSyncStatusLabel() ?? 'Ожидает')
                        : '—')
                    ->badge()
                    ->color(fn (Channel $record): string => $record->isAccountConnection()
                        ? ($record->runtimeState?->getSyncStatusColor() ?? 'gray')
                        : 'gray')
                    ->toggleable(),
                TextColumn::make('auto_reply_mode')
                    ->label('Автоответ')
                    ->state(fn (Channel $record): string => $record->getAutoReplyModeLabel())
                    ->badge()
                    ->extraAttributes(['class' => 'ac-channel-table-badge'])
                    ->color(fn (Channel $record): string => static::getAutoReplyModeColor($record->auto_reply_mode))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->label('Включён')
                    ->badge()
                    ->extraAttributes(['class' => 'ac-channel-table-badge'])
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('webhook_secret_status')
                    ->label('Webhook')
                    ->state(fn (Channel $record): string => static::resolveLiveWebhookStatusLabel(
                        $record,
                        static::resolveConnectionState($record),
                    ))
                    ->badge()
                    ->extraAttributes(['class' => 'ac-channel-table-badge'])
                    ->color(fn (Channel $record): string => static::resolveLiveWebhookStatusColor(
                        $record,
                        static::resolveConnectionState($record),
                    ))
                    ->toggleable(),
                TextColumn::make('connection_checked_at')
                    ->label('Последняя проверка')
                    ->state(fn (Channel $record) => static::resolveConnectionState($record)['connection_checked_at'])
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('connection_error_message')
                    ->label('Ошибка')
                    ->state(fn (Channel $record): ?string => static::resolveConnectionState($record)['connection_error_message'])
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('bot_external_id')
                    ->label('Внешний ID')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_webhook_received_at')
                    ->label('Последний webhook')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_reply_sent_at')
                    ->label('Последний ответ')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error_at')
                    ->label('Последняя ошибка')
                    ->state(fn (Channel $record) => $record->isAccountConnection()
                        ? $record->runtimeState?->last_error_at
                        : $record->last_error_at)
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error_message')
                    ->label('Текст ошибки')
                    ->state(fn (Channel $record): ?string => $record->isAccountConnection()
                        ? $record->runtimeState?->last_error_message
                        : $record->last_error_message)
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->label('Платформа')
                    ->options(Channel::platformOptions()),
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
            ->recordActionsColumnLabel('Кнопки')
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Столбцы')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->reorderableColumns()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Каналы связи ещё не добавлены')
            ->emptyStateDescription('Создайте первое подключение для бота.')
            ->recordActions([
                Action::make('registerWebhook')
                    ->label('Зарегистрировать webhook')
                    ->icon(Heroicon::OutlinedBolt)
                    ->color('success')
                    ->iconButton()
                    ->extraAttributes(['class' => 'ac-channel-table-operation'])
                    ->tooltip('Зарегистрировать webhook')
                    ->requiresConfirmation()
                    ->visible(fn (Channel $record): bool => $record->is_active
                        && $record->connection_type === Channel::CONNECTION_TYPE_BOT
                        && static::canUpdateChannel($record))
                    ->action(function (Channel $record): void {
                        static::authorizeChannelUpdate($record);

                        try {
                            app(RegisterChannelWebhookAction::class)->handle($record);
                            $record->refresh();

                            if ($record->connection_status !== Channel::CONNECTION_STATUS_CONNECTED) {
                                Notification::make()
                                    ->warning()
                                    ->title('Webhook зарегистрирован, но подключение не подтверждено')
                                    ->body($record->connection_error_message ?? 'Проверьте подключение канала.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Webhook зарегистрирован')
                                ->body('Секрет сохранён автоматически, webhook обновлён, данные бота синхронизированы.')
                                ->send();
                        } catch (InvalidArgumentException $throwable) {
                            Notification::make()
                                ->warning()
                                ->title('Нужно проверить настройки канала')
                                ->body($throwable->getMessage())
                                ->send();
                        } catch (Throwable $throwable) {
                            report($throwable);

                            Notification::make()
                                ->danger()
                                ->title('Не удалось зарегистрировать webhook')
                                ->body($throwable->getMessage())
                                ->send();
                        }
                    }),
                Action::make('checkConnection')
                    ->label('Проверить подключение')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->iconButton()
                    ->extraAttributes(['class' => 'ac-channel-table-operation'])
                    ->tooltip('Проверить подключение')
                    ->visible(fn (Channel $record): bool => static::canUpdateChannel($record))
                    ->action(function (Channel $record): void {
                        static::authorizeChannelUpdate($record);

                        $state = app(CheckChannelConnectionAction::class)->handle($record);

                        if ($state['connection_status'] === Channel::CONNECTION_STATUS_CONNECTED) {
                            Notification::make()
                                ->success()
                                ->title('Канал подключен')
                                ->body('Провайдер подтвердил webhook этой админки.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->danger()
                            ->title('Канал не подключен')
                            ->body($state['connection_error_message'] ?? 'Проверьте настройки канала.')
                            ->send();
                    }),
                Action::make('syncBotMetadata')
                    ->label('Обновить данные бота')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->iconButton()
                    ->extraAttributes(['class' => 'ac-channel-table-operation'])
                    ->tooltip('Обновить данные бота')
                    ->visible(fn (Channel $record): bool => $record->connection_type === Channel::CONNECTION_TYPE_BOT
                        && $record->hasBotTokenConfigured()
                        && static::canUpdateChannel($record))
                    ->action(function (Channel $record): void {
                        static::authorizeChannelUpdate($record);

                        try {
                            app(SyncChannelBotMetadataAction::class)->handle($record);

                            Notification::make()
                                ->success()
                                ->title('Канал проверен')
                                ->body('Доступ к боту подтверждён, данные бота синхронизированы с платформой.')
                                ->send();
                        } catch (InvalidArgumentException $throwable) {
                            Notification::make()
                                ->warning()
                                ->title('Нужно проверить настройки канала')
                                ->body($throwable->getMessage())
                                ->send();
                        } catch (Throwable $throwable) {
                            report($throwable);

                            Notification::make()
                                ->danger()
                                ->title('Не удалось обновить данные бота')
                                ->body($throwable->getMessage())
                                ->send();
                        }
                    }),
                Action::make('manageScenarios')
                    ->label('Сценарии')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->color('gray')
                    ->iconButton()
                    ->extraAttributes(['class' => 'ac-channel-table-operation'])
                    ->tooltip('Сценарии')
                    ->visible(fn (Channel $record): bool => $record->isBotConnection() && static::canUpdateChannel($record))
                    ->modalWidth(Width::Large)
                    ->modalHeading('Сценарии канала')
                    ->modalSubmitAction(fn (Action $action): Action => $action
                        ->label('Сохранить')
                        ->color('success'))
                    ->fillForm(function (Channel $record): array {
                        static::authorizeChannelUpdate($record);

                        $scenarioRegistry = app(ScenarioRegistry::class);
                        $selectableScenarioCodes = $scenarioRegistry->selectableScenarioCodesForChannel($record);

                        return [
                            'scenario_codes' => $record->scenarioBindings()
                                ->active()
                                ->whereIn('scenario_code', $selectableScenarioCodes)
                                ->pluck('scenario_code')
                                ->all(),
                        ];
                    })
                    ->form([
                        Placeholder::make('no_scenarios')
                            ->hiddenLabel()
                            ->content('Для этого канала нет доступных сценариев')
                            ->visible(fn (Channel $record): bool => app(ScenarioRegistry::class)->optionsForChannel($record) === []),
                        CheckboxList::make('scenario_codes')
                            ->label('Активные сценарии')
                            ->options(fn (Channel $record): array => app(ScenarioRegistry::class)->optionsForChannel($record))
                            ->hidden(fn (Channel $record): bool => app(ScenarioRegistry::class)->optionsForChannel($record) === [])
                            ->columns(1),
                    ])
                    ->action(function (Channel $record, array $data): void {
                        static::authorizeChannelUpdate($record);

                        app(SyncChannelScenarioBindingsAction::class)->handle(
                            $record,
                            (array) data_get($data, 'scenario_codes', []),
                        );

                        Notification::make()
                            ->success()
                            ->title('Сценарии обновлены')
                            ->body('Настройки сценариев для канала сохранены.')
                            ->send();
                    }),
                ViewAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->color('gray')
                    ->extraAttributes(['class' => 'ac-channel-table-action'])
                    ->extraModalWindowAttributes(['class' => 'ac-channel-view-modal'])
                    ->modalContent(fn (Channel $record): ViewContract => view(
                        'filament.channels.partials.channel-view-overview',
                        static::buildChannelViewModalData($record),
                    ))
                    ->infolist([])
                    ->tooltip('Просмотр'),
                EditAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->color('gray')
                    ->extraAttributes(['class' => 'ac-channel-table-action'])
                    ->visible(fn (Channel $record): bool => $record->isBotConnection())
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->extraModalWindowAttributes(['class' => 'ac-channel-form-modal'])
                    ->tooltip('Изменить')
                    ->fillForm(fn (Channel $record): array => [
                        'name' => $record->name,
                        'channel_connection_type_id' => $record->channel_connection_type_id
                            ?? ChannelConnectionType::resolveIdFor($record->platform, $record->connection_type),
                        'platform' => $record->platform,
                        'connection_type' => $record->connection_type,
                        'auto_reply_mode' => $record->auto_reply_mode,
                        'credentials' => [
                            'token' => null,
                        ],
                        'is_active' => $record->is_active ? '1' : '0',
                    ])
                    ->using(function (array $data, Channel $record): void {
                        static::updateChannelRecord($record, static::mutateChannelData($data, $record));
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([]);
    }

    protected static function canUpdateChannel(Channel $record): bool
    {
        return (bool) auth()->user()?->can('update', $record);
    }

    protected static function authorizeChannelUpdate(Channel $record): void
    {
        abort_unless(static::canUpdateChannel($record), 403);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageChannels::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateChannelData(array $data, ?Channel $record = null): array
    {
        $data = static::syncChannelConnectionTypeData($data);
        $token = trim((string) data_get($data, 'credentials.token', ''));
        $credentials = $record?->readableCredentials() ?? [];

        if (static::shouldClearBotMetadata($data, $record, $token)) {
            $data = static::clearBotMetadata($data);
        }

        if ($token !== '') {
            Arr::set($credentials, Channel::CREDENTIAL_TOKEN, $token);
            Arr::set($data, 'credentials', $credentials);

            return $data;
        }

        Arr::forget($data, 'credentials');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function syncChannelConnectionTypeData(array $data): array
    {
        $typeId = data_get($data, 'channel_connection_type_id');

        if (is_numeric($typeId)) {
            $type = ChannelConnectionType::query()->find((int) $typeId);

            if ($type instanceof ChannelConnectionType) {
                $data['channel_connection_type_id'] = $type->id;
                $data['platform'] = $type->platform;
                $data['connection_type'] = $type->connection_kind;

                return $data;
            }
        }

        $platform = (string) data_get($data, 'platform', '');
        $connectionType = (string) data_get($data, 'connection_type', '');
        $resolvedTypeId = ChannelConnectionType::resolveIdFor($platform, $connectionType);

        if ($resolvedTypeId !== null) {
            $data['channel_connection_type_id'] = $resolvedTypeId;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function updateChannelRecord(Channel $record, array $data): void
    {
        if (! $record->hasUnreadableCredentials() || ! array_key_exists('credentials', $data)) {
            $record->update($data);

            return;
        }

        $record->forceFill($data);
        $record->syncTokenPresenceFromCredentials();

        $columns = array_merge(array_keys($data), ['bot_token_present']);

        if ($record->usesTimestamps()) {
            $record->setUpdatedAt($record->freshTimestamp());
            $columns[] = $record->getUpdatedAtColumn();
        }

        DB::table($record->getTable())
            ->where($record->getKeyName(), $record->getKey())
            ->update(Arr::only($record->getAttributes(), array_values(array_unique($columns))));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function clearBotMetadata(array $data): array
    {
        $data['bot_external_id'] = null;
        $data['bot_username'] = null;
        $data['bot_name'] = null;
        $data['bot_profile_url'] = null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function shouldClearBotMetadata(array $data, ?Channel $record, string $token): bool
    {
        if ($record === null) {
            return false;
        }

        $nextPlatform = (string) data_get($data, 'platform', $record->platform);
        $nextConnectionType = (string) data_get($data, 'connection_type', $record->connection_type);

        if ($nextPlatform !== $record->platform || $nextConnectionType !== $record->connection_type) {
            return true;
        }

        return $token !== '' && $token !== $record->getToken();
    }

    protected static function resolveLatestSavedMessage(Channel $record): ?Message
    {
        return $record->messages()
            ->with('contactIdentity')
            ->where('direction', Message::DIRECTION_INBOUND)
            ->tap(fn ($query) => static::messageChronology()->applyLatestOrder($query))
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function resolveMessageFeedSummary(Message $message): ?array
    {
        /** @var array<int, array<string, mixed>|null> $cache */
        static $cache = [];

        if (array_key_exists($message->id, $cache)) {
            return $cache[$message->id];
        }

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(new Collection([$message]));

        return $cache[$message->id] = $feed[0] ?? null;
    }

    protected static function resolveLatestSavedMessageDisplayText(Channel $record): ?string
    {
        $message = static::resolveLatestSavedMessage($record);

        if (! $message instanceof Message) {
            return null;
        }

        $feed = static::resolveMessageFeedSummary($message);

        if (is_array($feed) && filled($feed['display_text'] ?? null)) {
            $mediaBadge = static::resolveFirstMediaBadge($message, $feed);

            if ($mediaBadge !== null) {
                return $mediaBadge;
            }

            return (string) $feed['display_text'];
        }

        return $message->text;
    }

    protected static function renderRecentSavedMessages(Channel $record): HtmlString
    {
        $messages = $record->messages()
            ->with(['contactIdentity', 'replyTo'])
            ->tap(fn ($query) => static::messageChronology()->applyLatestOrder($query))
            ->limit(15)
            ->get();

        if ($messages->isEmpty()) {
            return new HtmlString('<div class="text-sm text-gray-500">Сообщений ещё не было.</div>');
        }

        $items = $messages->map(function (Message $message): string {
            $badges = [
                static::renderFeedBadge(sprintf('Сохранено: %s', $message->created_at?->format('d.m.Y H:i:s') ?? '—')),
                static::renderFeedBadge(sprintf('Получено: %s', $message->received_at?->format('d.m.Y H:i:s') ?? '—')),
                static::renderFeedBadge(sprintf('Пользователь: %s', $message->contactIdentity?->external_user_id ?? '—')),
                static::renderFeedBadge(
                    sprintf('Направление: %s', static::formatMessageDirection($message->direction)),
                    static::getMessageDirectionBadgeClasses($message->direction),
                ),
                static::renderFeedBadge(
                    sprintf('Тип: %s', static::formatMessageKind($message->message_kind)),
                    static::getMessageKindBadgeClasses($message->message_kind),
                ),
                static::renderFeedBadge(sprintf('Message ID: %s', $message->external_message_id ?? '—')),
            ];

            if ($message->direction === Message::DIRECTION_INBOUND) {
                $badges[] = static::renderFeedBadge(sprintf('Event key: %s', $message->provider_event_key ?? '—'));
                $badges[] = static::renderFeedBadge(sprintf('Автоответ: %s', $message->auto_reply_sent_at?->format('d.m.Y H:i:s') ?? '—'));
                $badges[] = static::renderFeedBadge(
                    sprintf('Статус: %s', static::formatMessageReplyStatus($message)),
                    static::getMessageReplyStatusBadgeClasses($message),
                );
            }

            if ($message->direction === Message::DIRECTION_OUTBOUND) {
                $badges[] = static::renderFeedBadge(
                    sprintf('Связь: %s', static::formatOutboundReplyLink($message)),
                    static::getOutboundReplyLinkBadgeClasses(),
                );
            }

            $feed = static::resolveMessageFeedSummary($message);

            foreach ($feed['media_badges'] ?? [] as $badge) {
                if (! is_string($badge) || ! filled($badge)) {
                    continue;
                }

                $badges[] = static::renderFeedBadge($badge);
            }

            foreach ($feed['media_state_badges'] ?? [] as $badge) {
                if (! is_array($badge) || ! filled($badge['label'] ?? null)) {
                    continue;
                }

                $badges[] = static::renderFeedBadge(
                    (string) $badge['label'],
                    static::getMediaStateBadgeClasses((string) ($badge['tone'] ?? 'gray')),
                );
            }

            $badgeMarkup = implode('', $badges);

            return sprintf(
                '<div class="rounded-xl border border-gray-200/80 px-4 py-3 dark:border-white/10"><div class="mb-3 flex flex-wrap gap-2">%s</div><div class="whitespace-pre-wrap break-words text-sm text-gray-950 dark:text-white">%s</div></div>',
                $badgeMarkup,
                e(static::resolveLatestSavedMessageBodyText($message)),
            );
        })->implode('');

        return new HtmlString(sprintf('<div class="space-y-3">%s</div>', $items));
    }

    protected static function renderRecentActivityLogs(Channel $record): HtmlString
    {
        $logs = $record->activityLogs()
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        if ($logs->isEmpty()) {
            return new HtmlString('<div class="text-sm text-gray-500">Событий ещё не было.</div>');
        }

        $items = $logs->map(function (ChannelActivityLog $log): string {
            $isDedupeEvent = static::isDedupeActivityEvent((string) $log->event);
            $badges = [
                static::renderFeedBadge(sprintf('Время: %s', $log->created_at?->format('d.m.Y H:i:s') ?? '—')),
                static::renderFeedBadge(
                    sprintf('Уровень: %s', static::formatActivityLevel((string) $log->level)),
                    static::getActivityLevelBadgeClasses((string) $log->level),
                ),
                static::renderFeedBadge(
                    sprintf('Событие: %s', static::formatActivityEvent((string) $log->event)),
                    $isDedupeEvent ? static::getDedupeActivityBadgeClasses() : null,
                ),
            ];

            $providerEventKey = data_get($log->context, 'provider_event_key');
            if (filled($providerEventKey)) {
                $badges[] = static::renderFeedBadge(
                    sprintf('Event key: %s', $providerEventKey),
                    $isDedupeEvent ? static::getDedupeActivityBadgeClasses() : null,
                );
            }

            $deliveryLagSeconds = data_get($log->context, 'delivery_lag_seconds');
            if ($log->event === 'webhook.delayed_received' && is_numeric($deliveryLagSeconds) && (int) $deliveryLagSeconds > 0) {
                $badges[] = static::renderFeedBadge(
                    sprintf('Лаг: %d сек', (int) $deliveryLagSeconds),
                    static::getDelayedActivityBadgeClasses(),
                );
            }

            $secondsBehindLatestInbound = data_get($log->context, 'seconds_behind_latest_inbound');
            if ($log->event === 'webhook.out_of_order_received' && is_numeric($secondsBehindLatestInbound) && (int) $secondsBehindLatestInbound > 0) {
                $badges[] = static::renderFeedBadge(
                    sprintf('Отставание: %d сек', (int) $secondsBehindLatestInbound),
                    static::getOutOfOrderActivityBadgeClasses(),
                );
            }

            if ($log->event === 'webhook.rate_limited') {
                static::appendRateLimitedActivityBadges($badges, $log);
            }

            $badgeMarkup = implode('', $badges);

            return sprintf(
                '<div class="%s" data-dedupe-event="%s"><div class="mb-3 flex flex-wrap gap-2">%s</div><div class="whitespace-pre-wrap break-words text-sm text-gray-950 dark:text-white">%s</div></div>',
                static::getActivityCardClasses($isDedupeEvent),
                $isDedupeEvent ? 'true' : 'false',
                $badgeMarkup,
                e(filled($log->message) ? (string) $log->message : '—'),
            );
        })->implode('');

        return new HtmlString(sprintf('<div class="space-y-3">%s</div>', $items));
    }

    protected static function formatActivityEvent(string $event): string
    {
        return match ($event) {
            'webhook.received' => 'Webhook',
            'webhook.max_unhandled_payload' => 'MAX webhook не распознан',
            'webhook.rate_limited' => 'Webhook ограничен по частоте',
            'bot.reply_queued' => 'Ответ в очереди',
            'bot.reply_rule_matched' => 'Правило автоответа',
            'bot.reply_rule_skipped_contact_condition' => 'Правило не прошло условие',
            'bot.reply_skipped_no_rule' => 'Автоответ пропущен',
            'bot.reply_skipped_contact_disabled' => 'Автоответ отключён',
            'bot.reply_legacy_default_used' => 'Legacy автоответ',
            'bot.reply_sent' => 'Ответ',
            'bot.reply_failed' => 'Ошибка ответа',
            'contact.phone_captured' => 'Номер телефона сохранён',
            'contact.phone_capture_skipped_sender_mismatch' => 'Номер телефона пропущен',
            'contact.phone_capture_confirmation_queued' => 'Подтверждение номера в очереди',
            'contact.phone_capture_confirmed' => 'Подтверждение номера отправлено',
            'contact.phone_capture_confirmation_failed' => 'Подтверждение номера не отправлено',
            'contact.phone_capture_confirmation_skipped' => 'Подтверждение номера пропущено',
            'contact.phone_capture_arrived_late' => 'Поздний phone share обработан',
            'max.contact_share_unknown_format' => 'Номер телефона не распознан',
            'contact.reply_sent' => 'Ручной ответ',
            'contact.reply_failed' => 'Ошибка ручного ответа',
            'webhook.duplicate_ignored' => 'Дубликат проигнорирован',
            'webhook.duplicate_retry_reply' => 'Дубликат → retry ответа',
            'webhook.delayed_received' => 'Webhook пришёл с задержкой',
            'webhook.out_of_order_received' => 'Webhook пришёл не по порядку',
            'bot.metadata_synced' => 'Sync metadata',
            'bot.metadata_sync_failed' => 'Ошибка metadata',
            'webhook.registration_started' => 'Регистрация webhook',
            'webhook.registration_completed' => 'Webhook готов',
            'webhook.registration_failed' => 'Ошибка webhook',
            default => $event,
        };
    }

    protected static function formatActivityLevel(string $level): string
    {
        return match ($level) {
            'error' => 'Ошибка',
            'warning' => 'Предупреждение',
            default => 'Info',
        };
    }

    protected static function getAutoReplyModeColor(?string $autoReplyMode): string
    {
        return match ($autoReplyMode) {
            Channel::AUTO_REPLY_MODE_RULES_ONLY, Channel::AUTO_REPLY_MODE_LEGACY_DEFAULT => 'info',
            default => 'gray',
        };
    }

    protected static function buildChannelTableSummary(Channel $record): string
    {
        if ($record->isAccountConnection()) {
            $runtimeState = $record->runtimeState;

            if ($runtimeState === null) {
                return 'Аккаунт ещё не авторизован';
            }

            if ($runtimeState->authorization_state === ChannelRuntimeState::AUTHORIZATION_STATE_READY) {
                return sprintf(
                    'Авторизация: %s · Синхронизация: %s',
                    $runtimeState->getAuthStatusLabel(),
                    $runtimeState->getSyncStatusLabel(),
                );
            }

            return sprintf(
                'Авторизация: %s · Шаг: %s',
                $runtimeState->getAuthStatusLabel(),
                $runtimeState->getAuthorizationStateLabel(),
            );
        }

        if (filled($record->bot_username)) {
            return $record->getBotUsernameLabel() ?? 'Данные бота синхронизированы';
        }

        if (filled($record->bot_name)) {
            return (string) $record->bot_name;
        }

        if ($record->hasBotTokenConfigured()) {
            return 'Токен сохранён, данные бота ещё не загружены';
        }

        return 'Токен ещё не настроен';
    }

    /**
     * @return array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}
     */
    protected static function resolveConnectionState(Channel $record): array
    {
        return app(CheckChannelConnectionAction::class)->resolveEffectiveState($record);
    }

    /**
     * @param  array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}  $connectionState
     */
    protected static function resolveConnectionStatusLabel(Channel $record, array $connectionState): string
    {
        if (static::isStaleConnectionState($connectionState)) {
            return 'Проверка устарела';
        }

        return $record->getConnectionStatusLabel($connectionState['connection_status']);
    }

    /**
     * @param  array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}  $connectionState
     */
    protected static function resolveConnectionStatusColor(Channel $record, array $connectionState): string
    {
        if (static::isStaleConnectionState($connectionState)) {
            return 'warning';
        }

        return $record->getConnectionStatusColor($connectionState['connection_status']);
    }

    /**
     * @param  array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}  $connectionState
     */
    protected static function resolveLiveWebhookStatusLabel(Channel $record, array $connectionState): string
    {
        if (static::isStaleConnectionState($connectionState)) {
            return 'Проверка устарела';
        }

        return $record->getLiveWebhookStatusLabel($connectionState['webhook_status']);
    }

    /**
     * @param  array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}  $connectionState
     */
    protected static function resolveLiveWebhookStatusColor(Channel $record, array $connectionState): string
    {
        if (static::isStaleConnectionState($connectionState)) {
            return 'warning';
        }

        return $record->getLiveWebhookStatusColor($connectionState['webhook_status']);
    }

    /**
     * @param  array{connection_status: string, webhook_status: string, connection_error_message: ?string, provider_webhook_url: ?string, expected_webhook_url: ?string, connection_checked_at: mixed}  $connectionState
     */
    protected static function isStaleConnectionState(array $connectionState): bool
    {
        return ($connectionState['connection_status'] ?? null) === Channel::CONNECTION_STATUS_CONNECTED
            && ($connectionState['connection_error_message'] ?? null) === Channel::CONNECTION_ERROR_STALE;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildChannelViewModalData(Channel $record): array
    {
        $record->loadMissing('runtimeState');

        $formatDate = static fn (mixed $value): string => $value instanceof \DateTimeInterface
            ? $value->format('d.m.Y H:i:s')
            : '—';
        $formatText = static fn (mixed $value, string $empty = '—'): string => filled($value)
            ? (string) $value
            : $empty;
        $latestMessage = static::resolveLatestSavedMessage($record);
        $connectionState = static::resolveConnectionState($record);
        $lastErrorAt = $record->isAccountConnection()
            ? $record->runtimeState?->last_error_at
            : $record->last_error_at;
        $lastErrorMessage = $record->isAccountConnection()
            ? $record->runtimeState?->last_error_message
            : $record->last_error_message;

        return [
            'record' => $record,
            'summaryTables' => [
                [
                    ['label' => 'ID', 'value' => $formatText($record->id)],
                    ['label' => 'Автоответ', 'value' => $record->getAutoReplyModeLabel(), 'tone' => static::getAutoReplyModeColor($record->auto_reply_mode)],
                    ['label' => 'Внешний ID', 'value' => $record->isBotConnection() ? $formatText($record->bot_external_id, 'Не загружен') : '—'],
                    ['label' => 'Последний ответ', 'value' => $formatDate($record->last_reply_sent_at)],
                    ['label' => 'Текст ошибки', 'value' => $formatText($lastErrorMessage, 'Ошибок не было')],
                    ['label' => 'Создан', 'value' => $formatDate($record->created_at)],
                ],
                [
                    ['label' => 'Название', 'value' => $formatText($record->name)],
                    ['label' => 'Состояние', 'value' => static::resolveConnectionStatusLabel($record, $connectionState), 'tone' => static::resolveConnectionStatusColor($record, $connectionState)],
                    ['label' => 'Включён', 'value' => $record->is_active ? 'Да' : 'Нет', 'tone' => $record->is_active ? 'success' : 'gray'],
                    ['label' => 'Последняя проверка', 'value' => $formatDate($connectionState['connection_checked_at'])],
                    ['label' => 'Ошибка подключения', 'value' => $formatText($connectionState['connection_error_message'], 'Ошибок не было')],
                    ['label' => 'Обновлён', 'value' => $formatDate($record->updated_at)],
                ],
                [
                    ['label' => 'Платформа', 'value' => Channel::platformOptions()[$record->platform] ?? $record->platform, 'tone' => 'info'],
                    ['label' => 'Имя бота', 'value' => $record->isBotConnection() ? $formatText($record->bot_name, 'Не загружено') : '—'],
                    ['label' => 'Webhook', 'value' => static::resolveLiveWebhookStatusLabel($record, $connectionState), 'tone' => static::resolveLiveWebhookStatusColor($record, $connectionState)],
                    ['label' => 'Ожидаемый URL', 'value' => $formatText($connectionState['expected_webhook_url'])],
                ],
                [
                    ['label' => 'Тип', 'value' => $record->getConnectionTypeLabel(), 'tone' => 'warning'],
                    ['label' => 'Username', 'value' => $record->isBotConnection() ? ($record->getBotUsernameLabel() ?? 'Не загружен') : '—'],
                    ['label' => 'Последний webhook', 'value' => $formatDate($record->last_webhook_received_at)],
                    ['label' => 'URL в Telegram', 'value' => $formatText($connectionState['provider_webhook_url'])],
                ],
            ],
            'latestMessageTables' => [
                [
                    ['label' => 'Сохранено в системе', 'value' => $formatDate($latestMessage?->created_at)],
                    ['label' => 'Provider event key', 'value' => $formatText($latestMessage?->provider_event_key)],
                    ['label' => 'Сохранено сообщений', 'value' => (string) $record->messages()->count()],
                    ['label' => 'Текст', 'value' => $formatText(static::resolveLatestSavedMessageDisplayText($record))],
                ],
                [
                    ['label' => 'Получено', 'value' => $formatDate($latestMessage?->received_at)],
                    [
                        'label' => 'Направление',
                        'value' => $latestMessage instanceof Message ? static::formatMessageDirection($latestMessage->direction) : '—',
                        'tone' => $latestMessage instanceof Message ? static::getMessageDirectionColor($latestMessage->direction) : null,
                    ],
                ],
                [
                    ['label' => 'Внешний пользователь', 'value' => $formatText($latestMessage?->contactIdentity?->external_user_id)],
                    ['label' => 'Автоответ отправлен', 'value' => $formatDate($latestMessage?->auto_reply_sent_at)],
                ],
                [
                    ['label' => 'Внешний message ID', 'value' => $formatText($latestMessage?->external_message_id)],
                    [
                        'label' => 'Статус автоответа',
                        'value' => $formatText(static::resolveLatestSavedMessageReplyStatus($record), 'Сообщений ещё не было'),
                        'tone' => static::getLatestSavedMessageReplyStatusColor($record),
                    ],
                ],
            ],
            'recentMessagesFeed' => static::renderRecentSavedMessages($record),
            'recentActivityFeed' => static::renderRecentActivityLogs($record),
        ];
    }

    protected static function resolveLatestSavedMessageReplyStatus(Channel $record): ?string
    {
        $message = static::resolveLatestSavedMessage($record);

        if ($message === null) {
            return null;
        }

        return static::formatMessageReplyStatus($message);
    }

    protected static function getLatestSavedMessageReplyStatusColor(Channel $record): string
    {
        $message = static::resolveLatestSavedMessage($record);

        if ($message === null) {
            return 'gray';
        }

        return static::getMessageReplyStatusColor($message);
    }

    protected static function messageChronology(): MessageChronology
    {
        return app(MessageChronology::class);
    }

    protected static function formatMessageDirection(?string $direction): string
    {
        return match ($direction) {
            Message::DIRECTION_INBOUND => 'Входящее',
            Message::DIRECTION_OUTBOUND => 'Исходящее',
            default => $direction ?? '—',
        };
    }

    protected static function getMessageDirectionColor(?string $direction): string
    {
        return match ($direction) {
            Message::DIRECTION_INBOUND => 'info',
            Message::DIRECTION_OUTBOUND => 'success',
            default => 'gray',
        };
    }

    protected static function formatMessageReplyStatus(Message $message): string
    {
        return $message->hasSuccessfulAutoReply()
            ? 'Ответ отправлен'
            : 'Ответ еще не отправлен';
    }

    protected static function getMessageReplyStatusColor(Message $message): string
    {
        return $message->hasSuccessfulAutoReply() ? 'success' : 'gray';
    }

    protected static function formatMessageKind(?string $messageKind): string
    {
        return match ($messageKind) {
            Message::KIND_INBOUND_USER => 'Пользователь',
            Message::KIND_INBOUND_CONTACT_SHARE => 'Поделился телефоном',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'Автоответ',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => 'Подтверждение телефона',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'Ручной ответ',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'Вопрос анкеты',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'Анкета завершена',
            default => 'Не определен',
        };
    }

    protected static function getMessageKindBadgeClasses(?string $messageKind): string
    {
        return match ($messageKind) {
            Message::KIND_INBOUND_USER => 'inline-flex items-center rounded-md border border-violet-200 bg-violet-50 px-2 py-1 text-xs text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-200',
            Message::KIND_INBOUND_CONTACT_SHARE => 'inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700 dark:border-slate-500/30 dark:bg-slate-500/10 dark:text-slate-200',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => 'inline-flex items-center rounded-md border border-cyan-200 bg-cyan-50 px-2 py-1 text-xs text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-200',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'inline-flex items-center rounded-md border border-sky-200 bg-sky-50 px-2 py-1 text-xs text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'inline-flex items-center rounded-md border border-lime-200 bg-lime-50 px-2 py-1 text-xs text-lime-700 dark:border-lime-500/30 dark:bg-lime-500/10 dark:text-lime-200',
            default => 'inline-flex items-center rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200',
        };
    }

    protected static function renderFeedBadge(string $badge, ?string $classes = null): string
    {
        return sprintf(
            '<span class="%s">%s</span>',
            e($classes ?? 'inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:text-gray-200'),
            e($badge),
        );
    }

    protected static function resolveLatestSavedMessageBodyText(Message $message): string
    {
        $feed = static::resolveMessageFeedSummary($message);

        if (is_array($feed) && filled($feed['display_text'] ?? null)) {
            $mediaBadge = static::resolveFirstMediaBadge($message, $feed);

            if ($mediaBadge !== null) {
                return $mediaBadge;
            }

            return (string) $feed['display_text'];
        }

        return filled($message->text) ? (string) $message->text : '—';
    }

    /**
     * @param  array<string, mixed>  $feed
     */
    protected static function resolveFirstMediaBadge(Message $message, array $feed): ?string
    {
        if (filled($message->text)) {
            return null;
        }

        $mediaBadges = $feed['media_badges'] ?? null;

        if (! is_array($mediaBadges)) {
            return null;
        }

        foreach ($mediaBadges as $badge) {
            if (is_string($badge) && filled($badge)) {
                return $badge;
            }
        }

        return null;
    }

    protected static function getMessageDirectionBadgeClasses(?string $direction): string
    {
        if ($direction === Message::DIRECTION_INBOUND) {
            return 'inline-flex items-center rounded-md border border-sky-200 bg-sky-50 px-2 py-1 text-xs text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200';
        }

        if ($direction === Message::DIRECTION_OUTBOUND) {
            return 'inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200';
        }

        return 'inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:text-gray-200';
    }

    protected static function getMessageReplyStatusBadgeClasses(Message $message): string
    {
        if ($message->hasSuccessfulAutoReply()) {
            return 'inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200';
        }

        return 'inline-flex items-center rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200';
    }

    protected static function getActivityLevelBadgeClasses(string $level): string
    {
        if ($level === 'error') {
            return 'inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200';
        }

        if ($level === 'warning') {
            return 'inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200';
        }

        return 'inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:text-gray-200';
    }

    protected static function getMediaStateBadgeClasses(string $tone): string
    {
        return match ($tone) {
            'danger' => 'inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200',
            'warning' => 'inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
            default => 'inline-flex items-center rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200',
        };
    }

    protected static function appendRateLimitedActivityBadges(array &$badges, ChannelActivityLog $log): void
    {
        $retryAfterSeconds = data_get($log->context, 'retry_after_seconds');
        if (is_numeric($retryAfterSeconds) && (int) $retryAfterSeconds > 0) {
            $badges[] = static::renderFeedBadge(
                sprintf('Retry after: %d сек', (int) $retryAfterSeconds),
                static::getWarningActivityDetailBadgeClasses(),
            );
        }

        $maxPerMinute = data_get($log->context, 'max_per_minute');
        if (is_numeric($maxPerMinute) && (int) $maxPerMinute > 0) {
            $badges[] = static::renderFeedBadge(
                sprintf('Лимит: %d/мин', (int) $maxPerMinute),
                static::getWarningActivityDetailBadgeClasses(),
            );
        }

        $route = data_get($log->context, 'route');
        if (filled($route)) {
            $badges[] = static::renderFeedBadge(
                sprintf('Route: %s', $route),
                static::getWarningActivityDetailBadgeClasses(),
            );
        }

        $requestIp = data_get($log->context, 'request_ip');
        if (filled($requestIp)) {
            $badges[] = static::renderFeedBadge(
                sprintf('IP: %s', $requestIp),
                static::getWarningActivityDetailBadgeClasses(),
            );
        }
    }

    protected static function isDedupeActivityEvent(string $event): bool
    {
        return in_array($event, [
            'webhook.duplicate_ignored',
            'webhook.duplicate_retry_reply',
        ], true);
    }

    protected static function getDedupeActivityBadgeClasses(): string
    {
        return 'inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200';
    }

    protected static function getDelayedActivityBadgeClasses(): string
    {
        return 'inline-flex items-center rounded-md border border-orange-200 bg-orange-50 px-2 py-1 text-xs text-orange-800 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-200';
    }

    protected static function getOutOfOrderActivityBadgeClasses(): string
    {
        return 'inline-flex items-center rounded-md border border-sky-200 bg-sky-50 px-2 py-1 text-xs text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200';
    }

    protected static function getWarningActivityDetailBadgeClasses(): string
    {
        return 'inline-flex items-center rounded-md border border-orange-200 bg-orange-50 px-2 py-1 text-xs text-orange-800 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-200';
    }

    protected static function formatOutboundReplyLink(Message $message): string
    {
        $replyTo = $message->replyTo;

        if ($replyTo === null) {
            return 'Ответ без связи';
        }

        if (filled($replyTo->provider_event_key)) {
            return 'Ответ на event key: '.$replyTo->provider_event_key;
        }

        return 'Ответ на inbound #'.$replyTo->id;
    }

    protected static function getOutboundReplyLinkBadgeClasses(): string
    {
        return 'inline-flex items-center rounded-md border border-teal-200 bg-teal-50 px-2 py-1 text-xs text-teal-700 dark:border-teal-500/30 dark:bg-teal-500/10 dark:text-teal-200';
    }

    protected static function getActivityCardClasses(bool $isDedupeEvent): string
    {
        if ($isDedupeEvent) {
            return 'rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 dark:border-amber-500/30 dark:bg-amber-500/10';
        }

        return 'rounded-xl border border-gray-200/80 px-4 py-3 dark:border-white/10';
    }
}
