<div data-role="conversation-thread" class="max-h-[36rem] space-y-3 overflow-y-auto rounded-2xl border border-gray-200/80 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/[0.03]">
    @if ($messages === [])
        <div data-role="conversation-empty" class="rounded-xl border border-dashed border-gray-200 bg-white/80 px-4 py-6 text-center text-sm text-gray-500 dark:border-white/10 dark:bg-white/[0.02] dark:text-gray-400">
            Сообщений ещё не было.
        </div>
    @else
        @foreach ($messages as $message)
            <div
                data-role="conversation-message"
                data-direction="{{ $message['direction'] }}"
                data-kind="{{ $message['kind'] }}"
                class="flex {{ $message['is_outbound'] ? 'justify-end' : 'justify-start' }}"
            >
                <article class="w-full max-w-3xl rounded-2xl border px-4 py-3 shadow-sm {{ $message['is_outbound']
                    ? 'border-emerald-200/80 bg-emerald-50/90 dark:border-emerald-500/20 dark:bg-emerald-500/10'
                    : 'border-white/80 bg-white dark:border-white/10 dark:bg-slate-900/70' }}">
                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                        <span class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 font-medium text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
                            {{ $message['direction_label'] }}
                        </span>

                        <span class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 font-medium text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
                            {{ $message['kind_label'] }}
                        </span>

                        <span>{{ $message['channel_label'] }}</span>
                        <span aria-hidden="true">•</span>
                        <time>{{ $message['time_label'] }}</time>
                    </div>

                    <div class="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-gray-950 dark:text-white">
                        {{ $message['text'] }}
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                        @if (filled($message['message_id_label']))
                            <span>Message ID: {{ $message['message_id_label'] }}</span>
                        @endif

                        @if (filled($message['provider_event_key_label']))
                            <span>Event key: {{ $message['provider_event_key_label'] }}</span>
                        @endif

                        @if (filled($message['auto_reply_sent_at_label']))
                            <span>Автоответ: {{ $message['auto_reply_sent_at_label'] }}</span>
                        @endif

                        @if (filled($message['reply_status_label']))
                            <span>Статус: {{ $message['reply_status_label'] }}</span>
                        @endif

                        @if (filled($message['reply_link_label']))
                            <span>{{ $message['reply_link_label'] }}</span>
                        @endif
                    </div>
                </article>
            </div>
        @endforeach
    @endif
</div>
