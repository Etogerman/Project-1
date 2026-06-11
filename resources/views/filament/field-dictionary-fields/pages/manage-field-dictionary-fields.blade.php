<x-filament-panels::page>
    @php
        $selectedRow = $selectedFieldId !== null ? ($fieldRows[$selectedFieldId] ?? null) : null;
        $countLabel = count($fieldRows).' '.trans_choice('поле|поля|полей', count($fieldRows));

        $typeClass = function (?string $type): string {
            return match ($type) {
                \App\Models\FieldDictionaryField::TYPE_NUMBER => 'sp-type--number',
                \App\Models\FieldDictionaryField::TYPE_SELECT => 'sp-type--select',
                \App\Models\FieldDictionaryField::TYPE_BOOLEAN => 'sp-type--bool',
                \App\Models\FieldDictionaryField::TYPE_DATE => 'sp-type--date',
                \App\Models\FieldDictionaryField::TYPE_PHONE => 'sp-type--phone',
                \App\Models\FieldDictionaryField::TYPE_EMAIL => 'sp-type--email',
                default => 'sp-type--text',
            };
        };
    @endphp

    <style>
        .sp-page {
            display: grid;
            gap: var(--ac-sp-4);
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0 var(--ac-sp-4);
        }

        .fi-main.fi-admin-content-wide:has([data-role="field-dictionary-page"]) {
            width: 100%;
            max-width: none;
        }

        .sp-page > * {
            min-width: 0;
        }

        .sp-page.is-drawer-open {
            margin-left: 0;
            margin-right: 444px;
            max-width: none;
        }

        .sp-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: var(--ac-sp-4);
        }

        .sp-head__title {
            display: grid;
            gap: 3px;
            min-width: 0;
        }

        .sp-head__title h1 {
            margin: 0;
            color: var(--ac-text);
            font-size: var(--ac-fs-22);
            font-weight: var(--ac-fw-bold);
            line-height: 1.15;
        }

        .sp-head__title p {
            margin: 0;
            color: var(--ac-text-3);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-medium);
        }

        .sp-actions,
        .sp-toolbar,
        .sp-tabs,
        .sp-groups,
        .sp-row {
            display: flex;
            align-items: center;
        }

        .sp-actions {
            gap: var(--ac-sp-2);
        }

        .sp-btn {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 30px;
            border: 1px solid var(--ac-border-input);
            border-radius: var(--ac-radius-2);
            background: var(--ac-surface);
            color: var(--ac-text);
            cursor: pointer;
            font-size: var(--ac-fs-13);
            font-weight: var(--ac-fw-semi);
            padding: 0 12px;
        }

        .sp-btn:hover {
            background: var(--ac-surface-2);
        }

        .sp-btn--primary {
            border-color: var(--ac-accent);
            background: var(--ac-accent);
            color: var(--ac-text-inverse);
        }

        .sp-btn--danger {
            border-color: var(--ac-danger);
            color: var(--ac-danger);
        }

        .sp-btn:disabled {
            cursor: not-allowed;
            opacity: .48;
        }

        .sp-tabs {
            gap: 6px;
            border-bottom: 1px solid var(--ac-border);
            padding-bottom: var(--ac-sp-3);
        }

        .sp-tab {
            appearance: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 30px;
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-pill);
            background: var(--ac-surface);
            color: var(--ac-text-2);
            cursor: pointer;
            font-size: var(--ac-fs-13);
            font-weight: var(--ac-fw-semi);
            padding: 0 12px;
        }

        .sp-tab.is-active {
            border-color: var(--ac-accent);
            background: var(--ac-accent-soft);
            color: var(--ac-accent);
        }

        .sp-tab__count {
            color: var(--ac-text-3);
            font-size: var(--ac-fs-12);
        }

        .sp-toolbar {
            justify-content: space-between;
            gap: var(--ac-sp-3);
        }

        .sp-search {
            position: relative;
            flex: 0 0 320px;
            max-width: 420px;
        }

        .sp-search input {
            width: 100%;
            height: 32px;
            border: 1px solid var(--ac-border-input);
            border-radius: var(--ac-radius-2);
            background: var(--ac-surface);
            color: var(--ac-text);
            font-size: var(--ac-fs-13);
            outline: none;
            padding: 0 10px 0 30px;
        }

        .sp-search input:focus {
            border-color: var(--ac-accent);
            box-shadow: 0 0 0 3px var(--ac-accent-ring);
        }

        .sp-search svg {
            position: absolute;
            left: 9px;
            top: 50%;
            width: 14px;
            height: 14px;
            color: var(--ac-text-3);
            transform: translateY(-50%);
        }

        .sp-count {
            color: var(--ac-text-3);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-medium);
            white-space: nowrap;
        }

        .sp-count b {
            color: var(--ac-text);
            font-weight: var(--ac-fw-semi);
        }

        .sp-groups {
            gap: 4px;
            overflow-x: auto;
            border-radius: var(--ac-radius-3);
            background: var(--ac-surface-2);
            padding: 4px;
        }

        .sp-group-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 28px;
            border: 1px solid transparent;
            border-radius: var(--ac-radius-2);
            background: transparent;
            color: var(--ac-text-2);
            font-size: var(--ac-fs-13);
            font-weight: var(--ac-fw-medium);
            padding: 0 10px;
            white-space: nowrap;
        }

        .sp-group-chip.is-active {
            background: var(--ac-surface);
            color: var(--ac-text);
            font-weight: var(--ac-fw-semi);
        }

        .sp-group-chip span {
            border-radius: var(--ac-radius-pill);
            color: var(--ac-accent);
            background: var(--ac-accent-soft);
            font-size: var(--ac-fs-12);
            padding: 1px 6px;
        }

        .sp-table-section {
            width: 100%;
            max-width: 100%;
            overflow: hidden;
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-4);
            background: var(--ac-surface);
        }

        .sp-table-wrap {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            overflow: auto;
        }

        .sp-table {
            width: 100%;
            min-width: 1180px;
            border-collapse: collapse;
            color: var(--ac-text);
            font-size: var(--ac-fs-13);
            table-layout: fixed;
        }

        .sp-table th,
        .sp-table td {
            height: var(--ac-row-h-table);
            overflow: hidden;
            padding: 0 10px;
            text-align: left;
            vertical-align: middle;
            white-space: nowrap;
        }

        .sp-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            border-bottom: 1px solid var(--ac-border);
            background: var(--ac-surface);
            color: var(--ac-text-3);
            font-size: var(--ac-fs-11);
            font-weight: var(--ac-fw-semi);
            letter-spacing: .06em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .sp-table tbody td,
        .sp-table tfoot td {
            border-bottom: 1px solid var(--ac-border);
            background: var(--ac-surface);
        }

        .sp-table tbody tr:hover td {
            background: var(--ac-surface-2);
        }

        .sp-table tbody tr.is-selected td {
            background: var(--ac-accent-soft);
        }

        .sp-table tbody tr.is-selected td:first-child {
            box-shadow: inset 3px 0 0 var(--ac-accent);
        }

        .sp-col-drag { width: 30px; }
        .sp-col-key { width: 170px; }
        .sp-col-label { width: 190px; }
        .sp-col-type { width: 130px; }
        .sp-col-group { width: 120px; }
        .sp-col-source { width: 150px; }
        .sp-col-multiple { width: 120px; }
        .sp-col-system { width: 116px; }
        .sp-col-order { width: 88px; text-align: right !important; }
        .sp-col-menu { width: 88px; }

        .sp-drag,
        .sp-key-copy,
        .sp-kebab {
            opacity: 0;
            transition: opacity 100ms ease, background 100ms ease;
        }

        .sp-table tbody tr:hover .sp-drag,
        .sp-table tbody tr:hover .sp-key-copy,
        .sp-table tbody tr:hover .sp-kebab {
            opacity: 1;
        }

        .sp-drag,
        .sp-key-copy,
        .sp-kebab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border: 0;
            border-radius: var(--ac-radius-2);
            background: transparent;
            color: var(--ac-text-3);
        }

        .sp-key {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            width: 100%;
            min-width: 0;
            font-family: var(--ac-font-mono);
            color: var(--ac-text-2);
            font-size: var(--ac-fs-12);
        }

        .sp-key-text {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sp-label-main {
            display: inline-block;
            max-width: calc(100% - 18px);
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
            white-space: nowrap;
            color: var(--ac-text);
            font-weight: var(--ac-fw-medium);
        }

        .sp-label-lock {
            margin-left: 5px;
            color: var(--ac-text-3);
            vertical-align: -1px;
        }

        .sp-type {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            max-width: 100%;
            min-height: 22px;
            border-radius: var(--ac-radius-pill);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-medium);
            padding: 0 8px;
            white-space: nowrap;
        }

        .sp-type svg {
            width: 13px;
            height: 13px;
        }

        .sp-type--text { background: var(--ac-tag-gray-soft); color: var(--ac-tag-gray); }
        .sp-type--number { background: var(--ac-tag-blue-soft); color: var(--ac-tag-blue); }
        .sp-type--date { background: var(--ac-tag-purple-soft); color: var(--ac-tag-purple); }
        .sp-type--select { background: var(--ac-tag-yellow-soft); color: var(--ac-tag-yellow); }
        .sp-type--bool { background: var(--ac-tag-green-soft); color: var(--ac-tag-green); }
        .sp-type--phone { background: var(--ac-channel-telegram-soft); color: var(--ac-channel-telegram); }
        .sp-type--email { background: var(--ac-tag-blue-soft); color: var(--ac-tag-blue); }

        .sp-row-group,
        .sp-source,
        .sp-multiple {
            color: var(--ac-text-2);
            font-size: var(--ac-fs-12);
        }

        .sp-source,
        .sp-multiple {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .sp-source__dot,
        .sp-multiple__dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--ac-text-3);
        }

        .sp-multiple.is-on .sp-multiple__dot {
            background: var(--ac-accent);
        }

        .sp-source.is-on .sp-source__dot {
            background: var(--ac-accent);
        }

        .sp-badge {
            display: inline-flex;
            align-items: center;
            min-height: 22px;
            border-radius: var(--ac-radius-pill);
            color: var(--ac-text-2);
            background: var(--ac-surface-2);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-medium);
            padding: 0 8px;
        }

        .sp-badge--system {
            color: var(--ac-accent);
            background: var(--ac-accent-soft);
        }

        .sp-input,
        .sp-select,
        .sp-textarea {
            width: 100%;
            border: 1px solid var(--ac-border-input);
            border-radius: var(--ac-radius-2);
            background: var(--ac-surface);
            color: var(--ac-text);
            font: inherit;
            font-size: var(--ac-fs-13);
            outline: none;
        }

        .sp-input,
        .sp-select {
            height: 32px;
            padding: 0 9px;
        }

        .sp-textarea {
            min-height: 92px;
            padding: 8px 9px;
            resize: vertical;
            font-family: var(--ac-font-mono);
            white-space: pre;
        }

        .sp-input:focus,
        .sp-select:focus,
        .sp-textarea:focus {
            border-color: var(--ac-accent);
            box-shadow: 0 0 0 3px var(--ac-accent-ring);
        }

        .sp-input:disabled,
        .sp-select:disabled,
        .sp-textarea:disabled {
            cursor: not-allowed;
            color: var(--ac-text-3);
            background: var(--ac-surface-2);
        }

        .sp-check {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--ac-text-2);
            font-size: var(--ac-fs-13);
            font-weight: var(--ac-fw-medium);
        }

        .sp-check input {
            width: 16px;
            height: 16px;
            accent-color: var(--ac-accent);
        }

        .sp-drawer {
            position: fixed;
            top: var(--ac-topbar-h);
            right: 0;
            bottom: 0;
            width: 420px;
            z-index: var(--ac-z-popover);
            display: flex;
            flex-direction: column;
            border-left: 1px solid var(--ac-border);
            background: var(--ac-surface);
            box-shadow: var(--ac-shadow-lg);
        }

        .sp-drawer__head,
        .sp-drawer__foot {
            display: flex;
            align-items: center;
            gap: var(--ac-sp-3);
            padding: var(--ac-sp-4);
            border-bottom: 1px solid var(--ac-border);
        }

        .sp-drawer__title {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .sp-drawer__head .sp-kebab {
            margin-left: auto;
            opacity: 1;
        }

        .sp-drawer__title h2 {
            margin: 0;
            color: var(--ac-text);
            font-size: var(--ac-fs-15);
            font-weight: var(--ac-fw-semi);
        }

        .sp-drawer__body {
            display: grid;
            gap: var(--ac-sp-4);
            overflow: auto;
            padding: var(--ac-sp-4);
        }

        .sp-drawer__foot {
            margin-top: auto;
            justify-content: flex-end;
            border-top: 1px solid var(--ac-border);
            border-bottom: 0;
        }

        .sp-form-row {
            display: grid;
            gap: 6px;
        }

        .sp-form-row__label {
            color: var(--ac-text-2);
            font-size: var(--ac-fs-12);
            font-weight: var(--ac-fw-medium);
        }

        .sp-form-row__hint {
            color: var(--ac-text-3);
            font-size: var(--ac-fs-11);
        }

        .sp-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--ac-sp-3);
        }

        .sp-danger-zone {
            display: grid;
            gap: var(--ac-sp-2);
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-3);
            background: var(--ac-surface-2);
            padding: var(--ac-sp-3);
        }

        .sp-danger-zone p {
            margin: 0;
            color: var(--ac-text-3);
            font-size: var(--ac-fs-12);
        }

        @media (max-width: 1180px) {
            .sp-page.is-drawer-open {
                padding-right: 0;
            }

            .sp-page.is-drawer-open .sp-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .sp-drawer {
                width: min(420px, 100vw);
            }

            .sp-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .sp-search {
                flex-basis: auto;
                max-width: none;
            }
        }
    </style>

    <div class="sp-page {{ $selectedRow ? 'is-drawer-open' : '' }}" data-role="field-dictionary-page">
        <header class="sp-head">
            <div class="sp-head__title">
                <h1>Справочник полей</h1>
                <p>Настройки › Справочники › Поля</p>
            </div>

            <div class="sp-actions">
                <button type="button" class="sp-btn">Импорт CSV</button>
                <button type="button" class="sp-btn sp-btn--primary" onclick="document.querySelector('[data-role=new-field-key]')?.focus()">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 4v12M4 10h12"/></svg>
                    Новое поле
                </button>
            </div>
        </header>

        <nav class="sp-tabs" aria-label="Тип справочника">
            @foreach ($this->entityTabs() as $entity => $label)
                <button
                    type="button"
                    class="sp-tab {{ $activeEntity === $entity ? 'is-active' : '' }}"
                    wire:click="selectEntity('{{ $entity }}')"
                >
                    {{ $label === 'Контакты' ? 'Поля контакта' : 'Поля диалога' }}
                    <span class="sp-tab__count">{{ $activeEntity === $entity ? count($fieldRows) : '' }}</span>
                </button>
            @endforeach
            <button type="button" class="sp-tab" disabled>Глобальные переменные</button>
        </nav>

        <section class="sp-toolbar">
            <label class="sp-search">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="9" r="5.5"/><path d="m13 13 4 4"/></svg>
                <input type="search" placeholder="Поиск по ключу или названию" />
            </label>

            <div class="sp-count">
                <b>{{ $countLabel }}</b> · системные защищены · множественность выбирается при создании
            </div>
        </section>

        <nav class="sp-groups" aria-label="Группы полей">
            <span class="sp-group-chip is-active">Все <span>{{ count($fieldRows) }}</span></span>
            <span class="sp-group-chip">Основное</span>
            <span class="sp-group-chip">Адрес</span>
            <span class="sp-group-chip">Контакты</span>
            <span class="sp-group-chip">Системные</span>
        </nav>

        <section class="sp-table-section">
            <div class="sp-table-wrap">
                <table class="sp-table">
                    <colgroup>
                        <col class="sp-col-drag">
                        <col class="sp-col-key">
                        <col class="sp-col-label">
                        <col class="sp-col-type">
                        <col class="sp-col-group">
                        <col class="sp-col-source">
                        <col class="sp-col-multiple">
                        <col class="sp-col-system">
                        <col class="sp-col-order">
                        <col class="sp-col-menu">
                    </colgroup>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Ключ</th>
                            <th>Название</th>
                            <th>Тип</th>
                            <th>Группа</th>
                            <th>Источник</th>
                            <th>Множ.</th>
                            <th>Системное</th>
                            <th class="sp-col-order">Порядок</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fieldRows as $fieldId => $row)
                            <tr
                                wire:key="field-row-{{ $fieldId }}"
                                wire:click="selectField({{ $fieldId }})"
                                class="{{ $selectedFieldId === (int) $fieldId ? 'is-selected' : '' }}"
                            >
                                <td>
                                    <span class="sp-drag" aria-hidden="true">
                                        <svg viewBox="0 0 16 16" fill="currentColor"><circle cx="6" cy="4" r="1"/><circle cx="6" cy="8" r="1"/><circle cx="6" cy="12" r="1"/><circle cx="10" cy="4" r="1"/><circle cx="10" cy="8" r="1"/><circle cx="10" cy="12" r="1"/></svg>
                                    </span>
                                </td>
                                <td>
                                    <span class="sp-key">
                                        <span class="sp-key-text">{{ $row['field_key'] }}</span>
                                        <span class="sp-key-copy" aria-hidden="true">
                                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="5" y="5" width="8" height="9" rx="1.5"/><path d="M3 11V3.5C3 2.7 3.7 2 4.5 2H11"/></svg>
                                        </span>
                                    </span>
                                </td>
                                <td>
                                    <span class="sp-label-main">{{ $row['name'] }}</span>
                                    @if ($row['is_system'])
                                        <svg class="sp-label-lock" width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-label="Системное поле"><rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5 7V5a3 3 0 0 1 6 0v2"/></svg>
                                    @endif
                                </td>
                                <td>
                                    <span class="sp-type {{ $typeClass($row['type']) }}">
                                        {{ $row['type_label'] }}
                                    </span>
                                </td>
                                <td><span class="sp-row-group">{{ $row['group_label'] }}</span></td>
                                <td>
                                    <span class="sp-source {{ ($row['source_field_key'] ?? '') !== '' ? 'is-on' : '' }}" title="{{ ($row['source_field_label'] ?? '') !== '' ? $row['source_field_label'] : $row['source_label'] }}">
                                        <span class="sp-source__dot"></span>
                                        {{ $row['source_label'] }}
                                    </span>
                                </td>
                                <td>
                                    <span class="sp-multiple {{ $row['is_multiple'] ? 'is-on' : '' }}">
                                        <span class="sp-multiple__dot"></span>
                                        {{ $row['is_multiple'] ? 'Да' : 'Нет' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="sp-badge {{ $row['is_system'] ? 'sp-badge--system' : '' }}">
                                        {{ $row['is_system'] ? 'Да' : 'Нет' }}
                                    </span>
                                </td>
                                <td class="sp-col-order">{{ $row['sort_order'] }}</td>
                                <td>
                                    <button type="button" class="sp-kebab" aria-label="Открыть поле">
                                        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><circle cx="10" cy="5" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="10" cy="15" r="1.5"/></svg>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td></td>
                            <td><input class="sp-input" data-role="new-field-key" type="text" placeholder="my_new_field" wire:model.defer="newField.field_key" /></td>
                            <td><input class="sp-input" type="text" placeholder="Моё новое поле" wire:model.defer="newField.name" /></td>
                            <td>
                                <select class="sp-select" wire:model.live="newField.type">
                                    @foreach ($this->typeOptions() as $type => $label)
                                        <option value="{{ $type }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><span class="sp-row-group">Пользовательское</span></td>
                            <td>
                                <select class="sp-select" wire:model.defer="newField.source_field_key">
                                    <option value="">Нет</option>
                                    @foreach ($this->sourceOptions() as $sourceKey => $sourceLabel)
                                        <option value="{{ $sourceKey }}">{{ $sourceLabel }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <label class="sp-check">
                                    <input type="checkbox" wire:model.defer="newField.is_multiple" />
                                    Да
                                </label>
                            </td>
                            <td><span class="sp-badge">Нет</span></td>
                            <td><input class="sp-input" type="number" step="1" wire:model.defer="newField.sort_order" /></td>
                            <td><button type="button" class="sp-btn sp-btn--primary" wire:click="createField">Добавить</button></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        @if ($selectedRow)
            <aside class="sp-drawer" role="dialog" aria-label="Поле {{ $selectedRow['field_key'] }}">
            <div class="sp-drawer__head">
                <div class="sp-drawer__title">
                    <h2>{{ $selectedRow['name'] }}</h2>
                    <span class="sp-key"><span class="sp-key-text">{{ $selectedRow['field_key'] }}</span></span>
                </div>
                <button type="button" class="sp-kebab" wire:click="closeFieldDrawer" aria-label="Закрыть панель">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 5 10 10M15 5 5 15"/></svg>
                </button>
            </div>

                <div class="sp-drawer__body">
                    <div class="sp-form-row">
                        <label class="sp-form-row__label">Название поля</label>
                        <input class="sp-input" type="text" wire:model.defer="fieldRows.{{ $selectedFieldId }}.name" />
                        <span class="sp-form-row__hint">Так поле подписано в карточках, таблицах и настройках.</span>
                    </div>

                    <div class="sp-form-row">
                        <label class="sp-form-row__label">Ключ</label>
                        <input class="sp-input" type="text" wire:model.defer="fieldRows.{{ $selectedFieldId }}.field_key" disabled />
                        <span class="sp-form-row__hint">Ключ нельзя менять после создания поля.</span>
                    </div>

                    <div class="sp-form-grid">
                        <div class="sp-form-row">
                            <label class="sp-form-row__label">Тип</label>
                            <select class="sp-select" wire:model.live="fieldRows.{{ $selectedFieldId }}.type" @disabled($selectedRow['is_system'])>
                                @foreach ($this->typeOptions() as $type => $label)
                                    <option value="{{ $type }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="sp-form-row">
                            <label class="sp-form-row__label">Порядок</label>
                            <input class="sp-input" type="number" step="1" wire:model.defer="fieldRows.{{ $selectedFieldId }}.sort_order" />
                        </div>
                    </div>

                    <div class="sp-form-row">
                        <label class="sp-form-row__label">Множественное поле</label>
                        <label class="sp-check">
                            <input type="checkbox" wire:model.defer="fieldRows.{{ $selectedFieldId }}.is_multiple" disabled />
                            {{ $selectedRow['is_multiple'] ? 'Да' : 'Нет' }}
                        </label>
                        <span class="sp-form-row__hint">Выбирается только при создании поля. После записи данных менять этот признак нельзя.</span>
                    </div>

                    <div class="sp-form-row">
                        <label class="sp-form-row__label">Источник</label>
                        <select class="sp-select" wire:model.defer="fieldRows.{{ $selectedFieldId }}.source_field_key" @disabled($selectedRow['is_system'])>
                            <option value="">Нет</option>
                            @foreach ($this->sourceOptions((int) $selectedFieldId) as $sourceKey => $sourceLabel)
                                <option value="{{ $sourceKey }}">{{ $sourceLabel }}</option>
                            @endforeach
                        </select>
                        <span class="sp-form-row__hint">Если включено, система хранит, откуда известно значение поля.</span>
                    </div>

                    @if (($selectedRow['type'] ?? null) === \App\Models\FieldDictionaryField::TYPE_SELECT)
                        <div class="sp-form-row">
                            <label class="sp-form-row__label">Значения списка</label>
                            <textarea class="sp-textarea" wire:model.defer="fieldRows.{{ $selectedFieldId }}.options_text"></textarea>
                            <span class="sp-form-row__hint">Формат: код = подпись. Код системных значений менять нельзя.</span>
                        </div>
                    @endif

                    <div class="sp-danger-zone">
                        <strong>Удаление поля</strong>
                        <p>Системные поля удалить нельзя. Если поле используется как источник, сначала нужно убрать связь.</p>
                        <button
                            type="button"
                            class="sp-btn sp-btn--danger"
                            wire:click="deleteField({{ $selectedFieldId }})"
                            @disabled($selectedRow['is_system'] || $selectedRow['is_referenced_as_source'])
                            onclick="return confirm('Удалить поле из справочника?')"
                        >
                            Удалить поле
                        </button>
                    </div>
                </div>

                <div class="sp-drawer__foot">
                    <button type="button" class="sp-btn" wire:click="closeFieldDrawer">Отмена</button>
                    <button type="button" class="sp-btn sp-btn--primary" wire:click="saveField({{ $selectedFieldId }})">Сохранить</button>
                </div>
            </aside>
        @endif
    </div>
</x-filament-panels::page>
