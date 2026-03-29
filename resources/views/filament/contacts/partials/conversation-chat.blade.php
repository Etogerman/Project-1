<div
    data-role="conversation-thread"
    style="max-height: 36rem; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 18px; background: #f8fafc; padding: 1rem;"
>
    @if ($messages === [])
        <div
            data-role="conversation-empty"
            style="border: 1px dashed #d1d5db; border-radius: 14px; background: #ffffff; padding: 1.5rem 1rem; text-align: center; font-size: 0.875rem; color: #6b7280;"
        >
            Сообщений ещё не было.
        </div>
    @else
        @php($previousDateKey = null)
        @foreach ($messages as $message)
            @if ($previousDateKey !== $message['date_key'])
                <div
                    data-role="conversation-date-separator"
                    style="display: flex; justify-content: center; padding: 0.25rem 0 0.75rem;"
                >
                    <span
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db; border-radius: 999px; background: #ffffff; padding: 0.3rem 0.75rem; font-size: 0.75rem; font-weight: 600; color: #6b7280;"
                    >
                        {{ $message['date_label'] }}
                    </span>
                </div>
                @php($previousDateKey = $message['date_key'])
            @endif

            <div
                data-role="conversation-message"
                data-direction="{{ $message['direction'] }}"
                data-kind="{{ $message['kind'] }}"
                style="display: flex; justify-content: {{ $message['is_outbound'] ? 'flex-end' : 'flex-start' }}; width: 100%; margin-bottom: 0.75rem;"
            >
                <article
                    style="
                        display: inline-block;
                        width: fit-content;
                        max-width: 72%;
                        border: 1px solid {{ $message['is_outbound'] ? '#bbf7d0' : '#e5e7eb' }};
                        border-radius: 18px;
                        border-top-right-radius: {{ $message['is_outbound'] ? '6px' : '18px' }};
                        border-top-left-radius: {{ $message['is_outbound'] ? '18px' : '6px' }};
                        background: {{ $message['is_outbound'] ? '#ecfdf5' : '#ffffff' }};
                        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
                        padding: 0.85rem 1rem;
                        text-align: left;
                    "
                >
                    <div style="white-space: pre-wrap; word-break: break-word; font-size: 0.95rem; line-height: 1.55; color: #111827;">
                        {{ $message['display_text'] }}
                    </div>

                    <div style="display: flex; justify-content: {{ $message['is_outbound'] ? 'flex-end' : 'flex-start' }}; margin-top: 0.5rem;">
                        <time style="font-size: 0.75rem; line-height: 1; color: #6b7280; font-style: italic;">{{ $message['timestamp_label'] }}</time>
                    </div>
                </article>
            </div>
        @endforeach
    @endif
</div>
