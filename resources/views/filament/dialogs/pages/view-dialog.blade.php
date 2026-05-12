<x-filament-panels::page>
    <div data-role="dialog-page" class="ac-panel-stack ac-panel-stack--relaxed">
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
                            @if (filled($kanbanBackUrl))
                                <a
                                    href="{{ $kanbanBackUrl }}"
                                    class="ac-button ac-button--secondary"
                                >
                                    Вернуться в канбан
                                </a>
                            @endif

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
                    <div class="ac-dialog-stage-strip__track" role="group" aria-label="Этап диалога">
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

                <div class="ac-dialog-summary__sections">
                    <div class="ac-dialog-summary__section">
                        <div class="ac-meta-grid ac-meta-grid--dialog-summary">
                            <div class="ac-meta">
                                <label for="dialog-inbox-status" class="ac-meta__label">
                                    Статус диалога
                                </label>
                                <select
                                    id="dialog-inbox-status"
                                    data-role="dialog-inbox-status-select"
                                    wire:model="dialogInboxStatusSelection"
                                    wire:change="updateDialogInboxStatus"
                                    @disabled(! $dialogInboxStatus['is_editable'])
                                    class="ac-select"
                                >
                                    @foreach ($dialogInboxStatus['options'] as $statusValue => $statusLabel)
                                        <option value="{{ $statusValue }}">
                                            {{ $statusLabel }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Ответственный
                                </p>
                                <p class="ac-meta__value ac-meta__value--emphasis">
                                    {{ $contactSummary['assigned_user_label'] }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="ac-dialog-summary__section">
                        <div class="ac-meta-grid ac-meta-grid--dialog-summary">
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Имя из мессенджера
                                </p>
                                <p data-role="dialog-messenger-name" class="ac-meta__value">
                                    {{ $dialogHeader['messenger_name_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Канал
                                </p>
                                <p data-role="dialog-channel-label" class="ac-meta__value">
                                    {{ $dialogHeader['channel_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Источник маршрута
                                </p>
                                <p class="ac-meta__value">
                                    {{ $dialogHeader['route_source_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Телефон мессенджера
                                </p>
                                <p class="ac-meta__value">
                                    {{ $dialogHeader['phone_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Телефоны контакта
                                </p>
                                <p class="ac-meta__value">
                                    {{ $contactSummary['phones_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    ID чата
                                </p>
                                <p class="ac-meta__value">
                                    {{ $dialogHeader['external_chat_id_label'] }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                @if ($peerSyncState['is_visible'])
                    <div class="ac-dialog-summary__section ac-surface__divider">
                        <p class="ac-dialog-summary__section-title">Загрузка истории</p>
                        <div class="ac-meta-grid ac-meta-grid--compact">
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
                    </div>
                @endif
            </section>
        </div>

        <div data-role="dialog-workspace" class="ac-dialog-workspace">
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
                            if (document.visibilityState !== 'visible' || this.isRefreshing) {
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
                class="ac-surface"
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
            </section>

            @if ($replyComposer['isVisible'])
                @include('filament.dialogs.partials.reply-composer', $replyComposer)
            @endif
        </div>
    </div>
</x-filament-panels::page>
