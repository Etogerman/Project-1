<x-filament-panels::page>
    <div data-role="dialog-page" class="ac-panel-stack ac-panel-stack--relaxed">
        <div data-role="dialog-overview" class="ac-dialog-overview">
            <section data-role="dialog-contact-summary" class="ac-surface ac-surface--hero">
                <div class="ac-surface__header ac-surface__header--centered">
                    <div class="ac-surface__title-group">
                        <p class="ac-surface__eyebrow">
                            Рабочее место оператора
                        </p>
                        <h2 class="ac-surface__title ac-surface__title--hero">
                            {{ $contactSummary['contact_label'] }}
                        </h2>
                        <p class="ac-surface__subtitle">
                            Канал: {{ $dialogHeader['channel_label'] }} · Платформа: {{ $dialogHeader['platform_label'] }}
                        </p>
                    </div>

                    <a
                        href="{{ $contactUrl }}"
                        class="ac-button ac-button--primary"
                    >
                        Открыть контакт
                    </a>
                </div>

                <div class="ac-meta-grid ac-surface__divider">
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

                    <span
                        data-role="dialog-route-status"
                        data-tone="{{ $dialogHeader['route_status_tone'] }}"
                        class="ac-pill"
                    >
                        {{ $dialogHeader['route_status_label'] }}
                    </span>
                </div>

                <div class="ac-meta-grid ac-meta-grid--compact ac-surface__divider">
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
            </section>
        </div>

        <div data-role="dialog-workspace" class="ac-dialog-workspace">
            <section
                data-role="dialog-history"
                x-data="{
                    thread: null,
                    initialized: false,
                    previousHeight: null,
                    captureThread() {
                        this.thread = this.$root.querySelector('[data-role=\"conversation-thread\"]');
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
                }"
                x-init="$nextTick(() => { if (! initialized) { scrollToBottom(); initialized = true; } })"
                x-on:dialog-history-older-messages-loaded.window="$nextTick(() => restorePositionAfterPrepend())"
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
                        <p class="ac-surface__subtitle">
                            Здесь показаны только сообщения текущего диалога в хронологическом порядке.
                        </p>
                    </div>

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

                @include('filament.contacts.partials.conversation-chat', ['messages' => $conversationMessages])
            </section>

            @include('filament.dialogs.partials.reply-composer', $replyComposer)
        </div>
    </div>
</x-filament-panels::page>
