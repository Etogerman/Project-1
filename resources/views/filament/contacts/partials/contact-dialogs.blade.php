<section
    data-role="contact-dialogs"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    @if ($dialogs === [])
        <div
            data-role="contact-dialogs-empty"
            style="border: 1px dashed #d1d5db; border-radius: 14px; background: #ffffff; padding: 1.2rem 1rem; text-align: center; font-size: 0.9rem; color: #6b7280;"
        >
            Диалоги ещё не появились.
        </div>
    @else
        <div style="display: grid; gap: 0.75rem;">
            @foreach ($dialogs as $dialog)
                @php
                    $routeTone = match ($dialog['route_status_tone']) {
                        'success' => ['background' => '#dcfce7', 'color' => '#166534'],
                        'warning' => ['background' => '#fef3c7', 'color' => '#92400e'],
                        default => ['background' => '#f3f4f6', 'color' => '#4b5563'],
                    };
                @endphp

                <article
                    data-role="contact-dialog"
                    data-dialog-id="{{ $dialog['id'] }}"
                    style="border: 1px solid #e5e7eb; border-radius: 14px; background: #ffffff; padding: 0.9rem 1rem;"
                >
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                        <div style="display: grid; gap: 0.3rem;">
                            <p
                                data-role="dialog-channel"
                                style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #111827;"
                            >
                                {{ $dialog['channel_label'] }}
                            </p>
                            <p
                                data-role="dialog-route-identity"
                                style="margin: 0; font-size: 0.82rem; color: #6b7280;"
                            >
                                Route source: {{ $dialog['route_identity_label'] }}
                            </p>
                        </div>

                        <span
                            data-role="dialog-route-status"
                            data-tone="{{ $dialog['route_status_tone'] }}"
                            style="display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.75rem; font-weight: 700; background: {{ $routeTone['background'] }}; color: {{ $routeTone['color'] }};"
                        >
                            {{ $dialog['route_status_label'] }}
                        </span>
                    </div>

                    <div style="display: grid; gap: 0.85rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); margin-top: 0.85rem;">
                        <div>
                            <p style="margin: 0 0 0.25rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                                Телефон канала
                            </p>
                            <p data-role="dialog-phone" style="margin: 0; font-size: 0.92rem; color: #111827;">
                                {{ $dialog['phone_label'] }}
                            </p>
                        </div>
                        <div>
                            <p style="margin: 0 0 0.25rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                                Chat ID
                            </p>
                            <p data-role="dialog-chat-id" style="margin: 0; font-size: 0.92rem; color: #111827;">
                                {{ $dialog['external_chat_id_label'] }}
                            </p>
                        </div>
                        <div>
                            <p style="margin: 0 0 0.25rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                                Последнее сообщение
                            </p>
                            <p style="margin: 0; font-size: 0.92rem; color: #111827;">
                                {{ $dialog['last_message_label'] }}
                            </p>
                        </div>
                        <div>
                            <p style="margin: 0 0 0.25rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                                Последнее входящее
                            </p>
                            <p style="margin: 0; font-size: 0.92rem; color: #111827;">
                                {{ $dialog['last_inbound_label'] }}
                            </p>
                        </div>
                        <div>
                            <p style="margin: 0 0 0.25rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                                Последнее исходящее
                            </p>
                            <p style="margin: 0; font-size: 0.92rem; color: #111827;">
                                {{ $dialog['last_outbound_label'] }}
                            </p>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
