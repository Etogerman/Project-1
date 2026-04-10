<x-filament-panels::page>
    <div data-role="contact-view-page" class="ac-contact-page">
        <section data-role="contact-page-header" class="ac-surface ac-surface--hero">
            <div class="ac-surface__header ac-surface__header--centered">
                <div class="ac-surface__title-group">
                    <p class="ac-surface__eyebrow">
                        Карточка контакта
                    </p>
                    <h2 class="ac-surface__title ac-surface__title--hero">
                        {{ $contactHeader['title'] }}
                    </h2>
                </div>

                <div class="ac-button-group ac-button-group--end">
                    <a href="{{ $contactHeader['backUrl'] }}" class="ac-button ac-button--secondary">
                        Назад к контактам
                    </a>

                    @if ($contactHeader['canEditProfile'])
                        <button
                            type="button"
                            wire:click="openEditProfileDialog"
                            wire:loading.attr="disabled"
                            wire:target="openEditProfileDialog,saveMountedContactProfile"
                            class="ac-button ac-button--warning"
                        >
                            Редактировать
                        </button>
                    @endif
                </div>
            </div>
        </section>

        <nav data-role="contact-tabs" class="ac-contact-page__tabs" aria-label="Вкладки контакта">
            @foreach ($tabs as $tab)
                <a
                    href="{{ $tab['url'] }}"
                    data-role="contact-tab-{{ $tab['key'] }}"
                    data-active="{{ $tab['isActive'] ? 'true' : 'false' }}"
                    @class([
                        'ac-contact-page__tab',
                        'ac-contact-page__tab--active' => $tab['isActive'],
                    ])
                >
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>

        @if (filled($contactHeader['mergedRootUrl']) && filled($contactHeader['mergedRootLabel']))
            <div data-role="contact-merged-indicator" class="ac-note-box ac-note-box--warning">
                <p class="ac-copy">
                    Контакт объединён с основным контактом
                    <a href="{{ $contactHeader['mergedRootUrl'] }}" class="ac-copy__link">
                        {{ $contactHeader['mergedRootLabel'] }}
                    </a>.
                    Актуальные данные и рабочие действия доступны на основном контакте.
                </p>
            </div>
        @endif

        @if ($activeTab === 'general')
            <div data-role="contact-general-layout" class="ac-contact-page__layout">
                <div class="ac-contact-page__column">
                    @include('filament.contacts.partials.contact-flat-section', [
                        'dataRole' => 'contact-section-client-data',
                        'title' => 'Данные клиента',
                        'rows' => $profileRows,
                        'showFieldKeys' => $showFieldKeys,
                    ])

                    @include('filament.contacts.partials.contact-flat-section', [
                        'dataRole' => 'contact-section-work',
                        'title' => 'Работа с контактом',
                        'rows' => $workRows,
                        'showFieldKeys' => $showFieldKeys,
                    ])
                </div>

                <div class="ac-contact-page__column">
                    @include('filament.contacts.partials.contact-flat-section', [
                        'dataRole' => 'contact-section-location',
                        'title' => 'Локация',
                        'rows' => $locationRows,
                        'showFieldKeys' => $showFieldKeys,
                    ])

                    @include('filament.contacts.partials.contact-flat-section', [
                        'dataRole' => 'contact-section-questionnaire',
                        'title' => 'Анкета',
                        'rows' => $questionnaireRows,
                        'showFieldKeys' => $showFieldKeys,
                        'sectionAction' => $questionnaireAction,
                    ])

                    @if (filled($ownershipControls['deleteBlockedReason'] ?? null))
                        <div data-role="contact-delete-blocked-reason" class="ac-note-box ac-note-box--danger">
                            <p class="ac-copy"><strong>Удаление недоступно.</strong> {{ $ownershipControls['deleteBlockedReason'] }}</p>
                        </div>
                    @elseif ($ownershipControls['canDeleteContact'] ?? false)
                        <div class="ac-actions">
                            <button
                                data-role="contact-open-delete-dialog"
                                type="button"
                                wire:click="openDeleteContactDialog"
                                wire:loading.attr="disabled"
                                wire:target="openDeleteContactDialog,deleteMountedContact"
                                class="ac-button ac-button--danger-soft"
                            >
                                <span wire:loading.remove wire:target="openDeleteContactDialog,deleteMountedContact">Удалить клиента</span>
                                <span wire:loading wire:target="openDeleteContactDialog,deleteMountedContact">Открываем...</span>
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            @include('filament.contacts.partials.contact-profile-edit-dialog', $profileViewData)
            @include('filament.contacts.partials.ownership-controls', array_merge($ownershipControls, ['renderSurface' => false]))
            @include('filament.contacts.partials.contact-tags', array_merge($tagsViewData, ['renderSurface' => false]))
            @include('filament.contacts.partials.phone-numbers', array_merge($phoneNumbersViewData, ['renderSurface' => false]))
        @elseif ($activeTab === 'dialogs')
            <div data-role="contact-dialogs-tab" class="ac-contact-page__full-width">
                @include('filament.contacts.partials.contact-dialogs', $dialogsViewData)
            </div>
        @elseif ($activeTab === 'bitrix24')
            <div data-role="contact-bitrix-tab" class="ac-contact-page__stack">
                @foreach ($bitrixSections as $section)
                    <section class="ac-surface ac-surface--secondary">
                        <div class="ac-surface__header ac-surface__header--centered">
                            <div class="ac-surface__title-group">
                                <p class="ac-surface__eyebrow">Битрикс24</p>
                                <h3 class="ac-surface__title">{{ $section['title'] }}</h3>
                                <p class="ac-surface__subtitle">{{ $section['subtitle'] }}</p>
                            </div>
                        </div>

                        @include('filament.contacts.partials.contact-field-grid', [
                            'rows' => $section['rows'],
                            'showFieldKeys' => $showFieldKeys,
                        ])
                    </section>
                @endforeach
            </div>
        @elseif ($activeTab === 'diagnostics')
            <div data-role="contact-diagnostics-tab" class="ac-contact-page__stack">
                @include('filament.contacts.partials.contact-diagnostics-section', [
                    'dataRole' => 'contact-diagnostics-latest-inbound',
                    'title' => 'Последний inbound webhook',
                    'subtitle' => 'Последнее входящее сообщение и его технический payload.',
                    'rows' => $diagnosticsViewData['latestInboundRows'],
                    'showFieldKeys' => $showFieldKeys,
                    'emptyState' => $diagnosticsViewData['hasLatestInboundMessage']
                        ? null
                        : 'Inbound-сообщений для этого контакта ещё не было.',
                    'payloadLabel' => 'Последний raw payload',
                    'payload' => $diagnosticsViewData['latestInboundPayload'],
                ])

                @include('filament.contacts.partials.contact-diagnostics-section', [
                    'dataRole' => 'contact-diagnostics-route-context',
                    'title' => 'Route context',
                    'subtitle' => 'Текущий dialog route и технический контекст маршрутизации.',
                    'rows' => $diagnosticsViewData['routeContextRows'],
                    'showFieldKeys' => $showFieldKeys,
                ])

                @include('filament.contacts.partials.contact-diagnostics-section', [
                    'dataRole' => 'contact-diagnostics-identity',
                    'title' => 'Identity',
                    'subtitle' => 'Текущая identity контакта для активного dialog route.',
                    'rows' => $diagnosticsViewData['identityRows'],
                    'showFieldKeys' => $showFieldKeys,
                ])

                @include('filament.contacts.partials.contact-diagnostics-section', [
                    'dataRole' => 'contact-diagnostics-dedup',
                    'title' => 'Дедупликация',
                    'subtitle' => 'Техническое dedup-состояние контакта без возврата отдельной секции в `Общее`.',
                    'rows' => $diagnosticsViewData['dedupRows'],
                    'showFieldKeys' => $showFieldKeys,
                ])
            </div>
        @else
            <div data-role="contact-history-tab" class="ac-contact-page__full-width">
                @include('filament.contacts.partials.contact-history-timeline', array_merge($historyViewData ?? [
                    'items' => [],
                    'hasMore' => false,
                    'visibleCount' => 0,
                    'totalCount' => 0,
                ], [
                    'commentForm' => $historyCommentViewData ?? ['canAddComment' => false],
                ]))
            </div>
        @endif
    </div>
</x-filament-panels::page>
