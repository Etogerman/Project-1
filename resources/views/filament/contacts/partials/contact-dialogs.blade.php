<section
    data-role="contact-dialogs"
    class="ac-surface ac-surface--secondary ac-contact-modal-surface ac-contact-modal-surface--dialogs"
>
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Диалоги</p>
            <h3 class="ac-surface__title">Каналы общения с контактом</h3>
            <p class="ac-surface__subtitle">
                Здесь собраны все рабочие диалоги по каналам. Откройте нужный, чтобы продолжить переписку.
            </p>
        </div>

        <p class="ac-note">
            Нажмите на карточку диалога, чтобы перейти в рабочее место оператора.
        </p>
    </div>

    @if ($dialogs === [])
        <div data-role="contact-dialogs-empty" class="ac-empty-state ac-surface__divider">
            Диалоги ещё не появились.
        </div>
    @else
        <div class="ac-list-stack ac-surface__divider">
            @foreach ($dialogs as $dialog)
                <a
                    href="{{ $dialog['url'] }}"
                    data-role="dialog-card-link"
                    data-dialog-id="{{ $dialog['id'] }}"
                    class="ac-link-reset ac-list-card ac-list-card--interactive"
                >
                    <div data-role="contact-dialog" class="ac-panel-stack">
                        <div class="ac-contact-modal-dialogs__heading">
                            <div class="ac-surface__title-group ac-contact-modal-dialogs__primary">
                                <p
                                    data-role="dialog-channel"
                                    class="ac-list-card__title"
                                >
                                    {{ $dialog['channel_label'] }}
                                </p>
                                <p
                                    data-role="dialog-route-identity"
                                    class="ac-surface__subtitle ac-contact-modal-dialogs__route"
                                >
                                    Источник маршрута: {{ $dialog['route_identity_label'] }}
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

                        <div class="ac-note-box ac-contact-modal-dialogs__meta-panel">
                            <div class="ac-meta-grid ac-meta-grid--compact ac-contact-modal-dialogs__meta">
                                <div class="ac-meta">
                                    <p class="ac-meta__label">
                                        Имя из мессенджера
                                    </p>
                                    <p data-role="dialog-messenger-name" class="ac-meta__value">
                                        {{ $dialog['messenger_name_label'] }}
                                    </p>
                                </div>
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
                                        ID чата
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

                        <div class="ac-inline-split ac-list-card__section ac-contact-modal-dialogs__cta">
                            <p class="ac-note">Открыть рабочее место диалога</p>
                            <span class="ac-pill" data-tone="primary">Открыть</span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</section>
