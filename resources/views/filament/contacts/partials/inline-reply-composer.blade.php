<section data-role="conversation-reply-form" class="rounded-3xl border-2 border-primary-200/80 bg-gradient-to-br from-primary-50/70 via-white to-emerald-50/40 p-5 shadow-md ring-1 ring-primary-100/80 dark:border-primary-500/20 dark:from-primary-500/10 dark:via-slate-900/90 dark:to-emerald-500/10 dark:ring-primary-500/10">
    <div class="mb-3 flex items-center justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Ответ</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Сообщение будет отправлено через последний активный канал контакта.
                @if ($canReply && ! filled($blockedReason))
                    Если контакт ещё свободен, он автоматически перейдёт к вам после отправки.
                @endif
            </p>
        </div>
    </div>

    <div class="space-y-3">
        <div>
            @if (filled($blockedReason))
                <p class="mb-2 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-700 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
                    {{ $blockedReason }}
                </p>
            @endif

            <textarea
                data-role="conversation-reply-textarea"
                wire:model.defer="inlineReplyText"
                rows="7"
                maxlength="2000"
                placeholder="Введите текст ответа"
                @disabled(! $canReply)
                style="width: 100%; min-width: 100%;"
                class="block min-h-[12rem] w-full min-w-full rounded-2xl border-2 border-primary-300 bg-white px-4 py-3 text-base leading-6 text-gray-950 shadow-inner outline-none transition focus:border-primary-500 focus:ring-4 focus:ring-primary-500/20 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-100/80 dark:border-primary-500/30 dark:bg-slate-950 dark:text-white dark:disabled:border-white/10 dark:disabled:bg-slate-900/70"
            ></textarea>

            @error('inlineReplyText')
                <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex justify-end">
            <button
                data-role="conversation-reply-submit"
                type="button"
                wire:click="sendInlineReply"
                @disabled(! $canReply)
                wire:loading.attr="disabled"
                wire:target="sendInlineReply"
                class="inline-flex items-center rounded-xl bg-success-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-success-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="sendInlineReply">Отправить</span>
                <span wire:loading wire:target="sendInlineReply">Отправка...</span>
            </button>
        </div>
    </div>
</section>
