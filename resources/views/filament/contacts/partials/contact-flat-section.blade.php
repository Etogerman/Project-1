<section @if (filled($dataRole ?? null)) data-role="{{ $dataRole }}" @endif class="ac-contact-form-section">
    <div class="ac-contact-form-section__header">
        <h3 class="ac-contact-form-section__title">{{ $title }}</h3>

        @if (filled($sectionAction['method'] ?? null))
            <button
                type="button"
                wire:click="{{ $sectionAction['method'] }}"
                wire:loading.attr="disabled"
                wire:target="{{ $sectionAction['target'] ?? $sectionAction['method'] }}"
                class="ac-button ac-button--primary-soft"
            >
                <span wire:loading.remove wire:target="{{ $sectionAction['target'] ?? $sectionAction['method'] }}">
                    {{ $sectionAction['label'] }}
                </span>
                <span wire:loading wire:target="{{ $sectionAction['target'] ?? $sectionAction['method'] }}">
                    Выполняем...
                </span>
            </button>
        @endif
    </div>

    <div class="ac-contact-form-grid">
        @foreach ($rows as $row)
            <article class="ac-contact-form-row">
                <p class="ac-contact-form-row__label">{{ $row['label'] }}</p>

                <div class="ac-contact-form-row__value-shell">
                    <p @class([
                        'ac-contact-form-row__value',
                        'ac-contact-form-row__value--with-action' => filled($row['action']['method'] ?? null),
                    ])>{{ $row['value'] }}</p>

                    @if (filled($row['action']['method'] ?? null))
                        <button
                            type="button"
                            wire:click="{{ $row['action']['method'] }}"
                            wire:loading.attr="disabled"
                            wire:target="{{ $row['action']['target'] ?? $row['action']['method'] }}"
                            class="ac-icon-button ac-icon-button--field"
                            title="{{ $row['action']['label'] }}"
                            aria-label="{{ $row['action']['label'] }}"
                        >
                            <x-filament::icon :icon="$row['action']['icon'] ?? 'heroicon-m-pencil-square'" class="h-4 w-4" />
                        </button>
                    @endif
                </div>

                @if (($showFieldKeys ?? false) && filled($row['key'] ?? null))
                    <p class="ac-contact-form-row__key">{{ $row['key'] }}</p>
                @endif

                @if (($row['items'] ?? []) !== [])
                    <div class="ac-contact-form-row__items">
                        @foreach ($row['items'] as $item)
                            <div @class([
                                'ac-contact-form-item',
                                'ac-contact-form-item--tag' => ($item['kind'] ?? null) === 'tag',
                            ])>
                                <div class="ac-contact-form-item__body">
                                    @if (($item['kind'] ?? null) === 'tag')
                                        <span class="ac-pill" data-tone="{{ $item['tone'] ?? 'gray' }}">
                                            {{ $item['label'] }}
                                        </span>
                                    @else
                                        <span class="ac-contact-form-item__value">{{ $item['label'] }}</span>
                                    @endif

                                    @if (filled($item['meta'] ?? null))
                                        <span class="ac-contact-form-item__meta">{{ $item['meta'] }}</span>
                                    @endif
                                </div>

                                @if (filled($item['editAction'] ?? null) || filled($item['deleteAction'] ?? null))
                                    <div class="ac-contact-form-item__actions">
                                        @if (filled($item['editAction'] ?? null))
                                            <button
                                                type="button"
                                                wire:click="{{ $item['editAction'] }}"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $item['editTarget'] ?? $item['editAction'] }}"
                                                class="ac-inline-action"
                                            >
                                                Изменить
                                            </button>
                                        @endif

                                        @if (filled($item['deleteAction'] ?? null))
                                            <button
                                                type="button"
                                                wire:click="{{ $item['deleteAction'] }}"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $item['deleteTarget'] ?? $item['deleteAction'] }}"
                                                class="ac-inline-action ac-inline-action--danger"
                                            >
                                                Убрать
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        @endforeach
    </div>
</section>
