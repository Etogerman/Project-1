<div class="ac-field-grid ac-surface__divider">
    @foreach ($rows as $row)
        @php
            $fieldLink = is_array($row['link'] ?? null) ? $row['link'] : null;
            $hasFieldLink = $fieldLink !== null
                && filled($fieldLink['url'] ?? null)
                && filled($fieldLink['label'] ?? null)
                && ($row['value'] ?? '—') !== '—';
            $fieldLinkReplacesValue = $hasFieldLink && (bool) ($fieldLink['replaceValue'] ?? false);
        @endphp

        <article class="ac-field-card">
            <p class="ac-field-card__label">{{ $row['label'] }}</p>
            <p class="ac-field-card__value">
                @if ($fieldLinkReplacesValue)
                    <a
                        href="{{ $fieldLink['url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ac-field-card__external-link"
                        title="{{ $fieldLink['title'] ?? $fieldLink['label'] }}"
                        aria-label="{{ $fieldLink['title'] ?? $fieldLink['label'] }}"
                        @if (filled($fieldLink['dataRole'] ?? null))
                            data-role="{{ $fieldLink['dataRole'] }}"
                        @endif
                    >
                        {{ $fieldLink['label'] }}
                    </a>
                @else
                    <span>{{ $row['value'] }}</span>

                    @if ($hasFieldLink)
                        <a
                            href="{{ $fieldLink['url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="ac-field-card__external-link"
                            title="{{ $fieldLink['title'] ?? $fieldLink['label'] }}"
                            aria-label="{{ $fieldLink['title'] ?? $fieldLink['label'] }}"
                            @if (filled($fieldLink['dataRole'] ?? null))
                                data-role="{{ $fieldLink['dataRole'] }}"
                            @endif
                        >
                            {{ $fieldLink['label'] }}
                        </a>
                    @endif
                @endif
            </p>

            @if (($showFieldKeys ?? false) && filled($row['key'] ?? null))
                <p class="ac-field-card__key">{{ $row['key'] }}</p>
            @endif
        </article>
    @endforeach
</div>
