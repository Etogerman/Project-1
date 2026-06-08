@php
    $sectionRows = $rows ?? [];
    $filledRowsCount = collect($sectionRows)
        ->filter(fn (array $row): bool => trim((string) ($row['value'] ?? '')) !== '—')
        ->count();
    $isFullyEmptySection = count($sectionRows) > 0 && $filledRowsCount === 0;
    $emptySummary = $title === 'Локация' ? 'не указана · добавить' : 'не указано · добавить';
@endphp

<details
    @if (filled($dataRole ?? null)) data-role="{{ $dataRole }}" @endif
    @class([
        'ac-contact-form-section',
        'ac-contact-form-section--empty-collapsed' => $isFullyEmptySection,
    ])
    @if (! $isFullyEmptySection) open @endif
>
    <summary class="ac-contact-form-section__header">
        <h3 class="ac-contact-form-section__title">{{ $title }}</h3>

        @if ($isFullyEmptySection)
            <span class="ac-contact-form-section__empty-summary">{{ $emptySummary }}</span>
        @endif

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
    </summary>

    <div class="ac-contact-form-grid">
        @foreach ($sectionRows as $row)
            @php
                $isEmptyValue = trim((string) ($row['value'] ?? '')) === '—';
            @endphp

            <article @class([
                'ac-contact-form-row',
                'ac-contact-form-row--empty' => $isEmptyValue,
                'ac-contact-form-row--actionable' => filled($row['action']['method'] ?? null),
                'ac-contact-form-row--wide' => $row['wide'] ?? false,
            ])>
                <p class="ac-contact-form-row__label">{{ $row['label'] }}</p>

                <div class="ac-contact-form-row__value-shell">
                    @if (filled($row['edit']['model'] ?? null))
                        @if (($row['edit']['type'] ?? null) === 'select')
                            <select
                                wire:model.live="{{ $row['edit']['model'] }}"
                                @class([
                                    'ac-contact-form-row__value',
                                    'ac-inline-profile-field',
                                    'ac-inline-profile-field--select',
                                    'ac-contact-form-row__value--empty' => $isEmptyValue,
                                ])
                                aria-label="{{ $row['label'] }}"
                            >
                                <option value="">Не указано</option>
                                @foreach (($row['edit']['options'] ?? []) as $optionValue => $optionLabel)
                                    <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                        @else
                            <input
                                type="{{ $row['edit']['type'] ?? 'text' }}"
                                wire:model.live.debounce.300ms="{{ $row['edit']['model'] }}"
                                @if (($row['edit']['type'] ?? null) === 'number')
                                    min="0"
                                    max="150"
                                @endif
                                @class([
                                    'ac-contact-form-row__value',
                                    'ac-inline-profile-field',
                                    'ac-contact-form-row__value--empty' => $isEmptyValue,
                                ])
                                placeholder="—"
                                aria-label="{{ $row['label'] }}"
                            />
                        @endif

                        @error($row['edit']['model'])
                            <p class="ac-contact-form-row__error">{{ $message }}</p>
                        @enderror
                    @else
                        <p @class([
                            'ac-contact-form-row__value',
                            'ac-contact-form-row__value--empty' => $isEmptyValue,
                            'ac-contact-form-row__value--with-action' => filled($row['action']['method'] ?? null),
                        ])>{{ $row['value'] }}</p>
                    @endif

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
</details>
