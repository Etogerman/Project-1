<div class="ac-field-grid ac-surface__divider">
    @foreach ($rows as $row)
        <article class="ac-field-card">
            <p class="ac-field-card__label">{{ $row['label'] }}</p>
            <p class="ac-field-card__value">{{ $row['value'] }}</p>

            @if (($showFieldKeys ?? false) && filled($row['key'] ?? null))
                <p class="ac-field-card__key">{{ $row['key'] }}</p>
            @endif
        </article>
    @endforeach
</div>
