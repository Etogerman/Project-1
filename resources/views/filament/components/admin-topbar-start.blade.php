@php
    use App\Models\Contact;
    use App\Models\Dialog;
    use App\Filament\Resources\Dialogs\DialogResource;
    use App\Services\Contacts\ResolveContactDisplayNameAction;

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
    $sectionUrl = null;
    $currentLabel = null;

    $resolveDialogsBackUrl = static function (mixed $backTo): ?string {
        if (! is_string($backTo) || $backTo === '') {
            return null;
        }

        $dialogsPaths = collect([
            DialogResource::getUrl('index'),
            DialogResource::getUrl('kanban'),
        ])
            ->map(static fn (string $url): string => parse_url($url, PHP_URL_PATH) ?: $url)
            ->filter()
            ->unique()
            ->values();
        $backToPath = parse_url($backTo, PHP_URL_PATH) ?: $backTo;

        if (! $dialogsPaths->contains($backToPath)) {
            return null;
        }

        $backToQuery = parse_url($backTo, PHP_URL_QUERY);
        $safeBackTo = $backToPath.(is_string($backToQuery) && $backToQuery !== '' ? '?'.$backToQuery : '');

        return url($safeBackTo);
    };

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
    } elseif ($firstSegment === 'dialogs') {
        $thirdSegment = request()->segment(3);
        $sectionUrl = $resolveDialogsBackUrl(request()->query('back_to')) ?? DialogResource::getUrl('index');

        if ($thirdSegment === 'kanban') {
            $currentLabel = 'Канбан';
        } elseif (blank($thirdSegment)) {
            $currentLabel = 'Список';
        } else {
            $routeRecord = request()->route('record');
            $recordId = is_object($routeRecord) && method_exists($routeRecord, 'getKey')
                ? $routeRecord->getKey()
                : $routeRecord;

            if (filled($recordId) && is_numeric($recordId)) {
                $dialog = Dialog::query()
                    ->with('contact')
                    ->find($recordId);

                if ($dialog instanceof Dialog) {
                    $contactLabel = $dialog->contact instanceof Contact
                        ? app(ResolveContactDisplayNameAction::class)->handle($dialog->contact, $dialog)
                        : null;

                    $currentLabel = filled($contactLabel)
                        ? $contactLabel
                        : 'Диалог #'.$dialog->id;
                }
            }
        }
    }
@endphp

<div class="ac-admin-topbar-start">
    <nav class="ac-admin-breadcrumbs" aria-label="Хлебные крошки">
        @if (filled($sectionUrl))
            <a class="ac-admin-breadcrumbs__item" href="{{ $sectionUrl }}">{{ $sectionLabel }}</a>
        @else
            <span class="ac-admin-breadcrumbs__item">{{ $sectionLabel }}</span>
        @endif

        @if (filled($currentLabel))
            <span class="ac-admin-breadcrumbs__separator">/</span>
            <span class="ac-admin-breadcrumbs__item ac-admin-breadcrumbs__item--current">{{ $currentLabel }}</span>
        @endif
    </nav>
</div>
