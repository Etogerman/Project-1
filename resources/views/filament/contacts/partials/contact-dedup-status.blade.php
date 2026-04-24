<section
    data-role="contact-dedup-status"
    class="ac-surface"
>
    <div class="ac-inline-split">
        <div class="ac-meta">
            <p class="ac-meta__label">
                Статус
            </p>
            <span class="ac-pill" data-tone="{{ $dedupStatusTone }}">
                {{ $dedupStatusLabel }}
            </span>
        </div>

        @if ($isMerged)
            <div class="ac-meta ac-right-aligned">
                <p class="ac-meta__label">
                    Основной контакт
                </p>
                <p class="ac-meta__value ac-meta__value--emphasis">
                    {{ $rootContactLabel }}
                </p>
            </div>
        @elseif ($mergedChildrenCount > 0)
            <div class="ac-meta ac-right-aligned">
                <p class="ac-meta__label">
                    Склеено дублей
                </p>
                <p class="ac-meta__value ac-meta__value--emphasis">
                    {{ $mergedChildrenCount }}
                </p>
            </div>
        @endif
    </div>

    @if ($isMerged)
        <div class="ac-meta-grid ac-surface__divider">
            <div class="ac-meta">
                <p class="ac-meta__label">Склеен</p>
                <p class="ac-meta__value ac-meta__value--emphasis">{{ $mergedAtLabel }}</p>
            </div>
            <div class="ac-meta">
                <p class="ac-meta__label">Причина</p>
                <p class="ac-meta__value ac-meta__value--emphasis">{{ $mergeReasonLabel }}</p>
            </div>
            <div class="ac-meta">
                <p class="ac-meta__label">Триггерный телефон</p>
                <p class="ac-meta__value ac-meta__value--emphasis">{{ $mergeTriggerPhone }}</p>
            </div>
        </div>
    @endif

    @if ($openReviewsCount > 0)
        <div class="ac-surface__divider">
            <p class="ac-list-card__title ac-title-with-gap">
                Открытые проверки: {{ $openReviewsCount }}
            </p>

            <div class="ac-list-stack">
                @foreach ($openReviews as $review)
                    <article class="ac-list-card ac-list-card--warning">
                        <p class="ac-list-card__title">
                            {{ $review['typeLabel'] }}
                        </p>
                        @if (filled($review['phoneLabel']))
                            <p class="ac-list-card__body">
                                Телефон: <strong>{{ $review['phoneLabel'] }}</strong>
                            </p>
                        @endif
                        @if (filled($review['identityLabel']))
                            <p class="ac-list-card__body">
                                Identity: <strong>{{ $review['identityLabel'] }}</strong>
                            </p>
                        @endif
                        <p class="ac-list-card__body">
                            Кандидаты: {{ $review['candidateRootsLabel'] }}
                        </p>
                        @if (filled($review['channelContextLabel']))
                            <p class="ac-list-card__body">
                                Канал: {{ $review['channelContextLabel'] }}
                            </p>
                        @endif
                        <p class="ac-note">
                            Создано: {{ $review['createdAtLabel'] }}
                        </p>

                        @if (($review['isCrossChannelIdentityReview'] ?? false) && ($review['canManageLifecycle'] ?? false))
                            <div class="ac-actions ac-surface__divider">
                                <button
                                    data-role="contact-open-cross-channel-review-resolve-dialog"
                                    type="button"
                                    wire:click="openResolveCrossChannelIdentityReviewDialog({{ $review['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="openResolveCrossChannelIdentityReviewDialog,saveResolvedCrossChannelIdentityReview"
                                    class="ac-button ac-button--primary"
                                >
                                    Разобрать
                                </button>
                                <button
                                    data-role="contact-dismiss-cross-channel-review"
                                    type="button"
                                    wire:click="dismissMountedCrossChannelIdentityReview({{ $review['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="dismissMountedCrossChannelIdentityReview"
                                    class="ac-button ac-button--danger-soft"
                                >
                                    Оставить отдельным root
                                </button>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    @endif

    @if ((! $isMerged) && ($mergedChildrenCount > 0))
        <div class="ac-surface__divider">
            <p class="ac-list-card__title ac-title-with-gap">
                Последние склейки
            </p>

            <div class="ac-list-stack">
                @foreach ($mergedChildren as $mergedChild)
                    <article class="ac-list-card">
                        <p class="ac-list-card__title">
                            Дубль #{{ $mergedChild['id'] }}
                        </p>
                        <p class="ac-list-card__body">
                            Склеен: {{ $mergedChild['mergedAtLabel'] }}
                        </p>
                        <p class="ac-list-card__body">
                            Причина: {{ $mergedChild['reasonLabel'] }}
                        </p>
                        <p class="ac-list-card__body">
                            Триггерный телефон: {{ $mergedChild['triggerPhone'] }}
                        </p>
                    </article>
                @endforeach
            </div>
        </div>
    @endif
</section>

@if ($this->showResolveCrossChannelIdentityReviewDialog)
    <div data-role="cross-channel-review-resolve-dialog-backdrop" class="ac-modal-backdrop">
        <div data-role="cross-channel-review-resolve-dialog" class="ac-modal ac-modal--md">
            <div class="ac-modal__body">
                <div class="ac-modal__header">
                    <div>
                        <h3 class="ac-modal__title">Разобрать identity ambiguity</h3>
                        <p class="ac-modal__description">
                            Выберите root-контакт, в который должен маршрутизироваться этот platform user ID.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeResolveCrossChannelIdentityReviewDialog"
                        class="ac-modal__close"
                    >
                        Закрыть
                    </button>
                </div>

                <div class="ac-note-box ac-note-box--info ac-copy--spaced">
                    <p class="ac-copy"><strong>Identity:</strong> {{ $this->resolvingCrossChannelIdentityIdentityKey }}</p>
                    <p class="ac-copy"><strong>Anchor:</strong> {{ $this->resolvingCrossChannelIdentityAnchorLabel }}</p>
                </div>

                <label for="cross-channel-review-routed-contact-select" class="ac-field-label">
                    Канонический root-контакт
                </label>
                <select
                    id="cross-channel-review-routed-contact-select"
                    wire:model="selectedResolvedRoutedContactId"
                    class="ac-select"
                >
                    <option value="">Выберите контакт</option>
                    @foreach ($this->resolvingCrossChannelIdentityContactOptions as $contactId => $contactLabel)
                        <option value="{{ $contactId }}">{{ $contactLabel }}</option>
                    @endforeach
                </select>

                @error('selectedResolvedRoutedContactId')
                    <p class="ac-field-error">{{ $message }}</p>
                @enderror

                <div class="ac-actions">
                    <button
                        type="button"
                        wire:click="closeResolveCrossChannelIdentityReviewDialog"
                        class="ac-button ac-button--secondary"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-save-cross-channel-review-resolution"
                        type="button"
                        wire:click="saveResolvedCrossChannelIdentityReview"
                        wire:loading.attr="disabled"
                        wire:target="saveResolvedCrossChannelIdentityReview"
                        class="ac-button ac-button--success"
                    >
                        <span wire:loading.remove wire:target="saveResolvedCrossChannelIdentityReview">Сохранить решение</span>
                        <span wire:loading wire:target="saveResolvedCrossChannelIdentityReview">Сохраняем...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
