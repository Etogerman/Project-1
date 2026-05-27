@php
    use App\Models\Contact;

    $sectionLabels = [
        'contacts' => 'Контакты',
        'dialogs' => 'Диалоги',
        'constructor' => 'Конструктор',
        'scenarios' => 'Сценарии',
        'channels' => 'Каналы связи',
        'ai-requests' => 'ИИ-запросы',
        'contact-first-name-resolution-events' => 'Распознавание имён',
    ];

    $firstSegment = request()->segment(2);
    $sectionLabel = $sectionLabels[$firstSegment] ?? 'Админка';
    $currentLabel = null;

    if ($firstSegment === 'contacts') {
        $routeRecord = request()->route('record');
        $recordId = is_object($routeRecord) && method_exists($routeRecord, 'getKey')
            ? $routeRecord->getKey()
            : $routeRecord;

        if (filled($recordId) && is_numeric($recordId)) {
            $contact = Contact::query()->find($recordId);

            if ($contact instanceof Contact) {
                $nameParts = array_values(array_filter([
                    filled($contact->last_name) ? trim((string) $contact->last_name) : null,
                    filled($contact->first_name) ? trim((string) $contact->first_name) : null,
                ]));

                $currentLabel = $nameParts !== []
                    ? implode(' ', $nameParts)
                    : (filled($contact->display_name) ? (string) $contact->display_name : 'Контакт #'.$contact->id);
            }
        }
    }
@endphp

<div class="ac-admin-topbar-start">
    <nav class="ac-admin-breadcrumbs" aria-label="Хлебные крошки">
        <span class="ac-admin-breadcrumbs__item">{{ $sectionLabel }}</span>

        @if (filled($currentLabel))
            <span class="ac-admin-breadcrumbs__separator">/</span>
            <span class="ac-admin-breadcrumbs__item ac-admin-breadcrumbs__item--current">{{ $currentLabel }}</span>
        @endif
    </nav>
</div>
