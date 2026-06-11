<section
    data-role="conversation-reply-form"
    x-data="{
        storageKey: 'conversation-reply-textarea-height',
        restoreTextareaHeight() {
            const textarea = this.$root.querySelector('[data-role=conversation-reply-textarea]');
            const height = window.sessionStorage.getItem(this.storageKey);

            if (! textarea || ! height) {
                return;
            }

            textarea.style.height = height;
            textarea.dataset.manualResized = '1';
            textarea.style.overflowY = 'auto';
        },
        rememberTextareaHeight(textarea) {
            window.sessionStorage.setItem(this.storageKey, textarea.style.height || `${textarea.offsetHeight}px`);
        },
    }"
    x-init="$nextTick(() => restoreTextareaHeight())"
    @class([
        'ac-surface',
        'ac-surface--emphasis',
        'ac-composer',
        $composerClass ?? '',
    ])
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
            <h3 class="ac-surface__title">Написать клиенту</h3>
        </div>

        <div class="ac-button-group ac-composer__format-toggle">
            @foreach ($replyFormatOptions as $replyFormatValue => $replyFormatLabel)
                <button
                    type="button"
                    wire:click="$set('{{ $replyFormatModel }}', '{{ $replyFormatValue }}')"
                    @class([
                        'ac-button',
                        'ac-button--warning-soft' => $this->{$replyFormatModel} === $replyFormatValue,
                        'ac-button--secondary' => $this->{$replyFormatModel} !== $replyFormatValue,
                    ])
                    @disabled(! $canReply)
                >
                    {{ $replyFormatLabel }}
                </button>
            @endforeach
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

        <textarea
            id="conversation-reply-textarea"
            data-role="conversation-reply-textarea"
            wire:model.defer="{{ $replyTextModel }}"
            onkeydown="if (event.key === 'Enter' && !event.shiftKey && !event.altKey && !event.ctrlKey && !event.metaKey && !event.isComposing) { event.preventDefault(); this.closest('[data-role=conversation-reply-form]').querySelector('[data-role=conversation-reply-submit]').click(); }"
            onpointerdown="const rect = this.getBoundingClientRect(); this.dataset.manualResizePending = event.clientX >= rect.right - 20 && event.clientY >= rect.bottom - 20 ? '1' : '';"
            onpointerup="if (this.dataset.manualResizePending === '1') { this.dataset.manualResized = '1'; this.style.overflowY = 'auto'; this.dispatchEvent(new CustomEvent('reply-textarea-manual-resized', { bubbles: true })); } this.dataset.manualResizePending = '';"
            oninput="const minHeight = 40; const maxAutoHeight = 180; if (this.dataset.manualResized === '1') { this.style.overflowY = 'auto'; return; } this.style.height = Math.max(this.offsetHeight, minHeight) + 'px'; if (this.scrollHeight > this.clientHeight) { this.style.height = Math.min(this.scrollHeight, maxAutoHeight) + 'px'; } this.style.overflowY = this.scrollHeight > this.clientHeight ? 'auto' : 'hidden';"
            x-on:reply-textarea-manual-resized="rememberTextareaHeight($event.target)"
            aria-label="Текст ответа"
            rows="1"
            maxlength="2000"
            placeholder="Введите текст ответа клиенту"
            @disabled(! $canReply)
            wire:loading.attr="disabled"
            wire:target="{{ $submitMethod }}"
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
