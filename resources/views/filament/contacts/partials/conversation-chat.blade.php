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

                @php($isSystemMessage = $message['is_system_message'] ?? ($message['is_system_event'] ?? false))
                @php($messageMediaItems = $message['media_items'] ?? [])
                @php($messageMediaCollection = collect($messageMediaItems))
                @php($previewableMediaItems = $messageMediaCollection->filter(fn ($mediaItem): bool => ! empty($mediaItem['is_previewable']) && filled($mediaItem['preview_url'] ?? null))->values())
                @php($previewableImageMediaItems = $previewableMediaItems->filter(fn ($mediaItem): bool => ($mediaItem['preview_kind'] ?? null) === \App\Models\MessageAttachment::PREVIEW_KIND_IMAGE)->values())
                @php($previewableFileMediaItems = $previewableMediaItems->reject(fn ($mediaItem): bool => ($mediaItem['preview_kind'] ?? null) === \App\Models\MessageAttachment::PREVIEW_KIND_IMAGE)->values())
                @php($nonPreviewableMediaItems = $messageMediaCollection->reject(fn ($mediaItem): bool => ! empty($mediaItem['is_previewable']) && filled($mediaItem['preview_url'] ?? null))->values())
                @php($attachmentMediaItems = $previewableFileMediaItems->merge($nonPreviewableMediaItems)->values())
                @php($hasPreviewableMedia = $previewableMediaItems->isNotEmpty())
                @php($hasPreviewableImages = $previewableImageMediaItems->isNotEmpty())
                @php($hasOnlyStickerPreviewImages = $hasPreviewableImages && $previewableImageMediaItems->every(fn ($mediaItem): bool => ($mediaItem['media_kind'] ?? null) === \App\Models\MessageAttachment::MEDIA_KIND_STICKER))
                @php($hasInlineVideoAttachments = $attachmentMediaItems->contains(fn ($mediaItem): bool => empty($mediaItem['is_video_note']) && ($mediaItem['preview_kind'] ?? null) === \App\Models\MessageAttachment::PREVIEW_KIND_VIDEO && ! empty($mediaItem['is_previewable']) && filled($mediaItem['preview_url'] ?? null)))
                @php($hasOnlyPreviewableMedia = $messageMediaItems !== [] && $nonPreviewableMediaItems->isEmpty())
                @php($hideGeneratedMediaSummary = $hasOnlyPreviewableMedia && ! empty($message['is_media_only_display_text'] ?? false))
                @php($contactShareContext = $message['contact_share_context'] ?? null)
                @php($hideGeneratedContactShareSummary = is_array($contactShareContext) && ($message['kind'] ?? null) === \App\Models\Message::KIND_INBOUND_CONTACT_SHARE)
                <div
                    wire:key="conversation-message-{{ $message['item_key'] ?? $message['id'] }}"
                    data-role="conversation-message"
                    data-direction="{{ $message['direction'] }}"
                    data-kind="{{ $message['kind'] }}"
                    @class([
                        'ac-message',
                        'ac-message--system' => $isSystemMessage,
                        'ac-message--outbound' => $message['is_outbound'] && ! $isSystemMessage,
                        'ac-message--inbound' => ! $message['is_outbound'] && ! $isSystemMessage,
                    ])
                >
                    <article @class([
                        'ac-message__bubble',
                        'ac-message__bubble--has-gallery' => $hasPreviewableImages,
                    ])>
                        <div data-role="conversation-meta" class="ac-message__meta">
                            <div class="ac-message__meta-main">
                                <span
                                    class="ac-pill"
                                    data-tone="{{ $message['direction_tone'] ?? ($message['is_outbound'] ? 'success' : 'info') }}"
                                >
                                    {{ $message['direction_label'] ?? ($message['is_outbound'] ? 'Исходящее' : 'Входящее') }}
                                </span>

                                @if (! $isSystemMessage && filled($message['sender_label']))
                                    <span
                                        data-role="conversation-sender"
                                        class="ac-pill"
                                        data-tone="{{ $message['sender_tone'] ?? 'primary' }}"
                                    >
                                        {{ $message['sender_label'] }}
                                    </span>
                                @endif

                            </div>

                            <div class="ac-message__timestamp">
                                <time>{{ $message['timestamp_label'] }}</time>
                            </div>
                        </div>

                        @if (! $isSystemMessage && filled($message['forwarded_label'] ?? null))
                            @php($forwardedDetails = $message['forwarded_context']['details'] ?? [])
                            @php($forwardedContactUrl = $message['forwarded_context']['contact_url'] ?? null)
                            @php($forwardedStateKey = 'ac-forwarded-'.$message['id'])
                            <div
                                data-role="conversation-forwarded"
                                class="ac-message__forwarded"
                                x-data="{
                                    key: @js($forwardedStateKey),
                                    open: false,
                                    init() {
                                        this.open = window.sessionStorage.getItem(this.key) === '1'
                                    },
                                    toggle() {
                                        this.open = ! this.open
                                        window.sessionStorage.setItem(this.key, this.open ? '1' : '0')
                                    },
                                }"
                            >
                                <button
                                    type="button"
                                    class="ac-message__forwarded-summary"
                                    x-bind:aria-expanded="open.toString()"
                                    x-on:click.stop="toggle()"
                                >
                                    <span class="ac-message__forwarded-summary-icon" aria-hidden="true" x-text="open ? '▾' : '▸'"></span>
                                    {{ $message['forwarded_label'] }}
                                </button>

                                @if (! empty($forwardedDetails))
                                    <dl class="ac-message__forwarded-details" x-show="open" x-cloak>
                                        @foreach ($forwardedDetails as $forwardedDetail)
                                            <div class="ac-message__forwarded-row">
                                                <dt>{{ $forwardedDetail['label'] }}</dt>
                                                <dd @class([
                                                    'ac-message__forwarded-value',
                                                    'ac-message__forwarded-value--success' => ($forwardedDetail['tone'] ?? null) === 'success',
                                                    'ac-message__forwarded-value--warning' => ($forwardedDetail['tone'] ?? null) === 'warning',
                                                ])>
                                                    @if (($forwardedDetail['label'] ?? null) === 'AB контакт' && filled($forwardedContactUrl))
                                                        <a
                                                            href="{{ $forwardedContactUrl }}"
                                                            class="ac-message__forwarded-link"
                                                            x-on:click.stop
                                                        >
                                                            {{ $forwardedDetail['value'] }}
                                                        </a>
                                                    @else
                                                        {{ $forwardedDetail['value'] }}
                                                    @endif
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        @endif

                        @if (! $isSystemMessage && is_array($contactShareContext))
                            @php($contactShareDetails = $contactShareContext['details'] ?? [])
                            <div data-role="conversation-contact-share" class="ac-message__contact-share">
                                <div class="ac-message__contact-share-heading">
                                    {{ $contactShareContext['label'] ?? 'Поделился контактом' }}
                                </div>

                                @if (filled($contactShareContext['name'] ?? null))
                                    <div class="ac-message__contact-share-name">
                                        {{ $contactShareContext['name'] }}
                                    </div>
                                @endif

                                @if (! empty($contactShareDetails))
                                    <dl class="ac-message__contact-share-details">
                                        @foreach ($contactShareDetails as $contactShareDetail)
                                            <div class="ac-message__contact-share-row">
                                                <dt>{{ $contactShareDetail['label'] }}</dt>
                                                <dd @class([
                                                    'ac-message__contact-share-value',
                                                    'ac-message__contact-share-value--success' => ($contactShareDetail['tone'] ?? null) === 'success',
                                                    'ac-message__contact-share-value--warning' => ($contactShareDetail['tone'] ?? null) === 'warning',
                                                ])>
                                                    {{ $contactShareDetail['value'] }}
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        @endif

                        @if ($hasPreviewableImages)
                            <div
                                data-role="conversation-media-gallery"
                                data-media-viewer-gallery
                                @class([
                                    'ac-message-gallery',
                                    'ac-message-gallery--stickers' => $hasOnlyStickerPreviewImages,
                                ])
                                data-count="{{ min($previewableImageMediaItems->count(), 4) }}"
                            >
                                @foreach ($previewableImageMediaItems as $mediaItem)
                                    <a
                                        data-role="conversation-attachment-preview"
                                        data-media-viewer-trigger
                                        data-media-viewer-type="{{ \App\Models\MessageAttachment::PREVIEW_KIND_IMAGE }}"
                                        data-media-viewer-title="{{ $mediaItem['media_kind_label'] ?? 'Изображение' }}"
                                        data-media-viewer-download-url="{{ $mediaItem['download_url'] ?? '' }}"
                                        @class([
                                            'ac-message-gallery__item',
                                            'ac-message-gallery__item--sticker' => ($mediaItem['media_kind'] ?? null) === \App\Models\MessageAttachment::MEDIA_KIND_STICKER,
                                        ])
                                        href="{{ $mediaItem['preview_url'] }}"
                                        target="_blank"
                                        rel="noopener"
                                        aria-label="Открыть изображение"
                                        title="Открыть изображение"
                                    >
                                        <img
                                            src="{{ $mediaItem['preview_url'] }}"
                                            alt="{{ $mediaItem['media_kind_label'] ?? 'Изображение' }}"
                                            loading="lazy"
                                        >
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        @if (! $hideGeneratedContactShareSummary && ! $hideGeneratedMediaSummary && $displayMode === \App\Filament\Resources\Dialogs\Pages\ViewDialog::CONVERSATION_DISPLAY_MODE_FORMATTED && filled($message['formatted_html'] ?? null))
                            <div class="ac-message__text ac-message__text--html">{!! $message['formatted_html'] !!}</div>
                        @elseif (! $hideGeneratedContactShareSummary && ! $hideGeneratedMediaSummary && $displayMode === \App\Filament\Resources\Dialogs\Pages\ViewDialog::CONVERSATION_DISPLAY_MODE_HTML && filled($message['html_source_text'] ?? null))
                            <div class="ac-message__text">{{ $message['html_source_text'] }}</div>
                        @elseif (! $hideGeneratedContactShareSummary && ! $hideGeneratedMediaSummary)
                            <div class="ac-message__text">{{ $message['display_text'] }}</div>
                        @endif

                        @if (! $isSystemMessage && filled($message['edited_label'] ?? null))
                            @php($editHistory = $message['edit_history'] ?? [])
                            @if (! empty($editHistory))
                                <div
                                    data-role="conversation-message-edit-history"
                                    class="ac-message__edit-history"
                                    x-data="{ open: false }"
                                >
                                    <button
                                        type="button"
                                        class="ac-message__edit-summary"
                                        x-bind:aria-expanded="open.toString()"
                                        x-on:click="open = ! open"
                                    >
                                        <span class="ac-message__edit-summary-icon" aria-hidden="true" x-text="open ? '▾' : '▸'"></span>
                                        {{ $message['edited_label'] }}
                                    </button>

                                    <div class="ac-message__edit-details" x-show="open" x-cloak>
                                        @foreach ($editHistory as $editRevision)
                                            <div class="ac-message__edit-row">
                                                <div class="ac-message__edit-time">{{ $editRevision['label'] ?? 'время неизвестно' }}</div>
                                                <dl class="ac-message__edit-diff">
                                                    <div>
                                                        <dt>Было</dt>
                                                        <dd>{{ $editRevision['previous_text'] ?? 'Без текста' }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Стало</dt>
                                                        <dd>{{ $editRevision['new_text'] ?? 'Без текста' }}</dd>
                                                    </div>
                                                </dl>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div data-role="conversation-message-edited" class="ac-message__edited-label">
                                    {{ $message['edited_label'] }}
                                </div>
                            @endif
                        @endif

                        @if (! $hasPreviewableMedia && ! empty($message['media_badges'] ?? []))
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

                        @if (! $hasOnlyPreviewableMedia && ! empty($message['media_state_badges'] ?? []))
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

                        @if ($attachmentMediaItems->isNotEmpty())
                            <div data-role="conversation-attachments" @class([
                                'ac-message__attachments',
                                'ac-message__attachments--after-gallery' => $hasPreviewableImages,
                                'ac-message__attachments--inline-video' => $hasInlineVideoAttachments,
                            ])
                                @if ($previewableFileMediaItems->isNotEmpty())
                                    data-media-viewer-gallery
                                @endif
                            >
                                @foreach ($attachmentMediaItems as $mediaItem)
                                    @php($isInlineAudioAttachment = ($mediaItem['preview_kind'] ?? null) === \App\Models\MessageAttachment::PREVIEW_KIND_AUDIO && ! empty($mediaItem['is_previewable']) && filled($mediaItem['preview_url'] ?? null))
                                    @php($isInlineVideoNoteAttachment = ! empty($mediaItem['is_video_note']) && ($mediaItem['preview_kind'] ?? null) === \App\Models\MessageAttachment::PREVIEW_KIND_VIDEO && ! empty($mediaItem['is_previewable']) && filled($mediaItem['preview_url'] ?? null))
                                    @php($isInlineVideoAttachment = empty($mediaItem['is_video_note']) && ($mediaItem['preview_kind'] ?? null) === \App\Models\MessageAttachment::PREVIEW_KIND_VIDEO && ! empty($mediaItem['is_previewable']) && filled($mediaItem['preview_url'] ?? null))
                                    @php($audioSizeLabel = collect($mediaItem['meta'] ?? [])->first(fn ($part) => is_string($part) && preg_match('/\b(?:байт|Б|КБ|МБ|ГБ|B|KB|MB|GB)\b/u', $part) === 1))
                                    @php($videoNoteMeta = collect([$mediaItem['duration_label'] ?? null, $mediaItem['file_size_label'] ?? null])->filter()->implode(' · '))
                                    @php($videoMeta = collect([$mediaItem['duration_label'] ?? null, $mediaItem['file_size_label'] ?? null])->filter()->implode(' · '))
                                    <div
                                        data-role="conversation-attachment"
                                        data-status="{{ $mediaItem['status'] ?? 'unknown' }}"
                                        @class([
                                            'ac-message-attachment',
                                            'ac-message-attachment--audio' => $isInlineAudioAttachment,
                                            'ac-message-attachment--video-note' => $isInlineVideoNoteAttachment,
                                            'ac-message-attachment--video' => $isInlineVideoAttachment,
                                        ])
                                    >
                                        <div class="ac-message-attachment__main">
                                            @if ($isInlineAudioAttachment)
                                                <div
                                                    data-role="conversation-voice-player"
                                                    class="ac-voice-player"
                                                    data-voice-title="{{ $mediaItem['media_kind_label'] ?? 'Аудио' }} {{ $mediaItem['title'] ?? '' }}"
                                                >
                                                    <audio
                                                        data-role="conversation-attachment-audio"
                                                        class="ac-voice-player__audio"
                                                        preload="metadata"
                                                        src="{{ $mediaItem['preview_url'] }}"
                                                        aria-label="{{ $mediaItem['media_kind_label'] ?? 'Аудио' }} {{ $mediaItem['title'] ?? '' }}"
                                                    ></audio>
                                                    <button
                                                        type="button"
                                                        data-role="conversation-voice-toggle"
                                                        class="ac-voice-player__toggle"
                                                        aria-label="Воспроизвести голосовое"
                                                        title="Воспроизвести голосовое"
                                                    >
                                                        <span data-role="conversation-voice-play-icon" class="ac-voice-player__icon">
                                                            <x-filament::icon icon="heroicon-m-play" />
                                                        </span>
                                                        <span data-role="conversation-voice-pause-icon" class="ac-voice-player__icon" hidden>
                                                            <x-filament::icon icon="heroicon-m-pause" />
                                                        </span>
                                                    </button>
                                                    <div class="ac-voice-player__body">
                                                        <button
                                                            type="button"
                                                            data-role="conversation-voice-waveform"
                                                            class="ac-voice-player__waveform"
                                                            aria-label="Перемотать голосовое"
                                                            title="Перемотать голосовое"
                                                        >
                                                            @for ($barIndex = 0; $barIndex < 46; $barIndex++)
                                                                <span data-role="conversation-voice-waveform-bar"></span>
                                                            @endfor
                                                        </button>
                                                        <div class="ac-voice-player__meta">
                                                            <span data-role="conversation-voice-time">0:00</span>
                                                            @if (filled($audioSizeLabel))
                                                                <span aria-hidden="true">•</span>
                                                                <span>{{ $audioSizeLabel }}</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    @if (! empty($mediaItem['is_downloadable']) && filled($mediaItem['download_url'] ?? null))
                                                        <a
                                                            data-role="conversation-attachment-download"
                                                            class="ac-voice-player__download"
                                                            href="{{ $mediaItem['download_url'] }}"
                                                            target="_blank"
                                                            rel="noopener"
                                                            title="Скачать голосовое"
                                                            aria-label="Скачать голосовое"
                                                        >
                                                            <x-filament::icon icon="heroicon-m-arrow-down-tray" />
                                                        </a>
                                                    @endif
                                                </div>
                                            @elseif ($isInlineVideoNoteAttachment)
                                                <div
                                                    data-role="conversation-video-note-player"
                                                    class="ac-video-note-player"
                                                    data-video-note-title="{{ $mediaItem['media_kind_label'] ?? 'Кружок' }} {{ $mediaItem['title'] ?? '' }}"
                                                >
                                                    <video
                                                        data-role="conversation-video-note-video"
                                                        class="ac-video-note-player__video"
                                                        preload="metadata"
                                                        playsinline
                                                        src="{{ $mediaItem['preview_url'] }}"
                                                        aria-label="{{ $mediaItem['media_kind_label'] ?? 'Кружок' }} {{ $mediaItem['title'] ?? '' }}"
                                                    ></video>
                                                    <button
                                                        type="button"
                                                        data-role="conversation-video-note-toggle"
                                                        class="ac-video-note-player__toggle"
                                                        aria-label="Воспроизвести кружок"
                                                        title="Воспроизвести кружок"
                                                    >
                                                        <span data-role="conversation-video-note-play-icon" class="ac-video-note-player__icon">
                                                            <x-filament::icon icon="heroicon-m-play" />
                                                        </span>
                                                        <span data-role="conversation-video-note-pause-icon" class="ac-video-note-player__icon" hidden>
                                                            <x-filament::icon icon="heroicon-m-pause" />
                                                        </span>
                                                    </button>
                                                    @if (filled($videoNoteMeta))
                                                        <div class="ac-video-note-player__meta">{{ $videoNoteMeta }}</div>
                                                    @endif
                                                    @if (! empty($mediaItem['is_downloadable']) && filled($mediaItem['download_url'] ?? null))
                                                        <a
                                                            data-role="conversation-attachment-download"
                                                            class="ac-video-note-player__download"
                                                            href="{{ $mediaItem['download_url'] }}"
                                                            target="_blank"
                                                            rel="noopener"
                                                            title="Скачать кружок"
                                                            aria-label="Скачать кружок"
                                                        >
                                                            <x-filament::icon icon="heroicon-m-arrow-down-tray" />
                                                        </a>
                                                    @endif
                                                </div>
                                            @elseif ($isInlineVideoAttachment)
                                                <div
                                                    data-role="conversation-video-player"
                                                    class="ac-video-player"
                                                    data-video-title="{{ $mediaItem['media_kind_label'] ?? 'Видео' }} {{ $mediaItem['title'] ?? '' }}"
                                                >
                                                    <video
                                                        data-role="conversation-attachment-video"
                                                        class="ac-video-player__video"
                                                        controls
                                                        preload="metadata"
                                                        playsinline
                                                        src="{{ $mediaItem['preview_url'] }}"
                                                        @if (filled($mediaItem['poster_url'] ?? null))
                                                            poster="{{ $mediaItem['poster_url'] }}"
                                                        @endif
                                                        aria-label="{{ $mediaItem['media_kind_label'] ?? 'Видео' }} {{ $mediaItem['title'] ?? '' }}"
                                                    ></video>
                                                    <div class="ac-video-player__footer">
                                                        @if (filled($videoMeta))
                                                            <span class="ac-video-player__meta">{{ $videoMeta }}</span>
                                                        @endif
                                                        <div class="ac-video-player__actions">
                                                            <a
                                                                data-role="conversation-attachment-preview"
                                                                data-media-viewer-trigger
                                                                data-media-viewer-type="{{ \App\Models\MessageAttachment::PREVIEW_KIND_VIDEO }}"
                                                                data-media-viewer-title="{{ $mediaItem['title'] ?? ($mediaItem['media_kind_label'] ?? 'Видео') }}"
                                                                data-media-viewer-download-url="{{ $mediaItem['download_url'] ?? '' }}"
                                                                class="ac-video-player__button"
                                                                href="{{ $mediaItem['preview_url'] }}"
                                                                target="_blank"
                                                                rel="noopener"
                                                                title="Открыть видео"
                                                                aria-label="Открыть видео"
                                                            >
                                                                <x-filament::icon icon="heroicon-m-arrows-pointing-out" />
                                                            </a>
                                                            @if (! empty($mediaItem['is_downloadable']) && filled($mediaItem['download_url'] ?? null))
                                                                <a
                                                                    data-role="conversation-attachment-download"
                                                                    class="ac-video-player__button"
                                                                    href="{{ $mediaItem['download_url'] }}"
                                                                    target="_blank"
                                                                    rel="noopener"
                                                                    title="Скачать видео"
                                                                    aria-label="Скачать видео"
                                                                >
                                                                    <x-filament::icon icon="heroicon-m-arrow-down-tray" />
                                                                </a>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="ac-message-attachment__title">
                                                    <span class="ac-message-attachment__kind">{{ $mediaItem['media_kind_label'] ?? 'Медиа' }}</span>
                                                    <span>{{ $mediaItem['title'] ?? 'Вложение' }}</span>
                                                </div>
                                            @endif

                                            @if (! $isInlineAudioAttachment && ! $isInlineVideoNoteAttachment && ! $isInlineVideoAttachment && ! empty($mediaItem['meta'] ?? []))
                                                <div data-role="conversation-attachment-meta" class="ac-message-attachment__meta">
                                                    {{ implode(' · ', $mediaItem['meta']) }}
                                                </div>
                                            @endif

                                            @if (filled($mediaItem['error_message'] ?? null))
                                                <div data-role="conversation-attachment-error" class="ac-message-attachment__error">
                                                    {{ $mediaItem['error_message'] }}
                                                </div>
                                            @endif
                                        </div>

                                        @if (! $isInlineAudioAttachment && ! $isInlineVideoNoteAttachment && ! $isInlineVideoAttachment)
                                            <div class="ac-message-attachment__side">
                                            @if ((bool) ($mediaItem['show_status'] ?? true))
                                                <span
                                                    data-role="conversation-attachment-status"
                                                    class="ac-pill"
                                                    data-tone="{{ $mediaItem['status_tone'] ?? 'gray' }}"
                                                >
                                                    {{ $mediaItem['status_label'] ?? 'Статус не определён' }}
                                                </span>
                                            @endif

                                            @if (! $isInlineAudioAttachment && ! empty($mediaItem['is_previewable']) && filled($mediaItem['preview_url'] ?? null))
                                                <a
                                                    data-role="conversation-attachment-preview"
                                                    data-media-viewer-trigger
                                                    data-media-viewer-type="{{ $mediaItem['preview_kind'] ?? '' }}"
                                                    data-media-viewer-title="{{ $mediaItem['title'] ?? ($mediaItem['media_kind_label'] ?? 'Вложение') }}"
                                                    data-media-viewer-download-url="{{ $mediaItem['download_url'] ?? '' }}"
                                                    class="ac-message-attachment__download"
                                                    href="{{ $mediaItem['preview_url'] }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                >
                                                    Открыть
                                                </a>
                                            @endif

                                            @if (! empty($mediaItem['is_downloadable']) && filled($mediaItem['download_url'] ?? null))
                                                <a
                                                    data-role="conversation-attachment-download"
                                                    class="ac-message-attachment__download"
                                                    href="{{ $mediaItem['download_url'] }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                >
                                                    Скачать
                                                </a>
                                            @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                </div>
            @endforeach
        </div>
    @endif
</div>

<script>
    (() => {
        if (window.__acConversationMediaViewerReady) {
            return;
        }

        window.__acConversationMediaViewerReady = true;

        const triggerSelector = '[data-media-viewer-trigger][href]';
        const state = {
            items: [],
            index: 0,
            previousActiveElement: null,
        };

        const createViewer = () => {
            let viewer = document.querySelector('[data-role="media-viewer"]');

            if (viewer) {
                return viewer;
            }

            viewer = document.createElement('div');
            viewer.dataset.role = 'media-viewer';
            viewer.className = 'ac-media-viewer';
            viewer.hidden = true;
            viewer.setAttribute('role', 'dialog');
            viewer.setAttribute('aria-modal', 'true');
            viewer.setAttribute('aria-label', 'Просмотр медиа');
            viewer.setAttribute('tabindex', '-1');
            viewer.innerHTML = `
                <div class="ac-media-viewer__backdrop" data-media-viewer-action="close"></div>
                <div class="ac-media-viewer__dialog">
                    <div class="ac-media-viewer__toolbar">
                        <div class="ac-media-viewer__summary">
                            <span class="ac-media-viewer__title" data-role="media-viewer-title"></span>
                            <span class="ac-media-viewer__counter" data-role="media-viewer-counter"></span>
                        </div>
                        <div class="ac-media-viewer__actions">
                            <button
                                type="button"
                                class="ac-media-viewer__button"
                                data-media-viewer-action="copy"
                                data-role="media-viewer-copy"
                                title="Скопировать ссылку"
                            >Копировать</button>
                            <a
                                class="ac-media-viewer__button ac-media-viewer__button--download"
                                data-role="media-viewer-download"
                                target="_blank"
                                rel="noopener"
                                title="Скачать файл"
                            >Скачать</a>
                            <button
                                type="button"
                                class="ac-media-viewer__button ac-media-viewer__button--icon"
                                data-media-viewer-action="close"
                                aria-label="Закрыть просмотр"
                                title="Закрыть"
                            >×</button>
                        </div>
                    </div>
                    <div class="ac-media-viewer__copy-panel" data-role="media-viewer-copy-panel" hidden>
                        <input
                            class="ac-media-viewer__copy-input"
                            data-role="media-viewer-copy-input"
                            aria-label="Ссылка на медиа"
                            readonly
                        >
                        <span class="ac-media-viewer__copy-hint">Нажмите Cmd+C</span>
                    </div>
                    <button
                        type="button"
                        class="ac-media-viewer__nav ac-media-viewer__nav--prev"
                        data-media-viewer-action="previous"
                        aria-label="Предыдущее медиа"
                        title="Предыдущее медиа"
                    >‹</button>
                    <figure class="ac-media-viewer__figure">
                        <img data-role="media-viewer-image" alt="">
                        <video
                            data-role="media-viewer-video"
                            controls
                            playsinline
                            preload="metadata"
                            hidden
                        ></video>
                        <audio
                            data-role="media-viewer-audio"
                            controls
                            preload="metadata"
                            hidden
                        ></audio>
                        <div class="ac-media-viewer__audio-panel" data-role="media-viewer-audio-panel" hidden></div>
                        <iframe
                            data-role="media-viewer-pdf"
                            title="Предпросмотр PDF"
                            referrerpolicy="no-referrer"
                            sandbox
                            hidden
                        ></iframe>
                    </figure>
                    <button
                        type="button"
                        class="ac-media-viewer__nav ac-media-viewer__nav--next"
                        data-media-viewer-action="next"
                        aria-label="Следующее медиа"
                        title="Следующее медиа"
                    >›</button>
                </div>
            `;

            viewer.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const action = target?.closest('[data-media-viewer-action]')?.dataset.mediaViewerAction;

                if (! action) {
                    return;
                }

                event.preventDefault();

                if (action === 'close') {
                    closeViewer();
                }

                if (action === 'previous') {
                    showViewerItem(state.index - 1);
                }

                if (action === 'next') {
                    showViewerItem(state.index + 1);
                }

                if (action === 'copy') {
                    void copyCurrentViewerItem();
                }
            });

            document.body.appendChild(viewer);

            return viewer;
        };

        const collectItems = (trigger) => {
            const gallery = trigger.closest('[data-media-viewer-gallery]');
            const triggers = Array.from((gallery ?? trigger.parentElement)?.querySelectorAll(triggerSelector) ?? [trigger]);

            return triggers.map((node) => ({
                previewUrl: node.href,
                downloadUrl: node.dataset.mediaViewerDownloadUrl || '',
                type: node.dataset.mediaViewerType || 'image',
                title: node.dataset.mediaViewerTitle || node.querySelector('img')?.getAttribute('alt') || 'Медиа',
                alt: node.querySelector('img')?.getAttribute('alt') || node.dataset.mediaViewerTitle || 'Медиа',
                node,
            }));
        };

        const getViewerNodes = () => {
            const viewer = createViewer();

            return {
                viewer,
                figure: viewer.querySelector('.ac-media-viewer__figure'),
                image: viewer.querySelector('[data-role="media-viewer-image"]'),
                video: viewer.querySelector('[data-role="media-viewer-video"]'),
                audio: viewer.querySelector('[data-role="media-viewer-audio"]'),
                audioPanel: viewer.querySelector('[data-role="media-viewer-audio-panel"]'),
                pdf: viewer.querySelector('[data-role="media-viewer-pdf"]'),
                title: viewer.querySelector('[data-role="media-viewer-title"]'),
                counter: viewer.querySelector('[data-role="media-viewer-counter"]'),
                copy: viewer.querySelector('[data-role="media-viewer-copy"]'),
                copyPanel: viewer.querySelector('[data-role="media-viewer-copy-panel"]'),
                copyInput: viewer.querySelector('[data-role="media-viewer-copy-input"]'),
                download: viewer.querySelector('[data-role="media-viewer-download"]'),
                previous: viewer.querySelector('[data-media-viewer-action="previous"]'),
                next: viewer.querySelector('[data-media-viewer-action="next"]'),
                close: viewer.querySelector('[data-media-viewer-action="close"]'),
            };
        };

        const showViewerItem = (nextIndex) => {
            if (nextIndex < 0 || nextIndex >= state.items.length) {
                return;
            }

            state.index = nextIndex;

            const item = state.items[state.index];
            const nodes = getViewerNodes();

            nodes.figure.dataset.mediaViewerKind = item.type;

            if (item.type === 'pdf') {
                nodes.image.hidden = true;
                nodes.image.removeAttribute('src');
                nodes.video.pause();
                nodes.video.hidden = true;
                nodes.video.removeAttribute('src');
                nodes.video.load();
                nodes.audio.pause();
                nodes.audio.hidden = true;
                nodes.audioPanel.hidden = true;
                nodes.audio.removeAttribute('src');
                nodes.audio.load();
                nodes.pdf.hidden = false;
                nodes.pdf.src = item.previewUrl;
                nodes.pdf.title = item.title;
            } else if (item.type === 'video') {
                nodes.image.hidden = true;
                nodes.image.removeAttribute('src');
                nodes.pdf.hidden = true;
                nodes.pdf.removeAttribute('src');
                nodes.audio.pause();
                nodes.audio.hidden = true;
                nodes.audioPanel.hidden = true;
                nodes.audio.removeAttribute('src');
                nodes.audio.load();
                nodes.video.hidden = false;
                nodes.video.src = item.previewUrl;
                nodes.video.setAttribute('aria-label', item.title);
                nodes.video.load();
            } else if (item.type === 'audio') {
                nodes.image.hidden = true;
                nodes.image.removeAttribute('src');
                nodes.video.pause();
                nodes.video.hidden = true;
                nodes.video.removeAttribute('src');
                nodes.video.load();
                nodes.pdf.hidden = true;
                nodes.pdf.removeAttribute('src');
                nodes.audioPanel.hidden = false;
                nodes.audioPanel.appendChild(nodes.audio);
                nodes.audio.hidden = false;
                nodes.audio.src = item.previewUrl;
                nodes.audio.setAttribute('aria-label', item.title);
                nodes.audio.load();
            } else {
                nodes.video.pause();
                nodes.video.hidden = true;
                nodes.video.removeAttribute('src');
                nodes.video.load();
                nodes.audio.pause();
                nodes.audio.hidden = true;
                nodes.audioPanel.hidden = true;
                nodes.audio.removeAttribute('src');
                nodes.audio.load();
                nodes.pdf.hidden = true;
                nodes.pdf.removeAttribute('src');
                nodes.image.hidden = false;
                nodes.image.src = item.previewUrl;
                nodes.image.alt = item.alt;
            }

            nodes.title.textContent = item.title;

            nodes.counter.hidden = state.items.length < 2;
            nodes.counter.textContent = `${state.index + 1} / ${state.items.length}`;

            nodes.previous.hidden = state.items.length < 2;
            nodes.next.hidden = state.items.length < 2;
            nodes.previous.disabled = state.index === 0;
            nodes.next.disabled = state.index === state.items.length - 1;

            nodes.download.hidden = item.downloadUrl === '';
            nodes.download.href = item.downloadUrl || '#';

            nodes.copy.hidden = item.previewUrl === '';
            nodes.copy.disabled = false;
            nodes.copy.textContent = 'Копировать';
            nodes.copyPanel.hidden = true;
            nodes.copyInput.value = '';
        };

        const copyTextToClipboard = async (text) => {
            if (! text) {
                return false;
            }

            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';
            textarea.style.opacity = '0';

            document.body.appendChild(textarea);
            textarea.focus({ preventScroll: true });
            textarea.select();
            textarea.setSelectionRange(0, text.length);

            let copied = false;

            try {
                copied = typeof document.execCommand === 'function'
                    ? document.execCommand('copy')
                    : false;
            } catch (error) {
                copied = false;
            }

            textarea.remove();

            if (copied) {
                return true;
            }

            if (window.navigator?.clipboard?.writeText && window.isSecureContext) {
                try {
                    await window.navigator.clipboard.writeText(text);

                    return true;
                } catch (error) {
                    return false;
                }
            }

            return false;
        };

        const copyCurrentViewerItem = async () => {
            const item = state.items[state.index];
            const nodes = getViewerNodes();

            if (! item || item.previewUrl === '') {
                return;
            }

            nodes.copy.disabled = true;

            const copied = await copyTextToClipboard(item.previewUrl);

            if (copied) {
                nodes.copy.textContent = 'Скопировано';
                nodes.copyPanel.hidden = true;
            } else {
                nodes.copy.disabled = false;
                nodes.copy.textContent = 'Ссылка выделена';
                nodes.copyInput.value = item.previewUrl;
                nodes.copyPanel.hidden = false;
                nodes.copyInput.focus({ preventScroll: true });
                nodes.copyInput.select();
                nodes.copyInput.setSelectionRange(0, item.previewUrl.length);

                return;
            }

            window.setTimeout(() => {
                if (! nodes.viewer.hidden && state.items[state.index] === item) {
                    nodes.copy.disabled = false;
                    nodes.copy.textContent = 'Копировать';
                }
            }, 1400);
        };

        const openViewer = (trigger) => {
            const nodes = getViewerNodes();
            const items = collectItems(trigger);
            const index = Math.max(0, items.findIndex((item) => item.node === trigger));

            state.items = items;
            state.index = index;
            state.previousActiveElement = document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;

            showViewerItem(index);

            nodes.viewer.hidden = false;
            document.body.classList.add('ac-media-viewer-open');
            nodes.close?.focus({ preventScroll: true });
        };

        const closeViewer = () => {
            const nodes = getViewerNodes();

            if (nodes.viewer.hidden) {
                return;
            }

            nodes.viewer.hidden = true;
            nodes.image.removeAttribute('src');
            nodes.video.pause();
            nodes.video.removeAttribute('src');
            nodes.video.load();
            nodes.audio.pause();
            nodes.audio.removeAttribute('src');
            nodes.audio.load();
            nodes.audio.hidden = true;
            nodes.audioPanel.hidden = true;
            nodes.pdf.removeAttribute('src');
            nodes.copyPanel.hidden = true;
            nodes.copyInput.value = '';
            document.body.classList.remove('ac-media-viewer-open');

            state.previousActiveElement?.focus?.({ preventScroll: true });
            state.items = [];
            state.index = 0;
            state.previousActiveElement = null;
        };

        const focusableElements = (viewer) => Array.from(viewer.querySelectorAll('a[href]:not([hidden]), button:not([disabled]):not([hidden]), [tabindex]:not([tabindex="-1"]):not([hidden])'))
            .filter((node) => node instanceof HTMLElement && node.offsetParent !== null);

        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const trigger = target?.closest(triggerSelector);

            if (! trigger || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            openViewer(trigger);
        });

        document.addEventListener('keydown', (event) => {
            const viewer = document.querySelector('[data-role="media-viewer"]');

            if (! viewer || viewer.hidden) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                closeViewer();
                return;
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                showViewerItem(state.index - 1);
                return;
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                showViewerItem(state.index + 1);
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusable = focusableElements(viewer);

            if (focusable.length === 0) {
                event.preventDefault();
                viewer.focus({ preventScroll: true });
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus({ preventScroll: true });
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus({ preventScroll: true });
            }
        });
    })();

</script>
