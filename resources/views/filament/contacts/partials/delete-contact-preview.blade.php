<div style="display: flex; flex-direction: column; gap: 0.9rem;">
    <p style="margin: 0; font-size: 0.875rem; color: #4b5563;">
        @if ($hasMergeHistory)
            Будет удалён весь клиент <strong>{{ $contactLabel }}</strong> целиком, включая основной контакт, склеенные дубли, диалоги, сообщения, телефоны, идентичности и историю склейки.
        @else
            Контакт <strong>{{ $contactLabel }}</strong> будет удалён вместе с диалогами, сообщениями, телефонами и идентичностями.
        @endif
    </p>

    <div
        data-role="contact-delete-preview-counts"
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 0.65rem;"
    >
        @foreach ($counts as $item)
            <div style="border: 1px solid #e5e7eb; border-radius: 12px; background: #f9fafb; padding: 0.75rem 0.85rem;">
                <p style="margin: 0 0 0.2rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">
                    {{ $item['label'] }}
                </p>
                <p style="margin: 0; font-size: 1rem; font-weight: 700; color: #111827;">
                    {{ $item['value'] }}
                </p>
            </div>
        @endforeach
    </div>
</div>
