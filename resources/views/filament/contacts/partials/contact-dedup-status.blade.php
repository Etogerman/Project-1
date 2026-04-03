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
                        <p class="ac-list-card__body">
                            Телефон: <strong>{{ $review['phoneLabel'] }}</strong>
                        </p>
                        <p class="ac-list-card__body">
                            Кандидаты: {{ $review['candidateRootsLabel'] }}
                        </p>
                        <p class="ac-note">
                            Создано: {{ $review['createdAtLabel'] }}
                        </p>
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
