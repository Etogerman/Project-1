<section
    data-role="conversation-reply-form"
    class="ac-surface ac-surface--emphasis ac-composer"
>
    @php
        $replyTextModel = $replyTextModel ?? 'dialogReplyText';
        $replyFormatModel = $replyFormatModel ?? 'dialogReplyFormat';
        $replyFormatOptions = $replyFormatOptions ?? \App\Models\Message::textFormatOptions();
        $replyErrorModel = $replyErrorModel ?? $replyTextModel;
        $submitMethod = $submitMethod ?? 'sendDialogReply';
    @endphp

    <div class="ac-inline-split">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Ручной ответ</p>
            <h3 class="ac-surface__title">Написать клиенту</h3>
            <p class="ac-surface__subtitle">
                Сообщение уйдёт в этот диалог и сразу сохранится в истории переписки.
            </p>
        </div>

        <p class="ac-note">
            До 2000 символов
        </p>
    </div>

    <div class="ac-note-stack ac-surface__divider">
        @if (! $autoReplyEnabled)
            <p class="ac-note ac-note--warning">
                Автоответы для этого контакта отключены. Это не влияет на ручной ответ.
            </p>
        @endif
        @if (filled($blockedReason))
            <p class="ac-note ac-note--danger">
                {{ $blockedReason }}
            </p>
        @endif

        <label for="conversation-reply-textarea" class="ac-field-label">
            Сообщение
        </label>
        <div class="ac-button-group">
            @foreach ($replyFormatOptions as $replyFormatValue => $replyFormatLabel)
                <button
                    type="button"
                    wire:click="$set('{{ $replyFormatModel }}', '{{ $replyFormatValue }}')"
                    @class([
                        'ac-button',
                        'ac-button--primary' => $this->{$replyFormatModel} === $replyFormatValue,
                        'ac-button--secondary' => $this->{$replyFormatModel} !== $replyFormatValue,
                    ])
                    @disabled(! $canReply)
                >
                    {{ $replyFormatLabel }}
                </button>
            @endforeach
        </div>
        <textarea
            id="conversation-reply-textarea"
            data-role="conversation-reply-textarea"
            wire:model.defer="{{ $replyTextModel }}"
            rows="4"
            maxlength="2000"
            placeholder="Введите текст ответа клиенту"
            @disabled(! $canReply)
            class="ac-textarea ac-textarea--composer"
        ></textarea>

        @if ($this->{$replyFormatModel} === \App\Models\Message::TEXT_FORMAT_HTML)
            <p class="ac-note">
                Разрешены только теги: <code>&lt;b&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;i&gt;</code>, <code>&lt;em&gt;</code>,
                <code>&lt;u&gt;</code>, <code>&lt;ins&gt;</code>, <code>&lt;s&gt;</code>, <code>&lt;del&gt;</code>, <code>&lt;code&gt;</code>,
                <code>&lt;pre&gt;</code>, <code>&lt;a href="https://..."&gt;</code>.
            </p>
        @endif

        @error($replyErrorModel)
            <p class="ac-field-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="ac-actions ac-actions--between">
        <p class="ac-note ac-actions__hint">
            Ответ будет отправлен от имени выбранного канала и попадёт в историю этого диалога.
        </p>

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
