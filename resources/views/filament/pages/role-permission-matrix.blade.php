<x-filament-panels::page>
    <style>
        .ac-role-permission-page {
            display: grid;
            gap: 1rem;
        }

        .ac-role-permission-note {
            display: grid;
            gap: 0.45rem;
            padding: 0.8rem;
            border: 1px dashed color-mix(in srgb, var(--ac-warning-500) 45%, var(--ac-border));
            border-radius: var(--ac-radius-sm);
            background: color-mix(in srgb, var(--ac-warning-50) 78%, white);
        }

        .ac-role-permission-table-shell {
            display: grid;
            gap: 1rem;
        }

        .ac-role-permission-table-wrap {
            overflow-x: auto;
        }

        .ac-role-permission-table {
            width: 100%;
            min-width: 760px;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-md);
            background: var(--ac-surface-strong);
            table-layout: fixed;
        }

        .ac-role-permission-table col:nth-child(1) {
            width: auto;
        }

        .ac-role-permission-table col:nth-child(n + 2) {
            width: 12rem;
        }

        .ac-role-permission-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--ac-border);
            background: color-mix(in srgb, var(--ac-surface-muted) 92%, white);
            text-align: left;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--ac-text-soft);
        }

        .ac-role-permission-table thead th:not(:first-child) {
            text-align: center;
        }

        .ac-role-permission-table tbody td,
        .ac-role-permission-table tbody th {
            border-bottom: 1px solid var(--ac-border);
            vertical-align: top;
        }

        .ac-role-permission-table tbody:last-of-type tr:last-child td,
        .ac-role-permission-table tbody:last-of-type tr:last-child th {
            border-bottom: 0;
        }

        .ac-role-permission-group-row th {
            padding: 1rem;
            background: color-mix(in srgb, var(--ac-surface-muted) 88%, white);
            text-align: left;
        }

        .ac-role-permission-group__label {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: var(--ac-text);
        }

        .ac-role-permission-group__description {
            margin: 0.45rem 0 0;
            max-width: 52rem;
            font-size: 0.86rem;
            line-height: 1.45;
            color: var(--ac-text-soft);
        }

        .ac-role-permission-action {
            padding: 0.9rem 1rem;
            background: var(--ac-surface-strong);
        }

        .ac-role-permission-action--preparatory {
            background: linear-gradient(180deg, color-mix(in srgb, var(--ac-warning-50) 48%, white) 0%, var(--ac-surface-strong) 100%);
        }

        .ac-role-permission-code {
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.76rem;
            color: var(--ac-text-soft);
        }

        .ac-role-permission-action__topline {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.3rem;
        }

        .ac-role-permission-action__title {
            margin: 0;
            font-size: 0.98rem;
            font-weight: 700;
            color: var(--ac-text);
        }

        .ac-role-permission-action__description {
            margin: 0.45rem 0 0;
            font-size: 0.88rem;
            line-height: 1.45;
            color: var(--ac-text-soft);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .ac-role-permission-state {
            position: relative;
            padding: 0.65rem;
            text-align: center;
            vertical-align: middle;
            background: color-mix(in srgb, var(--ac-surface-muted) 94%, var(--ac-surface-strong));
        }

        .ac-role-permission-state[data-status="enabled"] {
            background: color-mix(in srgb, var(--ac-success-50) 88%, white);
        }

        .ac-role-permission-state[data-status="disabled"] {
            background: color-mix(in srgb, var(--ac-danger-50) 62%, white);
        }

        .ac-role-permission-state[data-status="missing"] {
            background: color-mix(in srgb, var(--ac-warning-50) 82%, white);
        }

        .ac-role-permission-state[data-editable="false"] {
            background-image: linear-gradient(135deg, color-mix(in srgb, white 62%, transparent) 0%, transparent 100%);
        }

        .ac-role-permission-state__toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2rem;
        }

        .ac-role-permission-checkbox {
            width: 1.1rem;
            height: 1.1rem;
            accent-color: color-mix(in srgb, var(--ac-success-600) 84%, white);
        }

        .ac-role-permission-indicator {
            position: absolute;
            top: 0.35rem;
            right: 0.45rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1rem;
            height: 1rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            line-height: 1;
        }

        .ac-role-permission-indicator--locked {
            background: color-mix(in srgb, var(--ac-warning-100) 84%, white);
            color: color-mix(in srgb, var(--ac-warning-700) 82%, black);
        }

        .ac-role-permission-indicator--missing {
            background: color-mix(in srgb, var(--ac-danger-100) 84%, white);
            color: color-mix(in srgb, var(--ac-danger-700) 82%, black);
        }

        @media (max-width: 960px) {
            .ac-role-permission-table {
                min-width: 680px;
            }

            .ac-role-permission-table col:nth-child(n + 2) {
                width: 8.5rem;
            }
        }
    </style>

    <div data-role="role-permission-matrix-page" class="ac-role-permission-page">
        <section class="ac-surface ac-surface--hero">
            <div class="ac-surface__header ac-surface__header--centered">
                <div class="ac-surface__title-group">
                    <p class="ac-surface__eyebrow">Команда</p>
                    <h3 class="ac-surface__title">Матрица ролей и прав</h3>
                    <p class="ac-surface__subtitle">
                        Страница показывает конфигурацию крупной матрицы прав из базы данных. На текущем этапе эти
                        значения ещё не управляют реальным доступом: рабочие проверки доступа по-прежнему определяются
                        кодом приложения.
                    </p>
                </div>

                <div class="ac-button-group">
                    <x-filament::button type="button" wire:click="reloadPermissionMatrix" color="gray">
                        Сбросить несохранённые изменения
                    </x-filament::button>
                    <x-filament::button type="button" wire:click="savePermissionMatrix">
                        Сохранить матрицу
                    </x-filament::button>
                    @foreach ($roles as $role)
                        <span class="ac-pill" data-tone="{{ $role['tone'] }}">
                            {{ $role['label'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="ac-role-permission-note" data-role="database-readonly-note">
            <div class="ac-button-group">
                <span class="ac-pill" data-tone="info">Конфигурация из базы</span>
                <span class="ac-pill" data-tone="warning">Runtime не переключён</span>
            </div>
            <p class="ac-list-card__body">
                Матрица читает и сохраняет значения в таблицу <code>role_permissions</code>, но пока не применяет их
                к реальной авторизации. Подготовительные строки отмечены отдельно и могут иметь значения в базе,
                даже если runtime ещё не умеет их использовать.
            </p>
        </section>

        <section class="ac-surface ac-surface--soft ac-role-permission-table-shell">
            <div class="ac-role-permission-table-wrap">
                <table class="ac-role-permission-table" data-role="role-permission-matrix-table">
                    <colgroup>
                        <col>
                        @foreach ($roles as $role)
                            <col>
                        @endforeach
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Право</th>
                            @foreach ($roles as $role)
                                <th>{{ $role['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    @foreach ($groups as $group)
                        <tbody data-role="permission-group-{{ $group['key'] }}">
                            <tr class="ac-role-permission-group-row">
                                <th colspan="{{ 1 + count($roles) }}" data-role="permission-entity">
                                    <p class="ac-role-permission-group__label">{{ $group['label'] }}</p>
                                    <p class="ac-role-permission-group__description">{{ $group['description'] }}</p>
                                </th>
                            </tr>

                            @foreach ($group['actions'] as $action)
                                <tr
                                    data-role="permission-row"
                                    data-code="{{ $action['code'] }}"
                                    data-runtime="{{ $action['isPreparatory'] ? 'preparatory' : 'active' }}"
                                >
                                    <td class="ac-role-permission-action{{ $action['isPreparatory'] ? ' ac-role-permission-action--preparatory' : '' }}">
                                        <p class="ac-role-permission-code">{{ $action['code'] }}</p>
                                        <div class="ac-role-permission-action__topline">
                                            <p class="ac-role-permission-action__title">{{ $action['label'] }}</p>

                                            @if ($action['isPreparatory'])
                                                <span class="ac-pill" data-tone="warning" data-role="permission-row-note">
                                                    {{ $action['preparatoryLabel'] }}
                                                </span>
                                            @endif
                                        </div>
                                        <p
                                            class="ac-role-permission-action__description"
                                            title="{{ $action['description'] }}"
                                        >
                                            {{ $action['description'] }}
                                        </p>
                                    </td>

                                    @foreach ($roles as $role)
                                        @php($state = $action['states'][$role['key']])
                                        @php($stateTitle = $state['status'] === 'missing'
                                            ? 'Нет записи в role_permissions'
                                            : (filled($state['lockReason']) ? $state['lockReason'] : null))

                                        <td
                                            class="ac-role-permission-state"
                                            data-role="permission-state"
                                            data-code="{{ $action['code'] }}"
                                            data-role-key="{{ $role['key'] }}"
                                            data-status="{{ $state['status'] }}"
                                            data-editable="{{ $state['editable'] ? 'true' : 'false' }}"
                                            data-state-key="{{ $action['code'] }}:{{ $role['key'] }}:{{ $state['status'] }}"
                                            @if (filled($stateTitle))
                                                title="{{ $stateTitle }}"
                                            @endif
                                        >
                                            <label class="ac-role-permission-state__toggle">
                                                <input
                                                    type="checkbox"
                                                    class="ac-role-permission-checkbox"
                                                    data-role="permission-checkbox"
                                                    wire:model.live="permissionState.{{ $role['key'] }}.{{ $action['code'] }}"
                                                    @disabled(! $state['editable'])
                                                >
                                            </label>

                                            @if ($state['status'] === 'missing')
                                                <span
                                                    class="ac-role-permission-indicator ac-role-permission-indicator--missing"
                                                    title="Нет записи в role_permissions"
                                                >
                                                    !
                                                </span>
                                            @elseif (filled($state['lockReason']))
                                                <span
                                                    class="ac-role-permission-indicator ac-role-permission-indicator--locked"
                                                    title="{{ $state['lockReason'] }}"
                                                >
                                                    *
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
