<div data-role="conversation-thread" class="max-h-[36rem] space-y-3 overflow-y-auto rounded-2xl border border-gray-200/80 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/[0.03]">
    @if ($messages === [])
        <div data-role="conversation-empty" class="rounded-xl border border-dashed border-gray-200 bg-white/80 px-4 py-6 text-center text-sm text-gray-500 dark:border-white/10 dark:bg-white/[0.02] dark:text-gray-400">
            Сообщений ещё не было.
        </div>
    @else
        @php($previousDateKey = null)
        @foreach ($messages as $message)
            @if ($previousDateKey !== $message['date_key'])
                <div data-role="conversation-date-separator" class="flex justify-center py-1">
                    <span class="rounded-full border border-gray-200/80 bg-white/80 px-3 py-1 text-[11px] font-medium text-gray-500 shadow-sm dark:border-white/10 dark:bg-slate-900/70 dark:text-gray-400">
                        {{ $message['date_label'] }}
                    </span>
                </div>
                @php($previousDateKey = $message['date_key'])
            @endif

            <div
                data-role="conversation-message"
                data-direction="{{ $message['direction'] }}"
                data-kind="{{ $message['kind'] }}"
                class="flex {{ $message['is_outbound'] ? 'justify-end' : 'justify-start' }}"
            >
                <article class="w-full max-w-[75%] rounded-2xl px-4 py-3 shadow-sm {{ $message['is_outbound']
                    ? 'rounded-tr-sm border border-emerald-200/80 bg-emerald-50/90 dark:border-emerald-500/20 dark:bg-emerald-500/10'
                    : 'rounded-tl-sm border border-white/80 bg-white dark:border-white/10 dark:bg-slate-900/70' }}">
                    <div class="whitespace-pre-wrap break-words text-sm leading-6 text-gray-950 dark:text-white">
                        {{ $message['display_text'] }}
                    </div>

                    <div class="mt-2 flex {{ $message['is_outbound'] ? 'justify-end' : 'justify-start' }}">
                        <time class="text-[11px] text-gray-500 dark:text-gray-400">{{ $message['time_label'] }}</time>
                    </div>
                </article>
            </div>
        @endforeach
    @endif
</div>
