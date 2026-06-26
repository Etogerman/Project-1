<?php

namespace App\Filament\Resources\Contacts;

use App\Data\Contacts\ResolvedContactDeletePreviewResult;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactEmail;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\ContactStartTag;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Services\Bitrix24\QueueBitrix24RescueSyncAction;
use App\Services\Contacts\BuildContactHistoryTimelineAction;
use App\Services\Contacts\DeleteContactAction;
use App\Services\Contacts\FindOpenCrossChannelIdentityAmbiguityReviewForContactsAction;
use App\Services\Contacts\ResolveContactDeletePreviewAction;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\LoadContactDialogsOverviewAction;
use App\Services\Dialogs\MessageChronology;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use JsonException;
use Throwable;
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
        return static::getTableRecordQuery();
    }

    public static function getTableRecordQuery(bool $excludeMerged = true): Builder
    {
        $query = parent::getEloquentQuery()
            ->addSelect([
                'primary_phone_raw' => static::buildPrimaryPhoneSubquery('phone_raw'),
                'primary_phone_normalized' => static::buildPrimaryPhoneSubquery('phone_normalized'),
                'phone_count' => static::buildPhoneCountSubquery(),
                'latest_message_id' => static::buildLatestMessageIdSubquery(),
                'latest_message_sort_at' => static::buildLatestMessageSortAtSubquery(),
                'latest_inbound_message_id' => static::buildLatestMessageIdSubquery(
                    fn (Builder $query): Builder => $query->where('direction', Message::DIRECTION_INBOUND),
                ),
                'latest_inbound_user_message_id' => static::buildLatestMessageIdSubquery(
                    fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_INBOUND_USER),
                ),
                'latest_inbound_user_message_sort_at' => static::buildLatestMessageSortAtSubquery(
                    fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_INBOUND_USER),
                ),
                'latest_outbound_manual_reply_message_id' => static::buildLatestMessageIdSubquery(
                    fn (Builder $query): Builder => $query->whereIn('message_kind', Message::dialogAnswerMessageKinds()),
                ),
                'latest_outbound_manual_reply_message_sort_at' => static::buildLatestMessageSortAtSubquery(
                    fn (Builder $query): Builder => $query->whereIn('message_kind', Message::dialogAnswerMessageKinds()),
                ),
            ])
            ->with([
                'assignedUser',
                'mergedInto',
                'tags',
                'primaryIdentity.channel',
                'latestConversationMessage.channel',
                'latestInboundMessage.channel',
                'openDuplicateReviews' => fn ($query) => $query
                    ->select([
                        'id',
                        'contact_id',
                        'review_type',
                        'phone_normalized',
                        'identity_key',
                        'candidate_root_contact_ids',
                        'context_payload',
                        'created_at',
                    ]),
                'recentMergedChildren' => fn ($query) => $query
                    ->select([
                        'id',
                        'merged_into_contact_id',
                        'merged_at',
                        'merge_trigger_phone',
                        'merge_reason',
                    ]),
            ])
            ->withCount([
                'duplicateReviews as open_duplicate_reviews_count' => fn (Builder $query): Builder => $query
                    ->where('status', ContactDuplicateReview::STATUS_OPEN),
                'mergedChildren',
            ])
            ->withCount('messages');

        if ($excludeMerged) {
            $query->whereNull('contacts.merged_into_contact_id');
        }

        return $query;
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
                Section::make('Дедупликация')
                    ->schema([
                        ViewEntry::make('contact_dedup_status')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.contact-dedup-status')
                            ->viewData(fn (Contact $record): array => static::buildDedupStatusViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (?Contact $record): bool => $record instanceof Contact && static::shouldShowDedupStatusSection($record))
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Профиль')
                    ->extraAttributes(['class' => 'ac-contact-modal-section ac-contact-modal-section--profile'])
                    ->schema([
                        ViewEntry::make('contact_profile')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.contact-profile')
                            ->viewData(fn (Contact $record): array => static::buildContactProfileViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Теги')
                    ->schema([
                        ViewEntry::make('contact_tags')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.contact-tags')
                            ->viewData(fn (Contact $record): array => static::buildTagsViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Диалоги')
                    ->extraAttributes(['class' => 'ac-contact-modal-section ac-contact-modal-section--dialogs'])
                    ->schema([
                        ViewEntry::make('dialogs')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.contact-dialogs')
                            ->viewData(fn (Contact $record): array => static::buildDialogsViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Сбор данных')
                    ->extraAttributes(['class' => 'ac-contact-modal-section ac-contact-modal-section--collector'])
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
                    ->extraAttributes(['class' => 'ac-contact-modal-section ac-contact-modal-section--secondary ac-contact-modal-section--ownership'])
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
                    ->extraAttributes(['class' => 'ac-contact-modal-section ac-contact-modal-section--secondary ac-contact-modal-section--phones'])
                    ->schema([
                        ViewEntry::make('phone_numbers')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.phone-numbers')
                            ->viewData(fn (Contact $record): array => static::buildPhoneNumbersViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Email')
                    ->extraAttributes(['class' => 'ac-contact-modal-section ac-contact-modal-section--secondary ac-contact-modal-section--emails'])
                    ->schema([
                        ViewEntry::make('contact_emails')
                            ->hiddenLabel()
                            ->view('filament.contacts.partials.contact-emails')
                            ->viewData(fn (Contact $record): array => static::buildContactEmailsViewData($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Подробности')
                    ->extraAttributes(['class' => 'ac-contact-modal-section ac-contact-modal-section--secondary ac-contact-modal-section--details'])
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
                        TextEntry::make('start_parameters')
                            ->label('Параметры перехода')
                            ->placeholder('—')
                            ->state(fn (Contact $record): ?string => static::formatContactStartParameters($record)),
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
        $contactFieldLabels = FieldDictionaryField::labelsFor(FieldDictionaryField::ENTITY_CONTACT);
        $contactFieldLabel = static fn (string $fieldKey, string $fallback): string => FieldDictionaryField::labelFrom($contactFieldLabels, $fieldKey, $fallback);

        return $table
            ->recordUrl(fn (Contact $record): string => static::getUrl('view', ['record' => $record]))
            ->splitSearchTerms(false)
            ->searchPlaceholder('Поиск')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Контакт')
                    ->html()
                    ->state(fn (Contact $record): HtmlString => static::renderContactTableDisplayName($record))
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
                TextColumn::make('tags_summary')
                    ->label('Теги')
                    ->toggleable()
                    ->html()
                    ->state(fn (Contact $record): HtmlString => static::renderContactTableTags($record)),
                TextColumn::make('primary_phone_raw')
                    ->label($contactFieldLabel('phones', 'Телефон'))
                    ->toggleable()
                    ->placeholder('—')
                    ->description(fn (Contact $record): ?string => static::formatPhoneCountSummary($record))
                    ->copyable(fn (Contact $record): bool => filled($record->getAttribute('primary_phone_raw')))
                    ->copyableState(fn (Contact $record): ?string => $record->getAttribute('primary_phone_raw')),
                TextColumn::make('latest_message_text')
                    ->label('Последнее сообщение')
                    ->toggleable()
                    ->placeholder('—')
                    ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessageDisplayText($record))
                    ->description(fn (Contact $record): ?string => static::formatLatestConversationMetaSummary($record))
                    ->limit(60)
                    ->tooltip(fn (Contact $record): ?string => static::resolveLatestConversationMessageTooltip($record)),
                TextColumn::make('latest_message_received_at')
                    ->label('Активность')
                    ->toggleable()
                    ->placeholder('—')
                    ->state(fn (Contact $record) => static::resolveLatestConversationMessageSortAt($record))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => static::applyLatestMessageActivitySort($query, $direction)),
                TextColumn::make('dedup_status')
                    ->label('Проверка дубля')
                    ->state(fn (Contact $record): string => static::formatDedupStatus($record))
                    ->badge()
                    ->color(fn (Contact $record): string => static::getDedupStatusColor($record))
                    ->toggleable(),
                TextColumn::make('id')
                    ->label($contactFieldLabel('id', 'ID'))
                    ->sortable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('latest_message_kind')
                    ->label('Тип')
                    ->toggleable()
                    ->placeholder('—')
                    ->state(fn (Contact $record): ?string => static::resolveLatestConversationMessage($record)?->message_kind)
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::formatMessageKind($state))
                    ->color(fn (?string $state): string => static::getMessageKindColor($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latest_message_channel')
                    ->label('Канал')
                    ->toggleable()
                    ->placeholder('—')
                    ->state(fn (Contact $record): ?string => static::formatLatestMessageChannel(static::resolveLatestConversationMessage($record)))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('primaryIdentity.external_user_id')
                    ->label('Внешний ID')
                    ->toggleable()
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('primaryIdentity.external_username')
                    ->label('Username')
                    ->toggleable()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? '@'.ltrim($state, '@') : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('messages_count')
                    ->label('Сообщений')
                    ->toggleable()
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone_count')
                    ->label($contactFieldLabel('phones', 'Телефонов'))
                    ->state(fn (Contact $record): int => (int) ($record->getAttribute('phone_count') ?? 0))
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label($contactFieldLabel('created_at', 'Создан'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('requires_manual_reply')
                    ->label('Требует ответа')
                    ->query(fn (Builder $query): Builder => static::applyRequiresManualReplyFilter($query)),
                Filter::make('assigned_to_me')
                    ->label('Назначены мне')
                    ->query(fn (Builder $query): Builder => static::applyAssignedToMeFilter($query)),
                Filter::make('unassigned_contacts')
                    ->label('Без ответственного')
                    ->query(fn (Builder $query): Builder => $query->whereNull('assigned_user_id')),
                Filter::make('duplicate_review_pending')
                    ->label('Нужна проверка дубля')
                    ->query(fn (Builder $query): Builder => $query->where('duplicate_review_status', Contact::DUPLICATE_REVIEW_STATUS_PENDING)),
                Filter::make('has_phone')
                    ->label('С телефоном')
                    ->query(fn (Builder $query): Builder => $query->whereHas('phoneNumbers')),
                Filter::make('without_phone')
                    ->label('Без телефона')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('phoneNumbers')),
                Filter::make('bitrix24_rescue_required')
                    ->label('Требуют синхронизации Bitrix24')
                    ->query(fn (Builder $query): Builder => static::applyBitrix24RescueSyncRequiredFilter($query)),
                SelectFilter::make('tags')
                    ->label('Теги')
                    ->multiple()
                    ->preload()
                    ->options(fn (): array => static::getTagFilterOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $tagIds = collect($data['values'] ?? [])
                            ->filter(fn (mixed $value): bool => filled($value))
                            ->map(fn (mixed $value): int => (int) $value)
                            ->values()
                            ->all();

                        if ($tagIds === []) {
                            return $query;
                        }

                        return $query->whereHas(
                            'tags',
                            fn (Builder $tagsQuery): Builder => $tagsQuery->whereKey($tagIds),
                        );
                    }),
            ])
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->button()
                    ->label('Фильтр')
                    ->extraAttributes(['class' => 'ac-contacts-filter-trigger'], merge: true),
            )
            ->columnManager()
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->defaultSort(fn (Builder $query, string $direction): Builder => static::applyLatestMessageActivitySort($query, $direction), 'desc')
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->emptyStateHeading('Контактов ещё нет')
            ->emptyStateDescription('Контакты появятся после первых входящих сообщений от внешней аудитории.')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                ViewAction::make()
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->color('gray')
                    ->tooltip('Просмотр')
                    ->hidden()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->mountUsing(function (Action $action, ?Schema $schema, ManageContacts $livewire): void {
                        $schema?->fill();
                        $livewire->showAssignContactDialog = false;
                        $livewire->selectedAssigneeId = '';
                        $livewire->showAddTagDialog = false;
                        $livewire->selectedTagId = '';
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
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->tooltip('Удалить')
                    ->visible(fn (Contact $record): bool => static::canDeleteContactFromUi($record))
                    ->authorize(fn (): bool => static::canCurrentUserDeleteContact())
                    ->modalHeading(fn (Contact $record): string => static::resolveDeleteContactModalHeading($record))
                    ->modalContent(fn (Contact $record): ViewContract => view(
                        'filament.contacts.partials.delete-contact-preview',
                        static::buildDeleteContactPreviewViewData($record),
                    ))
                    ->successNotificationTitle('Клиент удалён')
                    ->using(function (Contact $record): bool {
                        app(DeleteContactAction::class)->handle($record);

                        return true;
                    }),
            ])
            ->selectable()
            ->toolbarActions([
                BulkAction::make('queueBitrix24RescueSync')
                    ->label('Поставить выбранных в очередь Bitrix24')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Поставить выбранных в очередь Bitrix24')
                    ->modalDescription('Готовые контакты будут поставлены в очередь без сообщений клиентам. Контакты на ручной проверке и неготовые контакты будут пропущены.')
                    ->visible(fn (): bool => static::canCurrentUserRequestBitrix24RescueSync())
                    ->action(function (Collection $records): void {
                        static::queueSelectedContactsForBitrix24RescueSync($records);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageContacts::route('/'),
            'view' => ViewContact::route('/{record}'),
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
        if ($record->relationLoaded('latestInboundMessage')) {
            return $record->latestInboundMessage;
        }

        $latestInboundMessageId = $record->getAttribute('latest_inbound_message_id');

        if (filled($latestInboundMessageId)) {
            return Message::query()
                ->with('channel')
                ->find($latestInboundMessageId);
        }

        return $record->messages()
            ->with('channel')
            ->where('direction', Message::DIRECTION_INBOUND)
            ->tap(fn (Builder $query): Builder => static::messageChronology()->applyLatestOrder($query))
            ->first();
    }

    protected static function resolveLatestConversationMessage(Contact $record): ?Message
    {
        if ($record->relationLoaded('latestConversationMessage')) {
            return $record->latestConversationMessage;
        }

        $latestMessageId = $record->getAttribute('latest_message_id');

        if (filled($latestMessageId)) {
            return Message::query()
                ->with(['channel', 'replyTo'])
                ->find($latestMessageId);
        }

        return $record->messages()
            ->with(['channel', 'replyTo'])
            ->tap(fn (Builder $query): Builder => static::messageChronology()->applyLatestOrder($query))
            ->first();
    }

    protected static function resolveLatestConversationMessageSortAt(Contact $record): mixed
    {
        $latestMessage = static::resolveLatestConversationMessage($record);

        if ($latestMessage instanceof Message) {
            return static::messageChronology()->resolveSortAt($latestMessage);
        }

        return $record->getAttribute('latest_message_sort_at');
    }

    protected static function applyLatestMessageActivitySort(Builder $query, string $direction): Builder
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $nullsOrder = $direction === 'asc' ? 'FIRST' : 'LAST';

        return $query
            ->orderByRaw("latest_message_sort_at {$direction} NULLS {$nullsOrder}")
            ->orderBy('latest_message_id', $direction)
            ->orderBy('contacts.id', $direction);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function resolveLatestConversationMessageFeed(Contact $record): ?array
    {
        $message = static::resolveLatestConversationMessage($record);

        if (! $message instanceof Message) {
            return null;
        }

        /** @var array<int, array<string, mixed>|null> $cache */
        static $cache = [];

        if (array_key_exists($message->id, $cache)) {
            return $cache[$message->id];
        }

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(new Collection([$message]));

        return $cache[$message->id] = $feed[0] ?? null;
    }

    protected static function resolveLatestConversationMessageDisplayText(Contact $record): ?string
    {
        $feed = static::resolveLatestConversationMessageFeed($record);

        if (is_array($feed) && filled($feed['display_text'] ?? null)) {
            return (string) $feed['display_text'];
        }

        return static::resolveLatestConversationMessage($record)?->text;
    }

    protected static function resolveLatestConversationMessageTooltip(Contact $record): ?string
    {
        $displayText = static::resolveLatestConversationMessageDisplayText($record);
        $feed = static::resolveLatestConversationMessageFeed($record);

        $parts = [];

        if (filled($displayText)) {
            $parts[] = $displayText;
        }

        foreach ($feed['media_state_badges'] ?? [] as $badge) {
            if (is_array($badge) && filled($badge['label'] ?? null)) {
                $parts[] = (string) $badge['label'];
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' · ', array_values(array_unique($parts)));
    }

    protected static function contactRequiresManualReply(Contact $record): bool
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
        $chronology = static::messageChronology();
        $latestInboundUserMessageId = $chronology->latestContactMessageIdFragment(
            Message::KIND_INBOUND_USER,
        );
        $latestInboundUserMessageSortAt = $chronology->latestContactMessageSortAtFragment(
            Message::KIND_INBOUND_USER,
        );
        $latestOutboundManualReplyMessageId = $chronology->latestContactMessageIdFragment(
            Message::dialogAnswerMessageKinds(),
        );
        $latestOutboundManualReplyMessageSortAt = $chronology->latestContactMessageSortAtFragment(
            Message::dialogAnswerMessageKinds(),
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
        $currentUserId = static::resolveCurrentUserId();

        if ($currentUserId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('assigned_user_id', $currentUserId);
    }

    protected static function applyBitrix24RescueSyncRequiredFilter(Builder $query): Builder
    {
        $dealsSyncEnabled = (bool) config('bitrix24.features.deals_sync_enabled', false);
        $historySyncEnabled = (bool) config('bitrix24.features.timeline_history_import_enabled', false);

        return $query
            ->whereNull('contacts.merged_into_contact_id')
            ->where('data_collection_status', Contact::DATA_COLLECTION_STATUS_COMPLETED)
            ->whereNotNull('first_name')
            ->where('first_name', '!=', '')
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->whereNotNull('age_range')
            ->where('age_range', '!=', '')
            ->whereHas('phoneNumbers', function (Builder $query): Builder {
                return $query
                    ->whereNotNull('phone_normalized')
                    ->where('phone_normalized', '!=', '');
            })
            ->whereHas('identities', fn (Builder $query): Builder => $query->whereHas('channel'))
            ->where(function (Builder $query) use ($dealsSyncEnabled, $historySyncEnabled): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->whereIn('bitrix24_sync_status', [
                                Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
                                Contact::BITRIX24_SYNC_STATUS_FAILED,
                                Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW,
                            ])
                            ->where('bitrix24_sync_pending', false);
                    })
                    ->orWhere(function (Builder $query) use ($dealsSyncEnabled): void {
                        if (! $dealsSyncEnabled) {
                            $query->whereRaw('1 = 0');

                            return;
                        }

                        $query
                            ->whereNotNull('bitrix24_contact_id')
                            ->where('bitrix24_contact_id', '!=', '')
                            ->where('bitrix24_sync_status', Contact::BITRIX24_SYNC_STATUS_SYNCED)
                            ->where('bitrix24_sync_pending', false)
                            ->whereIn('bitrix24_deal_sync_status', [
                                Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED,
                                Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED,
                                Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW,
                            ])
                            ->where('bitrix24_deal_sync_pending', false);
                    })
                    ->orWhere(function (Builder $query) use ($historySyncEnabled): void {
                        if (! $historySyncEnabled) {
                            $query->whereRaw('1 = 0');

                            return;
                        }

                        $query
                            ->whereNotNull('bitrix24_contact_id')
                            ->where('bitrix24_contact_id', '!=', '')
                            ->where('bitrix24_sync_status', Contact::BITRIX24_SYNC_STATUS_SYNCED)
                            ->where('bitrix24_sync_pending', false)
                            ->whereIn('bitrix24_history_sync_status', [
                                Contact::BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED,
                                Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED,
                            ])
                            ->where('bitrix24_history_sync_pending', false);
                    });
            });
    }

    protected static function canCurrentUserRequestBitrix24RescueSync(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasRolePermission('bitrix24.edit');
    }

    protected static function queueSelectedContactsForBitrix24RescueSync(Collection $records): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasRolePermission('bitrix24.edit')) {
            Notification::make()
                ->danger()
                ->title('Не удалось запустить синхронизацию')
                ->body('Для ручной синхронизации с Bitrix24 нужно право bitrix24.edit.')
                ->send();

            return;
        }

        $summary = [
            'queued' => 0,
            'already_pending' => 0,
            'not_ready' => 0,
            'needs_manual_review' => 0,
            'synced' => 0,
            'feature_disabled' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $details = [];

        foreach ($records as $record) {
            if (! $record instanceof Contact) {
                continue;
            }

            try {
                $result = app(QueueBitrix24RescueSyncAction::class)->handle($record, $user);
                $summary[$result->status] = ($summary[$result->status] ?? 0) + 1;

                if (
                    ! $result->queuedContact
                    && ! $result->queuedDeal
                    && ! $result->queuedHistory
                    && (
                        in_array('deals_sync_disabled', $result->skippedReasons, true)
                        || in_array('history_sync_disabled', $result->skippedReasons, true)
                    )
                ) {
                    $summary['feature_disabled']++;
                }
            } catch (Throwable $throwable) {
                $summary['errors']++;

                if (count($details) < 3) {
                    $details[] = '#'.$record->id.': '.$throwable->getMessage();
                }
            }
        }

        $lines = static::formatBitrix24RescueSyncBulkSummary($summary);

        if ($details !== []) {
            $lines[] = 'Ошибки: '.implode(' · ', $details);
        }

        $notification = Notification::make()
            ->title($summary['errors'] > 0 ? 'Синхронизация выполнена частично' : 'Синхронизация поставлена в очередь')
            ->body(implode("\n", $lines));

        if ($summary['errors'] > 0) {
            $notification->warning();
        } elseif (($summary['queued'] ?? 0) > 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->send();
    }

    /**
     * @param  array<string, int>  $summary
     * @return list<string>
     */
    protected static function formatBitrix24RescueSyncBulkSummary(array $summary): array
    {
        $labels = [
            'queued' => 'Поставлено в очередь',
            'already_pending' => 'Уже было в очереди',
            'not_ready' => 'Не готово',
            'needs_manual_review' => 'Нужна ручная проверка',
            'synced' => 'Уже полностью синхронизировано',
            'feature_disabled' => 'Функция отключена',
            'skipped' => 'Пропущено',
            'errors' => 'Ошибки',
        ];

        $lines = [];

        foreach ($labels as $key => $label) {
            $count = (int) ($summary[$key] ?? 0);

            if ($count > 0) {
                $lines[] = $label.': '.$count;
            }
        }

        return $lines === [] ? ['Нет выбранных контактов для обработки.'] : $lines;
    }

    protected static function applyTableSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $phoneSearchDigits = static::resolvePhoneSearchDigits($search);
        $usernameSearch = static::resolveUsernameSearch($search);
        $likeSearch = "%{$search}%";

        return $query->where(function (Builder $query) use ($likeSearch, $phoneSearchDigits, $usernameSearch): void {
            $query->where('first_name', 'ilike', $likeSearch)
                ->orWhere('last_name', 'ilike', $likeSearch)
                ->orWhereRaw("trim(coalesce(first_name, '') || ' ' || coalesce(last_name, '')) ilike ?", [$likeSearch])
                ->orWhereRaw("trim(coalesce(last_name, '') || ' ' || coalesce(first_name, '')) ilike ?", [$likeSearch])
                ->orWhere('name', 'ilike', $likeSearch)
                ->orWhereHas('identities', function (Builder $identityQuery) use ($likeSearch, $usernameSearch): void {
                    $identityQuery
                        ->where('display_name', 'ilike', $likeSearch)
                        ->orWhere('external_user_id', 'ilike', $likeSearch)
                        ->orWhere('external_username', 'ilike', $likeSearch);

                    if ($usernameSearch !== null) {
                        $identityQuery->orWhere('external_username', 'ilike', "%{$usernameSearch}%");
                    }
                });

            if ($phoneSearchDigits !== null) {
                $query->orWhereHas('phoneNumbers', function (Builder $phoneQuery) use ($phoneSearchDigits): void {
                    static::whereDigitOnlyPhoneLike($phoneQuery, 'phone_raw', 'phone_normalized', $phoneSearchDigits);
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

    protected static function buildLatestMessageIdSubquery(?Closure $scope = null): Builder
    {
        return static::messageChronology()->latestMessageIdSubquery(
            'contact_id',
            'contacts.id',
            $scope,
        );
    }

    protected static function buildLatestMessageSortAtSubquery(?Closure $scope = null): Builder
    {
        return static::messageChronology()->latestMessageSortAtSubquery(
            'contact_id',
            'contacts.id',
            $scope,
        );
    }

    protected static function messageChronology(): MessageChronology
    {
        return app(MessageChronology::class);
    }

    /**
     * @return array{
     *     firstName: ?string,
     *     lastName: ?string,
     *     genderLabel: string,
     *     effectiveAgeLabel: string,
     *     ageRangeLabel: string,
     *     birthDateLabel: string,
     *     country: string,
     *     city: string,
     *     region: string,
     *     regionStatusLabel: string,
     *     distanceToMoscowLabel: string,
     *     distanceToMoscowStatusLabel: string,
     *     genderOptions: array<string, string>,
     *     ageRangeOptions: array<string, string>,
     *     regionOptions: array<string, string>
     * }
     */
    public static function buildContactProfileViewData(Contact $record): array
    {
        $effectiveAgeYears = $record->effective_age_years;
        $ageSourceLabel = $record->birth_date !== null
            ? 'из даты рождения'
            : ($record->age_years !== null ? 'указан вручную' : null);
        $genderOptionLabels = FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_CONTACT, 'gender');
        $ageRangeOptionLabels = FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_CONTACT, 'age_range');

        $effectiveAgeLabel = $effectiveAgeYears !== null
            ? sprintf('%d лет%s', $effectiveAgeYears, $ageSourceLabel !== null ? ' ('.$ageSourceLabel.')' : '')
            : '—';

        return [
            'firstName' => $record->first_name,
            'lastName' => $record->last_name,
            'genderLabel' => FieldDictionaryField::optionLabelFrom($genderOptionLabels, $record->gender, Contact::formatGender($record->gender)),
            'effectiveAgeLabel' => $effectiveAgeLabel,
            'ageRangeLabel' => FieldDictionaryField::optionLabelFrom($ageRangeOptionLabels, $record->age_range, Contact::formatAgeRange($record->age_range)),
            'birthDateLabel' => $record->birth_date?->format('d.m.Y') ?? '—',
            'country' => $record->country ?? '—',
            'city' => $record->city ?? '—',
            'region' => $record->region ?? '—',
            'regionStatusLabel' => Contact::formatRegionStatus($record->region_status),
            'distanceToMoscowLabel' => $record->distance_to_moscow_km !== null
                ? $record->distance_to_moscow_km.' км'
                : '—',
            'distanceToMoscowStatusLabel' => Contact::formatDistanceToMoscowStatus($record->distance_to_moscow_status),
            'genderOptions' => static::applyDictionaryOptionLabels(Contact::genderOptions(), $genderOptionLabels),
            'ageRangeOptions' => static::applyDictionaryOptionLabels(Contact::ageRangeOptions(), $ageRangeOptionLabels),
            'regionOptions' => Contact::russianRegionOptions(),
            'canEditProfile' => static::canCurrentUserManageContactProfile(),
        ];
    }

    /**
     * @param  array<string, string>  $fallbackOptions
     * @param  array<string, string>  $dictionaryLabels
     * @return array<string, string>
     */
    protected static function applyDictionaryOptionLabels(array $fallbackOptions, array $dictionaryLabels): array
    {
        return collect($fallbackOptions)
            ->mapWithKeys(fn (string $label, string $value): array => [
                $value => FieldDictionaryField::optionLabelFrom($dictionaryLabels, $value, $label),
            ])
            ->all();
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
    public static function buildCollectorStatusViewData(Contact $record): array
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
            'canResumeAction' => static::canCurrentUserManageContactProfile(),
            'firstName' => $record->first_name ?: '—',
            'country' => $record->country ?: '—',
            'city' => $record->city ?: '—',
            'ageRange' => Contact::formatAgeRange($record->age_range),
        ];
    }

    /**
     * @return array{
     *     hasLatestInboundMessage: bool,
     *     latestInboundRows: list<array{label:string,key:string,value:string}>,
     *     latestInboundPayload: ?string,
     *     routeContextRows: list<array{label:string,key:string,value:string}>,
     *     identityRows: list<array{label:string,key:string,value:string}>,
     *     technicalContactRows: list<array{label:string,key:string,value:string}>,
     *     dedupRows: list<array{label:string,key:string,value:string}>
     * }
     */
    public static function buildDiagnosticsViewData(Contact $record): array
    {
        $latestInboundMessage = static::resolveLatestInboundMessage($record);

        if ($latestInboundMessage instanceof Message) {
            $latestInboundMessage->loadMissing([
                'dialog.channel',
                'dialog.currentContactIdentity.channel',
                'contactIdentity.channel',
            ]);
        }

        $diagnosticsDialog = static::resolveDiagnosticsDialog($record, $latestInboundMessage);
        $diagnosticsIdentity = static::resolveDiagnosticsIdentity($record, $diagnosticsDialog, $latestInboundMessage);

        $record->loadMissing('mergedInto');

        return [
            'hasLatestInboundMessage' => $latestInboundMessage instanceof Message,
            'latestInboundRows' => [
                [
                    'label' => 'Последний внешний message ID',
                    'key' => 'external_message_id',
                    'value' => $latestInboundMessage?->external_message_id ?: '—',
                ],
                [
                    'label' => 'Provider event key',
                    'key' => 'provider_event_key',
                    'value' => $latestInboundMessage?->provider_event_key ?: '—',
                ],
                [
                    'label' => 'Распарсенное received_at',
                    'key' => 'received_at',
                    'value' => $latestInboundMessage?->received_at?->format('d.m.Y H:i:s') ?? '—',
                ],
                [
                    'label' => 'Автоответ отправлен',
                    'key' => 'auto_reply_sent_at',
                    'value' => $latestInboundMessage?->auto_reply_sent_at?->format('d.m.Y H:i:s') ?? '—',
                ],
                [
                    'label' => 'Статус автоответа',
                    'key' => 'reply_status',
                    'value' => static::formatMessageReplyStatus($latestInboundMessage) ?? '—',
                ],
            ],
            'latestInboundPayload' => filled($latestInboundMessage?->raw_payload)
                ? static::encodeJsonPayload($latestInboundMessage->raw_payload)
                : null,
            'routeContextRows' => [
                [
                    'label' => 'Dialog ID',
                    'key' => 'dialog_id',
                    'value' => $diagnosticsDialog instanceof Dialog ? (string) $diagnosticsDialog->id : '—',
                ],
                [
                    'label' => 'Канал',
                    'key' => 'channel',
                    'value' => static::formatChannelLabel($diagnosticsDialog?->channel),
                ],
                [
                    'label' => 'External chat ID',
                    'key' => 'external_chat_id',
                    'value' => $diagnosticsDialog?->external_chat_id ?: '—',
                ],
                [
                    'label' => 'Current contact identity ID',
                    'key' => 'current_contact_identity_id',
                    'value' => $diagnosticsDialog?->current_contact_identity_id !== null
                        ? (string) $diagnosticsDialog->current_contact_identity_id
                        : '—',
                ],
                [
                    'label' => 'Последнее сообщение',
                    'key' => 'last_message_at',
                    'value' => $diagnosticsDialog?->last_message_at?->format('d.m.Y H:i:s') ?? '—',
                ],
                [
                    'label' => 'Последнее входящее',
                    'key' => 'last_inbound_at',
                    'value' => $diagnosticsDialog?->last_inbound_at?->format('d.m.Y H:i:s') ?? '—',
                ],
                [
                    'label' => 'Последнее исходящее',
                    'key' => 'last_outbound_at',
                    'value' => $diagnosticsDialog?->last_outbound_at?->format('d.m.Y H:i:s') ?? '—',
                ],
            ],
            'identityRows' => [
                [
                    'label' => 'Contact identity ID',
                    'key' => 'contact_identity_id',
                    'value' => $diagnosticsIdentity?->id !== null ? (string) $diagnosticsIdentity->id : '—',
                ],
                [
                    'label' => 'External user ID',
                    'key' => 'external_user_id',
                    'value' => $diagnosticsIdentity?->external_user_id ?: '—',
                ],
                [
                    'label' => 'External username',
                    'key' => 'external_username',
                    'value' => filled($diagnosticsIdentity?->external_username)
                        ? '@'.ltrim((string) $diagnosticsIdentity->external_username, '@')
                        : '—',
                ],
                [
                    'label' => 'Channel ID',
                    'key' => 'channel_id',
                    'value' => $diagnosticsIdentity?->channel_id !== null ? (string) $diagnosticsIdentity->channel_id : '—',
                ],
            ],
            'technicalContactRows' => [
                [
                    'label' => 'Источник пола',
                    'key' => 'gender_source',
                    'value' => Contact::formatGenderSource($record->gender_source),
                ],
                [
                    'label' => 'Возраст из БД',
                    'key' => 'age_years',
                    'value' => $record->age_years !== null ? (string) $record->age_years : '—',
                ],
            ],
            'dedupRows' => [
                [
                    'label' => 'Статус дедупликации',
                    'key' => 'duplicate_review_status',
                    'value' => static::formatDedupStatusLabel($record->duplicate_review_status),
                ],
                [
                    'label' => 'Основной контакт',
                    'key' => 'merged_into_contact_id',
                    'value' => $record->mergedInto !== null
                        ? sprintf('#%d %s', $record->mergedInto->id, $record->mergedInto->display_name)
                        : '—',
                ],
                [
                    'label' => 'Склеен',
                    'key' => 'merged_at',
                    'value' => $record->merged_at?->format('d.m.Y H:i:s') ?? '—',
                ],
                [
                    'label' => 'Причина',
                    'key' => 'merge_reason',
                    'value' => static::formatMergeReason($record->merge_reason),
                ],
                [
                    'label' => 'Триггерный телефон',
                    'key' => 'merge_trigger_phone',
                    'value' => $record->merge_trigger_phone ?: '—',
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     isMerged: bool,
     *     dedupStatusLabel: string,
     *     dedupStatusTone: string,
     *     openReviewsCount: int,
     *     openReviews: array<int, array{
     *         id: int,
     *         typeLabel: string,
     *         phoneLabel: string|null,
     *         identityLabel: string|null,
     *         candidateRootsLabel: string,
     *         channelContextLabel: string|null,
     *         createdAtLabel: string,
     *         isCrossChannelIdentityReview: bool,
     *         canManageLifecycle: bool
     *     }>,
     *     mergedChildrenCount: int,
     *     mergedChildren: array<int, array{
     *         id: int,
     *         mergedAtLabel: string,
     *         triggerPhone: string,
     *         reasonLabel: string
     *     }>,
     *     rootContactLabel: string,
     *     mergedAtLabel: string,
     *     mergeReasonLabel: string,
     *     mergeTriggerPhone: string
     * }
     */
    public static function buildDedupStatusViewData(Contact $record): array
    {
        $record->loadMissing([
            'mergedInto',
            'openDuplicateReviews',
            'recentMergedChildren',
        ]);
        $visibleOpenReviews = static::resolveVisibleOpenDuplicateReviews($record);
        $openReviewsCount = $visibleOpenReviews->count();
        $mergedChildrenCount = static::resolveMergedChildrenCount($record);

        $mergedChildren = $record->recentMergedChildren->take(5);

        $dedupStatusLabel = $record->isMerged()
            ? 'Архивный дубль'
            : ($record->duplicate_review_status === Contact::DUPLICATE_REVIEW_STATUS_PENDING
                ? 'Нужна проверка'
                : ($mergedChildrenCount > 0 ? 'Есть история склейки' : '—'));

        $dedupStatusTone = $record->duplicate_review_status === Contact::DUPLICATE_REVIEW_STATUS_PENDING
            ? 'warning'
            : ($record->isMerged() || ($mergedChildrenCount > 0) ? 'info' : 'gray');

        return [
            'isMerged' => $record->isMerged(),
            'dedupStatusLabel' => $dedupStatusLabel,
            'dedupStatusTone' => $dedupStatusTone,
            'openReviewsCount' => $openReviewsCount,
            'openReviews' => $visibleOpenReviews
                ->map(fn (ContactDuplicateReview $review): array => [
                    'id' => $review->id,
                    'typeLabel' => static::formatDuplicateReviewType($review->review_type),
                    'phoneLabel' => static::formatDuplicateReviewPhoneLabel($review),
                    'identityLabel' => static::formatDuplicateReviewIdentityLabel($review),
                    'candidateRootsLabel' => static::formatCandidateRootIds($review->candidate_root_contact_ids),
                    'channelContextLabel' => static::formatDuplicateReviewChannelContext($review),
                    'createdAtLabel' => $review->created_at?->format('d.m.Y H:i') ?? '—',
                    'isCrossChannelIdentityReview' => $review->review_type === ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY,
                    'canManageLifecycle' => static::canCurrentUserManageContactMutations(),
                ])
                ->all(),
            'mergedChildrenCount' => $mergedChildrenCount,
            'mergedChildren' => $mergedChildren
                ->map(fn (Contact $mergedChild): array => [
                    'id' => $mergedChild->id,
                    'mergedAtLabel' => $mergedChild->merged_at?->format('d.m.Y H:i') ?? '—',
                    'triggerPhone' => $mergedChild->merge_trigger_phone ?: '—',
                    'reasonLabel' => static::formatMergeReason($mergedChild->merge_reason),
                ])
                ->all(),
            'rootContactLabel' => $record->mergedInto !== null
                ? sprintf('#%d %s', $record->mergedInto->id, $record->mergedInto->display_name)
                : '—',
            'mergedAtLabel' => $record->merged_at?->format('d.m.Y H:i') ?? '—',
            'mergeReasonLabel' => static::formatMergeReason($record->merge_reason),
            'mergeTriggerPhone' => $record->merge_trigger_phone ?: '—',
        ];
    }

    public static function shouldShowDedupStatusSection(Contact $record): bool
    {
        if ($record->isMerged()) {
            return true;
        }

        if ($record->duplicate_review_status === Contact::DUPLICATE_REVIEW_STATUS_PENDING) {
            return true;
        }

        return static::resolveOpenDuplicateReviewsCount($record) > 0
            || static::resolveMergedChildrenCount($record) > 0;
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

    protected static function formatChannelLabel(?Channel $channel, string $fallback = '—'): string
    {
        if ($channel === null) {
            return $fallback;
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: $fallback;
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
            Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE => 'Исходящее из Telegram',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'Вопрос сбора данных',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'Сбор данных завершён',
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
            Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE => 'success',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'info',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'success',
            default => 'gray',
        };
    }

    public static function formatDataCollectionStatus(?string $status): string
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

    public static function formatDataCollectionField(?string $field): string
    {
        return match ($field) {
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME => 'Имя',
            Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY => 'Город проживания',
            Contact::DATA_COLLECTION_FIELD_COUNTRY => 'Страна',
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => 'Регион',
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

        return static::formatChannelLabel($channel);
    }

    protected static function formatContactTableIdentitySummary(Contact $record): ?string
    {
        $record->loadMissing('primaryIdentity.channel');

        $parts = [];
        $channelLabel = static::formatChannelLabel($record->primaryIdentity?->channel, '');

        if ($channelLabel !== '') {
            $parts[] = $channelLabel;
        }

        $externalUsername = $record->primaryIdentity?->external_username;
        $externalUserId = $record->primaryIdentity?->external_user_id;

        if (filled($externalUsername)) {
            $parts[] = '@'.ltrim((string) $externalUsername, '@');
        } elseif (filled($externalUserId)) {
            $parts[] = 'ID: '.$externalUserId;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    protected static function formatPhoneCountSummary(Contact $record): ?string
    {
        $phoneCount = (int) ($record->getAttribute('phone_count') ?? 0);

        if ($phoneCount <= 0) {
            return 'Телефон ещё не сохранён';
        }

        return sprintf('Всего номеров: %d', $phoneCount);
    }

    protected static function formatLatestConversationMetaSummary(Contact $record): ?string
    {
        $message = static::resolveLatestConversationMessage($record);
        $feed = static::resolveLatestConversationMessageFeed($record);

        if (! $message instanceof Message) {
            return null;
        }

        $parts = [static::formatMessageKind($message->message_kind)];
        $channelLabel = static::formatLatestMessageChannel($message);

        if (filled($channelLabel)) {
            $parts[] = $channelLabel;
        }

        foreach ($feed['media_state_badges'] ?? [] as $badge) {
            if (is_array($badge) && filled($badge['label'] ?? null)) {
                $parts[] = (string) $badge['label'];
            }
        }

        return implode(' · ', $parts);
    }

    protected static function formatDedupStatus(Contact $record): string
    {
        return $record->duplicate_review_status === Contact::DUPLICATE_REVIEW_STATUS_PENDING
            ? 'Нужна проверка'
            : '—';
    }

    protected static function formatDedupStatusLabel(?string $value): string
    {
        return match ($value) {
            Contact::DUPLICATE_REVIEW_STATUS_PENDING => 'Нужна проверка',
            Contact::DUPLICATE_REVIEW_STATUS_RESOLVED => 'Разобрано',
            Contact::DUPLICATE_REVIEW_STATUS_NONE, null, '' => '—',
            default => (string) $value,
        };
    }

    protected static function getDedupStatusColor(Contact $record): string
    {
        return $record->duplicate_review_status === Contact::DUPLICATE_REVIEW_STATUS_PENDING
            ? 'warning'
            : 'gray';
    }

    /**
     * @param  array<int, int>|null  $candidateRootIds
     */
    protected static function formatCandidateRootIds(?array $candidateRootIds): string
    {
        if (blank($candidateRootIds)) {
            return '—';
        }

        return collect($candidateRootIds)
            ->map(fn (mixed $id): string => '#'.(string) $id)
            ->implode(', ');
    }

    protected static function formatDuplicateReviewType(?string $reviewType): string
    {
        return match ($reviewType) {
            ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE => 'Телефон найден у другого root-контакта',
            ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS => 'Телефон найден у нескольких root-контактов',
            ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY => 'Один platform user ID привязан к нескольким root-контактам',
            ContactDuplicateReview::TYPE_MERGE_CONFLICT => 'Не удалось безопасно склеить контакт',
            ContactDuplicateReview::TYPE_BROKEN_MERGE_CHAIN => 'Повреждена цепочка склейки',
            default => 'Требуется проверка дубля',
        };
    }

    protected static function formatDuplicateReviewPhoneLabel(ContactDuplicateReview $review): ?string
    {
        return filled($review->phone_normalized)
            ? (string) $review->phone_normalized
            : null;
    }

    protected static function formatDuplicateReviewIdentityLabel(ContactDuplicateReview $review): ?string
    {
        return filled($review->identity_key)
            ? (string) $review->identity_key
            : null;
    }

    protected static function formatDuplicateReviewChannelContext(ContactDuplicateReview $review): ?string
    {
        $channelId = data_get($review->context_payload, 'last_seen_channel_id');

        if (! is_numeric($channelId)) {
            return null;
        }

        $channelId = (int) $channelId;
        $channel = Channel::query()->find($channelId);

        if ($channel instanceof Channel) {
            return static::formatChannelLabel($channel, '#'.$channelId);
        }

        return 'Канал #'.$channelId;
    }

    protected static function formatMergeReason(?string $mergeReason): string
    {
        return match ($mergeReason) {
            'phone_exact_match' => 'Совпадение телефона',
            'cross_channel_identity_resolution' => 'Разрешение cross-channel identity ambiguity',
            null, '' => '—',
            default => $mergeReason,
        };
    }

    public static function buildOwnershipControlsViewData(Contact $record): array
    {
        $record->loadMissing('assignedUser');

        return [
            'assignedUserLabel' => static::formatAssignedUserLabel($record),
            'availableAssignees' => static::getAssignableUserOptions(),
            'ownershipHint' => static::getOwnershipHint($record),
            'autoReplyEnabled' => $record->isAutoReplyEnabled(),
            'autoReplyStatusLabel' => $record->isAutoReplyEnabled() ? 'Включены' : 'Отключены',
            'canManageOwnership' => static::canCurrentUserManageContactOwnership(),
            'canManageAutoReply' => static::canCurrentUserManageContactProfile(),
            'canDeleteContact' => static::canDeleteContactFromUi($record),
            'deleteBlockedReason' => static::canCurrentUserManageContactMutations()
                ? static::getDeleteBlockedReason($record)
                : null,
        ];
    }

    /**
     * @return array{
     *     canManageTags: bool,
     *     tags: array<int, array{id:int,name:string,slug:string,color:string,is_active:bool}>,
     *     availableTags: array<int, string>
     * }
     */
    public static function buildTagsViewData(Contact $record): array
    {
        $record->loadMissing('tags');
        $assignedTagIds = $record->tags
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'canManageTags' => static::canCurrentUserManageContactMutations(),
            'tags' => $record->tags
                ->sortBy('name')
                ->values()
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'color' => $tag->color,
                    'is_active' => $tag->is_active,
                ])
                ->all(),
            'availableTags' => static::getAvailableTagOptions($assignedTagIds),
        ];
    }

    protected static function formatContactStartParameters(Contact $record): ?string
    {
        $record->loadMissing('startTags');

        $codes = $record->startTags
            ->filter(fn (ContactStartTag $tag): bool => $tag->category === ContactStartTag::CATEGORY_START_PAYLOAD)
            ->pluck('code')
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->values()
            ->all();

        return $codes !== [] ? implode(', ', $codes) : null;
    }

    /**
     * @return array{
     *     phoneNumbers: array<int, array{id:int, phone:string, source:string, is_primary:bool}>,
     *     canEditPhones: bool,
     *     canDeletePhones: bool
     * }
     */
    public static function buildPhoneNumbersViewData(Contact $record): array
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
            'canEditPhones' => static::canCurrentUserEditExistingContactPhones(),
            'canDeletePhones' => static::canCurrentUserDeleteExistingContactPhones(),
        ];
    }

    /**
     * @return array{
     *     emails: array<int, array{id:int,email:string,source:string,is_primary:bool}>,
     *     canEditEmails: bool,
     *     canDeleteEmails: bool
     * }
     */
    public static function buildContactEmailsViewData(Contact $record): array
    {
        $emails = $record->relationLoaded('emails')
            ? $record->emails
            : $record->emails()->get();

        return [
            'emails' => $emails
                ->map(fn (ContactEmail $email): array => [
                    'id' => $email->id,
                    'email' => $email->email_raw,
                    'source' => ContactEmail::sourceLabel($email->source),
                    'is_primary' => $email->is_primary,
                ])
                ->all(),
            'canEditEmails' => static::canCurrentUserEditExistingContactPhones(),
            'canDeleteEmails' => static::canCurrentUserDeleteExistingContactPhones(),
        ];
    }

    protected static function renderContactTableTags(Contact $record): HtmlString
    {
        $record->loadMissing('tags');

        if ($record->tags->isEmpty()) {
            return new HtmlString('—');
        }

        $visibleTags = $record->tags
            ->sortBy('name')
            ->take(3)
            ->map(fn (Tag $tag): string => sprintf(
                '<span class="ac-pill" data-tone="%s">%s</span>',
                e($tag->color),
                e($tag->name),
            ))
            ->implode(' ');

        $remainingCount = $record->tags->count() - min($record->tags->count(), 3);

        if ($remainingCount > 0) {
            $visibleTags .= ' '.sprintf(
                '<span class="ac-pill" data-tone="gray">+%d</span>',
                $remainingCount,
            );
        }

        return new HtmlString($visibleTags);
    }

    protected static function renderContactTableDisplayName(Contact $record): HtmlString
    {
        $displayName = e($record->display_name);
        $label = Contact::formatFirstNameSourceBadgeLabel($record->first_name_source);
        $tone = Contact::firstNameSourceBadgeTone($record->first_name_source);

        if (! filled($record->first_name) || $label === null || $tone === null) {
            return new HtmlString($displayName);
        }

        return new HtmlString(sprintf(
            '<span class="inline-flex items-center gap-2"><span>%s</span><span class="ac-pill" data-tone="%s">%s</span></span>',
            $displayName,
            e($tone),
            e($label),
        ));
    }

    /**
     * @return array{
     *     dialogs: list<array{
     *         id:int,
     *         url:string,
     *         channel_label:string,
     *         route_status_label:string,
     *         route_status_tone:string,
     *         stage_label:string,
     *         stage_tone:string,
     *         messenger_name_label:string,
     *         phone_label:string,
     *         route_identity_label:string,
     *         external_chat_id_label:string,
     *         last_message_label:string,
     *         last_inbound_label:string,
     *         last_outbound_label:string,
     *         preview_text:string,
     *         preview_sender_label:?string,
     *         preview_sender_tone:?string,
     *         preview_media_state_badges:list<array{label:string,tone:string}>
     *     }>
     * }
     */
    public static function buildDialogsViewData(Contact $record): array
    {
        return [
            'dialogs' => app(LoadContactDialogsOverviewAction::class)->handle($record)->all(),
            'fieldLabels' => FieldDictionaryField::labelsFor(FieldDictionaryField::ENTITY_DIALOG),
        ];
    }

    /**
     * @return array{
     *     items:list<array{
     *         type:string,
     *         title:string,
     *         description:?string,
     *         body:?string,
     *         actorName:?string,
     *         timestampLabel:string
     *     }>,
     *     hasMore:bool,
     *     visibleCount:int,
     *     totalCount:int
     * }
     */
    public static function buildHistoryTimelineViewData(Contact $record, int $visibleCount = 20): array
    {
        $timelineItems = app(BuildContactHistoryTimelineAction::class)
            ->handle($record)
            ->values();

        $visibleCount = max(20, $visibleCount);
        $totalCount = $timelineItems->count();

        return [
            'items' => $timelineItems->take($visibleCount)->all(),
            'hasMore' => $totalCount > $visibleCount,
            'visibleCount' => min($visibleCount, $totalCount),
            'totalCount' => $totalCount,
        ];
    }

    protected static function formatDialogTimestamp(?Carbon $timestamp): string
    {
        return $timestamp?->format('d.m.Y H:i') ?? '—';
    }

    protected static function formatPhoneSource(?string $source): string
    {
        return match ($source) {
            ContactPhoneNumber::SOURCE_TELEGRAM_CONTACT_SHARE => 'Telegram contact share',
            ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE => 'MAX contact share',
            ContactPhoneNumber::SOURCE_V3_CAPTURE => 'V3-конструктор',
            default => $source ?? '—',
        };
    }

    /**
     * @return array<int, string>
     */
    protected static function getAssignableUserOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->canBeAssignedToContacts())
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->getFilamentName()])
            ->all();
    }

    /**
     * @param  list<int>  $excludedTagIds
     * @return array<int, string>
     */
    protected static function getAvailableTagOptions(array $excludedTagIds = []): array
    {
        return Tag::query()
            ->active()
            ->when(
                $excludedTagIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('id', $excludedTagIds),
            )
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [$tag->id => $tag->name])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function getTagFilterOptions(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [$tag->id => $tag->name])
            ->all();
    }

    protected static function resolveCurrentUserId(): ?int
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->id;
    }

    public static function formatAssignedUserLabel(Contact $record): string
    {
        $record->loadMissing('assignedUser');

        return $record->assignedUser instanceof User
            ? $record->assignedUser->getFilamentName()
            : 'Свободен';
    }

    protected static function getOwnershipHint(Contact $record): ?string
    {
        return match (static::getContactOwnershipState($record)) {
            'mine' => 'Контакт закреплён за вами.',
            'other' => $record->assignedUser instanceof User
                ? 'Контакт закреплён за '.$record->assignedUser->getFilamentName().'.'
                : 'Контакт уже назначен другому сотруднику.',
            default => 'Контакт пока свободен. Можно выбрать ответственного или оставить контакт свободным.',
        };
    }

    protected static function canDeleteContactFromUi(Contact $record): bool
    {
        return static::canCurrentUserDeleteContact()
            && static::getDeleteBlockedReason($record) === null;
    }

    protected static function getDeleteBlockedReason(Contact $record): ?string
    {
        if (! static::canCurrentUserDeleteContact()) {
            return 'Удаление контакта недоступно по текущим правам.';
        }

        $blockingReview = app(FindOpenCrossChannelIdentityAmbiguityReviewForContactsAction::class)
            ->handle($record);

        if (! $blockingReview instanceof ContactDuplicateReview) {
            return null;
        }

        return sprintf(
            'Удаление заблокировано: контакт участвует в открытой cross-channel identity проверке (%s).',
            $blockingReview->identity_key ?: 'без identity_key',
        );
    }

    protected static function canCurrentUserDeleteContact(): bool
    {
        return static::currentUser()?->canDeleteContacts() ?? false;
    }

    protected static function canCurrentUserManageContactProfile(): bool
    {
        return static::currentUser()?->canManageContactProfile() ?? false;
    }

    protected static function canCurrentUserEditExistingContactPhones(): bool
    {
        return static::currentUser()?->canEditExistingContactPhones() ?? false;
    }

    protected static function canCurrentUserDeleteExistingContactPhones(): bool
    {
        return static::currentUser()?->canDeleteExistingContactPhones() ?? false;
    }

    protected static function canCurrentUserManageContactMutations(): bool
    {
        return static::currentUser()?->canManageContactWorkspaceMutations() ?? false;
    }

    protected static function canCurrentUserManageContactOwnership(): bool
    {
        return static::currentUser()?->canManageContactOwnership() ?? false;
    }

    public static function canCurrentUserViewContactDiagnostics(): bool
    {
        return static::currentUser()?->canManageSystem() ?? false;
    }

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected static function resolveDiagnosticsDialog(Contact $record, ?Message $latestInboundMessage): ?Dialog
    {
        $dialog = $latestInboundMessage?->dialog;

        if ($dialog instanceof Dialog) {
            $dialog->loadMissing(['channel', 'currentContactIdentity.channel']);

            return $dialog;
        }

        return $record->dialogs()
            ->with(['channel', 'currentContactIdentity.channel'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();
    }

    protected static function resolveDiagnosticsIdentity(
        Contact $record,
        ?Dialog $diagnosticsDialog,
        ?Message $latestInboundMessage,
    ): ?ContactIdentity {
        $identity = $diagnosticsDialog?->currentContactIdentity;

        if ($identity instanceof ContactIdentity) {
            $identity->loadMissing('channel');

            return $identity;
        }

        $identity = $latestInboundMessage?->contactIdentity;

        if ($identity instanceof ContactIdentity) {
            $identity->loadMissing('channel');

            return $identity;
        }

        $record->loadMissing('primaryIdentity.channel');

        return $record->primaryIdentity;
    }

    protected static function resolveDeleteContactPreview(Contact $record): ResolvedContactDeletePreviewResult
    {
        return app(ResolveContactDeletePreviewAction::class)->handle($record);
    }

    protected static function buildDeleteContactPreviewViewData(Contact $record): array
    {
        $preview = static::resolveDeleteContactPreview($record);

        return [
            'contactLabel' => $preview->rootContact->display_name,
            'hasMergeHistory' => $preview->hasMergeHistory,
            'counts' => static::formatDeleteContactPreviewCounts($preview),
        ];
    }

    protected static function resolveDeleteContactModalHeading(Contact $record): string
    {
        return static::resolveDeleteContactPreview($record)->hasMergeHistory
            ? 'Удалить клиента целиком?'
            : 'Удалить клиента?';
    }

    /**
     * @return array<int, array{label:string,value:int}>
     */
    protected static function formatDeleteContactPreviewCounts(ResolvedContactDeletePreviewResult $preview): array
    {
        return [
            ['label' => 'Контактов', 'value' => $preview->contactsCount],
            ['label' => 'Диалогов', 'value' => $preview->dialogsCount],
            ['label' => 'Сообщений', 'value' => $preview->messagesCount],
            ['label' => 'Телефонов', 'value' => $preview->phonesCount],
            ['label' => 'Профилей каналов', 'value' => $preview->identitiesCount],
        ];
    }

    protected static function resolveOpenDuplicateReviewsCount(Contact $record): int
    {
        return static::resolveVisibleOpenDuplicateReviews($record)->count();
    }

    /**
     * @return Collection<int, ContactDuplicateReview>
     */
    protected static function resolveVisibleOpenDuplicateReviews(Contact $record): Collection
    {
        $ownedReviews = $record->relationLoaded('openDuplicateReviews')
            ? $record->openDuplicateReviews
            : $record->openDuplicateReviews()->get();

        $externalCrossChannelReviews = ContactDuplicateReview::query()
            ->where('review_type', ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY)
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->where('contact_id', '!=', $record->id)
            ->whereJsonContains('candidate_root_contact_ids', $record->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $ownedReviews
            ->concat($externalCrossChannelReviews)
            ->unique('id')
            ->sort(function (ContactDuplicateReview $left, ContactDuplicateReview $right): int {
                $leftTimestamp = $left->created_at?->getTimestamp() ?? 0;
                $rightTimestamp = $right->created_at?->getTimestamp() ?? 0;

                if ($leftTimestamp !== $rightTimestamp) {
                    return $rightTimestamp <=> $leftTimestamp;
                }

                return $right->id <=> $left->id;
            })
            ->values();
    }

    protected static function resolveMergedChildrenCount(Contact $record): int
    {
        $count = $record->getAttribute('merged_children_count');

        if ($count !== null) {
            return (int) $count;
        }

        return $record->mergedChildren()->count();
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
}
