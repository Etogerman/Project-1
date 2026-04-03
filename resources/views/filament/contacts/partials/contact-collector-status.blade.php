<section
    data-role="contact-collector-status"
    class="ac-surface"
>
    <div class="ac-surface__header">
        <div class="ac-meta">
            <p class="ac-meta__label">Статус</p>
            <span class="ac-pill" data-tone="{{ $statusTone }}">
                {{ $statusLabel }}
            </span>
        </div>

        <div class="ac-meta-grid ac-meta-grid--compact">
            <div class="ac-meta">
                <p class="ac-meta__label">Текущий шаг</p>
                <p class="ac-meta__value">{{ $currentStepLabel }}</p>
            </div>
            <div class="ac-meta">
                <p class="ac-meta__label">Попыток</p>
                <p class="ac-meta__value">{{ $attemptsLabel }}</p>
            </div>
        </div>
    </div>

    <div class="ac-meta-grid ac-surface__divider">
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

    @if ($canResume)
        <div class="ac-actions ac-surface__divider">
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
