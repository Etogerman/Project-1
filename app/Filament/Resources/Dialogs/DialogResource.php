<?php

namespace App\Filament\Resources\Dialogs;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Filament\Resources\Dialogs\Pages\DialogKanban;
use App\Filament\Resources\Dialogs\Pages\ListDialogs;
use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\MessageChronology;
use App\Services\Dialogs\ResolveDialogStageAction;
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
    public const NAVIGATION_URL_SESSION_KEY = 'filament.dialogs.navigation_url';

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

    public static function getNavigationUrl(): string
    {
        $defaultUrl = static::getUrl('index');
        $rememberedUrl = session(static::NAVIGATION_URL_SESSION_KEY);

        if (! is_string($rememberedUrl) || $rememberedUrl === '') {
            return $defaultUrl;
        }

        return static::isDialogsBrowsingUrl($rememberedUrl)
            ? $rememberedUrl
            : $defaultUrl;
    }

    public static function rememberNavigationUrl(string $url): void
    {
        if (! static::isDialogsBrowsingUrl($url)) {
            return;
        }

        session()->put(static::NAVIGATION_URL_SESSION_KEY, $url);
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
            ->poll('10s')
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('contact_label')
                    ->label('Контакт')
                    ->state(fn (Dialog $record): string => static::resolveContactLabel($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::applyTableSearch($query, $search))
                    ->toggleable(),
                TextColumn::make('inbox_status')
                    ->label('Статус')
                    ->state(fn (Dialog $record): string => static::formatInboxStatus($record))
                    ->badge()
                    ->color(fn (Dialog $record): string => static::getInboxStatusColor($record))
                    ->toggleable(),
                TextColumn::make('stage')
                    ->label('Этап')
                    ->state(fn (Dialog $record): string => static::formatStageLabel($record))
                    ->badge()
                    ->color(fn (Dialog $record): string => static::getStageColor($record))
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
                TextColumn::make('external_user_id')
                    ->label('Внешний ID')
                    ->state(fn (Dialog $record): ?string => static::resolveDialogExternalUserId($record))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('external_username')
                    ->label('Username')
                    ->state(fn (Dialog $record): ?string => static::resolveDialogUsername($record))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone_label')
                    ->label('Номер телефона')
                    ->state(fn (Dialog $record): ?string => static::resolveDialogPhoneValue($record))
                    ->placeholder('—')
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
                SelectFilter::make('inbox_status')
                    ->label('Статус')
                    ->options(static::getInboxStatusFilterOptions())
                    ->placeholder('Все')
                    ->default(DialogInboxStatusData::CODE_REQUIRES_REPLY)
                    ->query(function (Builder $query, array $data): void {
                        $status = $data['value'] ?? null;

                        if (! is_string($status) || $status === '') {
                            return;
                        }

                        static::applyInboxStatusFilter($query, $status);
                    }),
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
                SelectFilter::make('stage')
                    ->label('Этап')
                    ->options(fn (): array => Dialog::stageLabels())
                    ->query(function (Builder $query, array $data): void {
                        $stage = $data['value'] ?? null;

                        if (! is_string($stage) || $stage === '') {
                            return;
                        }

                        static::applyStageFilter($query, $stage);
                    }),
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
            'kanban' => DialogKanban::route('/kanban'),
            'view' => ViewDialog::route('/{record}'),
        ];
    }

    protected static function isDialogsBrowsingUrl(string $url): bool
    {
        return str_starts_with($url, static::getUrl('index'))
            || str_starts_with($url, static::getUrl('kanban'));
    }

    protected static function buildPreviewMessageSubquery(): Builder
    {
        return Message::query()
            ->select('id')
            ->whereColumn('dialog_id', 'dialogs.id')
            ->where(function (Builder $query): Builder {
                return $query
                    ->whereNull('message_kind')
                    ->orWhere('message_kind', '!=', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE);
            })
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

        if ($previewMessage->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return 'Система';
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
        $previewFeed = static::resolvePreviewFeed($record);

        if (! $previewMessage instanceof Message) {
            return 'gray';
        }

        if ($previewMessage->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return 'gray';
        }

        if ($previewMessage->direction === Message::DIRECTION_INBOUND) {
            return 'info';
        }

        if (is_array($previewFeed) && filled($previewFeed['sender_tone'] ?? null)) {
            return (string) $previewFeed['sender_tone'];
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
        $previewFeed = static::resolvePreviewFeed($record);

        if (! $previewMessage instanceof Message) {
            return null;
        }

        $parts = [
            $previewMessage->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT
                ? 'Системное'
                : ($previewMessage->direction === Message::DIRECTION_INBOUND ? 'Входящее' : 'Исходящее'),
        ];

        if (filled($record->channel?->display_title)) {
            $parts[] = $record->channel->display_title;
        }

        if (is_array($previewFeed)) {
            foreach ($previewFeed['media_state_badges'] ?? [] as $badge) {
                if (is_array($badge) && filled($badge['label'] ?? null)) {
                    $parts[] = (string) $badge['label'];
                }
            }
        }

        return implode(' · ', $parts);
    }

    protected static function dialogRequiresManualReply(Dialog $record): bool
    {
        return static::resolveInboxStatusCode($record) === DialogInboxStatusData::CODE_REQUIRES_REPLY;
    }

    protected static function resolveInboxStatusCode(Dialog $record): string
    {
        $latestInboundUserMessageId = $record->getAttribute('latest_inbound_user_message_id');
        $latestInboundUserMessageSortAt = $record->getAttribute('latest_inbound_user_message_sort_at');
        $latestOutboundManualReplyMessageId = $record->getAttribute('latest_outbound_manual_reply_message_id');
        $latestOutboundManualReplyMessageSortAt = $record->getAttribute('latest_outbound_manual_reply_message_sort_at');
        $manualReplyDismissedSourceMessageId = $record->getAttribute('manual_reply_dismissed_source_message_id');

        if (! filled($latestInboundUserMessageId)) {
            return DialogInboxStatusData::CODE_NO_NEW;
        }

        if (! filled($latestOutboundManualReplyMessageId)) {
            return (int) $manualReplyDismissedSourceMessageId === (int) $latestInboundUserMessageId
                ? DialogInboxStatusData::CODE_NOT_REQUIRED
                : DialogInboxStatusData::CODE_REQUIRES_REPLY;
        }

        if (! static::messageChronology()->isAfter(
            $latestInboundUserMessageSortAt,
            $latestInboundUserMessageId,
            $latestOutboundManualReplyMessageSortAt,
            $latestOutboundManualReplyMessageId,
        )) {
            return DialogInboxStatusData::CODE_NO_NEW;
        }

        return (int) $manualReplyDismissedSourceMessageId === (int) $latestInboundUserMessageId
            ? DialogInboxStatusData::CODE_NOT_REQUIRED
            : DialogInboxStatusData::CODE_REQUIRES_REPLY;
    }

    protected static function formatInboxStatus(Dialog $record): string
    {
        return match (static::resolveInboxStatusCode($record)) {
            DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
            DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
            default => 'Нет новых',
        };
    }

    protected static function getInboxStatusColor(Dialog $record): string
    {
        return match (static::resolveInboxStatusCode($record)) {
            DialogInboxStatusData::CODE_REQUIRES_REPLY => 'warning',
            DialogInboxStatusData::CODE_NOT_REQUIRED => 'gray',
            default => 'success',
        };
    }

    protected static function formatStageLabel(Dialog $record): string
    {
        return Dialog::stageLabel(static::resolveEffectiveStage($record));
    }

    protected static function getStageColor(Dialog $record): string
    {
        return Dialog::stageTone(static::resolveEffectiveStage($record));
    }

    protected static function resolveEffectiveStage(Dialog $record): string
    {
        return app(ResolveDialogStageAction::class)->handle($record);
    }

    protected static function applyStageFilter(Builder $query, string $stage): void
    {
        if (Dialog::isServiceStage($stage)) {
            $query->where('dialogs.stage', $stage);

            return;
        }

        if (Dialog::isManualStage($stage)) {
            $query->where('dialogs.stage', $stage);

            return;
        }

        $query->where(function (Builder $query) use ($stage): void {
            $query->where('dialogs.stage', $stage)
                ->orWhere(function (Builder $query) use ($stage): void {
                    $query->where(function (Builder $query): void {
                        $query->whereNull('dialogs.stage')
                            ->orWhereNotIn('dialogs.stage', Dialog::workingStages());
                    });

                    match ($stage) {
                        Dialog::STAGE_QUESTIONNAIRE_COMPLETED => $query
                            ->whereHas('contact', fn (Builder $query): Builder => static::applyCompletedContactScope($query)),
                        Dialog::STAGE_PHONE_RECEIVED => $query
                            ->whereNotNull('dialogs.phone_confirmed_at')
                            ->whereHas('contact', fn (Builder $query): Builder => static::applyNotCompletedContactScope($query)),
                        default => $query
                            ->whereNull('dialogs.phone_confirmed_at')
                            ->whereHas('contact', fn (Builder $query): Builder => static::applyNotCompletedContactScope($query)),
                    };
                });
        });
    }

    protected static function applyCompletedContactScope(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('data_collection_status', Contact::DATA_COLLECTION_STATUS_COMPLETED)
                ->orWhereNotNull('data_collection_completed_at');
        });
    }

    protected static function applyNotCompletedContactScope(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('data_collection_status', '!=', Contact::DATA_COLLECTION_STATUS_COMPLETED)
                ->orWhereNull('data_collection_status');
        })->whereNull('data_collection_completed_at');
    }

    protected static function applyRequiresManualReplyFilter(Builder $query): Builder
    {
        [
            'latestInboundUserMessageId' => $latestInboundUserMessageId,
            'latestOutboundManualReplyMessageId' => $latestOutboundManualReplyMessageId,
            'latestInboundAfterOutboundManualReply' => $latestInboundAfterOutboundManualReply,
        ] = static::buildInboxStatusFilterFragments();

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
            })
            ->where(function (Builder $query) use ($latestInboundUserMessageId): Builder {
                return $query
                    ->whereNull('dialogs.manual_reply_dismissed_source_message_id')
                    ->orWhereRaw(
                        $latestInboundUserMessageId['sql'].' <> dialogs.manual_reply_dismissed_source_message_id',
                        $latestInboundUserMessageId['bindings'],
                    );
            });
    }

    protected static function applyInboxStatusFilter(Builder $query, string $status): Builder
    {
        if ($status === DialogInboxStatusData::CODE_REQUIRES_REPLY) {
            return static::applyRequiresManualReplyFilter($query);
        }

        [
            'latestInboundUserMessageId' => $latestInboundUserMessageId,
            'latestOutboundManualReplyMessageId' => $latestOutboundManualReplyMessageId,
            'latestInboundAfterOutboundManualReply' => $latestInboundAfterOutboundManualReply,
        ] = static::buildInboxStatusFilterFragments();

        return match ($status) {
            DialogInboxStatusData::CODE_NOT_REQUIRED => $query
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
                })
                ->whereRaw(
                    $latestInboundUserMessageId['sql'].' = dialogs.manual_reply_dismissed_source_message_id',
                    $latestInboundUserMessageId['bindings'],
                ),
            DialogInboxStatusData::CODE_NO_NEW => $query->where(function (Builder $query) use (
                $latestInboundUserMessageId,
                $latestOutboundManualReplyMessageId,
                $latestInboundAfterOutboundManualReply,
            ): Builder {
                return $query
                    ->whereRaw(
                        $latestInboundUserMessageId['sql'].' is null',
                        $latestInboundUserMessageId['bindings'],
                    )
                    ->orWhere(function (Builder $query) use (
                        $latestOutboundManualReplyMessageId,
                        $latestInboundAfterOutboundManualReply,
                    ): Builder {
                        return $query
                            ->whereRaw(
                                $latestOutboundManualReplyMessageId['sql'].' is not null',
                                $latestOutboundManualReplyMessageId['bindings'],
                            )
                            ->whereRaw(
                                'not ('.$latestInboundAfterOutboundManualReply['sql'].')',
                                $latestInboundAfterOutboundManualReply['bindings'],
                            );
                    });
            }),
            default => $query,
        };
    }

    protected static function applyAssignedToMeFilter(Builder $query): Builder
    {
        $currentUserId = auth()->user()?->id;

        if ($currentUserId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('contact', fn (Builder $query): Builder => $query->where('assigned_user_id', $currentUserId));
    }

    public static function applyTableSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $phoneSearchDigits = static::resolvePhoneSearchDigits($search);
        $usernameSearch = static::resolveUsernameSearch($search);
        $likeSearch = "%{$search}%";

        return $query->where(function (Builder $query) use ($phoneSearchDigits, $likeSearch, $usernameSearch): void {
            $query
                ->where('external_chat_id', 'ilike', $likeSearch)
                ->orWhereHas('contact', function (Builder $contactQuery) use ($phoneSearchDigits, $likeSearch): void {
                    $contactQuery
                        ->where('first_name', 'ilike', $likeSearch)
                        ->orWhere('last_name', 'ilike', $likeSearch)
                        ->orWhereRaw("trim(coalesce(first_name, '') || ' ' || coalesce(last_name, '')) ilike ?", [$likeSearch])
                        ->orWhereRaw("trim(coalesce(last_name, '') || ' ' || coalesce(first_name, '')) ilike ?", [$likeSearch]);

                    if ($phoneSearchDigits !== null) {
                        $contactQuery->orWhereHas('phoneNumbers', function (Builder $phoneQuery) use ($phoneSearchDigits): void {
                            static::whereDigitOnlyPhoneLike($phoneQuery, 'phone_raw', 'phone_normalized', $phoneSearchDigits);
                        });
                    }
                })
                ->orWhereHas('currentContactIdentity', function (Builder $identityQuery) use ($likeSearch, $usernameSearch): void {
                    $identityQuery
                        ->where('display_name', 'ilike', $likeSearch)
                        ->orWhere('external_user_id', 'ilike', $likeSearch)
                        ->orWhere('external_username', 'ilike', $likeSearch);

                    if ($usernameSearch !== null) {
                        $identityQuery->orWhere('external_username', 'ilike', "%{$usernameSearch}%");
                    }
                });

            if ($phoneSearchDigits !== null) {
                $query->orWhere(function (Builder $query) use ($phoneSearchDigits): void {
                    static::whereDigitOnlyPhoneLike($query, 'confirmed_phone_raw', 'confirmed_phone_normalized', $phoneSearchDigits);
                });
            }
        });
    }

    protected static function resolveUsernameSearch(string $search): ?string
    {
        if (! str_starts_with($search, '@')) {
            return null;
        }

        $username = ltrim($search, '@');

        return $username !== '' ? $username : null;
    }

    protected static function resolvePhoneSearchDigits(string $search): ?string
    {
        if (preg_match('/^[0-9+\s()\-]+$/u', $search) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/u', '', $search) ?? '';

        return strlen($digits) >= 6 ? $digits : null;
    }

    protected static function whereDigitOnlyPhoneLike(Builder $query, string $rawColumn, string $normalizedColumn, string $digits): Builder
    {
        $likeDigits = "%{$digits}%";

        return $query
            ->whereRaw("regexp_replace({$rawColumn}, '[^0-9]', '', 'g') ilike ?", [$likeDigits])
            ->orWhereRaw("regexp_replace({$normalizedColumn}, '[^0-9]', '', 'g') ilike ?", [$likeDigits]);
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

    /**
     * @return array{
     *     latestInboundUserMessageId: array{sql: string, bindings: list<mixed>},
     *     latestInboundUserMessageSortAt: array{sql: string, bindings: list<mixed>},
     *     latestOutboundManualReplyMessageId: array{sql: string, bindings: list<mixed>},
     *     latestOutboundManualReplyMessageSortAt: array{sql: string, bindings: list<mixed>},
     *     latestInboundAfterOutboundManualReply: array{sql: string, bindings: list<mixed>}
     * }
     */
    protected static function buildInboxStatusFilterFragments(): array
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

        return [
            'latestInboundUserMessageId' => $latestInboundUserMessageId,
            'latestInboundUserMessageSortAt' => $latestInboundUserMessageSortAt,
            'latestOutboundManualReplyMessageId' => $latestOutboundManualReplyMessageId,
            'latestOutboundManualReplyMessageSortAt' => $latestOutboundManualReplyMessageSortAt,
            'latestInboundAfterOutboundManualReply' => $chronology->buildIsAfterCondition(
                $latestInboundUserMessageSortAt,
                $latestInboundUserMessageId,
                $latestOutboundManualReplyMessageSortAt,
                $latestOutboundManualReplyMessageId,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function getInboxStatusFilterOptions(): array
    {
        return [
            DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
            DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
            DialogInboxStatusData::CODE_NO_NEW => 'Нет новых',
        ];
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

    protected static function resolveDialogPhoneValue(Dialog $dialog): ?string
    {
        if (filled($dialog->confirmed_phone_raw)) {
            return (string) $dialog->confirmed_phone_raw;
        }

        if (filled($dialog->confirmed_phone_normalized)) {
            return (string) $dialog->confirmed_phone_normalized;
        }

        return null;
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

    protected static function resolveDialogExternalUserId(Dialog $dialog): ?string
    {
        return filled($dialog->currentContactIdentity?->external_user_id)
            ? (string) $dialog->currentContactIdentity->external_user_id
            : null;
    }

    protected static function resolveDialogUsername(Dialog $dialog): ?string
    {
        return filled($dialog->currentContactIdentity?->external_username)
            ? '@'.ltrim((string) $dialog->currentContactIdentity->external_username, '@')
            : null;
    }
}
