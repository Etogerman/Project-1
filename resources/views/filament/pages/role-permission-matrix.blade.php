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
            min-width: 900px;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-md);
            background: var(--ac-surface-strong);
        }

        .ac-role-permission-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 0.95rem 1rem;
            border-bottom: 1px solid var(--ac-border);
            background: color-mix(in srgb, var(--ac-surface-muted) 90%, white);
            text-align: left;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--ac-text-soft);
        }

        .ac-role-permission-table tbody td {
            padding: 0;
            border-bottom: 1px solid var(--ac-border);
            vertical-align: top;
        }

        .ac-role-permission-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .ac-role-permission-entity {
            width: 14rem;
            padding: 1.15rem 1rem;
            background: color-mix(in srgb, var(--ac-surface-muted) 85%, white);
        }

        .ac-role-permission-entity__label {
            display: block;
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: var(--ac-text);
        }

        .ac-role-permission-entity__description {
            margin: 0.55rem 0 0;
            font-size: 0.83rem;
            line-height: 1.45;
            color: var(--ac-text-soft);
        }

        .ac-role-permission-action {
            padding: 1rem;
            background: var(--ac-surface-strong);
        }

        .ac-role-permission-action--preparatory {
            background: linear-gradient(180deg, color-mix(in srgb, var(--ac-warning-50) 70%, white) 0%, var(--ac-surface-strong) 100%);
        }

        .ac-role-permission-code {
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.78rem;
            color: var(--ac-text-soft);
        }

        .ac-role-permission-action__title {
            margin: 0.45rem 0 0;
            font-size: 1rem;
            font-weight: 700;
            color: var(--ac-text);
        }

        .ac-role-permission-action__description {
            margin: 0.55rem 0 0;
            font-size: 0.9rem;
            line-height: 1.5;
            color: var(--ac-text-soft);
        }

        .ac-role-permission-action__meta {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.8rem;
        }

        .ac-role-permission-state {
            min-width: 0;
            padding: 0.9rem 1rem;
            background: color-mix(in srgb, var(--ac-surface-muted) 92%, var(--ac-surface-strong));
        }

        .ac-role-permission-state[data-status="enabled"] {
            background: color-mix(in srgb, var(--ac-success-50) 84%, white);
        }

        .ac-role-permission-state[data-status="disabled"] {
            background: color-mix(in srgb, var(--ac-danger-50) 68%, white);
        }

        .ac-role-permission-state[data-status="missing"] {
            background: color-mix(in srgb, var(--ac-warning-50) 82%, white);
        }

        .ac-role-permission-state[data-editable="false"] {
            background-image: linear-gradient(135deg, color-mix(in srgb, white 70%, transparent) 0%, transparent 100%);
        }

        .ac-role-permission-state__toggle {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-wrap: wrap;
        }

        .ac-role-permission-checkbox {
            width: 1rem;
            height: 1rem;
            accent-color: color-mix(in srgb, var(--ac-success-500) 80%, white);
        }

        .ac-role-permission-lock {
            margin: 0.55rem 0 0;
            font-size: 0.78rem;
            line-height: 1.4;
            color: var(--ac-text-soft);
        }

        @media (max-width: 960px) {
            .ac-role-permission-table {
                min-width: 760px;
            }

            .ac-role-permission-entity {
                width: 11rem;
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
                <table
                    class="ac-role-permission-table"
                    data-role="role-permission-matrix-table"
                >
                    <thead>
                        <tr>
                            <th>Сущность</th>
                            <th>Право</th>
                            @foreach ($roles as $role)
                                <th>{{ $role['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    @foreach ($groups as $group)
                        <tbody data-role="permission-group-{{ $group['key'] }}">
                            @foreach ($group['actions'] as $action)
                                <tr
                                    data-role="permission-row"
                                    data-code="{{ $action['code'] }}"
                                    data-runtime="{{ $action['isPreparatory'] ? 'preparatory' : 'active' }}"
                                >
                                    @if ($loop->first)
                                        <td
                                            class="ac-role-permission-entity"
                                            data-role="permission-entity"
                                            rowspan="{{ count($group['actions']) }}"
                                        >
                                            <p class="ac-role-permission-entity__label">{{ $group['label'] }}</p>
                                            <p class="ac-role-permission-entity__description">{{ $group['description'] }}</p>
                                        </td>
                                    @endif

                                    <td class="ac-role-permission-action{{ $action['isPreparatory'] ? ' ac-role-permission-action--preparatory' : '' }}">
                                        <p class="ac-role-permission-code">{{ $action['code'] }}</p>
                                        <p class="ac-role-permission-action__title">{{ $action['label'] }}</p>
                                        <p class="ac-role-permission-action__description">{{ $action['description'] }}</p>

                                        @if ($action['isPreparatory'])
                                            <div class="ac-role-permission-action__meta" data-role="permission-row-note">
                                                <span class="ac-pill" data-tone="warning">
                                                    {{ $action['preparatoryLabel'] }}
                                                </span>
                                            </div>
                                        @endif
                                    </td>

                                    @foreach ($roles as $role)
                                        @php($state = $action['states'][$role['key']])

                                        <td
                                            class="ac-role-permission-state"
                                            data-role="permission-state"
                                            data-code="{{ $action['code'] }}"
                                            data-role-key="{{ $role['key'] }}"
                                            data-status="{{ $state['status'] }}"
                                            data-editable="{{ $state['editable'] ? 'true' : 'false' }}"
                                            data-state-key="{{ $action['code'] }}:{{ $role['key'] }}:{{ $state['status'] }}"
                                        >
                                            <label class="ac-role-permission-state__toggle">
                                                <input
                                                    type="checkbox"
                                                    class="ac-role-permission-checkbox"
                                                    data-role="permission-checkbox"
                                                    wire:model.live="permissionState.{{ $role['key'] }}.{{ $action['code'] }}"
                                                    @disabled(! $state['editable'])
                                                >
                                                <span class="ac-pill" data-tone="{{ $state['tone'] }}">
                                                    {{ $state['label'] }}
                                                </span>
                                            </label>

                                            @if (filled($state['lockReason']))
                                                <p class="ac-role-permission-lock">{{ $state['lockReason'] }}</p>
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
