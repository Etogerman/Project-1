<section
    data-role="contact-dedup-status"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
        <div>
            <p style="margin: 0 0 0.35rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                Статус
            </p>
            <p style="margin: 0; font-size: 1rem; font-weight: 700; color: {{ $dedupStatusTone === 'warning' ? '#b45309' : ($dedupStatusTone === 'info' ? '#1d4ed8' : '#374151') }};">
                {{ $dedupStatusLabel }}
            </p>
        </div>

        @if ($isMerged)
            <div style="min-width: 18rem; flex: 1 1 22rem; text-align: right;">
                <p style="margin: 0 0 0.35rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                    Основной контакт
                </p>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #111827;">
                    {{ $rootContactLabel }}
                </p>
            </div>
        @elseif ($mergedChildrenCount > 0)
            <div style="min-width: 18rem; flex: 1 1 22rem; text-align: right;">
                <p style="margin: 0 0 0.35rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                    Склеено дублей
                </p>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #111827;">
                    {{ $mergedChildrenCount }}
                </p>
            </div>
        @endif
    </div>

    @if ($isMerged)
        <div style="margin-top: 1rem; display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));">
            <div>
                <p style="margin: 0 0 0.25rem; font-size: 0.8125rem; color: #6b7280;">Склеен</p>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600; color: #111827;">{{ $mergedAtLabel }}</p>
            </div>
            <div>
                <p style="margin: 0 0 0.25rem; font-size: 0.8125rem; color: #6b7280;">Причина</p>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600; color: #111827;">{{ $mergeReasonLabel }}</p>
            </div>
            <div>
                <p style="margin: 0 0 0.25rem; font-size: 0.8125rem; color: #6b7280;">Триггерный телефон</p>
                <p style="margin: 0; font-size: 0.95rem; font-weight: 600; color: #111827;">{{ $mergeTriggerPhone }}</p>
            </div>
        </div>
    @endif

    @if ($openReviewsCount > 0)
        <div style="margin-top: 1rem;">
            <p style="margin: 0 0 0.75rem; font-size: 0.875rem; font-weight: 700; color: #111827;">
                Открытые проверки: {{ $openReviewsCount }}
            </p>

            <div style="display: grid; gap: 0.75rem;">
                @foreach ($openReviews as $review)
                    <article style="border: 1px solid #fde68a; border-radius: 12px; background: #fffbeb; padding: 0.85rem 0.95rem;">
                        <p style="margin: 0 0 0.4rem; font-size: 0.95rem; font-weight: 700; color: #92400e;">
                            {{ $review['typeLabel'] }}
                        </p>
                        <p style="margin: 0; font-size: 0.875rem; color: #4b5563;">
                            Телефон: <strong>{{ $review['phoneLabel'] }}</strong>
                        </p>
                        <p style="margin: 0.2rem 0 0; font-size: 0.875rem; color: #4b5563;">
                            Кандидаты: {{ $review['candidateRootsLabel'] }}
                        </p>
                        <p style="margin: 0.2rem 0 0; font-size: 0.8125rem; color: #6b7280;">
                            Создано: {{ $review['createdAtLabel'] }}
                        </p>
                    </article>
                @endforeach
            </div>
        </div>
    @endif

    @if ((! $isMerged) && ($mergedChildrenCount > 0))
        <div style="margin-top: 1rem;">
            <p style="margin: 0 0 0.75rem; font-size: 0.875rem; font-weight: 700; color: #111827;">
                Последние склейки
            </p>

            <div style="display: grid; gap: 0.75rem;">
                @foreach ($mergedChildren as $mergedChild)
                    <article style="border: 1px solid #d1d5db; border-radius: 12px; background: #f9fafb; padding: 0.85rem 0.95rem;">
                        <p style="margin: 0 0 0.4rem; font-size: 0.95rem; font-weight: 700; color: #111827;">
                            Дубль #{{ $mergedChild['id'] }}
                        </p>
                        <p style="margin: 0; font-size: 0.875rem; color: #4b5563;">
                            Склеен: {{ $mergedChild['mergedAtLabel'] }}
                        </p>
                        <p style="margin: 0.2rem 0 0; font-size: 0.875rem; color: #4b5563;">
                            Причина: {{ $mergedChild['reasonLabel'] }}
                        </p>
                        <p style="margin: 0.2rem 0 0; font-size: 0.875rem; color: #4b5563;">
                            Триггерный телефон: {{ $mergedChild['triggerPhone'] }}
                        </p>
                    </article>
                @endforeach
            </div>
        </div>
    @endif
</section>
