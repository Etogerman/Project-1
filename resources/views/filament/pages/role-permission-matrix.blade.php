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

        .ac-role-permission-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.78rem;
            color: var(--ac-text-soft);
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
                        Страница показывает только текущие права, которые уже существуют в рабочем контуре панели.
                        Изменение прав через интерфейс на этом шаге не поддерживается.
                    </p>
                </div>

                <div class="ac-button-group">
                    @foreach ($roles as $role)
                        <span class="ac-pill" data-tone="{{ $role['tone'] }}">
                            {{ $role['label'] }}
                        </span>
                    @endforeach
                </div>
            </div>
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
                                class="ac-role-permission-row"
                            >
                                <div class="ac-role-permission-row__header">
                                    <p class="ac-role-permission-code">{{ $action['code'] }}</p>
                                    <p class="ac-list-card__title">{{ $action['label'] }}</p>
                                    <p class="ac-list-card__body">{{ $action['description'] }}</p>
                                </div>

                                <div class="ac-role-permission-row__states">
                                    @foreach ($roles as $role)
                                        @php($state = $action['states'][$role['key']])

                                        <div class="ac-role-permission-state">
                                            <p class="ac-meta__label">{{ $role['label'] }}</p>
                                            <div class="ac-button-group">
                                                <span class="ac-pill" data-tone="{{ $state['tone'] }}">
                                                    {{ $state['label'] }}
                                                </span>
                                            </div>
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
