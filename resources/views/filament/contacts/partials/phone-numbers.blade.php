<section
    data-role="contact-phone-numbers"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    @if ($phoneNumbers === [])
        <p style="margin: 0; font-size: 0.95rem; color: #6b7280;">
            Номера телефонов ещё не сохранены.
        </p>
    @else
        <div style="display: grid; gap: 0.75rem;">
            @foreach ($phoneNumbers as $phoneNumber)
                <div style="border: 1px solid #e5e7eb; border-radius: 12px; padding: 0.85rem 0.95rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                        <div>
                            <p style="margin: 0; font-size: 1rem; font-weight: 700; color: #111827;">
                                {{ $phoneNumber['phone'] }}
                            </p>
                            <p style="margin: 0.3rem 0 0; font-size: 0.875rem; color: #6b7280;">
                                Источник: {{ $phoneNumber['source'] }}
                            </p>
                        </div>

                        <span
                            style="display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.75rem; font-weight: 700; background: {{ $phoneNumber['is_primary'] ? '#dcfce7' : '#f3f4f6' }}; color: {{ $phoneNumber['is_primary'] ? '#166534' : '#4b5563' }};"
                        >
                            {{ $phoneNumber['is_primary'] ? 'Основной' : 'Дополнительный' }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
