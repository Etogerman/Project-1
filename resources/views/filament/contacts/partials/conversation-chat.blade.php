@php
    $displayMode = $displayMode ?? \App\Filament\Resources\Dialogs\Pages\ViewDialog::CONVERSATION_DISPLAY_MODE_FORMATTED;
@endphp

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
                    <div
                        wire:key="conversation-date-{{ $message['date_key'] }}"
                        data-role="conversation-date-separator"
                        class="ac-thread__date"
                    >
                        <span class="ac-thread__date-pill">
                            {{ $message['date_label'] }}
                        </span>
                    </div>
                    @php($previousDateKey = $message['date_key'])
                @endif

                <div
                    wire:key="conversation-message-{{ $message['id'] }}"
                    data-role="conversation-message"
                    data-direction="{{ $message['direction'] }}"
                    data-kind="{{ $message['kind'] }}"
                    @class([
                        'ac-message',
                        'ac-message--system' => $message['is_system_event'] ?? false,
                        'ac-message--outbound' => $message['is_outbound'] && ! ($message['is_system_event'] ?? false),
                        'ac-message--inbound' => ! $message['is_outbound'] && ! ($message['is_system_event'] ?? false),
                    ])
                >
                    <article class="ac-message__bubble">
                        <div data-role="conversation-meta" class="ac-message__meta">
                            <div class="ac-message__meta-main">
                                <span
                                    class="ac-pill"
                                    data-tone="{{ $message['direction_tone'] ?? ($message['is_outbound'] ? 'success' : 'info') }}"
                                >
                                    {{ $message['direction_label'] ?? ($message['is_outbound'] ? 'Исходящее' : 'Входящее') }}
                                </span>

                                @if (filled($message['sender_label']))
                                    <span
                                        data-role="conversation-sender"
                                        class="ac-pill"
                                        data-tone="{{ $message['sender_tone'] ?? 'primary' }}"
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

                        @if ($displayMode === \App\Filament\Resources\Dialogs\Pages\ViewDialog::CONVERSATION_DISPLAY_MODE_FORMATTED && filled($message['formatted_html'] ?? null))
                            <div class="ac-message__text ac-message__text--html">{!! $message['formatted_html'] !!}</div>
                        @elseif ($displayMode === \App\Filament\Resources\Dialogs\Pages\ViewDialog::CONVERSATION_DISPLAY_MODE_HTML && filled($message['html_source_text'] ?? null))
                            <div class="ac-message__text">{{ $message['html_source_text'] }}</div>
                        @else
                            <div class="ac-message__text">{{ $message['display_text'] }}</div>
                        @endif

                        @if (! empty($message['media_badges'] ?? []))
                            <div data-role="conversation-media" class="ac-message__meta-main">
                                @foreach ($message['media_badges'] as $mediaBadge)
                                    <span
                                        data-role="conversation-media-badge"
                                        class="ac-pill"
                                        data-tone="gray"
                                    >
                                        {{ $mediaBadge }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @if (! empty($message['media_state_badges'] ?? []))
                            <div data-role="conversation-media-state" class="ac-message__meta-main">
                                @foreach ($message['media_state_badges'] as $mediaStateBadge)
                                    <span
                                        data-role="conversation-media-status-badge"
                                        class="ac-pill"
                                        data-tone="{{ $mediaStateBadge['tone'] ?? 'gray' }}"
                                    >
                                        {{ $mediaStateBadge['label'] ?? 'Статус не определён' }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </article>
                </div>
            @endforeach
        </div>
    @endif
</div>
