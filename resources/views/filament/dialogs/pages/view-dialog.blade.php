<x-filament-panels::page>
    <div data-role="dialog-page" class="ac-panel-stack">
        <section data-role="dialog-header" class="ac-surface">
            <div class="ac-surface__header">
                <div class="ac-surface__title-group">
                    <h2 class="ac-surface__title">
                        {{ $dialogHeader['channel_label'] }}
                    </h2>
                    <p class="ac-surface__subtitle">
                        Платформа: {{ $dialogHeader['platform_label'] }}
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

            <div class="ac-meta-grid ac-surface__divider">
                <div class="ac-meta">
                    <p class="ac-meta__label">
                        Route source
                    </p>
                    <p class="ac-meta__value">
                        {{ $dialogHeader['route_source_label'] }}
                    </p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">
                        Chat ID
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

        <section data-role="dialog-contact-summary" class="ac-surface">
            <div class="ac-surface__header">
                <div class="ac-surface__title-group">
                    <h3 class="ac-surface__title">
                        {{ $contactSummary['contact_label'] }}
                    </h3>
                    <p class="ac-surface__subtitle">
                        Ответственный: {{ $contactSummary['assigned_user_label'] }}
                    </p>
                    <p class="ac-surface__subtitle">
                        Телефон: {{ $contactSummary['phone_label'] }}
                    </p>
                </div>

                <a
                    href="{{ $contactUrl }}"
                    class="ac-button ac-button--secondary"
                >
                    Открыть контакт
                </a>
            </div>
        </section>

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
            <div class="ac-surface__header">
                <div>
                    <h3 class="ac-surface__title">
                        История сообщений
                    </h3>
                    <p class="ac-surface__subtitle">
                        Только сообщения этого диалога.
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
                        <span wire:loading.remove wire:target="loadOlderMessages">Загрузить более ранние сообщения</span>
                        <span wire:loading wire:target="loadOlderMessages">Загружаем…</span>
                    </button>
                @endif
            </div>

            @include('filament.contacts.partials.conversation-chat', ['messages' => $conversationMessages])
        </section>

        @include('filament.dialogs.partials.reply-composer', $replyComposer)
    </div>
</x-filament-panels::page>
