<section class="ac-surface ac-surface--secondary">
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">История</p>
            <h3 class="ac-surface__title">История событий контакта</h3>
            <p class="ac-surface__subtitle">
                Хронология ключевых событий по контакту без сообщений и технических логов.
            </p>
        </div>
    </div>

    @if (($commentForm['canAddComment'] ?? false) === true)
        <div class="ac-note-stack ac-surface__divider">
            <label for="contact-history-comment-textarea" class="ac-field-label">
                Комментарий оператора
            </label>

            <textarea
                id="contact-history-comment-textarea"
                data-role="contact-history-comment-textarea"
                wire:model.defer="historyCommentBody"
                rows="4"
                maxlength="2000"
                placeholder="Добавьте комментарий для внутренней истории контакта"
                class="ac-textarea"
            ></textarea>

            @error('historyCommentBody')
                <p class="ac-field-error">{{ $message }}</p>
            @enderror

            <div class="ac-actions ac-actions--between">
                <p class="ac-note ac-actions__hint">
                    Комментарий сохраняется только во внутренней истории контакта.
                </p>

                <button
                    data-role="contact-history-comment-submit"
                    type="button"
                    wire:click="addHistoryComment"
                    wire:loading.attr="disabled"
                    wire:target="addHistoryComment"
                    class="ac-button ac-button--warning"
                >
                    <span wire:loading.remove wire:target="addHistoryComment">Добавить комментарий</span>
                    <span wire:loading wire:target="addHistoryComment">Сохраняем...</span>
                </button>
            </div>
        </div>
    @endif

    @if (($items ?? []) === [])
        <div class="ac-empty-state ac-surface__divider">
            По этому контакту пока нет событий для вкладки «История».
        </div>
    @else
        <div class="ac-history-timeline ac-surface__divider">
            @foreach ($items as $item)
                <article class="ac-history-timeline__item" data-role="contact-history-item-{{ $loop->index }}">
                    <div class="ac-history-timeline__rail" aria-hidden="true">
                        <span class="ac-history-timeline__dot"></span>
                    </div>

                    <div class="ac-history-timeline__card">
                        <p class="ac-history-timeline__timestamp">{{ $item['timestampLabel'] }}</p>
                        <h4 class="ac-history-timeline__title">{{ $item['title'] }}</h4>

                        @if (filled($item['actorName'] ?? null))
                            <p class="ac-history-timeline__meta">{{ $item['actorName'] }}</p>
                        @endif

                        @if (filled($item['body'] ?? null))
                            <p class="ac-history-timeline__comment-body">{{ $item['body'] }}</p>
                        @elseif (filled($item['description'] ?? null))
                            <p class="ac-history-timeline__description">{{ $item['description'] }}</p>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        @if ($hasMore ?? false)
            <div class="ac-actions">
                <button
                    type="button"
                    wire:click="loadMoreHistory"
                    wire:loading.attr="disabled"
                    wire:target="loadMoreHistory"
                    class="ac-button ac-button--secondary"
                >
                    <span wire:loading.remove wire:target="loadMoreHistory">Показать ещё</span>
                    <span wire:loading wire:target="loadMoreHistory">Загружаем...</span>
                </button>
            </div>
        @endif
    @endif
</section>
