<div class="ac-panel-stack">
    <p class="ac-copy">
        @if ($hasMergeHistory)
            Будет удалён весь клиент <strong>{{ $contactLabel }}</strong> целиком, включая основной контакт, склеенные дубли, диалоги, сообщения, телефоны, профили каналов и историю склейки.
        @else
            Контакт <strong>{{ $contactLabel }}</strong> будет удалён вместе с диалогами, сообщениями, телефонами и профилями каналов.
        @endif
    </p>

    <div data-role="contact-delete-preview-counts" class="ac-meta-grid ac-meta-grid--compact">
        @foreach ($counts as $item)
            <div class="ac-list-card">
                <p class="ac-meta__label">
                    {{ $item['label'] }}
                </p>
                <p class="ac-meta__value ac-meta__value--emphasis">
                    {{ $item['value'] }}
                </p>
            </div>
        @endforeach
    </div>
</div>
