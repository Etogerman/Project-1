<?php

namespace App\Filament\Resources\Dialogs;

use App\Data\Dialogs\DialogRouteStatusData;
use App\Filament\Resources\Dialogs\Pages\ListDialogs;
use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\MessageChronology;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use UnitEnum;

class DialogResource extends Resource
{
    protected static ?string $model = Dialog::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $modelLabel = 'Диалог';

    protected static ?string $pluralModelLabel = 'Диалоги';

    protected static ?string $navigationLabel = 'Диалоги';

    protected static string|UnitEnum|null $navigationGroup = 'Аудитория';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'channel',
                'currentContactIdentity',
                'contact.assignedUser',
                'contact.phoneNumbers',
                'contact.primaryIdentity',
            ]);
    }

    public static function getTableRecordQuery(bool $excludeMerged = true): Builder
    {
        $query = parent::getEloquentQuery()
            ->addSelect([
                'preview_message_id' => static::buildPreviewMessageSubquery(),
                'latest_inbound_user_message_id' => static::buildLatestMessageIdSubquery(
                    fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_INBOUND_USER),
                ),
                'latest_inbound_user_message_sort_at' => static::buildLatestMessageSortAtSubquery(
                    fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_INBOUND_USER),
                ),
                'latest_outbound_manual_reply_message_id' => static::buildLatestMessageIdSubquery(
                    fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_OUTBOUND_MANUAL_REPLY),
                ),
                'latest_outbound_manual_reply_message_sort_at' => static::buildLatestMessageSortAtSubquery(
                    fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_OUTBOUND_MANUAL_REPLY),
                ),
            ])
            ->with([
                'channel',
                'currentContactIdentity',
                'contact.assignedUser',
                'contact.primaryIdentity',
                'previewMessage.channel',
                'previewMessage.sentByUser',
            ]);

        if ($excludeMerged) {
            $query->whereHas('contact', fn (Builder $query): Builder => $query->whereNull('merged_into_contact_id'));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contact_label')
                    ->label('Контакт')
                    ->state(fn (Dialog $record): string => static::resolveContactLabel($record))
                    ->description(fn (Dialog $record): ?string => static::formatDialogTableIdentitySummary($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::applyTableSearch($query, $search))
                    ->toggleable(),
                TextColumn::make('inbox_status')
                    ->label('Статус')
                    ->state(fn (Dialog $record): string => static::formatInboxStatus($record))
                    ->badge()
                    ->color(fn (Dialog $record): string => static::getInboxStatusColor($record))
                    ->toggleable(),
                TextColumn::make('assigned_user')
                    ->label('Ответственный')
                    ->state(fn (Dialog $record): string => filled($record->contact?->assignedUser?->name)
                        ? (string) $record->contact->assignedUser->name
                        : 'Свободен')
                    ->toggleable(),
                TextColumn::make('channel_label')
                    ->label('Канал')
                    ->state(fn (Dialog $record): string => static::formatChannelLabel($record))
                    ->toggleable(),
                TextColumn::make('route_status')
                    ->label('Маршрут')
                    ->state(fn (Dialog $record): string => static::resolveDialogRouteStatus($record)->label)
                    ->badge()
                    ->color(fn (Dialog $record): string => static::resolveDialogRouteStatus($record)->tone)
                    ->toggleable(),
                TextColumn::make('preview_sender_label')
                    ->label('Кто')
                    ->state(fn (Dialog $record): ?string => static::resolvePreviewSenderLabel($record))
                    ->badge()
                    ->color(fn (Dialog $record): string => static::resolvePreviewSenderTone($record))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('preview_text')
                    ->label('Последнее сообщение')
                    ->state(fn (Dialog $record): string => static::resolvePreviewText($record))
                    ->description(fn (Dialog $record): ?string => static::formatPreviewMetaSummary($record))
                    ->tooltip(fn (Dialog $record): string => static::resolvePreviewText($record))
                    ->limit(80)
                    ->toggleable(),
                TextColumn::make('last_message_at')
                    ->label('Активность')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('dialogs.last_message_at', $direction)
                        ->orderBy('dialogs.id', $direction))
                    ->toggleable(),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('phone_label')
                    ->label('Телефон канала')
                    ->state(fn (Dialog $record): string => static::formatDialogPhoneLabel($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('route_source')
                    ->label('Источник маршрута')
                    ->state(fn (Dialog $record): string => static::formatDialogRouteIdentityLabel($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('external_chat_id')
                    ->label('ID чата')
                    ->state(fn (Dialog $record): string => filled($record->external_chat_id) ? (string) $record->external_chat_id : 'Не задан')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('requires_manual_reply')
                    ->label('Требует ответа')
                    ->default()
                    ->query(fn (Builder $query): Builder => static::applyRequiresManualReplyFilter($query)),
                Filter::make('assigned_to_me')
                    ->label('Назначены мне')
                    ->query(fn (Builder $query): Builder => static::applyAssignedToMeFilter($query)),
                Filter::make('unassigned_dialogs')
                    ->label('Без ответственного')
                    ->query(fn (Builder $query): Builder => $query->whereHas('contact', fn (Builder $query): Builder => $query->whereNull('assigned_user_id'))),
                SelectFilter::make('channel_id')
                    ->label('Канал')
                    ->options(fn (): array => Channel::query()
                        ->orderBy('name')
                        ->orderBy('id')
                        ->get()
                        ->mapWithKeys(fn (Channel $channel): array => [$channel->id => $channel->display_title])
                        ->all()),
                Filter::make('route_ready')
                    ->label('Маршрут готов')
                    ->query(fn (Builder $query): Builder => $query->whereRouteReady()),
                Filter::make('route_problem')
                    ->label('Проблема маршрута')
                    ->query(fn (Builder $query): Builder => $query->whereRouteProblem()),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->recordUrl(fn (Dialog $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Диалогов ещё нет')
            ->emptyStateDescription('Диалоги появятся после первых входящих сообщений от внешней аудитории.')
            ->columnManager()
            ->deferColumnManager(false)
            ->reorderableColumns();
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof Dialog) {
            return parent::getRecordTitle($record);
        }

        $channelName = $record->channel?->name ?? 'Канал';

        return sprintf('#%d %s', $record->id, $channelName);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDialogs::route('/'),
            'view' => ViewDialog::route('/{record}'),
        ];
    }

    protected static function buildPreviewMessageSubquery(): Builder
    {
        return Message::query()
            ->select('id')
            ->whereColumn('dialog_id', 'dialogs.id')
            ->tap(fn (Builder $query): Builder => app(MessageChronology::class)->applyLatestOrder($query))
            ->limit(1);
    }

    protected static function resolvePreviewMessage(Dialog $record): ?Message
    {
        if ($record->relationLoaded('previewMessage')) {
            return $record->previewMessage;
        }

        $previewMessageId = $record->getAttribute('preview_message_id');

        if (! filled($previewMessageId)) {
            return null;
        }

        return Message::query()
            ->with(['channel', 'sentByUser'])
            ->find($previewMessageId);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function resolvePreviewFeed(Dialog $record): ?array
    {
        $message = static::resolvePreviewMessage($record);

        if (! $message instanceof Message) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $cache */
        static $cache = [];

        if (array_key_exists($message->id, $cache)) {
            return $cache[$message->id];
        }

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(new Collection([$message]));

        return $cache[$message->id] = $feed[0] ?? null;
    }

    protected static function resolvePreviewText(Dialog $record): string
    {
        $previewFeed = static::resolvePreviewFeed($record);

        if (is_array($previewFeed) && filled($previewFeed['display_text'] ?? null)) {
            return (string) $previewFeed['display_text'];
        }

        return 'Сообщений ещё не было.';
    }

    protected static function resolvePreviewSenderLabel(Dialog $record): ?string
    {
        $previewMessage = static::resolvePreviewMessage($record);

        if (! $previewMessage instanceof Message) {
            return null;
        }

        if ($previewMessage->direction === Message::DIRECTION_INBOUND) {
            return 'Контакт';
        }

        $previewFeed = static::resolvePreviewFeed($record);

        if (is_array($previewFeed) && filled($previewFeed['sender_label'] ?? null)) {
            return (string) $previewFeed['sender_label'];
        }

        return 'Система';
    }

    protected static function resolvePreviewSenderTone(Dialog $record): string
    {
        $previewMessage = static::resolvePreviewMessage($record);

        if (! $previewMessage instanceof Message) {
            return 'gray';
        }

        if ($previewMessage->direction === Message::DIRECTION_INBOUND) {
            return 'info';
        }

        return match ($previewMessage->sent_by_type) {
            Message::SENT_BY_TYPE_OPERATOR => 'success',
            Message::SENT_BY_TYPE_AUTO_REPLY => 'warning',
            Message::SENT_BY_TYPE_COLLECTOR => 'primary',
            Message::SENT_BY_TYPE_SYSTEM => 'gray',
            default => 'gray',
        };
    }

    protected static function formatPreviewMetaSummary(Dialog $record): ?string
    {
        $previewMessage = static::resolvePreviewMessage($record);

        if (! $previewMessage instanceof Message) {
            return null;
        }

        $parts = [
            $previewMessage->direction === Message::DIRECTION_INBOUND ? 'Входящее' : 'Исходящее',
        ];

        if (filled($record->channel?->display_title)) {
            $parts[] = $record->channel->display_title;
        }

        return implode(' · ', $parts);
    }

    protected static function dialogRequiresManualReply(Dialog $record): bool
    {
        $latestInboundUserMessageId = $record->getAttribute('latest_inbound_user_message_id');
        $latestInboundUserMessageSortAt = $record->getAttribute('latest_inbound_user_message_sort_at');
        $latestOutboundManualReplyMessageId = $record->getAttribute('latest_outbound_manual_reply_message_id');
        $latestOutboundManualReplyMessageSortAt = $record->getAttribute('latest_outbound_manual_reply_message_sort_at');

        if (! filled($latestInboundUserMessageId)) {
            return false;
        }

        if (! filled($latestOutboundManualReplyMessageId)) {
            return true;
        }

        return static::messageChronology()->isAfter(
            $latestInboundUserMessageSortAt,
            $latestInboundUserMessageId,
            $latestOutboundManualReplyMessageSortAt,
            $latestOutboundManualReplyMessageId,
        );
    }

    protected static function formatInboxStatus(Dialog $record): string
    {
        return static::dialogRequiresManualReply($record)
            ? 'Требует ответа'
            : 'Нет новых';
    }

    protected static function getInboxStatusColor(Dialog $record): string
    {
        return static::dialogRequiresManualReply($record) ? 'warning' : 'success';
    }

    protected static function applyRequiresManualReplyFilter(Builder $query): Builder
    {
        $chronology = static::messageChronology();
        $latestInboundUserMessageId = $chronology->latestDialogMessageIdFragment(
            Message::KIND_INBOUND_USER,
        );
        $latestInboundUserMessageSortAt = $chronology->latestDialogMessageSortAtFragment(
            Message::KIND_INBOUND_USER,
        );
        $latestOutboundManualReplyMessageId = $chronology->latestDialogMessageIdFragment(
            Message::KIND_OUTBOUND_MANUAL_REPLY,
        );
        $latestOutboundManualReplyMessageSortAt = $chronology->latestDialogMessageSortAtFragment(
            Message::KIND_OUTBOUND_MANUAL_REPLY,
        );
        $latestInboundAfterOutboundManualReply = $chronology->buildIsAfterCondition(
            $latestInboundUserMessageSortAt,
            $latestInboundUserMessageId,
            $latestOutboundManualReplyMessageSortAt,
            $latestOutboundManualReplyMessageId,
        );

        return $query
            ->whereRaw(
                $latestInboundUserMessageId['sql'].' is not null',
                $latestInboundUserMessageId['bindings'],
            )
            ->where(function (Builder $query) use (
                $latestOutboundManualReplyMessageId,
                $latestInboundAfterOutboundManualReply,
            ): Builder {
                return $query
                    ->whereRaw(
                        $latestOutboundManualReplyMessageId['sql'].' is null',
                        $latestOutboundManualReplyMessageId['bindings'],
                    )
                    ->orWhereRaw(
                        $latestInboundAfterOutboundManualReply['sql'],
                        $latestInboundAfterOutboundManualReply['bindings'],
                    );
            });
    }

    protected static function applyAssignedToMeFilter(Builder $query): Builder
    {
        $currentUserId = auth()->user()?->id;

        if ($currentUserId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('contact', fn (Builder $query): Builder => $query->where('assigned_user_id', $currentUserId));
    }

    protected static function applyTableSearch(Builder $query, string $search): Builder
    {
        $normalizedPhoneSearch = AddContactPhoneAction::normalizePhone($search);

        return $query->where(function (Builder $query) use ($search, $normalizedPhoneSearch): void {
            $query
                ->where('external_chat_id', 'ilike', "%{$search}%")
                ->orWhereHas('contact', function (Builder $contactQuery) use ($search, $normalizedPhoneSearch): void {
                    $contactQuery
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%");

                    if ($normalizedPhoneSearch !== '') {
                        $contactQuery->orWhereHas('phoneNumbers', function (Builder $phoneQuery) use ($normalizedPhoneSearch): void {
                            $phoneQuery->where('phone_normalized', 'ilike', "%{$normalizedPhoneSearch}%");
                        });
                    }
                })
                ->orWhereHas('currentContactIdentity', function (Builder $identityQuery) use ($search): void {
                    $identityQuery
                        ->where('external_user_id', 'ilike', "%{$search}%")
                        ->orWhere('external_username', 'ilike', "%{$search}%");
                });
        });
    }

    protected static function resolveDialogRouteStatus(Dialog $dialog): DialogRouteStatusData
    {
        return app(ResolveDialogRouteStatusAction::class)->handle($dialog);
    }

    protected static function buildLatestMessageIdSubquery(?Closure $scope = null): Builder
    {
        return static::messageChronology()->latestMessageIdSubquery(
            'dialog_id',
            'dialogs.id',
            $scope,
        );
    }

    protected static function buildLatestMessageSortAtSubquery(?Closure $scope = null): Builder
    {
        return static::messageChronology()->latestMessageSortAtSubquery(
            'dialog_id',
            'dialogs.id',
            $scope,
        );
    }

    protected static function messageChronology(): MessageChronology
    {
        return app(MessageChronology::class);
    }

    protected static function formatChannelLabel(Dialog $dialog): string
    {
        $channel = $dialog->channel;

        if ($channel === null) {
            return 'Неизвестный канал';
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: 'Неизвестный канал';
    }

    protected static function formatDialogPhoneLabel(Dialog $dialog): string
    {
        if (filled($dialog->confirmed_phone_raw)) {
            return (string) $dialog->confirmed_phone_raw;
        }

        if (filled($dialog->confirmed_phone_normalized)) {
            return (string) $dialog->confirmed_phone_normalized;
        }

        return 'Телефон в этом канале не подтвержден';
    }

    protected static function formatDialogRouteIdentityLabel(Dialog $dialog): string
    {
        $identity = $dialog->currentContactIdentity;
        $parts = [];

        if (filled($identity?->external_user_id)) {
            $parts[] = 'ID: '.$identity->external_user_id;
        }

        if (filled($identity?->external_username)) {
            $parts[] = '@'.ltrim((string) $identity->external_username, '@');
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        if ($dialog->current_contact_identity_id !== null) {
            return 'Identity #'.$dialog->current_contact_identity_id;
        }

        return 'Не задан';
    }

    protected static function resolveContactLabel(Dialog $dialog): string
    {
        if (! $dialog->contact instanceof \App\Models\Contact) {
            return 'Контакт не найден';
        }

        return app(ResolveContactDisplayNameAction::class)->handle($dialog->contact, $dialog);
    }

    protected static function formatDialogTableIdentitySummary(Dialog $dialog): ?string
    {
        $parts = [];
        $routeIdentityLabel = static::formatDialogRouteIdentityLabel($dialog);

        if ($routeIdentityLabel !== 'Не задан') {
            $parts[] = $routeIdentityLabel;
        }

        $phoneLabel = static::formatDialogPhoneLabel($dialog);

        if ($phoneLabel !== 'Телефон в этом канале не подтвержден') {
            $parts[] = $phoneLabel;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
