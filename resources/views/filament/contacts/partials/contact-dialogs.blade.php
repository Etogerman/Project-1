<section
    data-role="contact-dialogs"
    class="ac-surface"
>
    @if ($dialogs === [])
        <div data-role="contact-dialogs-empty" class="ac-empty-state">
            Диалоги ещё не появились.
        </div>
    @else
        <div class="ac-list-stack">
            @foreach ($dialogs as $dialog)
                <a
                    href="{{ $dialog['url'] }}"
                    data-role="dialog-card-link"
                    data-dialog-id="{{ $dialog['id'] }}"
                    class="ac-link-reset ac-list-card"
                >
                    <div data-role="contact-dialog" class="ac-panel-stack">
                        <div class="ac-surface__header">
                            <div class="ac-surface__title-group">
                                <p
                                    data-role="dialog-channel"
                                    class="ac-list-card__title"
                                >
                                    {{ $dialog['channel_label'] }}
                                </p>
                                <p
                                    data-role="dialog-route-identity"
                                    class="ac-surface__subtitle"
                                >
                                    Route source: {{ $dialog['route_identity_label'] }}
                                </p>
                            </div>

                            <span
                                data-role="dialog-route-status"
                                data-tone="{{ $dialog['route_status_tone'] }}"
                                class="ac-pill"
                            >
                                {{ $dialog['route_status_label'] }}
                            </span>
                        </div>

                        <div data-role="dialog-preview" class="ac-preview-card">
                            <div class="ac-surface__header">
                                @if (filled($dialog['preview_sender_label']))
                                    <span
                                        data-role="dialog-preview-sender"
                                        class="ac-pill"
                                        data-tone="{{ $dialog['preview_sender_tone'] }}"
                                    >
                                        {{ $dialog['preview_sender_label'] }}
                                    </span>
                                @endif

                                <span class="ac-note">
                                    {{ $dialog['last_message_label'] }}
                                </span>
                            </div>

                            <p class="ac-preview-card__body">
                                {{ $dialog['preview_text'] }}
                            </p>
                        </div>

                        <div class="ac-meta-grid">
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Телефон канала
                                </p>
                                <p data-role="dialog-phone" class="ac-meta__value">
                                    {{ $dialog['phone_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Chat ID
                                </p>
                                <p data-role="dialog-chat-id" class="ac-meta__value">
                                    {{ $dialog['external_chat_id_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Последнее входящее
                                </p>
                                <p class="ac-meta__value">
                                    {{ $dialog['last_inbound_label'] }}
                                </p>
                            </div>
                            <div class="ac-meta">
                                <p class="ac-meta__label">
                                    Последнее исходящее
                                </p>
                                <p class="ac-meta__value">
                                    {{ $dialog['last_outbound_label'] }}
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</section>
