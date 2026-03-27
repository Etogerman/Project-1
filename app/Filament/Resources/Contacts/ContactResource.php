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
use Illuminate\Support\HtmlString;
use JsonException;
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
                                ->orderByDesc('received_at')
                                ->orderByDesc('id')
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
                Section::make('Диагностика webhook')
                    ->schema([
                        TextEntry::make('diagnostic_external_message_id')
                            ->label('Последний внешний message ID')
                            ->placeholder('Не задан')
                            ->state(fn (Contact $record): ?string => static::resolveLatestMessage($record)?->external_message_id)
                            ->copyable(),
                        TextEntry::make('diagnostic_received_at')
                            ->label('Распарсенное received_at')
                            ->placeholder('Не задано')
                            ->state(fn (Contact $record) => static::resolveLatestMessage($record)?->received_at)
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('diagnostic_raw_payload')
                            ->label('Последний raw payload')
                            ->placeholder('Сообщений ещё не было')
                            ->state(fn (Contact $record): ?string => filled(static::resolveLatestMessage($record)?->raw_payload)
                                ? static::encodeJsonPayload(static::resolveLatestMessage($record)->raw_payload)
                                : null)
                            ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(sprintf(
                                '<pre class="whitespace-pre-wrap break-all text-xs">%s</pre>',
                                e($state ?? '—'),
                            )))
                            ->html()
                            ->copyable()
                            ->columnSpanFull(),
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
                    ->sortable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('display_name')
                    ->label('Контакт')
                    ->toggleable()
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
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('primaryIdentity.platform')
                    ->label('Платформа')
                    ->toggleable()
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? (Channel::platformOptions()[$state] ?? $state) : '—'),
                TextColumn::make('primaryIdentity.external_user_id')
                    ->label('Внешний ID')
                    ->toggleable()
                    ->placeholder('—')
                    ->copyable(),
                TextColumn::make('primaryIdentity.external_username')
                    ->label('Username')
                    ->toggleable()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? '@'.ltrim($state, '@') : '—'),
                TextColumn::make('messages_count')
                    ->label('Сообщений')
                    ->toggleable()
                    ->badge()
                    ->sortable(),
                TextColumn::make('messages_max_received_at')
                    ->label('Последнее сообщение')
                    ->toggleable()
                    ->placeholder('—')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->columnManager()
            ->deferColumnManager(false)
            ->reorderableColumns()
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

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function encodeJsonPayload(array $payload): string
    {
        try {
            return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'Не удалось сериализовать payload.';
        }
    }

    protected static function resolveLatestMessage(Contact $record): ?Message
    {
        static $cache = [];

        /** @var ?Message $message */
        $message = $cache[$record->getKey()] ??= $record->messages()
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        return $message;
    }
}
