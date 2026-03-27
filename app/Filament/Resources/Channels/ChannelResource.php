<?php

namespace App\Filament\Resources\Channels;

use App\Filament\Resources\Channels\Pages\ManageChannels;
use App\Models\Channel;
use App\Services\Bots\RegisterChannelWebhookAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
                            ->label('Тип подключения')
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
                            ->label('Тип подключения')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Channel::connectionTypeOptions()[$state] ?? $state),
                        IconEntry::make('is_active')
                            ->label('Активен')
                            ->boolean(),
                        TextEntry::make('webhook_secret_status')
                            ->label('Webhook')
                            ->state(fn (Channel $record): string => filled($record->getWebhookSecret()) ? 'Настроен' : 'Не настроен')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Настроен' ? 'success' : 'gray'),
                        TextEntry::make('created_at')
                            ->label('Создан')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('updated_at')
                            ->label('Обновлён')
                            ->dateTime('d.m.Y H:i'),
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
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('platform')
                    ->label('Платформа')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Channel::platformOptions()[$state] ?? $state)
                    ->color('info')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('connection_type')
                    ->label('Тип подключения')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Channel::connectionTypeOptions()[$state] ?? $state)
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Активен' : 'Отключен')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('webhook_secret_status')
                    ->label('Webhook')
                    ->state(fn (Channel $record): string => filled($record->getWebhookSecret()) ? 'Настроен' : 'Не настроен')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Настроен' ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Каналы связи ещё не добавлены')
            ->emptyStateDescription('Создайте первое подключение для бота.')
            ->recordActions([
                Action::make('registerWebhook')
                    ->label('Зарегистрировать webhook')
                    ->icon(Heroicon::OutlinedBolt)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Channel $record): bool => $record->is_active && $record->connection_type === Channel::CONNECTION_TYPE_BOT)
                    ->action(function (Channel $record): void {
                        try {
                            app(RegisterChannelWebhookAction::class)->handle($record);

                            Notification::make()
                                ->success()
                                ->title('Webhook зарегистрирован')
                                ->body('Секрет сохранён автоматически, webhook обновлён у внешней платформы.')
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
                ViewAction::make(),
                EditAction::make()
                    ->using(function (array $data, Channel $record): void {
                        $record->update(static::mutateChannelData($data, $record));
                    }),
            ])
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

        if ($token !== '') {
            Arr::set($data, 'credentials', ['token' => $token]);

            return $data;
        }

        if ($record?->credentials !== null) {
            Arr::set($data, 'credentials', $record->credentials);

            return $data;
        }

        Arr::forget($data, 'credentials');

        return $data;
    }
}
