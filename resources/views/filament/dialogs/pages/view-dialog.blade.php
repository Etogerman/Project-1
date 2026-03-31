<x-filament-panels::page>
    @php
        $routeTone = match ($dialogHeader['route_status_tone']) {
            'success' => ['background' => '#dcfce7', 'color' => '#166534'],
            'warning' => ['background' => '#fef3c7', 'color' => '#92400e'],
            default => ['background' => '#f3f4f6', 'color' => '#4b5563'],
        };
    @endphp

    <div data-role="dialog-page" style="display: grid; gap: 1rem;">
        <section
            data-role="dialog-header"
            style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
        >
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                <div style="display: grid; gap: 0.35rem;">
                    <h2 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: #111827;">
                        {{ $dialogHeader['channel_label'] }}
                    </h2>
                    <p style="margin: 0; font-size: 0.88rem; color: #6b7280;">
                        Платформа: {{ $dialogHeader['platform_label'] }}
                    </p>
                </div>

                <span
                    data-role="dialog-route-status"
                    data-tone="{{ $dialogHeader['route_status_tone'] }}"
                    style="display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.75rem; font-weight: 700; background: {{ $routeTone['background'] }}; color: {{ $routeTone['color'] }};"
                >
                    {{ $dialogHeader['route_status_label'] }}
                </span>
            </div>

            <div style="display: grid; gap: 0.85rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); margin-top: 0.9rem;">
                <div>
                    <p style="margin: 0 0 0.25rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                        Route source
                    </p>
                    <p style="margin: 0; font-size: 0.92rem; color: #111827;">
                        {{ $dialogHeader['route_source_label'] }}
                    </p>
                </div>
                <div>
                    <p style="margin: 0 0 0.25rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                        Chat ID
                    </p>
                    <p style="margin: 0; font-size: 0.92rem; color: #111827;">
                        {{ $dialogHeader['external_chat_id_label'] }}
                    </p>
                </div>
                <div>
                    <p style="margin: 0 0 0.25rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                        Телефон канала
                    </p>
                    <p style="margin: 0; font-size: 0.92rem; color: #111827;">
                        {{ $dialogHeader['phone_label'] }}
                    </p>
                </div>
            </div>
        </section>

        <section
            data-role="dialog-contact-summary"
            style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
        >
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                <div style="display: grid; gap: 0.3rem;">
                    <h3 style="margin: 0; font-size: 1rem; font-weight: 700; color: #111827;">
                        {{ $contactSummary['contact_label'] }}
                    </h3>
                    <p style="margin: 0; font-size: 0.88rem; color: #6b7280;">
                        Ответственный: {{ $contactSummary['assigned_user_label'] }}
                    </p>
                    <p style="margin: 0; font-size: 0.88rem; color: #6b7280;">
                        Телефон: {{ $contactSummary['phone_label'] }}
                    </p>
                </div>

                <a
                    href="{{ $contactUrl }}"
                    style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #cbd5e1; border-radius: 999px; background: #ffffff; padding: 0.5rem 0.85rem; font-size: 0.8rem; font-weight: 700; color: #0f172a; text-decoration: none;"
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
            style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
        >
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.85rem; flex-wrap: wrap;">
                <div>
                    <h3 style="margin: 0; font-size: 1rem; font-weight: 700; color: #111827;">
                        История сообщений
                    </h3>
                    <p style="margin: 0.2rem 0 0; font-size: 0.82rem; color: #6b7280;">
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
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #cbd5e1; border-radius: 999px; background: #ffffff; padding: 0.5rem 0.85rem; font-size: 0.8rem; font-weight: 700; color: #0f172a;"
                    >
                        <span wire:loading.remove wire:target="loadOlderMessages">Загрузить более ранние сообщения</span>
                        <span wire:loading wire:target="loadOlderMessages">Загружаем…</span>
                    </button>
                @endif
            </div>

            @include('filament.contacts.partials.conversation-chat', ['messages' => $conversationMessages])
        </section>

        @include('filament.contacts.partials.inline-reply-composer', $replyComposer)
    </div>
</x-filament-panels::page>
