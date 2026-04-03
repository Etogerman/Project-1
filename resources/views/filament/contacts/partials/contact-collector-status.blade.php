<section
    data-role="contact-collector-status"
    class="ac-surface ac-surface--secondary ac-contact-modal-surface ac-contact-modal-surface--collector"
>
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Анкета</p>
            <h3 class="ac-surface__title">Состояние сбора данных</h3>
            <p class="ac-surface__subtitle">
                Видно, на каком шаге остановилась анкета и какие данные уже успели подтвердить.
            </p>
        </div>

        <span class="ac-pill" data-tone="{{ $statusTone }}">
            {{ $statusLabel }}
        </span>
    </div>

    <div class="ac-card-grid ac-surface__divider">
        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Текущий прогресс</p>

            <div class="ac-meta-grid ac-meta-grid--compact ac-list-card__section">
                <div class="ac-meta">
                    <p class="ac-meta__label">Текущий шаг</p>
                    <p class="ac-meta__value">{{ $currentStepLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Попыток</p>
                    <p class="ac-meta__value">{{ $attemptsLabel }}</p>
                </div>
            </div>
        </article>

        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Уже известно</p>

            <div class="ac-meta-grid ac-meta-grid--compact ac-list-card__section">
                <div class="ac-meta">
                    <p class="ac-meta__label">Имя</p>
                    <p class="ac-meta__value">{{ $firstName }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Страна</p>
                    <p class="ac-meta__value">{{ $country }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Город</p>
                    <p class="ac-meta__value">{{ $city }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Возраст</p>
                    <p class="ac-meta__value">{{ $ageRange }}</p>
                </div>
            </div>
        </article>
    </div>

    @if ($canResume && $canResumeAction)
        <div class="ac-actions ac-actions--between ac-surface__divider">
            <p class="ac-note ac-actions__hint">
                Возобновление продолжит анкету с ближайшего безопасного шага и отправит следующий вопрос клиенту.
            </p>

            <button
                data-role="contact-resume-data-collection"
                type="button"
                wire:click="resumeMountedContactDataCollection"
                wire:loading.attr="disabled"
                wire:target="resumeMountedContactDataCollection"
                class="ac-button ac-button--primary-soft"
            >
                <span wire:loading.remove wire:target="resumeMountedContactDataCollection">Возобновить анкету</span>
                <span wire:loading wire:target="resumeMountedContactDataCollection">Запускаем...</span>
            </button>
        </div>
    @endif
</section>
