<?php

namespace App\Filament\Resources\Channels;

use App\Filament\Resources\Channels\Pages\ManageChannels;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Message;
use App\Services\Bots\RegisterChannelWebhookAction;
use App\Services\Bots\SyncChannelBotMetadataAction;
use App\Services\Dialogs\MessageChronology;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static ?string $recordTitleAttribute = 'display_title';

    protected static ?string $modelLabel = 'Канал связи';

    protected static ?string $pluralModelLabel = 'Каналы связи';

    protected static ?string $navigationLabel = 'Каналы связи';

    protected static string|UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Канал связи')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        Select::make('platform')
                            ->label('Платформа')
                            ->options(Channel::platformOptions())
                            ->required()
                            ->native(false),
                        Select::make('connection_type')
                            ->label('Тип')
                            ->options(Channel::connectionTypeOptions())
                            ->default(Channel::CONNECTION_TYPE_BOT)
                            ->required()
                            ->native(false),
                        Select::make('auto_reply_mode')
                            ->label('Режим автоответа')
                            ->options(Channel::autoReplyModeOptions())
                            ->default(Channel::AUTO_REPLY_MODE_RULES_ONLY)
                            ->required()
                            ->native(false),
                        TextInput::make('credentials.token')
                            ->label('Токен')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->afterStateHydrated(function (TextInput $component, string $operation): void {
                                if ($operation === 'edit') {
                                    $component->state(null);
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->dehydrated(fn (?string $state, string $operation): bool => $operation === 'create' || filled($state))
                            ->helperText('Оставьте пустым при редактировании, чтобы сохранить текущий токен. Webhook и секрет регистрируются отдельным действием.')
                            ->maxLength(65535),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Сводка канала')
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
                            ->formatStateUsing(fn (string $state): string => Channel::connectionTypeOptions()[$state] ?? $state),
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
                        TextEntry::make('bot_name')
                            ->label('Имя бота')
                            ->state(fn (Channel $record): string => filled($record->bot_name) ? (string) $record->bot_name : 'Не загружено'),
                        TextEntry::make('bot_username')
                            ->label('Username')
                            ->state(fn (Channel $record): string => $record->getBotUsernameLabel() ?? 'Не загружен')
                            ->url(fn (Channel $record): ?string => $record->getBotProfileUrl())
                            ->openUrlInNewTab(),
                        TextEntry::make('bot_external_id')
                            ->label('Внешний ID')
                            ->state(fn (Channel $record): string => filled($record->bot_external_id) ? (string) $record->bot_external_id : 'Не загружен')
                            ->copyable(),
                        IconEntry::make('is_active')
                            ->label('Активен')
                            ->boolean(),
                        TextEntry::make('webhook_secret_status')
                            ->label('Webhook')
                            ->state(fn (Channel $record): string => $record->getWebhookStatusLabel())
                            ->badge()
                            ->color(fn (Channel $record): string => $record->getWebhookStatusColor()),
                        TextEntry::make('last_webhook_received_at')
                            ->label('Последний webhook')
                            ->placeholder('Ещё не было')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_reply_sent_at')
                            ->label('Последний ответ')
                            ->placeholder('Ещё не было')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_error_at')
                            ->label('Последняя ошибка')
                            ->placeholder('Ошибок не было')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_error_message')
                            ->label('Текст ошибки')
                            ->state(fn (Channel $record): string => filled($record->last_error_message) ? (string) $record->last_error_message : 'Ошибок не было')
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
                Section::make('Последний webhook')
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
                            ->state(fn (Channel $record): ?string => static::resolveLatestSavedMessage($record)?->text)
                            ->wrap()
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
                Section::make('Лента сообщений')
                    ->schema([
                        TextEntry::make('recent_messages_feed')
                            ->label('Последние сохранённые сообщения')
                            ->state(fn (Channel $record): HtmlString => static::renderRecentSavedMessages($record))
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Техжурнал')
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
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bot_name')
                    ->label('Имя бота')
                    ->state(fn (Channel $record): string => filled($record->bot_name) ? (string) $record->bot_name : '—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bot_username')
                    ->label('Username')
                    ->state(fn (Channel $record): string => $record->getBotUsernameLabel() ?? '—')
                    ->url(fn (Channel $record): ?string => $record->getBotProfileUrl())
                    ->openUrlInNewTab()
                    ->searchable(['bot_username'])
                    ->sortable(),
                TextColumn::make('health_status')
                    ->label('Состояние')
                    ->state(fn (Channel $record): string => $record->getHealthStatusLabel())
                    ->badge()
                    ->color(fn (Channel $record): string => $record->getHealthStatusColor()),
                TextColumn::make('platform')
                    ->label('Платформа')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Channel::platformOptions()[$state] ?? $state)
                    ->color('info')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('connection_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Channel::connectionTypeOptions()[$state] ?? $state)
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('auto_reply_mode')
                    ->label('Автоответ')
                    ->state(fn (Channel $record): string => $record->getAutoReplyModeLabel())
                    ->badge()
                    ->color(fn (Channel $record): string => static::getAutoReplyModeColor($record->auto_reply_mode))
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Активен')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Активен' : 'Отключен')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('webhook_secret_status')
                    ->label('Webhook')
                    ->state(fn (Channel $record): string => $record->getWebhookStatusLabel())
                    ->badge()
                    ->color(fn (Channel $record): string => $record->getWebhookStatusColor()),
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
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error_message')
                    ->label('Текст ошибки')
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
            ->columnManager()
            ->deferColumnManager(false)
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
                    ->tooltip('Зарегистрировать webhook')
                    ->requiresConfirmation()
                    ->visible(fn (Channel $record): bool => $record->is_active && $record->connection_type === Channel::CONNECTION_TYPE_BOT)
                    ->action(function (Channel $record): void {
                        try {
                            app(RegisterChannelWebhookAction::class)->handle($record);

                            Notification::make()
                                ->success()
                                ->title('Webhook зарегистрирован')
                                ->body('Секрет сохранён автоматически, webhook обновлён, данные бота синхронизированы.')
                                ->send();
                        } catch (Throwable $throwable) {
                            report($throwable);

                            Notification::make()
                                ->danger()
                                ->title('Не удалось зарегистрировать webhook')
                                ->body($throwable->getMessage())
                                ->send();

                            throw $throwable;
                        }
                    }),
                Action::make('syncBotMetadata')
                    ->label('Обновить данные бота')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Обновить данные бота')
                    ->visible(fn (Channel $record): bool => $record->connection_type === Channel::CONNECTION_TYPE_BOT && $record->hasBotTokenConfigured())
                    ->action(function (Channel $record): void {
                        try {
                            app(SyncChannelBotMetadataAction::class)->handle($record);

                            Notification::make()
                                ->success()
                                ->title('Канал проверен')
                                ->body('Доступ к боту подтверждён, данные бота синхронизированы с платформой.')
                                ->send();
                        } catch (Throwable $throwable) {
                            report($throwable);

                            Notification::make()
                                ->danger()
                                ->title('Не удалось обновить данные бота')
                                ->body($throwable->getMessage())
                                ->send();

                            throw $throwable;
                        }
                    }),
                ViewAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Просмотр'),
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить')
                    ->using(function (array $data, Channel $record): void {
                        $record->update(static::mutateChannelData($data, $record));
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([]);
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
        $token = trim((string) data_get($data, 'credentials.token', ''));
        $credentials = $record?->credentials ?? [];

        if (static::shouldClearBotMetadata($data, $record, $token)) {
            $data = static::clearBotMetadata($data);
        }

        if ($token !== '') {
            Arr::set($credentials, Channel::CREDENTIAL_TOKEN, $token);
            Arr::set($data, 'credentials', $credentials);

            return $data;
        }

        if ($record?->credentials !== null) {
            Arr::set($data, 'credentials', $record->credentials);

            return $data;
        }

        Arr::forget($data, 'credentials');

        return $data;
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

            $badgeMarkup = implode('', $badges);

            return sprintf(
                '<div class="rounded-xl border border-gray-200/80 px-4 py-3 dark:border-white/10"><div class="mb-3 flex flex-wrap gap-2">%s</div><div class="whitespace-pre-wrap break-words text-sm text-gray-950 dark:text-white">%s</div></div>',
                $badgeMarkup,
                e(filled($message->text) ? (string) $message->text : '—'),
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
                    sprintf('Уровень: %s', $log->level === 'error' ? 'Ошибка' : 'Info'),
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

    protected static function getAutoReplyModeColor(?string $autoReplyMode): string
    {
        return match ($autoReplyMode) {
            Channel::AUTO_REPLY_MODE_RULES_ONLY, Channel::AUTO_REPLY_MODE_LEGACY_DEFAULT => 'info',
            default => 'gray',
        };
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

        return 'inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-700 dark:border-white/10 dark:text-gray-200';
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
