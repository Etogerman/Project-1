<div
    data-role="conversation-thread"
    class="ac-thread"
>
    @if ($messages === [])
        <div data-role="conversation-empty" class="ac-empty-state">
            В этом диалоге пока нет сообщений.
        </div>
    @else
        <div class="ac-thread__stack">
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
                            <div class="ac-message__meta-main">
                                <span
                                    class="ac-pill"
                                    data-tone="{{ $message['is_outbound'] ? 'success' : 'info' }}"
                                >
                                    {{ $message['is_outbound'] ? 'Исходящее' : 'Входящее' }}
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

                                <span data-role="conversation-channel" class="ac-pill">
                                    {{ $message['channel_label'] }}
                                </span>
                            </div>

                            <div class="ac-message__timestamp">
                                <time>{{ $message['timestamp_label'] }}</time>
                            </div>
                        </div>

                        <div class="ac-message__text">{{ $message['display_text'] }}</div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
</div>
