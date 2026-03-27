<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?string $modelLabel = 'Контакт';

    protected static ?string $pluralModelLabel = 'Контакты';

    protected static ?string $navigationLabel = 'Контакты';

    protected static string|UnitEnum|null $navigationGroup = 'Аудитория';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof Contact) {
            return parent::getRecordTitle($record);
        }

        return sprintf('#%d %s', $record->id, $record->display_name);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'primaryIdentity.channel',
                'latestMessage',
            ])
            ->withCount('messages')
            ->withMax('messages', 'received_at');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Контакт')
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),
                        TextEntry::make('display_name')
                            ->label('Имя'),
                        TextEntry::make('name')
                            ->label('Сохранённое имя')
                            ->placeholder('Не задано'),
                        TextEntry::make('messages_count')
                            ->label('Сообщений')
                            ->state(fn (Contact $record): int => $record->messages_count ?? $record->messages()->count()),
                        TextEntry::make('messages_max_received_at')
                            ->label('Последнее сообщение')
                            ->placeholder('Сообщений ещё не было')
                            ->state(fn (Contact $record) => $record->messages_max_received_at)
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('created_at')
                            ->label('Создан')
                            ->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(2),
                Section::make('Идентификаторы')
                    ->schema([
                        RepeatableEntry::make('identities_list')
                            ->label('')
                            ->state(fn (Contact $record) => $record->identities()
                                ->with('channel')
                                ->orderBy('created_at')
                                ->get())
                            ->placeholder('Идентификаторов ещё нет.')
                            ->contained(false)
                            ->table([
                                TableColumn::make('Канал')->width('220px'),
                                TableColumn::make('Платформа')->width('130px'),
                                TableColumn::make('Внешний ID')->width('180px'),
                                TableColumn::make('Username'),
                            ])
                            ->schema([
                                TextEntry::make('channel.name')
                                    ->placeholder('—'),
                                TextEntry::make('platform')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => Channel::platformOptions()[$state] ?? $state),
                                TextEntry::make('external_user_id')
                                    ->placeholder('—'),
                                TextEntry::make('external_username')
                                    ->placeholder('—')
                                    ->formatStateUsing(fn (?string $state): string => filled($state) ? '@'.ltrim($state, '@') : '—'),
                            ]),
                    ]),
                Section::make('Последние сообщения')
                    ->schema([
                        RepeatableEntry::make('recent_messages')
                            ->label('')
                            ->state(fn (Contact $record) => $record->messages()
                                ->with(['channel', 'contactIdentity'])
                                ->latest('received_at')
                                ->limit(20)
                                ->get())
                            ->placeholder('Сообщений ещё не было.')
                            ->contained(false)
                            ->table([
                                TableColumn::make('Время')->width('170px'),
                                TableColumn::make('Канал')->width('220px'),
                                TableColumn::make('Направление')->width('130px'),
                                TableColumn::make('Текст'),
                            ])
                            ->schema([
                                TextEntry::make('received_at')
                                    ->dateTime('d.m.Y H:i:s'),
                                TextEntry::make('channel.name')
                                    ->placeholder('—'),
                                TextEntry::make('direction')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => $state === Message::DIRECTION_INBOUND ? 'Входящее' : $state)
                                    ->color(fn (string $state): string => $state === Message::DIRECTION_INBOUND ? 'info' : 'gray'),
                                TextEntry::make('text')
                                    ->placeholder('—')
                                    ->wrap(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('display_name')
                    ->label('Контакт')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('name', 'ilike', "%{$search}%")
                            ->orWhereHas('identities', function (Builder $identityQuery) use ($search): void {
                                $identityQuery
                                    ->where('external_user_id', 'ilike', "%{$search}%")
                                    ->orWhere('external_username', 'ilike', "%{$search}%");
                            });
                    }),
                TextColumn::make('primaryIdentity.channel.name')
                    ->label('Канал')
                    ->placeholder('—'),
                TextColumn::make('primaryIdentity.platform')
                    ->label('Платформа')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? (Channel::platformOptions()[$state] ?? $state) : '—'),
                TextColumn::make('primaryIdentity.external_user_id')
                    ->label('Внешний ID')
                    ->placeholder('—')
                    ->copyable(),
                TextColumn::make('primaryIdentity.external_username')
                    ->label('Username')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? '@'.ltrim($state, '@') : '—'),
                TextColumn::make('messages_count')
                    ->label('Сообщений')
                    ->badge()
                    ->sortable(),
                TextColumn::make('messages_max_received_at')
                    ->label('Последнее сообщение')
                    ->placeholder('—')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('messages_max_received_at', 'desc')
            ->emptyStateHeading('Контактов ещё нет')
            ->emptyStateDescription('Контакты появятся после первых входящих сообщений от внешней аудитории.')
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageContacts::route('/'),
        ];
    }
}
