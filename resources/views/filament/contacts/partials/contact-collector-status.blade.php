<section
    data-role="contact-collector-status"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    @php
        $statusStyles = match ($statusTone) {
            'warning' => 'border: 1px solid #fcd34d; background: #fffbeb; color: #92400e;',
            'success' => 'border: 1px solid #86efac; background: #f0fdf4; color: #166534;',
            default => 'border: 1px solid #d1d5db; background: #f9fafb; color: #374151;',
        };
    @endphp

    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.9rem;">
        <div>
            <p style="margin: 0 0 0.35rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Статус</p>
            <span style="display: inline-flex; align-items: center; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.8125rem; font-weight: 700; {{ $statusStyles }}">
                {{ $statusLabel }}
            </span>
        </div>

        <div style="display: grid; gap: 0.9rem; grid-template-columns: repeat(2, minmax(8rem, 1fr)); min-width: min(100%, 20rem);">
            <div>
                <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Текущий шаг</p>
                <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $currentStepLabel }}</p>
            </div>
            <div>
                <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Попыток</p>
                <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $attemptsLabel }}</p>
            </div>
        </div>
    </div>

    <div style="border-top: 1px solid #e5e7eb; padding-top: 0.85rem; display: grid; gap: 0.85rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));">
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Имя</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $firstName }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Страна</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $country }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Город</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $city }}</p>
        </div>
    </div>
</section>
