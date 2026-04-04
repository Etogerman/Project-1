<x-filament-panels::page>
    <style>
        .ac-role-permission-page {
            display: grid;
            gap: 1rem;
        }

        .ac-role-permission-grid {
            display: grid;
            gap: 1rem;
        }

        .ac-role-permission-group {
            display: grid;
            gap: 1rem;
        }

        .ac-role-permission-list {
            display: grid;
            gap: 0.85rem;
        }

        .ac-role-permission-row {
            display: grid;
            gap: 0.9rem;
            padding: 1rem;
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-md);
            background: var(--ac-surface-strong);
        }

        .ac-role-permission-row__header {
            display: grid;
            gap: 0.55rem;
        }

        .ac-role-permission-row--preparatory {
            background: linear-gradient(180deg, color-mix(in srgb, var(--ac-warning-50) 72%, white) 0%, var(--ac-surface-strong) 100%);
        }

        .ac-role-permission-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.78rem;
            color: var(--ac-text-soft);
        }

        .ac-role-permission-note {
            display: grid;
            gap: 0.45rem;
            padding: 0.8rem;
            border: 1px dashed color-mix(in srgb, var(--ac-warning-500) 45%, var(--ac-border));
            border-radius: var(--ac-radius-sm);
            background: color-mix(in srgb, var(--ac-warning-50) 78%, white);
        }

        .ac-role-permission-row__states {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .ac-role-permission-state {
            display: grid;
            gap: 0.35rem;
            padding: 0.8rem;
            border: 1px solid var(--ac-border);
            border-radius: var(--ac-radius-sm);
            background: linear-gradient(180deg, color-mix(in srgb, var(--ac-surface-muted) 92%, var(--ac-surface-strong)) 0%, var(--ac-surface-strong) 100%);
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
            font-size: 0.78rem;
            color: var(--ac-text-soft);
        }

        @media (min-width: 1100px) {
            .ac-role-permission-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .ac-role-permission-row__states {
                grid-template-columns: minmax(0, 1fr);
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

        <div class="ac-role-permission-grid">
            @foreach ($groups as $group)
                <section
                    data-role="permission-group-{{ $group['key'] }}"
                    class="ac-list-card ac-list-card--soft ac-role-permission-group"
                >
                    <div class="ac-surface__title-group">
                        <p class="ac-surface__eyebrow">Права</p>
                        <h3 class="ac-surface__title">{{ $group['label'] }}</h3>
                        <p class="ac-surface__subtitle">{{ $group['description'] }}</p>
                    </div>

                    <div class="ac-role-permission-list ac-list-card__section">
                        @foreach ($group['actions'] as $action)
                            <article
                                data-role="permission-row"
                                data-code="{{ $action['code'] }}"
                                data-runtime="{{ $action['isPreparatory'] ? 'preparatory' : 'active' }}"
                                class="ac-role-permission-row{{ $action['isPreparatory'] ? ' ac-role-permission-row--preparatory' : '' }}"
                            >
                                <div class="ac-role-permission-row__header">
                                    <p class="ac-role-permission-code">{{ $action['code'] }}</p>
                                    <p class="ac-list-card__title">{{ $action['label'] }}</p>
                                    <p class="ac-list-card__body">{{ $action['description'] }}</p>
                                </div>

                                @if ($action['isPreparatory'])
                                    <div class="ac-role-permission-note" data-role="permission-row-note">
                                        <div class="ac-button-group">
                                            <span class="ac-pill" data-tone="warning">
                                                {{ $action['preparatoryLabel'] }}
                                            </span>
                                        </div>
                                        <p class="ac-list-card__body">{{ $action['preparatoryDescription'] }}</p>
                                    </div>
                                @endif

                                <div class="ac-role-permission-row__states">
                                    @foreach ($roles as $role)
                                        @php($state = $action['states'][$role['key']])

                                        <div
                                            class="ac-role-permission-state"
                                            data-role="permission-state"
                                            data-code="{{ $action['code'] }}"
                                            data-role-key="{{ $role['key'] }}"
                                            data-status="{{ $state['status'] }}"
                                            data-editable="{{ $state['editable'] ? 'true' : 'false' }}"
                                            data-state-key="{{ $action['code'] }}:{{ $role['key'] }}:{{ $state['status'] }}"
                                        >
                                            <p class="ac-meta__label">{{ $role['label'] }}</p>
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
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
