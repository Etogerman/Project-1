<?php

namespace App\Filament\Resources\Channels;

use App\Filament\Resources\Channels\Pages\ManageChannels;
use App\Models\Channel;
use App\Services\Bots\RegisterChannelWebhookAction;
use App\Services\Bots\SyncChannelBotMetadataAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Notifications\Notification;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Throwable;
use UnitEnum;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static ?string $recordTitleAttribute = 'display_title';

    protected static ?string $modelLabel = 'Канал связи';

    protected static ?string $pluralModelLabel = 'Каналы связи';

    protected static ?string $navigationLabel = 'Каналы связи';

    protected static string | UnitEnum | null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 10;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

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
                Section::make('Канал связи')
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
                    ->columns(2),
                Section::make('Техжурнал')
                    ->schema([
                        RepeatableEntry::make('recent_activity_logs')
                            ->label('')
                            ->state(fn (Channel $record) => $record->activityLogs()->latest('created_at')->limit(15)->get())
                            ->placeholder('Событий ещё не было.')
                            ->contained(false)
                            ->table([
                                TableColumn::make('Время')->width('160px'),
                                TableColumn::make('Уровень')->width('110px'),
                                TableColumn::make('Событие')->width('170px'),
                                TableColumn::make('Сообщение'),
                            ])
                            ->schema([
                                TextEntry::make('created_at')
                                    ->dateTime('d.m.Y H:i:s'),
                                TextEntry::make('level')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => $state === 'error' ? 'Ошибка' : 'Info')
                                    ->color(fn (string $state): string => $state === 'error' ? 'danger' : 'gray'),
                                TextEntry::make('event')
                                    ->formatStateUsing(fn (string $state): string => match ($state) {
                                        'webhook.received' => 'Webhook',
                                        'bot.reply_sent' => 'Ответ',
                                        'bot.reply_failed' => 'Ошибка ответа',
                                        'bot.metadata_synced' => 'Sync metadata',
                                        'bot.metadata_sync_failed' => 'Ошибка metadata',
                                        'webhook.registration_started' => 'Регистрация webhook',
                                        'webhook.registration_completed' => 'Webhook готов',
                                        'webhook.registration_failed' => 'Ошибка webhook',
                                        default => $state,
                                    }),
                                TextEntry::make('message')
                                    ->wrap(),
                            ]),
                    ])
                    ->columns(2),
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
                    ->visible(fn (Channel $record): bool => $record->connection_type === Channel::CONNECTION_TYPE_BOT && filled($record->getToken()))
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
}
