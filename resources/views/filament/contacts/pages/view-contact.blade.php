<x-filament-panels::page>
    <div data-role="contact-view-page" class="ac-contact-page">
        <section data-role="contact-page-header" class="ac-contact-hero">
            <div class="ac-contact-hero__identity">
                <div class="ac-contact-hero__avatar" aria-hidden="true">
                    {{ $contactHeader['initial'] }}
                </div>

                <div class="ac-contact-hero__title-group">
                    <h1 class="ac-contact-hero__title">{{ $contactHeader['title'] }}</h1>
                    <p class="ac-contact-hero__meta">{{ $contactHeader['meta'] }}</p>
                </div>
            </div>
        </section>

        <nav data-role="contact-tabs" class="ac-contact-page__tabs" aria-label="Вкладки контакта">
            @foreach ($tabs as $tab)
                <a
                    href="{{ $tab['url'] }}"
                    wire:click.prevent="selectTab('{{ $tab['key'] }}')"
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
            @php
                $clientDataSection = $generalCardSections['client_data'] ?? [
                    'dataRole' => 'contact-section-client-data',
                    'title' => 'Данные клиента',
                    'rows' => $profileRows ?? [],
                ];
                $locationSection = $generalCardSections['location'] ?? [
                    'dataRole' => 'contact-section-location',
                    'title' => 'Локация',
                    'rows' => $locationRows ?? [],
                ];
                $workSection = $generalCardSections['work'] ?? [
                    'dataRole' => 'contact-section-work',
                    'title' => 'Работа с контактом',
                    'rows' => $workRows ?? [],
                ];
            @endphp

            <section data-role="contact-stats" class="ac-contact-stats" aria-label="Сводка по контакту">
                @foreach ($contactStats as $stat)
                    <article class="ac-contact-stats__item">
                        <p class="ac-contact-stats__label">{{ $stat['label'] }}</p>
                        <p class="ac-contact-stats__value">{{ $stat['value'] }}</p>
                        <p class="ac-contact-stats__meta">{{ $stat['meta'] }}</p>
                    </article>
                @endforeach
            </section>

            <div data-role="contact-general-layout" class="ac-contact-page__layout">
                <div class="ac-contact-page__column">
                    @include('filament.contacts.partials.contact-flat-section', [
                        'dataRole' => $clientDataSection['dataRole'],
                        'title' => $clientDataSection['title'],
                        'rows' => $clientDataSection['rows'],
                        'showFieldKeys' => $showFieldKeys,
                    ])

                    @include('filament.contacts.partials.contact-flat-section', [
                        'dataRole' => $locationSection['dataRole'],
                        'title' => $locationSection['title'],
                        'rows' => $locationSection['rows'],
                        'showFieldKeys' => $showFieldKeys,
                    ])
                </div>

                <div class="ac-contact-page__column">
                    @foreach ($generalBlocks as $section)
                        @foreach (($section['blocks'] ?? []) as $blockKey)
                            @if ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES)
                                @include('filament.contacts.partials.phone-numbers', array_merge($phoneNumbersViewData, [
                                    'renderSurface' => true,
                                    'sectionTitle' => $section['title'] ?? ($phoneNumbersViewData['sectionTitle'] ?? 'Телефоны'),
                                ]))
                            @elseif ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS)
                                @include('filament.contacts.partials.contact-emails', array_merge($emailsViewData, [
                                    'renderSurface' => true,
                                    'sectionTitle' => $section['title'] ?? ($emailsViewData['sectionTitle'] ?? 'Email'),
                                ]))
                            @elseif ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS)
                                @include('filament.contacts.partials.contact-tags', array_merge($tagsViewData, [
                                    'renderSurface' => true,
                                    'sectionTitle' => $section['title'] ?? 'Теги',
                                ]))
                            @endif
                        @endforeach
                    @endforeach

                    @include('filament.contacts.partials.contact-flat-section', [
                        'dataRole' => $workSection['dataRole'],
                        'title' => $workSection['title'],
                        'rows' => $workSection['rows'],
                        'showFieldKeys' => $showFieldKeys,
                    ])
                </div>
            </div>

            @if (filled($ownershipControls['deleteBlockedReason'] ?? null))
                <div data-role="contact-delete-blocked-reason" class="ac-note-box ac-note-box--danger">
                    <p class="ac-copy"><strong>Удаление недоступно.</strong> {{ $ownershipControls['deleteBlockedReason'] }}</p>
                </div>
            @elseif ($ownershipControls['canDeleteContact'] ?? false)
                <div class="ac-contact-danger-zone">
                    <span class="ac-contact-danger-zone__text">Опасная операция</span>
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

            @include('filament.contacts.partials.contact-profile-edit-dialog', $profileViewData)
            @include('filament.contacts.partials.ownership-controls', array_merge($ownershipControls, ['renderSurface' => false]))

            @if (($contactHeader['canEditProfile'] ?? false) && $this->inlineProfileDirty && ! $this->showEditProfileDialog)
                <div data-role="contact-inline-savebar" class="ac-savebar is-visible">
                    <span class="ac-savebar__status">Есть несохранённые изменения</span>

                    <div class="ac-savebar__actions">
                        <button
                            type="button"
                            wire:click="resetInlineContactProfile"
                            wire:loading.attr="disabled"
                            wire:target="resetInlineContactProfile,saveInlineContactProfile"
                            class="ac-button ac-button--secondary"
                        >
                            Отмена
                        </button>
                        <button
                            type="button"
                            wire:click="saveInlineContactProfile"
                            wire:loading.attr="disabled"
                            wire:target="saveInlineContactProfile"
                            class="ac-button ac-button--success"
                        >
                            <span wire:loading.remove wire:target="saveInlineContactProfile">Сохранить</span>
                            <span wire:loading wire:target="saveInlineContactProfile">Сохраняем...</span>
                        </button>
                    </div>
                </div>
            @endif
        @elseif ($activeTab === 'dialogs')
            <div data-role="contact-dialogs-tab" class="ac-contact-page__full-width">
                @foreach ($dialogBlocks as $section)
                    @foreach (($section['blocks'] ?? []) as $blockKey)
                        @if ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS)
                            @include('filament.contacts.partials.contact-dialogs', $dialogsViewData)
                        @endif
                    @endforeach
                @endforeach
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
        @elseif ($activeTab === 'system_fields')
            <div data-role="contact-system-fields-tab" class="ac-contact-page__stack">
                @foreach ($systemFieldSections as $section)
                    <section class="ac-surface ac-surface--secondary">
                        <div class="ac-surface__header ac-surface__header--centered">
                            <div class="ac-surface__title-group">
                                <p class="ac-surface__eyebrow">Системные поля</p>
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
        @elseif ($activeTab === 'dedup')
            <div data-role="contact-dedup-tab" class="ac-contact-page__full-width">
                @foreach ($dedupBlocks as $section)
                    @foreach (($section['blocks'] ?? []) as $blockKey)
                        @if ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP && is_array($dedupStatusViewData ?? null))
                            <div data-role="contact-dedup-section">
                                <div class="ac-surface__header ac-surface__header--centered">
                                    <div class="ac-surface__title-group">
                                        <h3 class="ac-surface__title">{{ $section['title'] ?? 'Склейки' }}</h3>
                                    </div>
                                </div>

                                @include('filament.contacts.partials.contact-dedup-status', $dedupStatusViewData)
                            </div>
                        @endif
                    @endforeach
                @endforeach
            </div>
        @elseif ($activeTab === 'diagnostics')
            <div data-role="contact-diagnostics-tab" class="ac-contact-page__stack">
                @foreach ($diagnosticsBlocks as $section)
                    @foreach (($section['blocks'] ?? []) as $blockKey)
                        @if ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS && is_array($diagnosticsViewData ?? null))
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
                                'dataRole' => 'contact-diagnostics-technical-contact',
                                'title' => 'Технические поля контакта',
                                'subtitle' => 'Служебные значения, которые не показываются в основной карточке.',
                                'rows' => $diagnosticsViewData['technicalContactRows'],
                                'showFieldKeys' => $showFieldKeys,
                            ])

                            @include('filament.contacts.partials.contact-diagnostics-section', [
                                'dataRole' => 'contact-diagnostics-dedup',
                                'title' => 'Дедупликация',
                                'subtitle' => 'Техническое dedup-состояние контакта без возврата отдельной секции в `Общее`.',
                                'rows' => $diagnosticsViewData['dedupRows'],
                                'showFieldKeys' => $showFieldKeys,
                            ])
                        @endif
                    @endforeach
                @endforeach
            </div>
        @elseif ($activeTab === 'history')
            <div data-role="contact-history-tab" class="ac-contact-page__full-width">
                @foreach ($historyBlocks as $section)
                    @foreach (($section['blocks'] ?? []) as $blockKey)
                        @if ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY)
                            @include('filament.contacts.partials.contact-history-timeline', array_merge($historyViewData ?? [
                                'items' => [],
                                'hasMore' => false,
                                'visibleCount' => 0,
                                'totalCount' => 0,
                            ], [
                                'commentForm' => $historyCommentViewData ?? ['canAddComment' => false],
                            ]))
                        @endif
                    @endforeach
                @endforeach
            </div>
        @elseif ($isCustomTab ?? false)
            <div data-role="contact-custom-tab-{{ $activeTab }}" class="ac-contact-page__stack">
                @forelse ($customTabSections as $section)
                    @if (($section['rows'] ?? []) !== [])
                        @include('filament.contacts.partials.contact-flat-section', [
                            'dataRole' => 'contact-custom-section-'.$section['section_key'],
                            'title' => $section['title'],
                            'rows' => $section['rows'],
                            'showFieldKeys' => $showFieldKeys,
                        ])
                    @endif

                    @foreach (($section['blocks'] ?? []) as $blockKey)
                        @php
                            $customBlockTitle = count($section['blocks'] ?? []) === 1 ? ($section['title'] ?? null) : null;
                        @endphp

                        @if ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES)
                            @include('filament.contacts.partials.phone-numbers', array_merge($phoneNumbersViewData, [
                                'renderSurface' => true,
                                'sectionTitle' => $customBlockTitle ?? ($phoneNumbersViewData['sectionTitle'] ?? 'Телефоны'),
                            ]))
                        @elseif ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS)
                            @include('filament.contacts.partials.contact-emails', array_merge($emailsViewData, [
                                'renderSurface' => true,
                                'sectionTitle' => $customBlockTitle ?? ($emailsViewData['sectionTitle'] ?? 'Email'),
                            ]))
                        @elseif ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS)
                            @include('filament.contacts.partials.contact-tags', array_merge($tagsViewData, [
                                'renderSurface' => true,
                                'sectionTitle' => $customBlockTitle ?? 'Теги',
                            ]))
                        @elseif ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS)
                            @include('filament.contacts.partials.contact-dialogs', $dialogsViewData)
                        @elseif ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP && is_array($dedupStatusViewData ?? null))
                            <div data-role="contact-custom-dedup-section">
                                <div class="ac-surface__header ac-surface__header--centered">
                                    <div class="ac-surface__title-group">
                                        <h3 class="ac-surface__title">{{ $customBlockTitle ?? 'Склейки' }}</h3>
                                    </div>
                                </div>

                                @include('filament.contacts.partials.contact-dedup-status', $dedupStatusViewData)
                            </div>
                        @elseif ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS && is_array($diagnosticsViewData ?? null))
                            @include('filament.contacts.partials.contact-diagnostics-section', [
                                'dataRole' => 'contact-custom-diagnostics-latest-inbound',
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
                                'dataRole' => 'contact-custom-diagnostics-route-context',
                                'title' => 'Route context',
                                'subtitle' => 'Текущий dialog route и технический контекст маршрутизации.',
                                'rows' => $diagnosticsViewData['routeContextRows'],
                                'showFieldKeys' => $showFieldKeys,
                            ])

                            @include('filament.contacts.partials.contact-diagnostics-section', [
                                'dataRole' => 'contact-custom-diagnostics-identity',
                                'title' => 'Identity',
                                'subtitle' => 'Текущая identity контакта для активного dialog route.',
                                'rows' => $diagnosticsViewData['identityRows'],
                                'showFieldKeys' => $showFieldKeys,
                            ])

                            @include('filament.contacts.partials.contact-diagnostics-section', [
                                'dataRole' => 'contact-custom-diagnostics-technical-contact',
                                'title' => 'Технические поля контакта',
                                'subtitle' => 'Служебные значения, которые не показываются в основной карточке.',
                                'rows' => $diagnosticsViewData['technicalContactRows'],
                                'showFieldKeys' => $showFieldKeys,
                            ])

                            @include('filament.contacts.partials.contact-diagnostics-section', [
                                'dataRole' => 'contact-custom-diagnostics-dedup',
                                'title' => 'Дедупликация',
                                'subtitle' => 'Техническое dedup-состояние контакта без возврата отдельной секции в `Общее`.',
                                'rows' => $diagnosticsViewData['dedupRows'],
                                'showFieldKeys' => $showFieldKeys,
                            ])
                        @elseif ($blockKey === \App\Services\Contacts\SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY)
                            @include('filament.contacts.partials.contact-history-timeline', array_merge($historyViewData ?? [
                                'items' => [],
                                'hasMore' => false,
                                'visibleCount' => 0,
                                'totalCount' => 0,
                            ], [
                                'commentForm' => $historyCommentViewData ?? ['canAddComment' => false],
                            ]))
                        @endif
                    @endforeach
                @empty
                    <div data-role="contact-custom-tab-empty" class="ac-empty-state">
                        В этой вкладке пока нет видимых элементов.
                    </div>
                @endforelse
            </div>
        @endif
    </div>
</x-filament-panels::page>
