<div
    data-role="conversation-thread"
    class="ac-thread"
>
    @if ($messages === [])
        <div data-role="conversation-empty" class="ac-empty-state">
            Сообщений ещё не было.
        </div>
    @else
        @php($previousDateKey = null)
        @foreach ($messages as $message)
            @if ($previousDateKey !== $message['date_key'])
                <div data-role="conversation-date-separator" class="ac-thread__date">
                    <span class="ac-thread__date-pill">
                        {{ $message['date_label'] }}
                    </span>
                </div>
                @php($previousDateKey = $message['date_key'])
            @endif

            <div
                data-role="conversation-message"
                data-direction="{{ $message['direction'] }}"
                data-kind="{{ $message['kind'] }}"
                @class([
                    'ac-message',
                    'ac-message--outbound' => $message['is_outbound'],
                    'ac-message--inbound' => ! $message['is_outbound'],
                ])
            >
                <article class="ac-message__bubble">
                    <div data-role="conversation-meta" class="ac-message__meta">
                        <span
                            data-role="conversation-channel"
                            class="ac-pill"
                            data-tone="{{ $message['is_outbound'] ? 'success' : 'neutral' }}"
                        >
                            {{ $message['channel_label'] }}
                        </span>

                        @if (filled($message['sender_label']))
                            <span
                                data-role="conversation-sender"
                                class="ac-pill"
                                data-tone="primary"
                            >
                                {{ $message['sender_label'] }}
                            </span>
                        @endif
                    </div>

                    <div class="ac-message__text">{{ $message['display_text'] }}</div>

                    <div class="ac-message__time">
                        <time>{{ $message['timestamp_label'] }}</time>
                    </div>
                </article>
            </div>
        @endforeach
    @endif
</div>
