<section
    data-role="conversation-reply-form"
    style="margin-top: 1rem; border: 1px solid #d1d5db; border-radius: 18px; background: #fff7cc; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); padding: 1.25rem;"
>
    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 0.875rem; flex-wrap: wrap;">
        <div style="min-width: 18rem; flex: 1 1 24rem;">
            <h3 style="margin: 0 0 0.375rem; font-size: 1rem; font-weight: 700; color: #111827;">Ответ</h3>
            <p style="margin: 0 0 0.375rem; font-size: 0.875rem; color: #374151;">
                Сообщение будет отправлено через последний активный канал контакта.
            </p>
            <p style="margin: 0; font-size: 0.8125rem; color: #4b5563;">
                <strong>Статус контакта:</strong> {{ $ownershipStatusLabel }}.
                <strong>Ответственный:</strong> {{ $assignedUserLabel }}.
            </p>
            @if ($canClaim)
                <p style="margin: 0.375rem 0 0; font-size: 0.8125rem; color: #92400e;">
                    Контакт сейчас свободен. Нажмите «Взять в работу» или просто отправьте сообщение — контакт закрепится за вами автоматически.
                </p>
            @elseif ($canRelease)
                <p style="margin: 0.375rem 0 0; font-size: 0.8125rem; color: #166534;">
                    Контакт уже закреплён за вами. Ручной ответ доступен.
                </p>
            @elseif (filled($blockedReason))
                <p style="margin: 0.375rem 0 0; font-size: 0.8125rem; color: #991b1b;">
                    {{ $blockedReason }}
                </p>
            @endif
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            @if ($canClaim)
                <button
                    data-role="contact-claim-button-inline"
                    type="button"
                    wire:click="claimMountedContact"
                    wire:loading.attr="disabled"
                    wire:target="claimMountedContact"
                    style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #1d4ed8; border-radius: 10px; background: #2563eb; color: #ffffff; font-size: 0.875rem; font-weight: 600; padding: 0.7rem 1rem; cursor: pointer;"
                >
                    <span wire:loading.remove wire:target="claimMountedContact">Взять в работу</span>
                    <span wire:loading wire:target="claimMountedContact">Берём...</span>
                </button>
            @endif

            @if ($canRelease)
                <button
                    data-role="contact-release-button-inline"
                    type="button"
                    wire:click="releaseMountedContact"
                    wire:loading.attr="disabled"
                    wire:target="releaseMountedContact"
                    style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #9ca3af; border-radius: 10px; background: #ffffff; color: #374151; font-size: 0.875rem; font-weight: 600; padding: 0.7rem 1rem; cursor: pointer;"
                >
                    <span wire:loading.remove wire:target="releaseMountedContact">Снять с себя</span>
                    <span wire:loading wire:target="releaseMountedContact">Снимаем...</span>
                </button>
            @endif
        </div>
    </div>

    <div>
        <textarea
            data-role="conversation-reply-textarea"
            wire:model.defer="inlineReplyText"
            rows="10"
            maxlength="2000"
            placeholder="Введите текст ответа"
            @disabled(! $canReply)
            style="display: block; box-sizing: border-box; width: 100%; min-width: 100%; min-height: 14rem; resize: vertical; border: 1px solid #9ca3af; border-radius: 14px; background: #fffbe6; color: #111827; padding: 1rem 1rem; font-size: 1rem; line-height: 1.55; box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.06); opacity: {{ $canReply ? '1' : '0.75' }};"
        ></textarea>

        @error('inlineReplyText')
            <p style="margin: 0.5rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
        @enderror
    </div>

    <div style="display: flex; justify-content: flex-end; margin-top: 0.875rem;">
        <button
            data-role="conversation-reply-submit"
            type="button"
            wire:click="sendInlineReply"
            @disabled(! $canReply)
            wire:loading.attr="disabled"
            wire:target="sendInlineReply"
            style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #15803d; border-radius: 12px; background: #16a34a; color: #ffffff; font-size: 0.9375rem; font-weight: 700; padding: 0.8rem 1.4rem; box-shadow: 0 8px 18px rgba(22, 163, 74, 0.22); cursor: {{ $canReply ? 'pointer' : 'not-allowed' }}; opacity: {{ $canReply ? '1' : '0.6' }};"
        >
            <span wire:loading.remove wire:target="sendInlineReply">Отправить</span>
            <span wire:loading wire:target="sendInlineReply">Отправка...</span>
        </button>
    </div>
</section>
