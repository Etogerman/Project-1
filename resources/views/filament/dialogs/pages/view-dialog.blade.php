<x-filament-panels::page>
    @php
        $dialogFieldLabels = $dialogFieldLabels ?? [];
        $dialogFieldLabel = static function (string $fieldKey, string $fallback) use ($dialogFieldLabels): string {
            $label = trim((string) ($dialogFieldLabels[$fieldKey] ?? ''));

            return $label !== '' ? $label : $fallback;
        };
    @endphp

    <div data-role="dialog-page" class="ac-panel-stack ac-panel-stack--relaxed">
        <nav
            data-role="dialog-top-breadcrumbs"
            data-entry-point="{{ $dialogBreadcrumbs['entry_point'] }}"
            class="ac-dialog-top-crumbs"
            aria-label="Навигация по диалогу"
        >
            @if (filled($dialogBreadcrumbs['back_url']))
                <a
                    href="{{ $dialogBreadcrumbs['back_url'] }}"
                    class="ac-dialog-top-crumbs__back"
                    title="{{ $dialogBreadcrumbs['back_label'] }}"
                    aria-label="{{ $dialogBreadcrumbs['back_label'] }}"
                >
                    <span class="ac-sr-only">{{ $dialogBreadcrumbs['back_label'] }}</span>
                    <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                        <path d="M9 3L5 7l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            @endif

            <ol class="ac-dialog-top-crumbs__list">
                @foreach ($dialogBreadcrumbs['items'] as $breadcrumbItem)
                    <li class="ac-dialog-top-crumbs__item">
                        @if (! $loop->first)
                            <svg class="ac-dialog-top-crumbs__separator" width="10" height="10" viewBox="0 0 10 10" fill="none" aria-hidden="true">
                                <path d="M3.5 2L6.5 5L3.5 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        @endif

                        @if ($breadcrumbItem['is_current'] || blank($breadcrumbItem['url']))
                            <span class="ac-dialog-top-crumbs__link is-current">
                                {{ $breadcrumbItem['label'] }}
                            </span>
                        @else
                            <a href="{{ $breadcrumbItem['url'] }}" class="ac-dialog-top-crumbs__link">
                                {{ $breadcrumbItem['label'] }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>

        <div data-role="dialog-overview" class="ac-dialog-overview ac-dialog-overview--single">
            <section data-role="dialog-header" class="ac-surface ac-surface--hero ac-dialog-summary">
                <div class="ac-dialog-summary__top">
                    <div class="ac-dialog-summary__identity">
                        <div data-role="dialog-contact-avatar" class="ac-dialog-avatar">
                            @if (filled($dialogHeader['avatar_url']))
                                <img
                                    src="{{ $dialogHeader['avatar_url'] }}"
                                    alt="Аватар клиента"
                                    data-role="dialog-contact-avatar-image"
                                    class="ac-dialog-avatar__image"
                                >
                            @elseif (filled($dialogHeader['avatar_fallback_label']))
                                <span data-role="dialog-contact-avatar-fallback" class="ac-dialog-avatar__fallback">
                                    {{ $dialogHeader['avatar_fallback_label'] }}
                                </span>
                            @else
                                <span data-role="dialog-contact-avatar-fallback" class="ac-dialog-avatar__fallback">
                                    <x-filament::icon icon="heroicon-m-user" class="h-5 w-5" />
                                </span>
                            @endif
                        </div>

                        <div class="ac-surface__title-group">
                            <div class="ac-dialog-summary__title-row">
                                <h2 class="ac-surface__title ac-surface__title--hero">
                                    {{ $contactSummary['contact_label'] }}
                                </h2>
                                <span data-tone="info" class="ac-pill">
                                    {{ $dialogHeader['platform_label'] }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ac-dialog-summary__actions">
                        <span
                            data-role="dialog-route-status"
                            data-tone="{{ $dialogHeader['route_status_tone'] }}"
                            class="ac-pill"
                        >
                            {{ $dialogHeader['route_status_label'] }}
                        </span>

                        <div class="ac-button-group ac-button-group--end">
                            <a
                                href="{{ $contactUrl }}"
                                class="ac-button ac-button--warning"
                            >
                                Открыть контакт
                            </a>
                        </div>
                    </div>
                </div>

                @if (filled($dialogHeader['route_status_reason']))
                    <div class="ac-note-stack ac-surface__divider">
                        <p data-role="dialog-route-status-reason" class="ac-note ac-note--danger">
                            {{ $dialogHeader['route_status_reason'] }}
                        </p>
                    </div>
                @endif

                <div
                    data-role="dialog-stage-strip"
                    data-current-tone="{{ $dialogStage['current_tone'] }}"
                    class="ac-dialog-stage-strip ac-dialog-summary__stage"
                >
                    <div class="ac-dialog-stage-strip__track" role="group" aria-label="{{ $dialogFieldLabel('stage', 'Этап') }} диалога">
                        @foreach ($dialogStage['steps'] as $stageStep)
                            @php
                                $stageState = $stageStep['is_current']
                                    ? 'current'
                                    : ($stageStep['is_completed']
                                        ? 'completed'
                                        : ($stageStep['is_clickable'] ? 'available' : 'locked'));
                            @endphp
                            <button
                                type="button"
                                data-role="dialog-stage-step"
                                data-state="{{ $stageState }}"
                                data-tone="{{ $stageStep['tone'] }}"
                                wire:click="selectDialogStage('{{ $stageStep['value'] }}')"
                                @disabled(! $stageStep['is_clickable'])
                                class="ac-dialog-stage-step"
                            >
                                <span class="ac-dialog-stage-step__label">
                                    {{ $stageStep['label'] }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                    @if (filled($dialogStage['blocked_reason']))
                        <p class="ac-dialog-stage-strip__hint">
                            {{ $dialogStage['blocked_reason'] }}
                        </p>
                    @endif
                </div>

            </section>
        </div>

        <div data-role="dialog-workspace" class="ac-dialog-workspace">
            <div class="ac-dialog-main-column">
                <section
                    data-role="dialog-history"
                    data-poll-interval-ms="{{ $liveRefreshPollIntervalMs }}"
                    x-data="{
                        thread: null,
                        initialized: false,
                        previousHeight: null,
                        refreshIntervalId: null,
                        isRefreshing: false,
                        shouldScrollOnRefresh: false,
                        captureThread() {
                            this.thread = this.$root.querySelector('[data-role=conversation-thread]');
                        },
                        isNearBottom() {
                            this.captureThread();

                            if (! this.thread) {
                                return false;
                            }

                            return (this.thread.scrollHeight - this.thread.scrollTop - this.thread.clientHeight) <= 48;
                        },
                        scrollToBottom() {
                            this.captureThread();

                            if (! this.thread) {
                                return;
                            }

                            this.thread.scrollTop = this.thread.scrollHeight;
                        },
                        hasActiveReplyComposer() {
                            const textarea = this.$root.querySelector('[data-role=conversation-reply-textarea]');

                            if (! textarea) {
                                return false;
                            }

                            return document.activeElement === textarea
                                || textarea.value.trim() !== ''
                                || textarea.dataset.manualResized === '1';
                        },
                        rememberPositionBeforePrepend() {
                            this.captureThread();

                            if (! this.thread) {
                                return;
                            }

                            this.previousHeight = this.thread.scrollHeight;
                        },
                        restorePositionAfterPrepend() {
                            this.captureThread();

                            if (! this.thread || this.previousHeight === null) {
                                return;
                            }

                            const delta = this.thread.scrollHeight - this.previousHeight;

                            this.thread.scrollTop = this.thread.scrollTop + delta;
                            this.previousHeight = null;
                        },
                        startLiveRefresh() {
                            if (this.refreshIntervalId) {
                                window.clearInterval(this.refreshIntervalId);
                            }

                            this.refreshIntervalId = window.setInterval(() => {
                                if (document.visibilityState !== 'visible' || this.isRefreshing || this.hasActiveReplyComposer()) {
                                    return;
                                }

                                this.shouldScrollOnRefresh = this.isNearBottom();
                                this.isRefreshing = true;
                                this.$wire.refreshDialogViewData();
                            }, {{ $liveRefreshPollIntervalMs }});
                        },
                        scheduleInitialScroll() {
                            this.$nextTick(() => {
                                this.scrollToBottom();
                                window.requestAnimationFrame(() => this.scrollToBottom());
                                window.setTimeout(() => this.scrollToBottom(), 60);
                            });
                        },
                        handleRefreshComplete(detail = {}) {
                            this.isRefreshing = false;

                            if ((detail.appendedCount ?? 0) < 1) {
                                return;
                            }

                            if (! this.shouldScrollOnRefresh) {
                                return;
                            }

                            this.$nextTick(() => this.scrollToBottom());
                        },
                    }"
                    x-init="if (! initialized) { scheduleInitialScroll(); initialized = true; } startLiveRefresh();"
                    x-on:dialog-history-older-messages-loaded.window="$nextTick(() => restorePositionAfterPrepend())"
                    x-on:dialog-history-refreshed.window="$nextTick(() => handleRefreshComplete($event.detail))"
                    x-on:dialog-reply-sent.window="$nextTick(() => scrollToBottom())"
                    class="ac-surface ac-dialog-chat-panel"
                >
                    <div class="ac-surface__header ac-surface__header--centered">
                        <div class="ac-surface__title-group">
                            <h3 class="ac-surface__title">
                                Сообщения диалога
                            </h3>
                        </div>

                        <div class="ac-button-group ac-button-group--end">
                            @foreach ($conversationDisplayModeOptions as $displayModeValue => $displayModeLabel)
                                <button
                                    type="button"
                                    wire:click="$set('conversationDisplayMode', '{{ $displayModeValue }}')"
                                    @class([
                                        'ac-button',
                                        'ac-button--compact',
                                        'ac-button--warning-soft' => $conversationDisplayMode === $displayModeValue,
                                        'ac-button--secondary' => $conversationDisplayMode !== $displayModeValue,
                                    ])
                                >
                                    {{ $displayModeLabel }}
                                </button>
                            @endforeach

                            @if ($hasMoreOlderMessages)
                                <button
                                    type="button"
                                    data-role="dialog-load-older"
                                    x-on:click="rememberPositionBeforePrepend()"
                                    wire:click="loadOlderMessages"
                                    wire:loading.attr="disabled"
                                    wire:target="loadOlderMessages"
                                    class="ac-button ac-button--compact ac-button--secondary"
                                >
                                    <span wire:loading.remove wire:target="loadOlderMessages">Показать более ранние</span>
                                    <span wire:loading wire:target="loadOlderMessages">Загружаем…</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    @include('filament.contacts.partials.conversation-chat', ['messages' => $conversationMessages, 'displayMode' => $conversationDisplayMode])

                    @if ($replyComposer['isVisible'])
                        @include('filament.dialogs.partials.reply-composer', array_merge($replyComposer, ['composerClass' => 'ac-composer--dialog-inline']))
                    @endif
                </section>
            </div>

            <aside data-role="dialog-side-panel" class="ac-dialog-side-column">
                <section class="ac-surface ac-dialog-side-card" data-role="dialog-system-fields-section">
                    <p class="ac-dialog-summary__section-title">Системные поля</p>
                    @php
                        $statusOptions = $dialogInboxStatus['options'];
                        $currentStatusLabel = $statusOptions[$dialogInboxStatusSelection] ?? (array_values($statusOptions)[0] ?? '—');
                        $nextStatusValue = collect($statusOptions)
                            ->keys()
                            ->first(fn ($statusValue) => $statusValue !== $dialogInboxStatusSelection);
                        $nextStatusLabel = $nextStatusValue !== null ? ($statusOptions[$nextStatusValue] ?? null) : null;
                    @endphp
                    <div class="ac-dialog-side-list">
                        @foreach ($dialogSystemFields['rows'] as $systemField)
                            <div
                                class="ac-meta @if ($systemField['key'] === 'assigned_user_id' && $this->isDialogAssigneeEditing) ac-meta--assignee-editing @endif"
                                data-role="dialog-system-field-row"
                                data-field-key="{{ $systemField['key'] }}"
                                @if (filled($systemField['tone'])) data-tone="{{ $systemField['tone'] }}" @endif
                            >
                                <p class="ac-meta__label">
                                    {{ $systemField['label'] }}
                                </p>
                                @if ($systemField['key'] === 'status')
                                    <button
                                        type="button"
                                        class="ac-dialog-status-toggle"
                                        data-role="dialog-inbox-status-toggle"
                                        wire:click="setDialogInboxStatus(@js($nextStatusValue))"
                                        wire:loading.attr="disabled"
                                        wire:target="setDialogInboxStatus"
                                        aria-label="{{ $dialogFieldLabel('status', 'Статус') }}"
                                        title="{{ $nextStatusLabel ? 'Сменить на: '.$nextStatusLabel : $currentStatusLabel }}"
                                        @disabled(! $dialogInboxStatus['is_editable'] || $nextStatusValue === null)
                                    >
                                        <span data-role="dialog-inbox-status-current">{{ $currentStatusLabel }}</span>
                                    </button>
                                @elseif ($systemField['key'] === 'assigned_user_id' && $dialogAssignee['can_manage'])
                                    @if ($this->isDialogAssigneeEditing)
                                        <div class="ac-dialog-assignee-editor" data-role="dialog-assignee-editor">
                                            <select
                                                id="dialog-assignee-select"
                                                data-role="dialog-assignee-select"
                                                wire:model="selectedDialogAssigneeId"
                                                class="ac-select"
                                            >
                                                <option value="">Свободен</option>
                                                @foreach ($dialogAssignee['available_assignees'] as $userId => $userName)
                                                    <option value="{{ $userId }}">{{ $userName }}</option>
                                                @endforeach
                                            </select>

                                            <button
                                                data-role="dialog-save-assignee-button"
                                                type="button"
                                                wire:click="saveDialogAssignee"
                                                wire:loading.attr="disabled"
                                                wire:target="saveDialogAssignee"
                                                class="ac-dialog-assignee-save"
                                                aria-label="Сохранить ответственного"
                                                title="Сохранить"
                                            >
                                                <span wire:loading.remove wire:target="saveDialogAssignee" aria-hidden="true">✓</span>
                                                <span wire:loading wire:target="saveDialogAssignee" aria-hidden="true">…</span>
                                            </button>
                                        </div>
                                    @else
                                        <button
                                            type="button"
                                            class="ac-dialog-status-toggle ac-dialog-assignee-toggle"
                                            data-role="dialog-assignee-toggle"
                                            wire:click="openDialogAssigneeEditor"
                                            wire:loading.attr="disabled"
                                            wire:target="openDialogAssigneeEditor,saveDialogAssignee"
                                            aria-label="Изменить ответственного за контакт"
                                            title="Изменить ответственного за контакт"
                                        >
                                            <span data-role="dialog-assignee-current">{{ $systemField['value'] }}</span>
                                        </button>
                                    @endif
                                @else
                                    <p
                                        @if (filled($systemField['value_role'])) data-role="{{ $systemField['value_role'] }}" @endif
                                        class="ac-meta__value"
                                        title="{{ $systemField['value'] }}"
                                    >
                                        @if (filled($systemField['url']))
                                            <a class="ac-meta__link" href="{{ $systemField['url'] }}" title="{{ $systemField['value'] }}">
                                                {{ $systemField['value'] }}
                                            </a>
                                        @else
                                            {{ $systemField['value'] }}
                                        @endif
                                    </p>
                                @endif
                                @if (filled($systemField['detail']))
                                    <p class="ac-meta__value ac-meta__value--muted" title="{{ $systemField['detail'] }}">
                                        {{ $systemField['detail'] }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                @if ($dialogFields['is_visible'])
                    <section
                        class="ac-surface ac-dialog-side-card"
                        data-role="dialog-fields-section"
                        data-dialog-id="{{ $record->id }}"
                    >
                        <p class="ac-dialog-summary__section-title">Поля диалога</p>
                        @if ($dialogFields['fields'] === [])
                            <p class="ac-empty-state ac-empty-state--compact" data-role="dialog-fields-empty">
                                Поля диалога пока не заполнены
                            </p>
                        @else
                            <div class="ac-dialog-fields-list">
                                @foreach ($dialogFields['fields'] as $field)
                                    <div
                                        class="ac-dialog-field-row"
                                        wire:key="dialog-field-row-{{ md5($field['key']) }}"
                                        data-role="dialog-field-row"
                                        data-field-key="{{ $field['key'] }}"
                                        data-field-value-type="{{ $field['value_type'] }}"
                                        x-data="{ value: @js($field['editable_value']), isEditing: false, isSaving: false }"
                                    >
                                        <div class="ac-dialog-field-row__content">
                                            @if ($field['can_edit'])
                                                <div class="ac-dialog-field-row__value-line">
                                                    <p class="ac-meta__label" data-role="dialog-field-key">
                                                        {{ $field['label'] }}
                                                    </p>
                                                    <p
                                                        class="ac-meta__value"
                                                        data-role="dialog-field-value"
                                                        x-show="! isEditing"
                                                    >
                                                        {{ $field['value'] }}
                                                    </p>
                                                    <input
                                                        x-cloak
                                                        x-ref="input"
                                                        x-show="isEditing"
                                                        type="text"
                                                        class="ac-dialog-field-row__input"
                                                        data-role="dialog-field-edit-input"
                                                        aria-label="Значение поля {{ $field['label'] }}"
                                                        value="{{ $field['editable_value'] }}"
                                                        x-model="value"
                                                        x-bind:hidden="! isEditing"
                                                        x-bind:disabled="isSaving"
                                                        x-on:keydown.enter.prevent="
                                                            isSaving = true;
                                                            $wire.saveDialogFieldValue(@js($field['key']), value).then(() => isEditing = false).finally(() => isSaving = false);
                                                        "
                                                    >
                                                    <button
                                                        type="button"
                                                        class="ac-dialog-field-row__edit"
                                                        data-role="dialog-field-edit"
                                                        title="Редактировать поле"
                                                        aria-label="Редактировать поле {{ $field['label'] }}"
                                                        x-on:click="
                                                            isEditing = true;
                                                            $nextTick(() => $refs.input?.focus());
                                                        "
                                                    >
                                                        <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4" />
                                                    </button>
                                                </div>
                                            @else
                                                <div class="ac-dialog-field-row__value-line">
                                                    <p class="ac-meta__label" data-role="dialog-field-key">
                                                        {{ $field['label'] }}
                                                    </p>
                                                    <p class="ac-meta__value" data-role="dialog-field-value">
                                                        {{ $field['value'] }}
                                                    </p>
                                                </div>
                                            @endif
                                        </div>
                                        <div
                                            class="ac-dialog-field-row__actions"
                                            x-cloak
                                            x-show="isEditing"
                                        >
                                            @if ($field['can_edit'])
                                                <button
                                                    type="button"
                                                    class="ac-dialog-field-row__save"
                                                    data-role="dialog-field-save"
                                                    x-bind:disabled="isSaving"
                                                    x-on:click="
                                                        isSaving = true;
                                                        $wire.saveDialogFieldValue(@js($field['key']), value).then(() => isEditing = false).finally(() => isSaving = false);
                                                    "
                                                >
                                                    <span x-show="! isSaving">Сохранить</span>
                                                    <span x-cloak x-show="isSaving">Сохраняю</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif

                @if ($peerSyncState['is_visible'])
                    <section class="ac-surface ac-dialog-side-card">
                        <p class="ac-dialog-summary__section-title">Загрузка истории</p>
                        <div class="ac-dialog-side-list">
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Статус загрузки истории
                                </p>
                                <div class="ac-meta__value">
                                    <span
                                        data-role="dialog-peer-sync-status"
                                        data-tone="{{ $peerSyncState['status_tone'] }}"
                                        class="ac-pill"
                                    >
                                        {{ $peerSyncState['status_label'] }}
                                    </span>
                                </div>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    История завершена
                                </p>
                                <p data-role="dialog-peer-sync-history-complete" class="ac-meta__value">
                                    {{ $peerSyncState['history_complete_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Самое раннее сообщение
                                </p>
                                <p data-role="dialog-peer-sync-oldest-message" class="ac-meta__value">
                                    {{ $peerSyncState['oldest_imported_message_id_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Последнее observed message
                                </p>
                                <p data-role="dialog-peer-sync-latest-message" class="ac-meta__value">
                                    {{ $peerSyncState['latest_observed_message_id_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Ошибка peer sync
                                </p>
                                <p data-role="dialog-peer-sync-error" class="ac-meta__value">
                                    {{ $peerSyncState['last_sync_error_label'] }}
                                </p>
                            </div>
                        </div>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-filament-panels::page>
