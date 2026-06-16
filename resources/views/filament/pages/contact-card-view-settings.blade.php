<x-filament-panels::page>
    <style>
        .fi-page,
        .fi-page-main,
        .fi-page-content {
            width: 100%;
            max-width: none;
            margin-inline: 0;
        }

        .fi-main.fi-admin-content-wide {
            width: min(100%, 1220px);
            margin-inline: auto;
            padding-left: var(--ac-sp-2);
            padding-right: var(--ac-sp-2);
        }

        .cv-settings {
            display: grid;
            gap: var(--ac-sp-4);
            width: 100%;
            max-width: none;
            padding: 0 var(--ac-sp-2);
        }

        .cv-settings__head,
        .cv-settings__toolbar,
        .cv-settings__inline,
        .cv-list-item,
        .cv-editor__actions,
        .cv-add-row,
        .cv-table-row {
            display: flex;
            align-items: center;
        }

        .cv-settings__head {
            justify-content: space-between;
            gap: var(--ac-sp-4);
        }

        .cv-settings__title {
            display: grid;
            gap: 3px;
            min-width: 0;
        }

        .cv-settings__title h1 {
            margin: 0;
            color: var(--ac-text);
            font-size: var(--ac-fs-22);
            font-weight: var(--ac-fw-bold);
            line-height: 1.15;
        }

        .cv-settings__title p,
        .cv-settings__hint,
        .cv-settings__meta {
            margin: 0;
            color: var(--ac-text-3);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-medium);
        }

        .cv-settings__toolbar {
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: var(--ac-sp-2);
        }

        .cv-settings__actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: var(--ac-sp-2);
        }

        .cv-settings__toolbar .ac-tabs {
            margin: 0;
        }

        .cv-status {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: var(--ac-sp-3);
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-3);
            background: var(--ac-surface);
            box-shadow: var(--ac-shadow-card);
            padding: var(--ac-sp-3);
        }

        .cv-settings__grid {
            display: grid;
            grid-template-columns: minmax(190px, 240px) minmax(220px, 270px) minmax(0, 1fr);
            gap: var(--ac-sp-4);
            align-items: start;
        }

        .cv-panel {
            min-width: 0;
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-3);
            background: var(--ac-surface);
            box-shadow: var(--ac-shadow-card);
            overflow: visible;
        }

        .cv-panel__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: var(--ac-sp-3);
            min-height: 46px;
            border-bottom: 1px solid var(--ac-border);
            padding: 8px var(--ac-sp-3);
        }

        .cv-panel__head h2,
        .cv-editor h3 {
            margin: 0;
            color: var(--ac-text);
            font-weight: var(--ac-fw-bold);
        }

        .cv-panel__head h2 {
            font-size: var(--ac-fs-15);
        }

        .cv-panel__body {
            display: grid;
            gap: var(--ac-sp-3);
            padding: var(--ac-sp-3);
        }

        .cv-list {
            display: grid;
            gap: 4px;
        }

        .cv-list-item {
            appearance: none;
            width: 100%;
            justify-content: space-between;
            gap: var(--ac-sp-2);
            min-height: 36px;
            border: 1px solid transparent;
            border-radius: var(--ac-radius-2);
            background: transparent;
            color: var(--ac-text);
            cursor: pointer;
            padding: 6px 8px;
            text-align: left;
        }

        .cv-list-item:hover {
            background: var(--ac-surface-2);
        }

        .cv-list-item.is-active {
            border-color: var(--ac-accent);
            background: var(--ac-accent-soft);
        }

        .cv-list-item__main {
            display: grid;
            gap: 1px;
            min-width: 0;
        }

        .cv-list-item__title {
            overflow: hidden;
            color: var(--ac-text);
            font-size: var(--ac-fs-13);
            font-weight: var(--ac-fw-semi);
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cv-list-item__key {
            overflow: hidden;
            color: var(--ac-text-3);
            font-size: var(--ac-fs-11);
            font-weight: var(--ac-fw-medium);
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cv-editor {
            display: grid;
            gap: var(--ac-sp-3);
            border-top: 1px solid var(--ac-border);
            padding-top: var(--ac-sp-3);
        }

        .cv-editor h3 {
            font-size: var(--ac-fs-13);
        }

        .cv-form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 82px;
            gap: var(--ac-sp-2);
        }

        .cv-editor__actions {
            flex-wrap: wrap;
            justify-content: space-between;
            gap: var(--ac-sp-2);
        }

        .cv-settings__inline {
            flex-wrap: wrap;
            gap: var(--ac-sp-2);
        }

        .cv-input,
        .cv-select {
            width: 100%;
            min-height: 32px;
            border: 1px solid var(--ac-border-input);
            border-radius: var(--ac-radius-2);
            background: var(--ac-surface);
            color: var(--ac-text);
            font-size: var(--ac-fs-13);
            outline: none;
            padding: 0 10px;
        }

        .cv-input:focus,
        .cv-select:focus {
            border-color: var(--ac-accent);
            box-shadow: 0 0 0 3px var(--ac-accent-ring);
        }

        .cv-input--order {
            width: 58px;
            padding-left: 6px;
            padding-right: 6px;
            text-align: center;
        }

        .cv-check {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ac-text-2);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-semi);
            white-space: nowrap;
        }

        .cv-badge {
            display: inline-flex;
            align-items: center;
            min-height: 22px;
            border-radius: var(--ac-radius-pill);
            background: var(--ac-surface-3);
            color: var(--ac-text-2);
            font-size: var(--ac-fs-11);
            font-weight: var(--ac-fw-bold);
            padding: 0 8px;
            white-space: nowrap;
        }

        .cv-badge--muted {
            opacity: .68;
        }

        .cv-table {
            display: grid;
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-2);
            min-width: 0;
            overflow: visible;
        }

        .cv-table-row {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) minmax(82px, auto) 76px minmax(92px, auto);
            gap: var(--ac-sp-2);
            align-items: center;
            min-height: var(--ac-row-h-table);
            min-width: 0;
            border-top: 1px solid var(--ac-border);
            padding: 6px 10px;
        }

        .cv-table-row--fields-only {
            grid-template-columns: minmax(0, 1fr) 76px 72px;
        }

        .cv-table-row:first-child {
            border-top: 0;
        }

        .cv-table-row--head {
            min-height: 34px;
            background: var(--ac-surface-2);
            color: var(--ac-text-3);
            font-size: var(--ac-fs-11);
            font-weight: var(--ac-fw-bold);
            text-transform: uppercase;
        }

        .cv-table-cell {
            min-width: 0;
        }

        .cv-table-cell--type {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .cv-table-row-actions {
            display: flex;
            flex: 0 0 auto;
            flex-wrap: nowrap;
            gap: 4px;
            margin-top: 0;
            margin-left: 2px;
        }

        .cv-table-row-actions .ac-btn {
            white-space: nowrap;
        }

        .cv-move-row {
            display: grid;
            grid-template-columns: minmax(150px, .8fr) minmax(260px, 1fr) auto auto;
            gap: var(--ac-sp-2);
            align-items: center;
            border-top: 1px solid var(--ac-border);
            background: var(--ac-surface-2);
            padding: 8px 10px;
        }

        .cv-move-row__label {
            overflow: hidden;
            color: var(--ac-text-2);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-semi);
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cv-table-name {
            display: flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
        }

        .cv-table-row--fields-only .cv-table-name {
            gap: 8px;
        }

        .cv-table-name strong {
            display: inline-block;
            flex: 1 1 140px;
            min-width: 0;
            color: var(--ac-text);
            font-size: var(--ac-fs-13);
            font-weight: var(--ac-fw-semi);
            line-height: 1.22;
            overflow: hidden;
            overflow-wrap: normal;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cv-table-name span {
            display: inline-block;
            flex: 0 1 150px;
            min-width: 58px;
            overflow: hidden;
            color: var(--ac-text-3);
            font-size: var(--ac-fs-11);
            font-weight: var(--ac-fw-medium);
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cv-table-row--fields-only .cv-table-row-actions {
            margin-left: 2px;
        }

        .cv-table-row--fields-only .cv-table-cell:last-child {
            display: flex;
            justify-content: center;
        }

        .cv-tooltip {
            position: relative;
            cursor: inherit;
        }

        .cv-floating-tooltip {
            position: fixed;
            z-index: 9999;
            display: none;
            max-width: min(420px, 72vw);
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-2);
            background: var(--ac-surface);
            box-shadow: var(--ac-shadow-card);
            color: var(--ac-text);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-medium);
            line-height: 1.35;
            overflow-wrap: anywhere;
            padding: 7px 9px;
            pointer-events: none;
            white-space: normal;
        }

        .cv-floating-tooltip.is-visible {
            display: block;
        }

        .cv-add {
            display: grid;
            gap: var(--ac-sp-2);
            border-top: 1px solid var(--ac-border);
            padding-top: var(--ac-sp-3);
        }

        .cv-add h3 {
            margin: 0;
            color: var(--ac-text);
            font-size: var(--ac-fs-13);
            font-weight: var(--ac-fw-bold);
        }

        .cv-add-row {
            flex-wrap: wrap;
            gap: var(--ac-sp-2);
        }

        .cv-add-row .cv-input,
        .cv-add-row .cv-select {
            flex: 1 1 150px;
            min-width: 0;
        }

        .cv-add-row .cv-input--order {
            flex: 0 0 82px;
            width: 82px;
        }

        .cv-add-row .ac-btn {
            white-space: nowrap;
        }

        .cv-add-advanced {
            border: 1px dashed var(--ac-border);
            border-radius: var(--ac-radius-2);
            background: var(--ac-surface-2);
            padding: 8px;
        }

        .cv-add-advanced summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--ac-sp-2);
            color: var(--ac-text);
            cursor: pointer;
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-semi);
            list-style: none;
        }

        .cv-add-advanced summary::-webkit-details-marker {
            display: none;
        }

        .cv-add-advanced summary::after {
            content: "";
            width: 7px;
            height: 7px;
            border-right: 1.5px solid currentColor;
            border-bottom: 1.5px solid currentColor;
            transform: rotate(45deg) translateY(-2px);
        }

        .cv-add-advanced[open] summary::after {
            transform: rotate(225deg) translateY(-2px);
        }

        .cv-add-advanced__body {
            display: grid;
            gap: var(--ac-sp-2);
            margin-top: 8px;
        }

        .cv-add-advanced__hint {
            margin: 0;
            color: var(--ac-text-3);
            font-size: var(--ac-fs-11);
            line-height: 1.35;
        }

        .cv-field-picker {
            position: relative;
            flex: 1 1 260px;
            min-width: 0;
        }

        .cv-field-picker__control {
            appearance: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--ac-sp-2);
            width: 100%;
            min-height: 32px;
            border: 1px solid var(--ac-border-input);
            border-radius: var(--ac-radius-2);
            background: var(--ac-surface);
            color: var(--ac-text);
            cursor: pointer;
            font-size: var(--ac-fs-13);
            list-style: none;
            outline: none;
            padding: 0 10px;
            text-align: left;
        }

        .cv-field-picker__control::-webkit-details-marker {
            display: none;
        }

        .cv-field-picker__control::marker {
            content: '';
        }

        .cv-field-picker__control:hover {
            background: var(--ac-surface-2);
        }

        .cv-field-picker__control:focus-visible {
            border-color: var(--ac-accent);
            box-shadow: 0 0 0 3px var(--ac-accent-ring);
        }

        .cv-field-picker__value {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cv-field-picker__chevron {
            flex: 0 0 auto;
            width: 14px;
            height: 14px;
            color: var(--ac-text-3);
        }

        .cv-field-picker__panel {
            position: absolute;
            right: 0;
            bottom: calc(100% + 6px);
            left: 0;
            z-index: 70;
            display: none;
            gap: var(--ac-sp-2);
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-2);
            background: var(--ac-surface);
            box-shadow: var(--ac-shadow-card);
            padding: var(--ac-sp-2);
        }

        .cv-field-picker[open] .cv-field-picker__panel {
            display: grid;
        }

        .cv-field-picker[open] .cv-field-picker__chevron {
            transform: rotate(180deg);
        }

        .cv-field-picker__options {
            display: grid;
            gap: 2px;
            max-height: 230px;
            overflow: auto;
        }

        .cv-field-picker__option {
            appearance: none;
            border: 0;
            border-radius: var(--ac-radius-2);
            background: transparent;
            color: var(--ac-text);
            cursor: pointer;
            font-size: var(--ac-fs-13);
            min-height: 30px;
            overflow: hidden;
            padding: 6px 8px;
            text-align: left;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cv-field-picker__option:hover,
        .cv-field-picker__option.is-selected {
            background: var(--ac-accent-soft);
        }

        .cv-field-picker__empty {
            margin: 0;
            color: var(--ac-text-3);
            font-size: var(--ac-fs-12);
            padding: 6px 8px;
        }

        @media (max-width: 1180px) {
            .cv-settings__grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .cv-settings__head,
            .cv-status,
            .cv-settings__actions,
            .cv-add-row {
                align-items: stretch;
                flex-direction: column;
            }

            .cv-table-row {
                grid-template-columns: 1fr;
            }

            .cv-table-row--head {
                display: none;
            }

            .cv-move-row {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="cv-settings">
        <header class="cv-settings__head">
            <div class="cv-settings__title">
                <h1>Виды карточек</h1>
                <p>Настройка карточки {{ $entityGenitiveLabel }}. Системный эталон остаётся нетронутым, изменения сохраняются в рабочей копии.</p>
            </div>
            <div class="cv-settings__toolbar">
                <div class="ac-tabs" role="tablist" aria-label="Тип карточки">
                    @foreach ($entityOptions as $entityKey => $label)
                        <button
                            @class([
                                'ac-tab',
                                'is-active' => $entity === $entityKey,
                            ])
                            type="button"
                            wire:click="selectEntity('{{ $entityKey }}')"
                            role="tab"
                            aria-selected="{{ $entity === $entityKey ? 'true' : 'false' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="cv-settings__actions">
                    @if ($previewUrl)
                        <a class="ac-btn ac-btn--sm" href="{{ $previewUrl }}">Открыть карточку</a>
                    @endif
                    <button class="ac-btn ac-btn--danger ac-btn--sm" type="button" wire:click="restoreStandardView">
                        Вернуть стандартный вид
                    </button>
                </div>
            </div>
        </header>

        <section class="cv-status">
            <div class="cv-settings__inline">
                <span class="cv-badge">{{ $isEditableView ? 'Рабочая копия' : 'Системный эталон' }}</span>
                <span class="cv-badge">{{ $entityLabel }}</span>
                <span class="cv-settings__meta">Активный вид: {{ $activeView?->name ?? 'не найден' }}</span>
            </div>
            <p class="cv-settings__hint">
                Системные вкладки, секции и элементы можно переименовывать, сортировать и скрывать. Пользовательские вкладки, секции и элементы можно добавлять и удалять.
            </p>
        </section>

        <div class="cv-settings__grid">
            <section class="cv-panel">
                <div class="cv-panel__head">
                    <h2>Вкладки</h2>
                </div>
                <div class="cv-panel__body">
                    <div class="cv-list">
                        @forelse ($tabRows as $tabKey => $tab)
                            <button wire:key="card-view-tab-{{ $tabKey }}" @class(['cv-list-item', 'is-active' => $selectedTabKey === $tabKey]) type="button" wire:click="selectTab('{{ $tabKey }}')">
                                <span class="cv-list-item__main">
                                    <span class="cv-list-item__title cv-tooltip" data-tooltip="{{ $tab['name'] }}" title="{{ $tab['name'] }}">{{ $tab['name'] }}</span>
                                    <span class="cv-list-item__key cv-tooltip" data-tooltip="{{ $tabKey }} · порядок {{ $tab['sort_order'] }}" title="{{ $tabKey }} · порядок {{ $tab['sort_order'] }}">{{ $tabKey }} · порядок {{ $tab['sort_order'] }}</span>
                                </span>
                                <span @class(['cv-badge', 'cv-badge--muted' => ! $tab['is_visible']])>
                                    {{ $tab['is_visible'] ? 'показана' : 'скрыта' }}
                                </span>
                            </button>
                        @empty
                            <p class="cv-settings__hint">Вкладки не найдены.</p>
                        @endforelse
                    </div>

                    @if ($selectedTabKey !== null && isset($tabRows[$selectedTabKey]))
                        <div class="cv-editor">
                            <h3>Настройки вкладки</h3>
                            <div class="cv-form-grid">
                                <input class="cv-input" type="text" wire:model.defer="tabRows.{{ $selectedTabKey }}.name">
                                <input class="cv-input cv-input--order" type="number" wire:model.defer="tabRows.{{ $selectedTabKey }}.sort_order">
                            </div>
                            <div class="cv-editor__actions">
                                <label class="cv-check">
                                    <input type="checkbox" wire:model.defer="tabRows.{{ $selectedTabKey }}.is_visible">
                                    Показывать вкладку
                                </label>
                                    <div class="cv-settings__inline">
                                    @unless ($tabRows[$selectedTabKey]['is_system'])
                                        <button class="ac-btn ac-btn--danger ac-btn--sm" type="button" wire:click="deleteTab('{{ $selectedTabKey }}')" onclick="return confirm('Удалить вкладку из вида карточки?')">
                                            Удалить
                                        </button>
                                    @endunless
                                    <button class="ac-btn ac-btn--primary ac-btn--sm" type="button" wire:click="saveTab('{{ $selectedTabKey }}')">
                                        Сохранить вкладку
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="cv-add">
                        <h3>Добавить вкладку</h3>
                        <div class="cv-add-row">
                            <input class="cv-input" type="text" placeholder="Название вкладки" wire:model.defer="newTab.name">
                            <input class="cv-input" type="text" placeholder="key_latin" wire:model.defer="newTab.tab_key">
                            <input class="cv-input cv-input--order" type="number" placeholder="Порядок" wire:model.defer="newTab.sort_order">
                            <button class="ac-btn ac-btn--primary ac-btn--sm" type="button" wire:click="addTab">
                                Добавить
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="cv-panel">
                <div class="cv-panel__head">
                    <h2>Секции</h2>
                </div>
                <div class="cv-panel__body">
                    <div class="cv-list">
                        @forelse ($sectionRows as $sectionKey => $section)
                            <button wire:key="card-view-section-{{ $sectionKey }}" @class(['cv-list-item', 'is-active' => $selectedSectionKey === $sectionKey]) type="button" wire:click="selectSection('{{ $sectionKey }}')">
                                <span class="cv-list-item__main">
                                    <span class="cv-list-item__title cv-tooltip" data-tooltip="{{ $section['name'] }}" title="{{ $section['name'] }}">{{ $section['name'] }}</span>
                                    <span class="cv-list-item__key cv-tooltip" data-tooltip="{{ $sectionKey }} · порядок {{ $section['sort_order'] }}" title="{{ $sectionKey }} · порядок {{ $section['sort_order'] }}">{{ $sectionKey }} · порядок {{ $section['sort_order'] }}</span>
                                </span>
                                <span @class(['cv-badge', 'cv-badge--muted' => ! $section['is_visible']])>
                                    {{ $section['is_visible'] ? 'показана' : 'скрыта' }}
                                </span>
                            </button>
                        @empty
                            <p class="cv-settings__hint">Выберите вкладку с секциями.</p>
                        @endforelse
                    </div>

                    @if ($selectedSectionKey !== null && isset($sectionRows[$selectedSectionKey]))
                        <div class="cv-editor">
                            <h3>Настройки секции</h3>
                            <div class="cv-form-grid">
                                <input class="cv-input" type="text" wire:model.defer="sectionRows.{{ $selectedSectionKey }}.name">
                                <input class="cv-input cv-input--order" type="number" wire:model.defer="sectionRows.{{ $selectedSectionKey }}.sort_order">
                            </div>
                            <div class="cv-editor__actions">
                                <div class="cv-settings__inline">
                                    <label class="cv-check">
                                        <input type="checkbox" wire:model.defer="sectionRows.{{ $selectedSectionKey }}.is_visible">
                                        Показывать
                                    </label>
                                    <label class="cv-check">
                                        <input type="checkbox" wire:model.defer="sectionRows.{{ $selectedSectionKey }}.is_collapsed_by_default">
                                        Сворачивать
                                    </label>
                                </div>
                                <button class="ac-btn ac-btn--primary ac-btn--sm" type="button" wire:click="saveSection('{{ $selectedSectionKey }}')">
                                    Сохранить
                                </button>
                                @unless ($sectionRows[$selectedSectionKey]['is_system'])
                                    <button class="ac-btn ac-btn--danger ac-btn--sm" type="button" wire:click="deleteSection('{{ $selectedSectionKey }}')" onclick="return confirm('Удалить секцию из вида карточки?')">
                                        Удалить
                                    </button>
                                @endunless
                            </div>
                        </div>
                    @endif

                    @if ($selectedTabKey !== null)
                        <div class="cv-add">
                            <h3>Добавить секцию</h3>
                            <div class="cv-add-row">
                                <input class="cv-input" type="text" placeholder="Название секции" wire:model.defer="newSection.name">
                                <input class="cv-input" type="text" placeholder="key_latin" wire:model.defer="newSection.section_key">
                                <input class="cv-input cv-input--order" type="number" placeholder="Порядок" wire:model.defer="newSection.sort_order">
                                <label class="cv-check">
                                    <input type="checkbox" wire:model.defer="newSection.is_collapsed_by_default">
                                    Сворачивать
                                </label>
                                <button class="ac-btn ac-btn--primary ac-btn--sm" type="button" wire:click="addSection">
                                    Добавить
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </section>

            <section class="cv-panel">
                <div class="cv-panel__head">
                    <h2>Элементы секции</h2>
                    @if ($itemRows !== [])
                        <button class="ac-btn ac-btn--primary ac-btn--sm" type="button" wire:click="saveSelectedItems">
                            Сохранить элементы
                        </button>
                    @endif
                </div>
                <div class="cv-panel__body">
                    @if ($itemRows !== [])
                        @php($moveSectionOptions = $this->moveSectionOptions())
                        <div class="cv-table">
                            <div class="cv-table-row cv-table-row--head cv-table-row--fields-only">
                                <div>Элемент</div>
                                <div>Порядок</div>
                                <div>Видимость</div>
                            </div>
                            @foreach ($itemRows as $itemKey => $item)
                                <div
                                    class="cv-table-row cv-table-row--fields-only"
                                    wire:key="card-view-item-{{ $selectedSectionKey }}-{{ $itemKey }}"
                                >
                                    <div class="cv-table-cell cv-table-name">
                                        <strong class="cv-tooltip" data-tooltip="{{ $item['name'] }}" title="{{ $item['name'] }}">{{ $item['name'] }}</strong>
                                        <span class="cv-tooltip" data-tooltip="{{ $item['item_key'] }}" title="{{ $item['item_key'] }}">{{ $item['item_key'] }}</span>
                                        <div class="cv-table-row-actions">
                                            @if ($moveSectionOptions !== [])
                                                <button class="ac-btn ac-btn--sm" type="button" wire:click="startMoveItem('{{ $itemKey }}')">
                                                    Переместить
                                                </button>
                                            @endif
                                            @unless ($item['is_system'])
                                                <button class="ac-btn ac-btn--danger ac-btn--sm" type="button" wire:click="deleteItem('{{ $itemKey }}')">
                                                    Удалить
                                                </button>
                                            @endunless
                                        </div>
                                    </div>
                                    <div class="cv-table-cell">
                                        <input class="cv-input cv-input--order" type="number" wire:model.defer="itemRows.{{ $itemKey }}.sort_order">
                                    </div>
                                    <div class="cv-table-cell">
                                        <label class="cv-check" title="Показывать элемент">
                                            <input
                                                type="checkbox"
                                                wire:model.defer="itemRows.{{ $itemKey }}.is_visible"
                                                aria-label="Показывать элемент «{{ $item['name'] }}»"
                                            >
                                        </label>
                                    </div>
                                </div>
                                @if ($movingItemKey === $itemKey && $moveSectionOptions !== [])
                                    <div class="cv-move-row" wire:key="card-view-move-{{ $selectedSectionKey }}-{{ $itemKey }}">
                                        <div class="cv-move-row__label cv-tooltip" data-tooltip="Переместить «{{ $item['name'] }}» в" title="Переместить «{{ $item['name'] }}» в">
                                            Переместить «{{ $item['name'] }}» в
                                        </div>
                                        <select class="cv-select" wire:model.defer="moveTargetSectionPath">
                                            @foreach ($moveSectionOptions as $sectionPath => $sectionLabel)
                                                <option value="{{ $sectionPath }}">{{ $sectionLabel }}</option>
                                            @endforeach
                                        </select>
                                        <button class="ac-btn ac-btn--primary ac-btn--sm" type="button" wire:click="moveItemToTarget('{{ $itemKey }}')">
                                            Переместить
                                        </button>
                                        <button class="ac-btn ac-btn--sm" type="button" wire:click="cancelMoveItem">
                                            Отмена
                                        </button>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <p class="cv-settings__hint">В выбранной секции нет элементов.</p>
                    @endif

                    @if ($selectedSectionKey !== null)
                        @php($fieldPickerOptions = collect($this->filteredFieldOptions())->map(fn ($label, $fieldId) => ['id' => (string) $fieldId, 'label' => $label])->values()->all())
                        <div class="cv-add">
                            <p class="cv-settings__hint">
                                Выберите поле ниже, чтобы добавить его в текущую секцию. Если поле уже есть в карточке, оно будет перенесено сюда.
                            </p>
                            <div class="cv-add-row">
                                <details
                                    class="cv-field-picker"
                                    data-field-picker
                                    @if ($fieldItemSearch !== '') open @endif
                                >
                                    <summary
                                        class="cv-field-picker__control"
                                        data-field-picker-control
                                        aria-haspopup="listbox"
                                    >
                                        <span
                                            class="cv-field-picker__value cv-tooltip"
                                            data-field-picker-value
                                            data-tooltip="{{ $this->selectedFieldItemLabel() }}"
                                            title="{{ $this->selectedFieldItemLabel() }}"
                                        >
                                            {{ $this->selectedFieldItemLabel() }}
                                        </span>
                                        <svg class="cv-field-picker__chevron" viewBox="0 0 16 16" aria-hidden="true">
                                            <path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </summary>

                                    <div class="cv-field-picker__panel" data-field-picker-panel>
                                        <input
                                            class="cv-input"
                                            type="search"
                                            placeholder="Поиск по названию или ключу"
                                            autocomplete="off"
                                            wire:model.live.debounce.150ms="fieldItemSearch"
                                            data-field-picker-search
                                        >
                                        <div class="cv-field-picker__options" role="listbox">
                                            @forelse ($fieldPickerOptions as $option)
                                                <button
                                                    @class([
                                                        'cv-field-picker__option',
                                                        'is-selected' => (string) ($newFieldItem['field_id'] ?? '') === (string) $option['id'],
                                                    ])
                                                    type="button"
                                                    role="option"
                                                    wire:click="selectFieldItem('{{ $option['id'] }}')"
                                                    data-field-id="{{ $option['id'] }}"
                                                    data-field-label="{{ $option['label'] }}"
                                                    title="{{ $option['label'] }}"
                                                >
                                                    {{ $option['label'] }}
                                                </button>
                                            @empty
                                                <p class="cv-field-picker__empty">В справочнике нет доступных полей</p>
                                            @endforelse
                                        </div>
                                    </div>
                                </details>
                                <button class="ac-btn ac-btn--primary ac-btn--sm" type="button" wire:click="addFieldItem">
                                    Добавить поле
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>

</x-filament-panels::page>
