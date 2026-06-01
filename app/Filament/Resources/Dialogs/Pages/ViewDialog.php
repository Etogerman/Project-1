<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\ChannelPeerSyncState;
use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\Message;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Bots\ContactIdentityAvatarStorage;
use App\Services\Bots\SendManualDialogReplyAction;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\LoadDialogMessagesPageAction;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Dialogs\ResolveDialogStageAction;
use App\Services\Dialogs\UpdateDialogInboxStatusAction;
use App\Services\Dialogs\UpdateDialogStageAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ViewDialog extends ViewRecord
{
    public const CONVERSATION_DISPLAY_MODE_FORMATTED = 'formatted';

    public const CONVERSATION_DISPLAY_MODE_HTML = 'html';

    public const INITIAL_CONVERSATION_MESSAGE_LIMIT = 25;

    public const OLDER_CONVERSATION_MESSAGE_LIMIT = 50;

    public const LIVE_REFRESH_MESSAGE_LIMIT = 50;

    public const LIVE_REFRESH_INTERVAL_MS = 5000;

    private const DIALOG_FIELD_VALUE_MAX_LENGTH = 2000;

    private const DIALOG_FIELDS_MAX_BYTES = 65536;

    protected static string $resource = DialogResource::class;

    protected string $view = 'filament.dialogs.pages.view-dialog';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @var list<array<string, mixed>>
     */
    public array $conversationMessages = [];

    public bool $hasMoreOlderMessages = false;

    public string $dialogReplyText = '';

    public string $dialogReplyFormat = Message::TEXT_FORMAT_PLAIN_TEXT;

    public string $dialogInboxStatusSelection = DialogInboxStatusData::CODE_NO_NEW;

    public string $dialogStageSelection = '';

    public string $conversationDisplayMode = self::CONVERSATION_DISPLAY_MODE_FORMATTED;

    /**
     * @var array{sort_at:string,id:int}|null
     */
    public ?array $nextOlderCursor = null;

    public ?int $latestKnownMessageId = null;

    public ?string $dialogsBackUrl = null;

    /**
     * @var array<string, string>|null
     */
    protected ?array $dialogFieldLabels = null;

    /**
     * @var array<string, array<string, string>>
     */
    protected array $dialogOptionLabels = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->dialogsBackUrl = $this->resolveDialogsBackUrlFromValue(request()->query('back_to'));

        $this->initializeConversationHistory();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Диалог';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function hasResourceBreadcrumbs(): bool
    {
        return false;
    }

    public function getBreadcrumb(): string
    {
        return 'Диалог: '.$this->formatChannelLabel($this->getRecord()->channel, 'Неизвестный канал');
    }

    public function getBreadcrumbs(): array
    {
        $dialog = $this->getRecord();
        $contact = $dialog->contact;

        return [
            ContactResource::getUrl('index') => ContactResource::getBreadcrumb(),
            $this->getContactViewUrl() => $contact instanceof Contact
                ? app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog)
                : 'Контакт',
            $this->getBreadcrumb(),
        ];
    }

    public function loadOlderMessages(): void
    {
        if (! $this->hasMoreOlderMessages) {
            return;
        }

        $page = app(LoadDialogMessagesPageAction::class)->handle(
            $this->getRecord(),
            $this->nextOlderCursor,
            self::OLDER_CONVERSATION_MESSAGE_LIMIT,
        );

        $olderMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);

        if ($olderMessages === []) {
            $this->hasMoreOlderMessages = false;
            $this->nextOlderCursor = null;

            return;
        }

        $this->prependConversationMessageViewData($olderMessages);
        $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
        $this->nextOlderCursor = $page->nextOlderCursor;
        $this->syncNextOlderCursorToVisibleConversationStart();

        $this->dispatch('dialog-history-older-messages-loaded');
    }

    public function refreshDialogViewData(): void
    {
        $this->refreshDialogRecord();
        $this->syncDialogInboxStatusSelection();
        $this->syncDialogStageSelection();

        $appendedCount = $this->appendLatestConversationMessages();

        $this->dispatch('dialog-history-refreshed', appendedCount: $appendedCount);
    }

    public function updateDialogInboxStatus(): void
    {
        try {
            $employee = $this->resolveCurrentEmployee();

            $result = app(UpdateDialogInboxStatusAction::class)->handle(
                $this->getRecord(),
                $employee,
                $this->dialogInboxStatusSelection,
            );

            if ($result->historyMessage instanceof Message) {
                $result->historyMessage->loadMissing(['channel', 'dialog.channel', 'sentByUser']);
                $this->appendOutboundMessageToConversation($result->historyMessage);
            }

            $this->refreshDialogRecord();
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->success()
                ->title('Статус обновлён')
                ->body('Статус диалога сохранён и добавлен в историю.')
                ->send();
        } catch (ValidationException $exception) {
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить статус')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить статус')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function setDialogInboxStatus(string $status): void
    {
        if ($this->dialogInboxStatusSelection === $status) {
            return;
        }

        $this->dialogInboxStatusSelection = $status;
        $this->updateDialogInboxStatus();
    }

    public function updateDialogStage(): void
    {
        try {
            $employee = $this->resolveCurrentEmployee();

            $result = app(UpdateDialogStageAction::class)->handle(
                $this->getRecord(),
                $employee,
                $this->dialogStageSelection,
            );

            if ($result->historyMessage instanceof Message) {
                $result->historyMessage->loadMissing(['channel', 'dialog.channel', 'sentByUser']);
                $this->appendOutboundMessageToConversation($result->historyMessage);
            }

            $this->refreshDialogRecord();
            $this->syncDialogStageSelection();

            Notification::make()
                ->success()
                ->title('Этап обновлён')
                ->body('Этап диалога сохранён и добавлен в историю.')
                ->send();
        } catch (ValidationException $exception) {
            $this->syncDialogStageSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить этап')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            $this->syncDialogStageSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить этап')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function selectDialogStage(string $stage): void
    {
        $this->dialogStageSelection = $stage;

        $this->updateDialogStage();
    }

    public function sendDialogReply(): void
    {
        $validated = $this->validate([
            'dialogReplyText' => ['required', 'string', 'max:2000'],
            'dialogReplyFormat' => ['required', 'string', Rule::in(array_keys(Message::textFormatOptions()))],
        ]);

        $text = trim((string) ($validated['dialogReplyText'] ?? ''));
        $textFormat = Message::normalizeTextFormat($validated['dialogReplyFormat'] ?? null);

        if ($text === '') {
            throw ValidationException::withMessages([
                'dialogReplyText' => 'Текст ответа обязателен.',
            ]);
        }

        try {
            $employee = $this->resolveCurrentEmployee();

            $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
                $this->getRecord(),
                $employee,
                $text,
                $textFormat,
            );

            $this->dialogReplyText = '';
            $this->appendOutboundMessageToConversation($outboundMessage);
            $this->refreshDialogRecord();
            $this->syncDialogInboxStatusSelection();
            $this->dispatch('dialog-reply-sent');

            $isQueuedForGateway = data_get($outboundMessage->raw_payload, 'provider') === 'telegram_account_gateway'
                && data_get($outboundMessage->raw_payload, 'delivery_status') === 'pending';

            Notification::make()
                ->success()
                ->title($isQueuedForGateway ? 'Ответ поставлен в очередь' : 'Ответ отправлен')
                ->body($isQueuedForGateway
                    ? 'Gateway заберёт сообщение и отправит его через Telegram account.'
                    : 'Сообщение отправлено и сохранено в истории диалога.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось отправить ответ')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function saveDialogFieldValue(string $fieldKey, mixed $value): void
    {
        $fieldKey = trim($fieldKey);
        $valueText = is_scalar($value) || $value === null
            ? (string) $value
            : '';

        try {
            if (mb_strlen($valueText) > self::DIALOG_FIELD_VALUE_MAX_LENGTH) {
                throw ValidationException::withMessages([
                    'dialog_field_value' => 'Значение поля диалога не должно быть длиннее 2000 символов.',
                ]);
            }

            DB::transaction(function () use ($fieldKey, $valueText): void {
                $dialog = Dialog::query()
                    ->whereKey($this->getRecord()->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $fieldsPayload = is_array($dialog->fields_payload) ? $dialog->fields_payload : [];

                if (! $this->canEditDialogFieldValue($fieldKey, $fieldsPayload)) {
                    throw ValidationException::withMessages([
                        'dialog_field_key' => 'Это поле диалога нельзя изменить из карточки.',
                    ]);
                }

                $fieldsPayload[$fieldKey] = $this->normalizeEditedDialogFieldValue(
                    $valueText,
                    $fieldsPayload[$fieldKey] ?? null,
                );

                $encoded = json_encode($fieldsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($encoded === false || strlen($encoded) > self::DIALOG_FIELDS_MAX_BYTES) {
                    throw ValidationException::withMessages([
                        'dialog_field_value' => 'Поля диалога стали слишком большими для сохранения.',
                    ]);
                }

                $dialog->forceFill(['fields_payload' => $fieldsPayload])->save();
            });

            $this->refreshDialogRecord();

            Notification::make()
                ->success()
                ->title('Поле обновлено')
                ->body('Значение поля диалога сохранено.')
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить поле')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить поле')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'dialogHeader' => $this->getDialogHeaderViewData(),
            'peerSyncState' => $this->getPeerSyncStateViewData(),
            'contactSummary' => $this->getContactSummaryViewData(),
            'dialogFields' => $this->getDialogFieldsViewData(),
            'dialogSystemFields' => $this->getDialogSystemFieldsViewData(),
            'dialogFieldLabels' => $this->getDialogFieldLabels(),
            'dialogBreadcrumbs' => $this->getDialogBreadcrumbsViewData(),
            'kanbanBackUrl' => $this->resolveDialogsBackUrl(),
            'contactUrl' => $this->getContactViewUrl(),
            'dialogInboxStatus' => $this->getDialogInboxStatusViewData(),
            'dialogStage' => $this->getDialogStageViewData(),
            'conversationDisplayModeOptions' => $this->getConversationDisplayModeOptions(),
            'liveRefreshPollIntervalMs' => static::LIVE_REFRESH_INTERVAL_MS,
            'replyComposer' => $this->getReplyComposerViewData(),
        ];
    }

    protected function initializeConversationHistory(): void
    {
        $page = app(LoadDialogMessagesPageAction::class)->handle(
            $this->getRecord(),
            null,
            self::INITIAL_CONVERSATION_MESSAGE_LIMIT,
        );

        $this->conversationMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);
        $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
        $this->nextOlderCursor = $page->nextOlderCursor;
        $this->latestKnownMessageId = $this->resolveLatestKnownMessageId($page->messages);
        $this->syncDialogInboxStatusSelection();
        $this->syncDialogStageSelection();
    }

    /**
     * @return array{
     *     isVisible:bool,
     *     canReply:bool,
     *     blockedReason:?string,
     *     autoReplyEnabled:bool,
     *     replyTextModel:string,
     *     replyFormatModel:string,
     *     replyFormatOptions:array<string, string>,
     *     replyErrorModel:string,
     *     submitMethod:string
     * }
     */
    protected function getReplyComposerViewData(): array
    {
        $replyOwner = $this->resolveReplyOwnerContact();

        return [
            'isVisible' => $this->canCurrentUserManageDialogReplies(),
            'canReply' => $this->canCurrentUserReplyToDialog(),
            'blockedReason' => $this->getDialogReplyBlockedReason(),
            'autoReplyEnabled' => $replyOwner?->isAutoReplyEnabled() ?? false,
            'replyTextModel' => 'dialogReplyText',
            'replyFormatModel' => 'dialogReplyFormat',
            'replyFormatOptions' => Message::textFormatOptions(),
            'replyErrorModel' => 'dialogReplyText',
            'submitMethod' => 'sendDialogReply',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getConversationDisplayModeOptions(): array
    {
        return [
            self::CONVERSATION_DISPLAY_MODE_FORMATTED => 'Обычный вид',
            self::CONVERSATION_DISPLAY_MODE_HTML => 'HTML-код',
        ];
    }

    /**
     * @return array{
     *     current_label:string,
     *     current_tone:string,
     *     is_editable:bool,
     *     status_model:string,
     *     update_method:string,
     *     options:array<string, string>
     * }
     */
    protected function getDialogInboxStatusViewData(): array
    {
        $status = $this->resolveDialogInboxStatus($this->getRecord());

        return [
            'current_label' => $status->label,
            'current_tone' => $status->tone,
            'is_editable' => $this->canCurrentUserManageDialogReplies()
                && $status->code !== DialogInboxStatusData::CODE_NO_NEW,
            'status_model' => 'dialogInboxStatusSelection',
            'update_method' => 'updateDialogInboxStatus',
            'options' => $this->getDialogInboxStatusOptions($status),
        ];
    }

    /**
     * @return array{
     *     current_label:string,
     *     current_tone:string,
     *     is_editable:bool,
     *     blocked_reason:?string,
     *     stage_model:string,
     *     update_method:string,
     *     options:array<string, string>,
     *     steps:list<array{
     *         value:string,
     *         label:string,
     *         tone:string,
     *         is_current:bool,
     *         is_clickable:bool,
     *         is_completed:bool
     *     }>
     * }
     */
    protected function getDialogStageViewData(): array
    {
        $dialog = $this->getRecord();
        $currentStage = $this->resolveEffectiveDialogStage($dialog);
        $isEditable = $this->canCurrentUserManageDialogStages()
            && $this->getDialogStageBlockedReason() === null;
        $allowedTargets = Dialog::allowedManualTransitionTargets($currentStage);
        $workingStages = Dialog::workingStages();
        $currentIndex = array_search($currentStage, $workingStages, true);

        return [
            'current_label' => $this->dialogOptionLabel('stage', $currentStage, Dialog::stageLabel($currentStage)),
            'current_tone' => Dialog::stageTone($currentStage),
            'is_editable' => $isEditable,
            'blocked_reason' => $this->getDialogStageBlockedReason(),
            'stage_model' => 'dialogStageSelection',
            'update_method' => 'updateDialogStage',
            'options' => $this->applyDialogDictionaryOptionLabels('stage', Dialog::manualTransitionOptions($currentStage)),
            'steps' => collect($workingStages)
                ->map(fn (string $stage, int $index): array => [
                    'value' => $stage,
                    'label' => $this->dialogOptionLabel('stage', $stage, Dialog::stageLabel($stage)),
                    'tone' => Dialog::stageTone($stage),
                    'is_current' => $stage === $currentStage,
                    'is_clickable' => $isEditable && in_array($stage, $allowedTargets, true),
                    'is_completed' => $currentIndex !== false && $index < $currentIndex,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     channel_label:string,
     *     platform_label:string,
     *     avatar_url:?string,
     *     avatar_fallback_label:?string,
     *     messenger_name_label:string,
     *     route_source_label:string,
     *     external_chat_id_label:string,
     *     route_status_label:string,
     *     route_status_tone:string,
     *     route_status_reason:?string,
     *     phone_label:string
     * }
     */
    protected function getDialogHeaderViewData(): array
    {
        $dialog = $this->getRecord();
        $routeStatus = $this->resolveDialogRouteStatus($dialog);

        return [
            'channel_label' => $this->formatChannelLabel($dialog->channel, 'Неизвестный канал'),
            'platform_label' => $dialog->channel?->platform !== null
                ? (Channel::platformOptions()[$dialog->channel->platform] ?? $dialog->channel->platform)
                : '—',
            'avatar_url' => $this->resolveDialogAvatarUrl($dialog),
            'avatar_fallback_label' => $this->formatDialogAvatarFallbackLabel($dialog),
            'messenger_name_label' => $this->formatDialogMessengerNameLabel($dialog),
            'route_source_label' => $this->formatDialogRouteIdentityLabel($dialog),
            'external_chat_id_label' => $dialog->external_chat_id ?: 'Не задан',
            'route_status_label' => $routeStatus->label,
            'route_status_tone' => $routeStatus->tone,
            'route_status_reason' => $routeStatus->blockedReason,
            'phone_label' => $this->formatDialogPhoneLabel($dialog),
        ];
    }

    /**
     * @return array{
     *     is_visible:bool,
     *     status_label:string,
     *     status_tone:string,
     *     history_complete_label:string,
     *     oldest_imported_message_id_label:string,
     *     latest_observed_message_id_label:string,
     *     last_sync_error_label:string
     * }
     */
    protected function getPeerSyncStateViewData(): array
    {
        $dialog = $this->getRecord();
        $channel = $dialog->channel;

        if (
            ! $channel instanceof Channel
            || ! $channel->isAccountConnection()
            || $channel->platform !== Channel::PLATFORM_TELEGRAM
            || ! filled($dialog->external_chat_id)
        ) {
            return [
                'is_visible' => false,
                'status_label' => '—',
                'status_tone' => 'gray',
                'history_complete_label' => '—',
                'oldest_imported_message_id_label' => '—',
                'latest_observed_message_id_label' => '—',
                'last_sync_error_label' => '—',
            ];
        }

        $peerSyncState = ChannelPeerSyncState::query()
            ->where('channel_id', $channel->getKey())
            ->where('external_chat_id', (string) $dialog->external_chat_id)
            ->first();

        if (! $peerSyncState instanceof ChannelPeerSyncState) {
            return [
                'is_visible' => true,
                'status_label' => 'Нет sync-state',
                'status_tone' => 'gray',
                'history_complete_label' => '—',
                'oldest_imported_message_id_label' => '—',
                'latest_observed_message_id_label' => '—',
                'last_sync_error_label' => '—',
            ];
        }

        return [
            'is_visible' => true,
            'status_label' => $peerSyncState->getBackfillStatusLabel(),
            'status_tone' => $peerSyncState->getBackfillStatusColor(),
            'history_complete_label' => $this->formatPeerSyncTimestamp($peerSyncState->history_complete_at),
            'oldest_imported_message_id_label' => $peerSyncState->oldest_imported_message_id ?: '—',
            'latest_observed_message_id_label' => $peerSyncState->latest_observed_message_id ?: '—',
            'last_sync_error_label' => $peerSyncState->last_sync_error ?: '—',
        ];
    }

    /**
     * @return array{
     *     contact_label:string,
     *     contact_id:int|null,
     *     phones_label:string,
     *     assigned_user_label:string
     * }
     */
    protected function getContactSummaryViewData(): array
    {
        $dialog = $this->getRecord();
        $contact = $dialog->contact;

        return [
            'contact_label' => $contact instanceof Contact
                ? app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog)
                : 'Контакт не найден',
            'contact_id' => $contact?->id,
            'phones_label' => $this->formatContactPhonesLabel($contact),
            'assigned_user_label' => $this->formatAssignedUserLabel($contact),
        ];
    }

    /**
     * @return array{
     *     is_visible: bool,
     *     fields: list<array{key: string, label: string, value: string, editable_value: string, value_type: string, is_truncated: bool, can_edit: bool}>
     * }
     */
    protected function getDialogFieldsViewData(): array
    {
        $fieldsPayload = $this->getRecord()->fields_payload;

        if (! is_array($fieldsPayload)) {
            return [
                'is_visible' => true,
                'fields' => [],
            ];
        }

        $fields = collect($fieldsPayload)
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key)
                && ! str_starts_with($key, '_'))
            ->map(function (mixed $value, string $key) use ($fieldsPayload): array {
                $formattedValue = $this->formatDialogFieldValue($value);

                return [
                    'key' => $key,
                    'label' => $this->dialogFieldLabel($key, $key),
                    'value' => $formattedValue['value'],
                    'editable_value' => $this->formatDialogFieldEditableValue($value),
                    'value_type' => $formattedValue['type'],
                    'is_truncated' => $formattedValue['is_truncated'],
                    'can_edit' => $this->canEditDialogFieldValue($key, $fieldsPayload),
                ];
            })
            ->sortBy('key', SORT_NATURAL)
            ->values()
            ->all();

        return [
            'is_visible' => true,
            'fields' => $fields,
        ];
    }

    /**
     * @return array{
     *     rows: list<array{
     *         key: string,
     *         label: string,
     *         value: string,
     *         detail: ?string,
     *         url: ?string,
     *         value_role: ?string,
     *         tone: ?string
     *     }>
     * }
     */
    protected function getDialogSystemFieldsViewData(): array
    {
        $dialog = $this->getRecord();
        $contactSummary = $this->getContactSummaryViewData();
        $inboxStatus = $this->getDialogInboxStatusViewData();
        $dialogStage = $this->getDialogStageViewData();
        $currentBlock = $this->getCurrentDialogBlockViewData($dialog);

        return [
            'rows' => [
                $this->dialogSystemFieldRow('id', 'ID', (string) $dialog->id),
                $this->dialogSystemFieldRow(
                    'contact_id',
                    'Контакт',
                    $contactSummary['contact_label'],
                    null,
                    'dialog-contact-label',
                    null,
                    $this->getContactViewUrl(),
                ),
                $this->dialogSystemFieldRow('channel_id', 'Канал', $this->formatChannelLabel($dialog->channel, 'Неизвестный канал'), null, 'dialog-channel-label'),
                $this->dialogSystemFieldRow('status', 'Статус', $inboxStatus['current_label']),
                $this->dialogSystemFieldRow('stage', 'Этап', $dialogStage['current_label']),
                $this->dialogSystemFieldRow('assigned_user_id', 'Ответственный', $contactSummary['assigned_user_label']),
                $this->dialogSystemFieldRow(
                    'current_block_id',
                    'Текущий блок',
                    $currentBlock['value'],
                    $currentBlock['detail'],
                    'dialog-current-block',
                    $currentBlock['tone'],
                ),
                $this->dialogSystemFieldRow('created_at', 'Создан', $this->formatDialogTimestamp($dialog->created_at)),
                $this->dialogSystemFieldRow('updated_at', 'Обновлён', $this->formatDialogTimestamp($dialog->updated_at)),
                $this->dialogSystemFieldRow(
                    'last_message_at',
                    'Последнее сообщение',
                    $this->formatDialogSnapshotLine(
                        $this->formatDialogTimestamp($dialog->last_message_at),
                        $this->formatDialogMessagePreview($dialog, 'last_message_id', $dialog->last_message_preview),
                    ),
                ),
                $this->dialogSystemFieldRow(
                    'last_inbound_message_at',
                    'Последнее входящее',
                    $this->formatDialogSnapshotLine(
                        $this->formatDialogTimestamp($dialog->last_inbound_at),
                        $this->formatDialogMessagePreview($dialog, 'last_inbound_message_id', $dialog->last_inbound_message_preview),
                    ),
                    null,
                    null,
                    null,
                    null,
                    'Последнее входящее',
                ),
                $this->dialogSystemFieldRow(
                    'last_outbound_message_at',
                    'Последнее исходящее',
                    $this->formatDialogSnapshotLine(
                        $this->formatDialogTimestamp($dialog->last_outbound_at),
                        $this->formatDialogMessagePreview($dialog, 'last_outbound_message_id', $dialog->last_outbound_message_preview),
                    ),
                    null,
                    null,
                    null,
                    null,
                    'Последнее исходящее',
                ),
                $this->dialogSystemFieldRow('phone', 'Телефон', $this->formatDialogPhoneLabel($dialog)),
            ],
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     value: string,
     *     detail: ?string,
     *     url: ?string,
     *     value_role: ?string,
     *     tone: ?string
     * }
     */
    protected function dialogSystemFieldRow(
        string $fieldKey,
        string $fallbackLabel,
        string $value,
        ?string $detail = null,
        ?string $valueRole = null,
        ?string $tone = null,
        ?string $url = null,
        ?string $displayLabel = null,
    ): array {
        return [
            'key' => $fieldKey,
            'label' => filled($displayLabel) ? $displayLabel : $this->dialogFieldLabel($fieldKey, $fallbackLabel),
            'value' => trim($value) !== '' ? $value : '—',
            'detail' => filled($detail) ? $detail : null,
            'url' => filled($url) ? $url : null,
            'value_role' => $valueRole,
            'tone' => $tone,
        ];
    }

    /**
     * @return array{value: string, detail: ?string, tone: ?string}
     */
    protected function getCurrentDialogBlockViewData(Dialog $dialog): array
    {
        $activeRuns = ScenarioRun::query()
            ->where('dialog_id', $dialog->id)
            ->where('status', ScenarioRun::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get(['id', 'scenario_code', 'current_step', 'state_payload', 'status']);

        /** @var ScenarioRun|null $run */
        $run = $activeRuns->first();

        if (! $run instanceof ScenarioRun) {
            $run = ScenarioRun::query()
                ->where('dialog_id', $dialog->id)
                ->orderByDesc('id')
                ->first(['id', 'scenario_code', 'current_step', 'state_payload', 'status']);
        }

        if (! $run instanceof ScenarioRun) {
            return [
                'value' => 'Сценарий не запускался',
                'detail' => null,
                'tone' => 'muted',
            ];
        }

        $statePayload = is_array($run->state_payload) ? $run->state_payload : [];
        $currentBlockId = $this->resolveCurrentDialogBlockId($dialog, $run, $activeRuns->contains('id', $run->id));

        $detailParts = [
            'run #'.$run->id,
            $run->status,
        ];

        if ($activeRuns->count() > 1) {
            $detailParts[] = 'найдено несколько активных запусков';
        }

        if ($currentBlockId === '') {
            return [
                'value' => 'Блок не определён · '.implode(' · ', $detailParts),
                'detail' => null,
                'tone' => 'muted',
            ];
        }

        $publishedVersionId = (int) data_get($statePayload, 'v3.published_version_id', 0);

        if ($publishedVersionId < 1) {
            return [
                'value' => $currentBlockId.' · сценарий без V3-схемы · '.implode(' · ', $detailParts),
                'detail' => null,
                'tone' => 'muted',
            ];
        }

        $detailParts[] = 'v'.$publishedVersionId;
        $blockViewData = $this->resolvePublishedV3BlockViewData($publishedVersionId, $currentBlockId);
        $blockTitle = $blockViewData['title'];
        $displayNumber = $blockViewData['display_number'] ?? $currentBlockId;

        return [
            'value' => '#'.$displayNumber.' · '.($blockTitle ?? 'блок не найден').' · '.implode(' · ', $detailParts),
            'detail' => null,
            'tone' => $blockTitle !== null ? null : 'warning',
        ];
    }

    protected function resolveCurrentDialogBlockId(Dialog $dialog, ScenarioRun $run, bool $isActiveRun): string
    {
        $statePayload = is_array($run->state_payload) ? $run->state_payload : [];
        $currentBlockId = trim((string) data_get($statePayload, 'v3.current_block_id', ''));

        if ($currentBlockId !== '') {
            return $currentBlockId;
        }

        $currentStep = filled($run->current_step) ? trim((string) $run->current_step) : '';

        if ($currentStep !== '') {
            return $currentStep;
        }

        if (! $isActiveRun) {
            $lastKnownBlockId = trim((string) data_get($statePayload, 'v3.last_known_block_id', ''));

            if ($lastKnownBlockId !== '') {
                return $lastKnownBlockId;
            }

            return $this->resolveLastKnownScenarioMessageBlockId($dialog, $run) ?? '';
        }

        return '';
    }

    protected function resolveLastKnownScenarioMessageBlockId(Dialog $dialog, ScenarioRun $run): ?string
    {
        return Message::query()
            ->where('dialog_id', $dialog->id)
            ->whereNotNull('raw_payload')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'raw_payload'])
            ->map(function (Message $message) use ($run): ?string {
                $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

                if ((int) data_get($rawPayload, 'v3.scenario_run_id', 0) !== (int) $run->id) {
                    return null;
                }

                $blockId = trim((string) data_get($rawPayload, 'v3.block_id', ''));

                return $blockId !== '' ? $blockId : null;
            })
            ->filter()
            ->first();
    }

    /**
     * @return array{title: ?string, display_number: ?string}
     */
    protected function resolvePublishedV3BlockViewData(int $publishedVersionId, string $currentBlockId): array
    {
        $version = ScenarioVersion::query()->find($publishedVersionId, ['id', 'schema_payload']);

        if (! $version instanceof ScenarioVersion) {
            return [
                'title' => null,
                'display_number' => null,
            ];
        }

        $schemaPayload = is_array($version->schema_payload) ? $version->schema_payload : [];
        $blocks = data_get($schemaPayload, 'builder_v3_runtime.blocks', []);

        if (! is_array($blocks)) {
            return [
                'title' => null,
                'display_number' => null,
            ];
        }

        $block = $blocks[$currentBlockId] ?? null;

        if (! is_array($block)) {
            $block = collect($blocks)->first(function (mixed $candidate) use ($currentBlockId): bool {
                if (! is_array($candidate)) {
                    return false;
                }

                return trim((string) ($candidate['id'] ?? '')) === $currentBlockId
                    || trim((string) ($candidate['card_id'] ?? '')) === $currentBlockId;
            });
        }

        if (! is_array($block)) {
            return [
                'title' => null,
                'display_number' => null,
            ];
        }

        $title = trim((string) ($block['title'] ?? ''));
        $displayNumber = trim((string) ($block['display_number'] ?? ''));

        if ($displayNumber === '') {
            $displayNumber = trim((string) ($block['card_id'] ?? ''));
        }

        if ($displayNumber === '') {
            $displayNumber = trim((string) ($block['id'] ?? ''));
        }

        return [
            'title' => $title !== '' ? $title : null,
            'display_number' => $displayNumber !== '' ? $displayNumber : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getDialogFieldLabels(): array
    {
        return $this->dialogFieldLabels ??= FieldDictionaryField::labelsFor(FieldDictionaryField::ENTITY_DIALOG);
    }

    protected function dialogFieldLabel(string $fieldKey, string $fallback): string
    {
        return FieldDictionaryField::labelFrom($this->getDialogFieldLabels(), $fieldKey, $fallback);
    }

    protected function dialogOptionLabel(string $fieldKey, mixed $value, string $fallback): string
    {
        $this->dialogOptionLabels[$fieldKey] ??= FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_DIALOG, $fieldKey);

        return FieldDictionaryField::optionLabelFrom($this->dialogOptionLabels[$fieldKey], $value, $fallback);
    }

    /**
     * @param  array<string, string>  $fallbackOptions
     * @return array<string, string>
     */
    protected function applyDialogDictionaryOptionLabels(string $fieldKey, array $fallbackOptions): array
    {
        $this->dialogOptionLabels[$fieldKey] ??= FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_DIALOG, $fieldKey);

        return collect($fallbackOptions)
            ->mapWithKeys(fn (string $label, string $value): array => [
                $value => FieldDictionaryField::optionLabelFrom($this->dialogOptionLabels[$fieldKey], $value, $label),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $fieldsPayload
     */
    protected function canEditDialogFieldValue(string $fieldKey, array $fieldsPayload): bool
    {
        return $fieldKey !== ''
            && ! str_starts_with($fieldKey, '_')
            && array_key_exists($fieldKey, $fieldsPayload);
    }

    protected function normalizeEditedDialogFieldValue(string $value, mixed $previousValue): mixed
    {
        $trimmed = trim($value);

        if (is_int($previousValue) && preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        if (is_float($previousValue) && is_numeric($trimmed)) {
            return (float) $trimmed;
        }

        if (is_bool($previousValue)) {
            return match (mb_strtolower($trimmed)) {
                '1', 'true', 'yes', 'да' => true,
                '0', 'false', 'no', 'нет' => false,
                default => $value,
            };
        }

        return $value;
    }

    protected function formatDialogFieldEditableValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '';
    }

    /**
     * @return array{value: string, type: string, is_truncated: bool}
     */
    protected function formatDialogFieldValue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [
                'value' => '—',
                'type' => 'empty',
                'is_truncated' => false,
            ];
        }

        if (is_bool($value)) {
            return [
                'value' => $value ? 'Да' : 'Нет',
                'type' => 'scalar',
                'is_truncated' => false,
            ];
        }

        if (is_scalar($value)) {
            return $this->truncateDialogFieldValue((string) $value, 'scalar');
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->truncateDialogFieldValue(
            $encoded !== false ? $encoded : 'Неподдерживаемое значение',
            'json',
        );
    }

    /**
     * @return array{value: string, type: string, is_truncated: bool}
     */
    protected function truncateDialogFieldValue(string $value, string $type): array
    {
        $limit = 500;
        $isTruncated = mb_strlen($value) > $limit;

        return [
            'value' => $isTruncated ? mb_substr($value, 0, $limit).'…' : $value,
            'type' => $type,
            'is_truncated' => $isTruncated,
        ];
    }

    protected function formatDialogTimestamp(mixed $value): string
    {
        return $value instanceof Carbon
            ? $value->format('d.m.Y H:i')
            : '—';
    }

    protected function formatDialogPreview(mixed $value): ?string
    {
        $preview = trim((string) $value);

        if ($preview === '') {
            return null;
        }

        return mb_strlen($preview) > 140
            ? mb_substr($preview, 0, 140).'…'
            : $preview;
    }

    protected function formatDialogMessagePreview(Dialog $dialog, string $messageIdColumn, mixed $fallbackPreview): ?string
    {
        $messageId = $dialog->getAttribute($messageIdColumn);

        if (filled($messageId)) {
            $message = Message::query()->find((int) $messageId);

            if ($message instanceof Message) {
                $feed = app(BuildConversationFeedViewDataAction::class)->handle(new Collection([$message]));
                $displayText = trim((string) data_get($feed, '0.display_text', ''));

                if ($displayText !== '') {
                    return $this->formatDialogPreview($displayText);
                }
            }
        }

        return $this->formatDialogPreview($fallbackPreview);
    }

    protected function formatDialogSnapshotLine(string $timestamp, ?string $preview): string
    {
        return filled($preview)
            ? $timestamp.' · '.$preview
            : $timestamp;
    }

    protected function appendOutboundMessageToConversation(Message $message): void
    {
        $message->loadMissing(['channel', 'dialog.channel', 'sentByUser']);

        $this->appendConversationMessages(collect([$message]));
    }

    protected function refreshDialogRecord(): void
    {
        /** @var Dialog $dialog */
        $dialog = DialogResource::getEloquentQuery()->findOrFail($this->getRecord()->getKey());

        $this->record = $dialog;
    }

    protected function syncDialogInboxStatusSelection(): void
    {
        $this->dialogInboxStatusSelection = $this->resolveDialogInboxStatus($this->getRecord())->code;
    }

    protected function syncDialogStageSelection(): void
    {
        $this->dialogStageSelection = $this->resolveEffectiveDialogStage($this->getRecord());
    }

    protected function resolveEffectiveDialogStage(Dialog $dialog): string
    {
        return app(ResolveDialogStageAction::class)->handle($dialog);
    }

    protected function appendLatestConversationMessages(): int
    {
        if ($this->conversationMessages === []) {
            $page = app(LoadDialogMessagesPageAction::class)->handle(
                $this->getRecord(),
                null,
                self::INITIAL_CONVERSATION_MESSAGE_LIMIT,
            );

            if ($page->messages->isEmpty()) {
                $this->hasMoreOlderMessages = false;
                $this->nextOlderCursor = null;
                $this->latestKnownMessageId = null;

                return 0;
            }

            $this->conversationMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);
            $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
            $this->nextOlderCursor = $page->nextOlderCursor;
            $this->latestKnownMessageId = $this->resolveLatestKnownMessageId($page->messages);

            return count($this->conversationMessages);
        }

        $messages = app(LoadDialogMessagesPageAction::class)->loadMessagesAddedAfterId(
            $this->getRecord(),
            $this->latestKnownMessageId,
            self::LIVE_REFRESH_MESSAGE_LIMIT,
        );

        return $this->appendConversationMessages($messages);
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function appendConversationMessages(Collection $messages): int
    {
        if ($messages->isEmpty()) {
            return 0;
        }

        $existingMessageIds = collect($this->conversationMessages)
            ->pluck('id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        $newMessages = $messages
            ->filter(fn (Message $message): bool => ! $existingMessageIds->has($message->id))
            ->values();

        if ($newMessages->isEmpty()) {
            $this->latestKnownMessageId = max($this->latestKnownMessageId ?? 0, $this->resolveLatestKnownMessageId($messages) ?? 0) ?: null;

            return 0;
        }

        $newMessageViewData = app(BuildConversationFeedViewDataAction::class)->handle($newMessages);

        if ($newMessageViewData === []) {
            return 0;
        }

        $this->conversationMessages = [
            ...$this->conversationMessages,
            ...$newMessageViewData,
        ];
        $this->conversationMessages = $this->sortConversationMessages($this->conversationMessages);
        $this->latestKnownMessageId = max($this->latestKnownMessageId ?? 0, $this->resolveLatestKnownMessageId($newMessages) ?? 0) ?: null;

        return count($newMessageViewData);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    protected function prependConversationMessageViewData(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $existingMessageIds = collect($this->conversationMessages)
            ->pluck('id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        $olderMessageViewData = collect($messages)
            ->filter(function (array $message) use ($existingMessageIds): bool {
                return ! $existingMessageIds->has((int) ($message['id'] ?? 0));
            })
            ->values()
            ->all();

        if ($olderMessageViewData === []) {
            return;
        }

        $this->conversationMessages = [
            ...$olderMessageViewData,
            ...$this->conversationMessages,
        ];
        $this->conversationMessages = $this->sortConversationMessages($this->conversationMessages);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    protected function sortConversationMessages(array $messages): array
    {
        usort($messages, function (array $left, array $right): int {
            return strcmp((string) ($left['sort_key'] ?? ''), (string) ($right['sort_key'] ?? ''));
        });

        return array_values($messages);
    }

    protected function syncNextOlderCursorToVisibleConversationStart(): void
    {
        if (! $this->hasMoreOlderMessages) {
            return;
        }

        $oldestVisibleMessage = $this->conversationMessages[0] ?? null;

        if (
            ! is_array($oldestVisibleMessage)
            || blank($oldestVisibleMessage['sort_at_iso'] ?? null)
            || blank($oldestVisibleMessage['id'] ?? null)
        ) {
            return;
        }

        $this->nextOlderCursor = [
            'sort_at' => (string) $oldestVisibleMessage['sort_at_iso'],
            'id' => (int) $oldestVisibleMessage['id'],
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function resolveLatestKnownMessageId(Collection $messages): ?int
    {
        $latestMessageId = $messages->max('id');

        return is_numeric($latestMessageId)
            ? (int) $latestMessageId
            : null;
    }

    protected function getContactViewUrl(): string
    {
        $contact = $this->getRecord()->contact;

        if (! $contact instanceof Contact) {
            return ContactResource::getUrl('index');
        }

        return ContactResource::getUrl('view', ['record' => $contact]);
    }

    /**
     * @return array{
     *     entry_point: string,
     *     back_url: string,
     *     back_label: string,
     *     items: list<array{label: string, url: string|null, is_current: bool}>
     * }
     */
    protected function getDialogBreadcrumbsViewData(): array
    {
        $dialog = $this->getRecord();
        $contact = $dialog->contact;
        $contactLabel = $contact instanceof Contact
            ? app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog)
            : 'Контакт';
        $dialogsBackUrl = $this->resolveDialogsBackUrl();

        if ($dialogsBackUrl !== null) {
            return [
                'entry_point' => 'dialogs',
                'back_url' => $dialogsBackUrl,
                'back_label' => 'Вернуться в диалоги',
                'items' => [
                    [
                        'label' => 'Диалоги',
                        'url' => $dialogsBackUrl,
                        'is_current' => false,
                    ],
                    [
                        'label' => 'Диалог #'.$dialog->id,
                        'url' => null,
                        'is_current' => true,
                    ],
                ],
            ];
        }

        return [
            'entry_point' => 'contact',
            'back_url' => $this->getContactViewUrl(),
            'back_label' => 'Вернуться к контакту',
            'items' => [
                [
                    'label' => 'Контакты',
                    'url' => ContactResource::getUrl('index'),
                    'is_current' => false,
                ],
                [
                    'label' => $contactLabel,
                    'url' => $this->getContactViewUrl(),
                    'is_current' => false,
                ],
                [
                    'label' => 'Диалог · '.$this->formatChannelLabel($dialog->channel, 'Неизвестный канал'),
                    'url' => null,
                    'is_current' => true,
                ],
            ],
        ];
    }

    protected function resolveDialogsBackUrl(): ?string
    {
        return $this->dialogsBackUrl
            ?? $this->resolveDialogsBackUrlFromValue(request()->query('back_to'));
    }

    protected function resolveDialogsBackUrlFromValue(mixed $backTo): ?string
    {
        if (! is_string($backTo) || $backTo === '') {
            return null;
        }

        $dialogsPaths = collect([
            DialogResource::getUrl('index'),
            DialogResource::getUrl('kanban'),
        ])
            ->map(static fn (string $url): string => parse_url($url, PHP_URL_PATH) ?: $url)
            ->filter()
            ->unique()
            ->values();
        $backToPath = parse_url($backTo, PHP_URL_PATH) ?: $backTo;

        if (! $dialogsPaths->contains($backToPath)) {
            return null;
        }

        $backToQuery = parse_url($backTo, PHP_URL_QUERY);
        $safeBackTo = $backToPath.(is_string($backToQuery) && $backToQuery !== '' ? '?'.$backToQuery : '');

        return url($safeBackTo);
    }

    protected function resolveCurrentEmployee(): User
    {
        /** @var User|null $employee */
        $employee = auth()->user();

        if (! $employee instanceof User) {
            throw new RuntimeException('Не удалось определить текущего сотрудника.');
        }

        return $employee;
    }

    protected function resolveReplyOwnerContact(): ?Contact
    {
        $contact = $this->getRecord()->contact;

        if (! $contact instanceof Contact) {
            return null;
        }

        return app(ResolveRootContactAction::class)->handle($contact);
    }

    protected function canCurrentUserReplyToDialog(): bool
    {
        if (! $this->canCurrentUserManageDialogReplies()) {
            return false;
        }

        return $this->getDialogRouteBlockedReason() === null;
    }

    protected function canCurrentUserManageDialogReplies(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canReplyInDialogs();
    }

    protected function canCurrentUserManageDialogStages(): bool
    {
        return $this->canCurrentUserManageDialogReplies();
    }

    protected function getDialogReplyBlockedReason(): ?string
    {
        return $this->getDialogRouteBlockedReason();
    }

    protected function getDialogRouteBlockedReason(): ?string
    {
        return $this->resolveDialogRouteStatus($this->getRecord())->blockedReason;
    }

    protected function getDialogStageBlockedReason(): ?string
    {
        return $this->getRecord()->hasCompleteStageHistoryRouteContext()
            ? null
            : 'Ручная смена этапа недоступна, пока не заполнен полный route context канала.';
    }

    /**
     * @return array<string, string>
     */
    protected function getDialogInboxStatusOptions(DialogInboxStatusData $status): array
    {
        return match ($status->code) {
            DialogInboxStatusData::CODE_REQUIRES_REPLY => [
                DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
                DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
            ],
            DialogInboxStatusData::CODE_NOT_REQUIRED => [
                DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
                DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
            ],
            default => [
                DialogInboxStatusData::CODE_NO_NEW => 'Нет новых',
            ],
        };
    }

    protected function formatContactPhonesLabel(?Contact $contact): string
    {
        if (! $contact instanceof Contact) {
            return '—';
        }

        $phoneNumbers = ($contact->relationLoaded('phoneNumbers')
            ? $contact->phoneNumbers
            : $contact->phoneNumbers()->get())
            ->map(fn (ContactPhoneNumber $phoneNumber): ?string => $phoneNumber->phone_raw ?: $phoneNumber->phone_normalized)
            ->filter(fn (?string $phone): bool => filled($phone))
            ->unique()
            ->values();

        return $phoneNumbers->isEmpty()
            ? '—'
            : $phoneNumbers->implode(', ');
    }

    protected function formatAssignedUserLabel(?Contact $contact): string
    {
        if (! $contact instanceof Contact) {
            return 'Свободен';
        }

        return filled($contact->assignedUser?->name)
            ? (string) $contact->assignedUser->name
            : 'Свободен';
    }

    protected function formatChannelLabel(?Channel $channel, string $fallback = '—'): string
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

    protected function resolveDialogRouteStatus(Dialog $dialog): DialogRouteStatusData
    {
        return app(ResolveDialogRouteStatusAction::class)->handle($dialog);
    }

    protected function resolveDialogInboxStatus(Dialog $dialog): DialogInboxStatusData
    {
        return app(ResolveDialogInboxStatusAction::class)->handle($dialog);
    }

    protected function formatDialogPhoneLabel(Dialog $dialog): string
    {
        if (filled($dialog->confirmed_phone_raw)) {
            return (string) $dialog->confirmed_phone_raw;
        }

        if (filled($dialog->confirmed_phone_normalized)) {
            return (string) $dialog->confirmed_phone_normalized;
        }

        return 'Телефон в этом канале не подтвержден';
    }

    protected function formatDialogMessengerNameLabel(Dialog $dialog): string
    {
        $identity = $dialog->currentContactIdentity;

        if (filled($identity?->display_name)) {
            return trim((string) $identity->display_name);
        }

        if (filled($identity?->external_username)) {
            return '@'.ltrim((string) $identity->external_username, '@');
        }

        if (filled($identity?->external_user_id)) {
            return 'ID: '.$identity->external_user_id;
        }

        if ($dialog->current_contact_identity_id !== null) {
            return 'Identity #'.$dialog->current_contact_identity_id;
        }

        return '—';
    }

    protected function formatDialogRouteIdentityLabel(Dialog $dialog): string
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

    protected function resolveDialogAvatarUrl(Dialog $dialog): ?string
    {
        $avatarPath = $dialog->currentContactIdentity?->avatar_path;

        if (! filled($avatarPath)) {
            return null;
        }

        try {
            $avatarStorage = app(ContactIdentityAvatarStorage::class);

            if ($avatarStorage->exists($avatarPath)) {
                return $avatarStorage->url($avatarPath);
            }
        } catch (Throwable) {
            // Fallback to the legacy disk when object storage is temporarily unavailable.
        }

        $legacyDisk = Storage::disk('public');

        if (! $legacyDisk->exists($avatarPath)) {
            return null;
        }

        return $legacyDisk->url($avatarPath);
    }

    protected function formatDialogAvatarFallbackLabel(Dialog $dialog): ?string
    {
        $identity = $dialog->currentContactIdentity;
        $candidate = null;

        if (filled($identity?->display_name)) {
            $candidate = trim((string) $identity->display_name);
        } elseif (filled($identity?->external_username)) {
            $candidate = ltrim((string) $identity->external_username, '@');
        }

        if (! filled($candidate)) {
            return null;
        }

        $parts = preg_split('/\s+/u', $candidate, -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($parts) && count($parts) > 1) {
            $initials = collect($parts)
                ->take(2)
                ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');

            return $initials !== '' ? $initials : null;
        }

        $initials = mb_strtoupper(mb_substr($candidate, 0, 2));

        return $initials !== '' ? $initials : null;
    }

    protected function formatPeerSyncTimestamp(mixed $value): string
    {
        return $value instanceof Carbon
            ? $value->format('d.m.Y H:i')
            : '—';
    }
}
