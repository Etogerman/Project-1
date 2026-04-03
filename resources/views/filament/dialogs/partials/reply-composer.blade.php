<section
    data-role="conversation-reply-form"
    class="ac-surface ac-surface--emphasis"
>
    @php
        $replyTextModel = $replyTextModel ?? 'dialogReplyText';
        $replyErrorModel = $replyErrorModel ?? $replyTextModel;
        $submitMethod = $submitMethod ?? 'sendDialogReply';
    @endphp

    <div class="ac-surface__title-group">
        <h3 class="ac-surface__title">Ответ</h3>
        @if (! $autoReplyEnabled)
            <p class="ac-note ac-note--warning">
                Автоответы для этого контакта отключены. Это не влияет на ручной ответ.
            </p>
        @endif
        @if (filled($blockedReason))
            <p class="ac-note ac-note--danger">
                {{ $blockedReason }}
            </p>
        @elseif ($canClaim)
            <p class="ac-note ac-note--warning">
                Ответственный пока не выбран. Его можно выбрать выше, либо просто отправить сообщение — контакт закрепится за вами автоматически.
            </p>
        @endif
    </div>

    <div class="ac-surface__divider">
        <textarea
            data-role="conversation-reply-textarea"
            wire:model.defer="{{ $replyTextModel }}"
            rows="3"
            maxlength="2000"
            placeholder="Введите текст ответа"
            @disabled(! $canReply)
            class="ac-textarea"
        ></textarea>

        @error($replyErrorModel)
            <p class="ac-field-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="ac-actions">
        <button
            data-role="conversation-reply-submit"
            type="button"
            wire:click="{{ $submitMethod }}"
            @disabled(! $canReply)
            wire:loading.attr="disabled"
            wire:target="{{ $submitMethod }}"
            class="ac-button ac-button--success"
        >
            <span wire:loading.remove wire:target="{{ $submitMethod }}">Отправить</span>
            <span wire:loading wire:target="{{ $submitMethod }}">Отправка...</span>
        </button>
    </div>
</section>
