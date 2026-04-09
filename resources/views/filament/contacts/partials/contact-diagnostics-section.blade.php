<section
    @if (filled($dataRole ?? null)) data-role="{{ $dataRole }}" @endif
    class="ac-surface ac-surface--secondary"
>
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Диагностика</p>
            <h3 class="ac-surface__title">{{ $title }}</h3>

            @if (filled($subtitle ?? null))
                <p class="ac-surface__subtitle">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    @if (filled($emptyState ?? null))
        <div class="ac-empty-state ac-surface__divider">
            {{ $emptyState }}
        </div>
    @else
        @include('filament.contacts.partials.contact-field-grid', [
            'rows' => $rows,
            'showFieldKeys' => $showFieldKeys,
        ])

        @if (filled($payload ?? null))
            <div class="ac-diagnostics-payload ac-surface__divider">
                <p class="ac-diagnostics-payload__label">{{ $payloadLabel ?? 'Raw payload' }}</p>
                <pre class="ac-diagnostics-payload__pre">{{ $payload }}</pre>
            </div>
        @endif
    @endif
</section>
