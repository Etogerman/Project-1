<section data-role="conversation-reply-form" class="rounded-2xl border border-gray-200/80 bg-white/90 p-4 shadow-sm dark:border-white/10 dark:bg-slate-900/80">
    <div class="mb-3 flex items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Ответ</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Сообщение будет отправлено через последний активный канал контакта.
            </p>
        </div>
    </div>

    <form wire:submit.prevent="sendInlineReply" class="space-y-3">
        <div>
            <textarea
                data-role="conversation-reply-textarea"
                wire:model.defer="inlineReplyText"
                rows="5"
                maxlength="2000"
                placeholder="Введите текст ответа"
                class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-white/10 dark:bg-slate-950 dark:text-white"
            ></textarea>

            @error('inlineReplyText')
                <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex justify-end">
            <button
                data-role="conversation-reply-submit"
                type="submit"
                wire:loading.attr="disabled"
                wire:target="sendInlineReply"
                class="inline-flex items-center rounded-lg bg-success-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-success-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="sendInlineReply">Отправить</span>
                <span wire:loading wire:target="sendInlineReply">Отправка...</span>
            </button>
        </div>
    </form>
</section>
