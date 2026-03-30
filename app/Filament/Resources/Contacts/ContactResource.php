<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Models\User;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Contacts\DeleteContactAction;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            ->addSelect([
                'primary_phone_raw' => static::buildPrimaryPhoneSubquery('phone_raw'),
                'primary_phone_normalized' => static::buildPrimaryPhoneSubquery('phone_normalized'),
                'phone_count' => static::buildPhoneCountSubquery(),
            ])
            ->with([
                'assignedUser',
                'primaryIdentity.channel',
                'latestMessage.channel',
            ])
            ->withCount('messages')
            ->withMax(['messages as latest_message_id' => fn (Builder $query): Builder => $query], 'id')
            ->withMax(['messages as latest_inbound_user_message_id' => fn (Builder $query): Builder => $query
                ->where('message_kind', Message::KIND_INBOUND_USER)], 'id')
            ->withMax(['messages as latest_outbound_manual_reply_message_id' => fn (Builder $query): Builder => $query
                ->where('message_kind', Message::KIND_OUTBOUND_MANUAL_REPLY)], 'id');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Контакт')
                    ->schema([
                        TextEntry::make('display_name')
                            ->label('Контакт'),
                        TextEntry::make('primary_phone_raw')
                            ->label('Телефон')
                            ->placeholder('—')
                            ->state(fn (Contact $record): ?string => static::resolvePrimaryPhoneRaw($record))
                            ->copyable(fn (Contact $record): bool => filled(static::resolvePrimaryPhoneRaw($record)))
                            ->copyableState(fn (Contact $record): ?string => static::resolvePrimaryPhoneRaw($record)),
                        TextEntry::make('primaryIdentity.platform')
                            ->label('Платформа')
                            ->badge()
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? (Channel::platformOptions()[$state] ?? $state) : '—'),
                        TextEntry::make('assignedUser.name')
                            ->label('Ответственный')
                            ->placeholder('Свободен'),
                        TextEntry::make('primaryIdentity.channel.name')
                            ->label('Канал')
                            ->placeholder('—'),
                    ])
                    ->columns(5)
                    ->columnSpanFull(),
                Section::make('Профиль')
                    ->schema([
                        ViewEntry::make('contact_profile')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.contact-profile')
                            ->viewData(fn (Contact $record): array => static::buildContactProfileViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Анкета')
                    ->schema([
                        ViewEntry::make('contact_collector_status')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.contact-collector-status')
                            ->viewData(fn (Contact $record): array => static::buildCollectorStatusViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Работа с контактом')
                    ->schema([
                        ViewEntry::make('ownership_controls')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.ownership-controls')
                            ->viewData(fn (Contact $record): array => static::buildOwnershipControlsViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Телефоны')
                    ->schema([
                        ViewEntry::make('phone_numbers')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.phone-numbers')
                            ->viewData(fn (Contact $record): array => static::buildPhoneNumbersViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('История сообщений')
                    ->schema([
                        TextEntry::make('conversation_history')
                            ->label('Последние сообщения контакта')
                            ->state(fn (Contact $record): HtmlString => static::renderConversationHistory($record))
                            ->html()
                            ->columnSpanFull(),
                        ViewEntry::make('conversation_reply_composer')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.inline-reply-composer')
                            ->viewData(fn (Contact $record): array => static::buildInlineReplyComposerViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Подробности')
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),
                        TextEntry::make('name')
                            ->label('Сохранённое имя')
                            ->placeholder('Не задано'),
                        TextEntry::make('primaryIdentity.external_user_id')
                            ->label('Внешний ID')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('primaryIdentity.external_username')
                            ->label('Username')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? '@'.ltrim($state, '@') : '—'),
                        TextEntry::make('messages_count')
                            ->label('Сообщений')
                            ->state(fn (Contact $record): int => $record->messages_count ?? $record->messages()->count()),
                        TextEntry::make('messages_max_received_at')
                            ->label('Последний webhook')
                            ->placeholder('Сообщений ещё не было')
                            ->state(fn (Contact $record) => static::resolveLatestInboundMessage($record)?->created_at)
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('created_at')
                            ->label('Создан')
                            ->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
                Section::make('Последнее сообщение')
                    ->schema([
                        TextEntry::make('latest_message_received_at')
                            ->label('Получено')
                            ->placeholder('Сообщений ещё не было')
                            ->state(fn (Contact $record) => static::resolveLatestConversationMessage($record)?->received_at)
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('latest_message_channel')
                            ->label('Канал')
                            ->placeholder('—')
                            ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->channel?->name),
                        TextEntry::make('latest_message_direction')
                            ->label('Направление')
                            ->placeholder('—')
                            ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->direction)
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => static::formatMessageDirection($state))
                            ->color(fn (?string $state): string => static::getMessageDirectionColor($state)),
                        TextEntry::make('latest_message_external_id')
                            ->label('Внешний message ID')
                            ->placeholder('Не задан')
                            ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->external_message_id)
                            ->copyable(),
                        TextEntry::make('latest_message_chat_id')
                            ->label('Chat ID')
                            ->placeholder('Не задан')
                            ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->external_chat_id)
                            ->copyable(),
                        TextEntry::make('latest_message_reply_link')
                            ->label('Связь')
                            ->placeholder('—')
                            ->state(fn (Contact $record): ?string => static::formatConversationReplyLink(static::resolveLatestConversationMessage($record)))
                            ->visible(fn (Contact $record): bool => static::resolveLatestConversationMessage($record)?->direction === Message::DIRECTION_OUTBOUND),
                        TextEntry::make('latest_message_text')
                            ->label('Текст')
                            ->placeholder('—')
                            ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->text)
                            ->wrap()
                            ->columnSpanFull(),
                        TextEntry::make('latest_message_saved_at')
                            ->label('Сохранено в системе')
                            ->placeholder('Не задано')
                            ->state(fn (Contact $record) => static::resolveLatestConversationMessage($record)?->created_at)
                            ->dateTime('d.m.Y H:i:s'),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
                Section::make('Диагностика webhook')
                    ->schema([
                        TextEntry::make('diagnostic_external_message_id')
                            ->label('Последний внешний message ID')
                            ->placeholder('Не задан')
                            ->state(fn (Contact $record): ?string => static::resolveLatestInboundMessage($record)?->external_message_id)
                            ->copyable(),
                        TextEntry::make('diagnostic_provider_event_key')
                            ->label('Provider event key')
                            ->placeholder('Не задан')
                            ->state(fn (Contact $record): ?string => static::resolveLatestInboundMessage($record)?->provider_event_key)
                            ->copyable(),
                        TextEntry::make('diagnostic_received_at')
                            ->label('Распарсенное received_at')
                            ->placeholder('Не задано')
                            ->state(fn (Contact $record) => static::resolveLatestInboundMessage($record)?->received_at)
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('diagnostic_auto_reply_sent_at')
                            ->label('Автоответ отправлен')
                            ->placeholder('Ответ ещё не отправлен')
                            ->state(fn (Contact $record) => static::resolveLatestInboundMessage($record)?->auto_reply_sent_at)
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('diagnostic_reply_status')
                            ->label('Статус автоответа')
                            ->placeholder('Сообщений ещё не было')
                            ->state(fn (Contact $record): ?string => static::formatMessageReplyStatus(static::resolveLatestInboundMessage($record)))
                            ->badge()
                            ->color(fn (Contact $record): string => static::getMessageReplyStatusColor(static::resolveLatestInboundMessage($record))),
                        TextEntry::make('diagnostic_raw_payload')
                            ->label('Последний raw payload')
                            ->placeholder('Сообщений ещё не было')
                            ->state(fn (Contact $record): ?string => filled(static::resolveLatestInboundMessage($record)?->raw_payload)
                                ? static::encodeJsonPayload(static::resolveLatestInboundMessage($record)->raw_payload)
                                : null)
                            ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(sprintf(
                                '<pre class="whitespace-pre-wrap break-all text-xs">%s</pre>',
                                e($state ?? '—'),
                            )))
                            ->html()
                            ->copyable()
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
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
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::applyTableSearch($query, $search)),
                TextColumn::make('inbox_status')
                    ->label('Статус')
                    ->state(fn (Contact $record): string => static::formatInboxStatus($record))
                    ->badge()
                    ->color(fn (Contact $record): string => static::getInboxStatusColor($record))
                    ->toggleable(),
                TextColumn::make('assignedUser.name')
                    ->label('Ответственный')
                    ->toggleable()
                    ->placeholder('Свободен'),
                TextColumn::make('primary_phone_raw')
                    ->label('Телефон')
                    ->toggleable()
                    ->placeholder('—')
                    ->copyable(fn (Contact $record): bool => filled($record->getAttribute('primary_phone_raw')))
                    ->copyableState(fn (Contact $record): ?string => $record->getAttribute('primary_phone_raw')),
                TextColumn::make('latest_message_text')
                    ->label('Последнее сообщение')
                    ->toggleable()
                    ->placeholder('—')
                    ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->text)
                    ->limit(60)
                    ->tooltip(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->text),
                TextColumn::make('latest_message_kind')
                    ->label('Тип')
                    ->toggleable()
                    ->placeholder('—')
                    ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->message_kind)
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::formatMessageKind($state))
                    ->color(fn (?string $state): string => static::getMessageKindColor($state)),
                TextColumn::make('latest_message_channel')
                    ->label('Канал')
                    ->toggleable()
                    ->placeholder('—')
                    ->state(fn (Contact $record): ?string => static::formatLatestMessageChannel(static::resolveLatestConversationMessage($record))),
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone_count')
                    ->label('Телефонов')
                    ->state(fn (Contact $record): int => (int) ($record->getAttribute('phone_count') ?? 0))
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latest_message_received_at')
                    ->label('Активность')
                    ->toggleable()
                    ->placeholder('—')
                    ->state(fn (Contact $record) => static::resolveLatestConversationMessage($record)?->received_at)
                    ->dateTime('d.m.Y H:i')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('latest_message_id', $direction)
                        ->orderBy('contacts.id', $direction)),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('requires_manual_reply')
                    ->label('Требует ответа')
                    ->query(fn (Builder $query): Builder => static::applyRequiresManualReplyFilter($query)),
                Filter::make('assigned_to_me')
                    ->label('Мои')
                    ->query(fn (Builder $query): Builder => static::applyAssignedToMeFilter($query)),
                Filter::make('unassigned_contacts')
                    ->label('Свободные')
                    ->query(fn (Builder $query): Builder => $query->whereNull('assigned_user_id')),
                Filter::make('has_phone')
                    ->label('Есть телефон')
                    ->query(fn (Builder $query): Builder => $query->whereHas('phoneNumbers')),
                Filter::make('without_phone')
                    ->label('Без телефона')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('phoneNumbers')),
            ])
            ->columnManager()
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->defaultSort('latest_message_id', 'desc')
            ->emptyStateHeading('Контактов ещё нет')
            ->emptyStateDescription('Контакты появятся после первых входящих сообщений от внешней аудитории.')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                ViewAction::make()
                    ->label('Просмотр')
                    ->icon(null)
                    ->button()
                    ->outlined()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->mountUsing(function (Action $action, ?Schema $schema, ManageContacts $livewire): void {
                        $schema?->fill();
                        $livewire->inlineReplyText = '';
                        $livewire->showAssignContactDialog = false;
                        $livewire->selectedAssigneeId = '';
                        $livewire->showEditPhoneDialog = false;
                        $livewire->editingPhoneId = '';
                        $livewire->editingPhoneRaw = '';
                        $livewire->showEditProfileDialog = false;
                        $livewire->editingFirstName = '';
                        $livewire->editingLastName = '';
                        $livewire->editingAgeYears = '';
                        $livewire->editingAgeRange = '';
                        $livewire->editingBirthDate = '';
                        $livewire->editingCountry = '';
                        $livewire->editingCity = '';
                        $livewire->showDeletePhoneDialog = false;
                        $livewire->deletingPhoneId = '';
                        $livewire->deletingPhoneLabel = '';
                        $livewire->showDeleteContactDialog = false;
                        $livewire->deletingContactLabel = '';
                    }),
                DeleteAction::make()
                    ->label('Удалить')
                    ->icon(null)
                    ->button()
                    ->authorize(fn (): bool => (bool) (auth()->user()?->is_active && auth()->user()?->is_admin))
                    ->modalHeading('Удалить клиента?')
                    ->modalDescription('Контакт будет удалён вместе с телефонами, сообщениями и идентичностями.')
                    ->successNotificationTitle('Клиент удалён')
                    ->using(function (Contact $record): bool {
                        app(DeleteContactAction::class)->handle($record);

                        return true;
                    }),
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

    protected static function resolveLatestInboundMessage(Contact $record): ?Message
    {
        return $record->messages()
            ->with('channel')
            ->where('direction', Message::DIRECTION_INBOUND)
            ->orderByDesc('id')
            ->first();
    }

    protected static function resolveLatestConversationMessage(Contact $record): ?Message
    {
        if ($record->relationLoaded('latestMessage')) {
            return $record->latestMessage;
        }

        return $record->messages()
            ->with(['channel', 'replyTo'])
            ->orderByDesc('id')
            ->first();
    }

    protected static function contactRequiresManualReply(Contact $record): bool
    {
        $latestInboundUserMessageId = $record->getAttribute('latest_inbound_user_message_id');
        $latestOutboundManualReplyMessageId = $record->getAttribute('latest_outbound_manual_reply_message_id');

        if (! filled($latestInboundUserMessageId)) {
            return false;
        }

        if (! filled($latestOutboundManualReplyMessageId)) {
            return true;
        }

        return (int) $latestInboundUserMessageId > (int) $latestOutboundManualReplyMessageId;
    }

    protected static function formatInboxStatus(Contact $record): string
    {
        return static::contactRequiresManualReply($record)
            ? 'Требует ответа'
            : 'Нет новых';
    }

    protected static function getInboxStatusColor(Contact $record): string
    {
        return static::contactRequiresManualReply($record) ? 'warning' : 'success';
    }

    protected static function applyRequiresManualReplyFilter(Builder $query): Builder
    {
        return $query
            ->whereExists(function (QueryBuilder $subquery): void {
                $subquery
                    ->selectRaw('1')
                    ->from('messages')
                    ->whereColumn('messages.contact_id', 'contacts.id')
                    ->where('messages.message_kind', Message::KIND_INBOUND_USER);
            })
            ->where(function (Builder $query): Builder {
                return $query
                    ->whereNotExists(function (QueryBuilder $subquery): void {
                        $subquery
                            ->selectRaw('1')
                            ->from('messages')
                            ->whereColumn('messages.contact_id', 'contacts.id')
                            ->where('messages.message_kind', Message::KIND_OUTBOUND_MANUAL_REPLY);
                    })
                    ->orWhereRaw(
                        '(select max(id) from messages where messages.contact_id = contacts.id and messages.message_kind = ?) > (select max(id) from messages where messages.contact_id = contacts.id and messages.message_kind = ?)',
                        [Message::KIND_INBOUND_USER, Message::KIND_OUTBOUND_MANUAL_REPLY],
                    );
            });
    }

    protected static function applyAssignedToMeFilter(Builder $query): Builder
    {
        $currentUserId = static::resolveCurrentUserId();

        if ($currentUserId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('assigned_user_id', $currentUserId);
    }

    protected static function applyTableSearch(Builder $query, string $search): Builder
    {
        $normalizedPhoneSearch = AddContactPhoneAction::normalizePhone($search);

        return $query->where(function (Builder $query) use ($search, $normalizedPhoneSearch): void {
            $query->where('name', 'ilike', "%{$search}%")
                ->orWhereHas('identities', function (Builder $identityQuery) use ($search): void {
                    $identityQuery
                        ->where('external_user_id', 'ilike', "%{$search}%")
                        ->orWhere('external_username', 'ilike', "%{$search}%");
                });

            if ($normalizedPhoneSearch !== '') {
                $query->orWhereHas('phoneNumbers', function (Builder $phoneQuery) use ($normalizedPhoneSearch): void {
                    $phoneQuery->where('phone_normalized', 'ilike', "%{$normalizedPhoneSearch}%");
                });
            }
        });
    }

    protected static function buildPrimaryPhoneSubquery(string $column): Builder
    {
        return ContactPhoneNumber::query()
            ->select($column)
            ->whereColumn('contact_id', 'contacts.id')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->limit(1);
    }

    protected static function buildPhoneCountSubquery(): Builder
    {
        return ContactPhoneNumber::query()
            ->selectRaw('count(*)')
            ->whereColumn('contact_id', 'contacts.id');
    }

    /**
     * @return array{
     *     firstName: ?string,
     *     lastName: ?string,
     *     effectiveAgeLabel: string,
     *     ageRangeLabel: string,
     *     birthDateLabel: string,
     *     country: string,
     *     city: string,
     *     messengerName: string,
     *     ageRangeOptions: array<string, string>
     * }
     */
    protected static function buildContactProfileViewData(Contact $record): array
    {
        $effectiveAgeYears = $record->effective_age_years;
        $ageSourceLabel = $record->birth_date !== null
            ? 'из даты рождения'
            : ($record->age_years !== null ? 'указан вручную' : null);

        $effectiveAgeLabel = $effectiveAgeYears !== null
            ? sprintf('%d лет%s', $effectiveAgeYears, $ageSourceLabel !== null ? ' ('.$ageSourceLabel.')' : '')
            : '—';

        return [
            'firstName' => $record->first_name,
            'lastName' => $record->last_name,
            'effectiveAgeLabel' => $effectiveAgeLabel,
            'ageRangeLabel' => Contact::formatAgeRange($record->age_range),
            'birthDateLabel' => $record->birth_date?->format('d.m.Y') ?? '—',
            'country' => $record->country ?? '—',
            'city' => $record->city ?? '—',
            'messengerName' => $record->name ?? '—',
            'ageRangeOptions' => Contact::ageRangeOptions(),
        ];
    }

    /**
     * @return array{
     *     statusLabel: string,
     *     statusTone: string,
     *     currentStepLabel: string,
     *     attemptsLabel: string,
     *     canResume: bool,
     *     firstName: string,
     *     country: string,
     *     city: string,
     *     ageRange: string
     * }
     */
    protected static function buildCollectorStatusViewData(Contact $record): array
    {
        return [
            'statusLabel' => static::formatDataCollectionStatus($record->data_collection_status),
            'statusTone' => static::getDataCollectionStatusTone($record->data_collection_status),
            'currentStepLabel' => $record->isInDataCollection()
                ? static::formatDataCollectionField($record->data_collection_current_field)
                : '—',
            'attemptsLabel' => $record->isInDataCollection()
                ? (string) ((int) $record->data_collection_attempts_count)
                : '—',
            'canResume' => static::canResumeDataCollection($record),
            'firstName' => $record->first_name ?: '—',
            'country' => $record->country ?: '—',
            'city' => $record->city ?: '—',
            'ageRange' => Contact::formatAgeRange($record->age_range),
        ];
    }

    protected static function canResumeDataCollection(Contact $record): bool
    {
        if ($record->data_collection_status === Contact::DATA_COLLECTION_STATUS_ACTIVE) {
            return false;
        }

        if (! filled(static::resolvePrimaryPhoneRaw($record))) {
            return false;
        }

        return app(ResolveNextDataCollectionFieldAction::class)->handle($record) !== null;
    }

    protected static function resolvePrimaryPhoneRaw(Contact $record): ?string
    {
        $primaryPhoneRaw = $record->getAttribute('primary_phone_raw');

        if (filled($primaryPhoneRaw)) {
            return $primaryPhoneRaw;
        }

        return $record->phoneNumbers()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->value('phone_raw');
    }

    protected static function renderConversationHistory(Contact $record): HtmlString
    {
        $messages = $record->messages()
            ->with(['channel', 'replyTo'])
            ->orderByRaw('coalesce(received_at, created_at) desc')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        if ($messages->isEmpty()) {
            return new HtmlString(view('filament.contacts.partials.conversation-chat', [
                'messages' => [],
            ])->render());
        }

        $sortedMessages = $messages
            ->sort(function (Message $left, Message $right): int {
                $leftAt = $left->received_at ?? $left->created_at;
                $rightAt = $right->received_at ?? $right->created_at;

                $comparison = ($leftAt?->getTimestamp() ?? 0) <=> ($rightAt?->getTimestamp() ?? 0);

                if ($comparison !== 0) {
                    return $comparison;
                }

                return $left->id <=> $right->id;
            })
            ->values();

        return new HtmlString(view('filament.contacts.partials.conversation-chat', [
            'messages' => static::buildConversationHistoryViewData($sortedMessages),
        ])->render());
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    protected static function buildConversationHistoryViewData(Collection $messages): array
    {
        return $messages
            ->map(function (Message $message): array {
                $messageAt = $message->received_at ?? $message->created_at;

                return [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'kind' => $message->message_kind ?? 'unknown',
                    'display_text' => static::resolveConversationDisplayText($message),
                    'time_label' => $messageAt?->format('H:i') ?? '—',
                    'timestamp_label' => $messageAt?->format('H:i d.m.Y') ?? '—',
                    'date_key' => $messageAt?->format('Y-m-d') ?? 'unknown-date',
                    'date_label' => static::formatConversationDateLabel($messageAt),
                    'is_inbound' => $message->direction === Message::DIRECTION_INBOUND,
                    'is_outbound' => $message->direction === Message::DIRECTION_OUTBOUND,
                ];
            })
            ->all();
    }

    protected static function resolveConversationDisplayText(Message $message): string
    {
        if (filled($message->text)) {
            return (string) $message->text;
        }

        return match ($message->message_kind) {
            Message::KIND_INBOUND_CONTACT_SHARE => 'Поделился номером телефона',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => 'Спасибо, номер получили.',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'Автоответ',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'Ответ оператора',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'Вопрос анкеты',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'Спасибо, данные сохранили.',
            default => 'Системное сообщение',
        };
    }

    protected static function formatConversationDateLabel(?Carbon $messageAt): string
    {
        if (! $messageAt instanceof Carbon) {
            return '—';
        }

        $today = now()->startOfDay();
        $messageDate = $messageAt->copy()->startOfDay();

        if ($messageDate->equalTo($today)) {
            return 'Сегодня';
        }

        if ($messageDate->equalTo($today->copy()->subDay())) {
            return 'Вчера';
        }

        return $messageAt->format('d.m.Y');
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

    protected static function formatMessageReplyStatus(?Message $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return $message->hasSuccessfulAutoReply()
            ? 'Ответ отправлен'
            : 'Ответ еще не отправлен';
    }

    protected static function getMessageReplyStatusColor(?Message $message): string
    {
        if ($message === null) {
            return 'gray';
        }

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

    protected static function getMessageKindColor(?string $messageKind): string
    {
        return match ($messageKind) {
            Message::KIND_INBOUND_USER => 'info',
            Message::KIND_INBOUND_CONTACT_SHARE => 'gray',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'warning',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => 'primary',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'success',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'info',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'success',
            default => 'gray',
        };
    }

    protected static function formatDataCollectionStatus(?string $status): string
    {
        return match ($status) {
            Contact::DATA_COLLECTION_STATUS_ACTIVE => 'В процессе',
            Contact::DATA_COLLECTION_STATUS_COMPLETED => 'Завершена',
            default => 'Не запущена',
        };
    }

    protected static function getDataCollectionStatusTone(?string $status): string
    {
        return match ($status) {
            Contact::DATA_COLLECTION_STATUS_ACTIVE => 'warning',
            Contact::DATA_COLLECTION_STATUS_COMPLETED => 'success',
            default => 'gray',
        };
    }

    protected static function formatDataCollectionField(?string $field): string
    {
        return match ($field) {
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME => 'Имя',
            Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY => 'Город проживания',
            Contact::DATA_COLLECTION_FIELD_COUNTRY => 'Страна',
            Contact::DATA_COLLECTION_FIELD_CITY => 'Город',
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => 'Возраст',
            default => '—',
        };
    }

    protected static function formatLatestMessageChannel(?Message $message): ?string
    {
        $channel = $message?->channel;

        if ($channel === null) {
            return null;
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel;
    }

    protected static function buildOwnershipControlsViewData(Contact $record): array
    {
        $record->loadMissing('assignedUser');

        return [
            'assignedUserLabel' => static::formatAssignedUserLabel($record),
            'availableAssignees' => static::getAssignableUserOptions(),
            'ownershipHint' => static::getOwnershipHint($record),
            'autoReplyEnabled' => $record->isAutoReplyEnabled(),
            'autoReplyStatusLabel' => $record->isAutoReplyEnabled() ? 'Включены' : 'Отключены',
        ];
    }

    /**
     * @return array{phoneNumbers: array<int, array{id:int, phone:string, source:string, is_primary:bool}>}
     */
    protected static function buildPhoneNumbersViewData(Contact $record): array
    {
        $phoneNumbers = $record->relationLoaded('phoneNumbers')
            ? $record->phoneNumbers
            : $record->phoneNumbers()->get();

        return [
            'phoneNumbers' => $phoneNumbers
                ->map(fn (ContactPhoneNumber $phoneNumber): array => [
                    'id' => $phoneNumber->id,
                    'phone' => $phoneNumber->phone_raw,
                    'source' => static::formatPhoneSource($phoneNumber->source),
                    'is_primary' => $phoneNumber->is_primary,
                ])
                ->all(),
        ];
    }

    protected static function formatPhoneSource(?string $source): string
    {
        return match ($source) {
            ContactPhoneNumber::SOURCE_TELEGRAM_CONTACT_SHARE => 'Telegram contact share',
            ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE => 'MAX contact share',
            default => $source ?? '—',
        };
    }

    protected static function buildInlineReplyComposerViewData(Contact $record): array
    {
        return [
            'canReply' => static::canCurrentUserReplyToContact($record),
            'blockedReason' => static::getInlineReplyBlockedReason($record),
            'canClaim' => static::canCurrentUserClaimContact($record),
            'assignedUserLabel' => static::formatAssignedUserLabel($record),
            'autoReplyEnabled' => $record->isAutoReplyEnabled(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function getAssignableUserOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->where('is_admin', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn (string $name, int|string $id): string => filled($name) ? $name : 'Сотрудник #'.$id)
            ->all();
    }

    protected static function resolveCurrentUserId(): ?int
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->id;
    }

    protected static function formatAssignedUserLabel(Contact $record): string
    {
        $record->loadMissing('assignedUser');

        return filled($record->assignedUser?->name)
            ? (string) $record->assignedUser->name
            : 'Свободен';
    }

    protected static function formatOwnershipStatus(Contact $record): string
    {
        return match (static::getContactOwnershipState($record)) {
            'mine' => 'Мой',
            'other' => 'Назначен другому',
            default => 'Свободен',
        };
    }

    protected static function getOwnershipStatusColor(Contact $record): string
    {
        return match (static::getContactOwnershipState($record)) {
            'mine' => 'success',
            'other' => 'gray',
            default => 'warning',
        };
    }

    protected static function getOwnershipHint(Contact $record): ?string
    {
        return match (static::getContactOwnershipState($record)) {
            'mine' => 'Контакт закреплён за вами.',
            'other' => filled($record->assignedUser?->name)
                ? 'Контакт закреплён за '.$record->assignedUser->name.'.'
                : 'Контакт уже назначен другому сотруднику.',
            default => 'Контакт пока свободен. Можно выбрать ответственного или оставить контакт свободным.',
        };
    }

    protected static function canCurrentUserClaimContact(Contact $record): bool
    {
        return static::getContactOwnershipState($record) === 'unassigned';
    }

    protected static function canCurrentUserReleaseContact(Contact $record): bool
    {
        return static::getContactOwnershipState($record) === 'mine';
    }

    protected static function canCurrentUserReplyToContact(Contact $record): bool
    {
        return in_array(static::getContactOwnershipState($record), ['mine', 'unassigned'], true);
    }

    protected static function getInlineReplyBlockedReason(Contact $record): ?string
    {
        return match (static::getContactOwnershipState($record)) {
            'other' => filled($record->assignedUser?->name)
                ? 'Контакт уже назначен сотруднику '.$record->assignedUser->name.'.'
                : 'Контакт уже назначен другому сотруднику.',
            default => null,
        };
    }

    protected static function getContactOwnershipState(Contact $record): string
    {
        $record->loadMissing('assignedUser');

        if (! $record->isAssigned()) {
            return 'unassigned';
        }

        $currentUserId = static::resolveCurrentUserId();

        if (($currentUserId !== null) && ((int) $record->assigned_user_id === $currentUserId)) {
            return 'mine';
        }

        return 'other';
    }

    protected static function formatConversationReplyLink(?Message $message): ?string
    {
        if ($message === null || $message->direction !== Message::DIRECTION_OUTBOUND) {
            return null;
        }

        $replyTo = $message->replyTo;

        if ($replyTo === null) {
            return 'Ответ без связи';
        }

        if (filled($replyTo->provider_event_key)) {
            return 'Ответ на event key: '.$replyTo->provider_event_key;
        }

        return 'Ответ на входящее #'.$replyTo->id;
    }
}
