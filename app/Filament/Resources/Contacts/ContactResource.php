<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use BackedEnum;
use Filament\Actions\Action;
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
            ->with([
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
                Section::make('Сводка')
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),
                        TextEntry::make('display_name')
                            ->label('Контакт'),
                        TextEntry::make('name')
                            ->label('Сохранённое имя')
                            ->placeholder('Не задано'),
                        TextEntry::make('primaryIdentity.channel.name')
                            ->label('Канал')
                            ->placeholder('—'),
                        TextEntry::make('primaryIdentity.platform')
                            ->label('Платформа')
                            ->badge()
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? (Channel::platformOptions()[$state] ?? $state) : '—'),
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
                TextColumn::make('inbox_status')
                    ->label('Статус')
                    ->state(fn (Contact $record): string => static::formatInboxStatus($record))
                    ->badge()
                    ->color(fn (Contact $record): string => static::getInboxStatusColor($record)),
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
            ])
            ->columnManager()
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->defaultSort('latest_message_id', 'desc')
            ->emptyStateHeading('Контактов ещё нет')
            ->emptyStateDescription('Контакты появятся после первых входящих сообщений от внешней аудитории.')
            ->recordActions([
                ViewAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->mountUsing(function (Action $action, ?Schema $schema, ManageContacts $livewire): void {
                        $schema?->fill();
                        $livewire->inlineReplyText = '';
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
            ->map(fn (Message $message): array => [
                'id' => $message->id,
                'direction' => $message->direction,
                'kind' => $message->message_kind ?? 'unknown',
                'direction_label' => static::formatMessageDirection($message->direction),
                'kind_label' => static::formatMessageKind($message->message_kind),
                'text' => filled($message->text) ? (string) $message->text : '—',
                'time_label' => ($message->received_at ?? $message->created_at)?->format('d.m.Y H:i:s') ?? '—',
                'channel_label' => static::formatLatestMessageChannel($message) ?? '—',
                'message_id_label' => $message->external_message_id,
                'provider_event_key_label' => $message->provider_event_key,
                'auto_reply_sent_at_label' => $message->auto_reply_sent_at?->format('d.m.Y H:i:s'),
                'reply_status_label' => $message->direction === Message::DIRECTION_INBOUND
                    ? static::formatMessageReplyStatus($message)
                    : null,
                'reply_link_label' => $message->direction === Message::DIRECTION_OUTBOUND
                    ? static::formatConversationReplyLink($message)
                    : null,
                'is_inbound' => $message->direction === Message::DIRECTION_INBOUND,
                'is_outbound' => $message->direction === Message::DIRECTION_OUTBOUND,
            ])
            ->all();
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
            Message::KIND_OUTBOUND_AUTO_REPLY => 'Автоответ',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'Ручной ответ',
            default => 'Не определен',
        };
    }

    protected static function getMessageKindColor(?string $messageKind): string
    {
        return match ($messageKind) {
            Message::KIND_INBOUND_USER => 'info',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'warning',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'success',
            default => 'gray',
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
