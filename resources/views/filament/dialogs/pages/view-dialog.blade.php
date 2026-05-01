<x-filament-panels::page>
    <div data-role="dialog-page" class="ac-panel-stack ac-panel-stack--relaxed">
        <div data-role="dialog-overview" class="ac-dialog-overview">
            <section data-role="dialog-contact-summary" class="ac-surface ac-surface--hero">
                <div class="ac-surface__header ac-surface__header--centered">
                    <div class="ac-surface__title-group">
                        <h2 class="ac-surface__title ac-surface__title--hero">
                            {{ $contactSummary['contact_label'] }}
                        </h2>
                        <p class="ac-surface__subtitle">
                            Канал: {{ $dialogHeader['channel_label'] }} · Платформа: {{ $dialogHeader['platform_label'] }}
                        </p>
                    </div>

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

                <div class="ac-meta-grid ac-surface__divider">
                    <div
                        class="ac-meta"
                        x-data="{ statusHelpOpen: false }"
                        x-on:keydown.escape.window="statusHelpOpen = false"
                    >
                        <div class="ac-meta__label-row">
                            <label for="dialog-inbox-status" class="ac-meta__label">
                                Статус диалога
                            </label>
                            <button
                                type="button"
                                class="ac-inline-help"
                                aria-label="Показать подсказку: новое входящее сообщение автоматически вернёт диалог в статус «Требует ответа»."
                                aria-controls="dialog-inbox-status-help-panel"
                                x-bind:aria-expanded="statusHelpOpen ? 'true' : 'false'"
                                x-on:click="statusHelpOpen = ! statusHelpOpen"
                                data-role="dialog-inbox-status-help"
                            >
                                <x-filament::icon icon="heroicon-m-information-circle" class="h-4 w-4" />
                            </button>
                        </div>
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
                        <div
                            id="dialog-inbox-status-help-panel"
                            data-role="dialog-inbox-status-help-panel"
                            x-cloak
                            x-show="statusHelpOpen"
                            x-transition.opacity.duration.150ms
                            x-on:click.outside="statusHelpOpen = false"
                            class="ac-inline-popover"
                        >
                            Новое входящее сообщение автоматически вернёт диалог в статус «Требует ответа».
                        </div>
                    </div>
                    <div class="ac-meta">
                        <label for="dialog-stage" class="ac-meta__label">
                            Этап диалога
                        </label>
                        <select
                            id="dialog-stage"
                            data-role="dialog-stage-select"
                            wire:model="dialogStageSelection"
                            wire:change="updateDialogStage"
                            @disabled(! $dialogStage['is_editable'])
                            class="ac-select"
                        >
                            @foreach ($dialogStage['options'] as $stageValue => $stageLabel)
                                <option value="{{ $stageValue }}">
                                    {{ $stageLabel }}
                                </option>
                            @endforeach
                        </select>
                        @if (filled($dialogStage['blocked_reason']))
                            <p class="ac-meta__hint">
                                {{ $dialogStage['blocked_reason'] }}
                            </p>
                        @endif
                    </div>
                    <div class="ac-meta">
                        <p class="ac-meta__label">
                            Ответственный
                        </p>
                        <p class="ac-meta__value ac-meta__value--emphasis">
                            {{ $contactSummary['assigned_user_label'] }}
                        </p>
                    </div>
                    <div class="ac-meta">
                        <p class="ac-meta__label">
                            Телефон контакта
                        </p>
                        <p class="ac-meta__value">
                            {{ $contactSummary['phone_label'] }}
                        </p>
                    </div>
                    <div class="ac-meta">
                        <p class="ac-meta__label">
                            Канал
                        </p>
                        <p class="ac-meta__value">
                            {{ $dialogHeader['channel_label'] }}
                        </p>
                    </div>
                    <div class="ac-meta">
                        <p class="ac-meta__label">
                            Платформа
                        </p>
                        <p class="ac-meta__value">
                            {{ $dialogHeader['platform_label'] }}
                        </p>
                    </div>
                </div>
            </section>

            <section data-role="dialog-header" class="ac-surface ac-surface--secondary">
                <div class="ac-surface__header ac-surface__header--centered">
                    <div class="ac-dialog-header-identity">
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
                            <p class="ac-surface__eyebrow">
                                Технический контекст
                            </p>
                            <h3 class="ac-surface__title">
                                Маршрут и идентификаторы
                            </h3>
                            <p class="ac-surface__subtitle">
                                Этот блок нужен для диагностики маршрута и проверки канала, когда что-то идёт не по плану.
                            </p>
                        </div>
                    </div>

                    <span
                        data-role="dialog-route-status"
                        data-tone="{{ $dialogHeader['route_status_tone'] }}"
                        class="ac-pill"
                    >
                        {{ $dialogHeader['route_status_label'] }}
                    </span>
                </div>

                @if (filled($dialogHeader['route_status_reason']))
                    <div class="ac-note-stack ac-surface__divider">
                        <p data-role="dialog-route-status-reason" class="ac-note ac-note--danger">
                            {{ $dialogHeader['route_status_reason'] }}
                        </p>
                    </div>
                @endif

                <div class="ac-meta-grid ac-meta-grid--compact ac-surface__divider">
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
                            Источник маршрута
                        </p>
                        <p class="ac-meta__value">
                            {{ $dialogHeader['route_source_label'] }}
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
                    <div class="ac-meta">
                        <p class="ac-meta__label">
                            Телефон канала
                        </p>
                        <p class="ac-meta__value">
                            {{ $dialogHeader['phone_label'] }}
                        </p>
                    </div>
                </div>

                @if ($peerSyncState['is_visible'])
                    <div class="ac-meta-grid ac-meta-grid--compact ac-surface__divider">
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
                        <p class="ac-surface__eyebrow">
                            История текущего канала
                        </p>
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
                                class="ac-button ac-button--secondary"
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
