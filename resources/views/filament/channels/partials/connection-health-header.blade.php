@php
    $showBanner = ($health['show_banner'] ?? false) === true;
    $tone = (string) ($health['tone'] ?? 'warning');
    $label = (string) ($health['label'] ?? 'Состояние проверок каналов неизвестно');
    $description = (string) ($health['description'] ?? '');
    $lastFinishedAt = $health['last_finished_at'] ?? null;
    $lastFinishedAtLabel = $lastFinishedAt instanceof DateTimeInterface
        ? $lastFinishedAt->format('d.m.Y H:i:s')
        : '—';
@endphp

@if ($showBanner)
    <section class="ac-channel-check-health" data-tone="{{ $tone }}" aria-label="Состояние проверок каналов">
        <div class="ac-channel-check-health__main">
            <span class="ac-channel-check-health__badge">{{ $label }}</span>

            @if (filled($description))
                <span class="ac-channel-check-health__description">{{ $description }}</span>
            @endif
        </div>

        <dl class="ac-channel-check-health__meta">
            <div>
                <dt>Последний запуск</dt>
                <dd>{{ $lastFinishedAtLabel }}</dd>
            </div>
            <div>
                <dt>Каналов</dt>
                <dd>{{ (int) ($health['processed_count'] ?? 0) }}</dd>
            </div>
            <div>
                <dt>Ошибок</dt>
                <dd>{{ (int) ($health['failure_count'] ?? 0) }}</dd>
            </div>

            @if (filled($health['app_rev'] ?? null))
                <div>
                    <dt>rev</dt>
                    <dd>{{ $health['app_rev'] }}</dd>
                </div>
            @endif
        </dl>
    </section>
@endif
