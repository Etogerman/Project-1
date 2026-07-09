<x-filament-panels::page>
    @php
        $dialogFieldLabels = $field_labels ?? [];
        $dialogFieldLabel = static function (string $fieldKey, string $fallback) use ($dialogFieldLabels): string {
            $label = trim((string) ($dialogFieldLabels[$fieldKey] ?? ''));

            return $label !== '' ? $label : $fallback;
        };
    @endphp

    <div
        data-role="dialog-kanban-page"
        class="ac-panel-stack ac-panel-stack--relaxed"
    >
        <section class="ac-kanban-hero">
            <div class="ac-kanban-hero__top">
                <div class="ac-kanban-hero__actions" role="toolbar" aria-label="Управление канбаном">
                    <div class="ac-kanban-toolbar__search">
                        <label for="kanban-search" class="sr-only">Поиск</label>
                        <div class="ac-kanban-search-control">
                            <input
                                id="kanban-search"
                                type="search"
                                wire:model.live.debounce.350ms="search"
                                class="ac-input"
                                placeholder="Поиск"
                            >

                            @if (trim($search) !== '')
                                <button
                                    type="button"
                                    wire:click="clearSearch"
                                    class="ac-button ac-button--secondary ac-button--compact ac-kanban-search-control__clear"
                                >
                                    Очистить
                                </button>
                            @endif
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="toggleFiltersPanel"
                        @class([
                            'ac-button',
                            'ac-button--secondary' => ! $filtersPanelOpen,
                            'ac-button--warning-soft' => $filtersPanelOpen,
                        ])
                    >
                        Фильтр
                        @if ($filter_state['active_count'] > 0)
                            <span class="ac-kanban-hero__badge">{{ $filter_state['active_count'] }}</span>
                        @endif
                    </button>

                    <span class="ac-kanban-view-switch" role="group" aria-label="Вид диалогов">
                        <span class="ac-kanban-view-switch__item is-active">Канбан</span>
                        <a
                            href="{{ $table_url }}"
                            class="ac-kanban-view-switch__item"
                            data-ac-dialogs-view-link
                        >
                            Таблица
                        </a>
                    </span>

                    <div class="ac-kanban-gear-wrap">
                        <button
                            type="button"
                            class="ac-kanban-gear-button"
                            data-role="dialog-kanban-card-fields-toggle"
                            title="Настроить вид карточек"
                            aria-label="Настроить вид карточек"
                            aria-expanded="false"
                        >
                            <svg width="15" height="15" viewBox="0 0 16 16" aria-hidden="true" fill="none">
                                <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M8 1.5v2M8 12.5v2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M1.5 8h2M12.5 8h2M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </button>

                        <div
                            hidden
                            data-role="dialog-kanban-card-fields-popover"
                            class="ac-kanban-fields-popover"
                        >
                            <div class="ac-kanban-fields-popover__head">
                                <span>Поля карточки</span>
                                <button type="button" data-role="dialog-kanban-card-fields-reset">По умолчанию</button>
                            </div>

                            <div class="ac-kanban-fields-popover__list">
                                <label class="ac-kanban-fields-popover__row">
                                    <input type="checkbox" data-kanban-card-field="id" checked>
                                    <span class="ac-kanban-fields-popover__box"></span>
                                    <span>{{ $dialogFieldLabel('id', 'ID диалога') }}</span>
                                </label>
                                <label class="ac-kanban-fields-popover__row">
                                    <input type="checkbox" data-kanban-card-field="channel" checked>
                                    <span class="ac-kanban-fields-popover__box"></span>
                                    <span>{{ $dialogFieldLabel('channel_id', 'Канал') }}</span>
                                </label>
                                <label class="ac-kanban-fields-popover__row">
                                    <input type="checkbox" data-kanban-card-field="status" checked>
                                    <span class="ac-kanban-fields-popover__box"></span>
                                    <span>{{ $dialogFieldLabel('status', 'Статус ответа') }}</span>
                                </label>
                                <label class="ac-kanban-fields-popover__row">
                                    <input type="checkbox" data-kanban-card-field="preview" checked>
                                    <span class="ac-kanban-fields-popover__box"></span>
                                    <span>{{ $dialogFieldLabel('last_message_at', 'Последнее сообщение') }}</span>
                                </label>
                                <label class="ac-kanban-fields-popover__row">
                                    <input type="checkbox" data-kanban-card-field="route" checked>
                                    <span class="ac-kanban-fields-popover__box"></span>
                                    <span>Маршрут</span>
                                </label>
                                <label class="ac-kanban-fields-popover__row">
                                    <input type="checkbox" data-kanban-card-field="responsible" checked>
                                    <span class="ac-kanban-fields-popover__box"></span>
                                    <span>Ответственный</span>
                                </label>
                                <label class="ac-kanban-fields-popover__row">
                                    <input type="checkbox" data-kanban-card-field="activity" checked>
                                    <span class="ac-kanban-fields-popover__box"></span>
                                    <span>Активность</span>
                                </label>
                                <label class="ac-kanban-fields-popover__row">
                                    <input type="checkbox" data-kanban-card-field="openLink" checked>
                                    <span class="ac-kanban-fields-popover__box"></span>
                                    <span>Ссылка открытия</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @if ($filtersPanelOpen)
            <section class="ac-surface ac-kanban-filters-panel">
                <div class="ac-card-grid ac-card-grid--kanban-filters">
                    <div class="ac-meta">
                        <label for="kanban-filter-channel" class="ac-meta__label">Канал</label>
                        <select id="kanban-filter-channel" wire:model.live="selectedChannelId" class="ac-select">
                            <option value="">Все каналы</option>
                            @foreach ($filters['channel_options'] as $channelId => $channelLabel)
                                <option value="{{ $channelId }}">{{ $channelLabel }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ac-meta">
                        <label for="kanban-filter-assignee" class="ac-meta__label">Ответственный</label>
                        <select id="kanban-filter-assignee" wire:model.live="selectedAssignedUserId" class="ac-select">
                            <option value="">Все сотрудники</option>
                            @foreach ($filters['assigned_user_options'] as $userId => $userLabel)
                                <option value="{{ $userId }}">{{ $userLabel }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ac-meta">
                        <label for="kanban-filter-route" class="ac-meta__label">Маршрут</label>
                        <select id="kanban-filter-route" wire:model.live="selectedRouteStatus" class="ac-select">
                            <option value="">Любой маршрут</option>
                            @foreach ($filters['route_status_options'] as $routeCode => $routeLabel)
                                <option value="{{ $routeCode }}">{{ $routeLabel }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ac-meta">
                        <label for="kanban-filter-inbox" class="ac-meta__label">Статус диалога</label>
                        <select id="kanban-filter-inbox" wire:model.live="selectedInboxStatus" class="ac-select">
                            <option value="">Любой статус</option>
                            @foreach ($filters['inbox_status_options'] as $inboxCode => $inboxLabel)
                                <option value="{{ $inboxCode }}">{{ $inboxLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="ac-button-group ac-button-group--end">
                    <button
                        type="button"
                        wire:click="resetKanbanFilters"
                        class="ac-button ac-button--secondary ac-button--compact"
                    >
                        Сбросить
                    </button>
                </div>
            </section>
        @endif

        <div
            data-role="dialog-kanban-board"
            x-data="{
                draggingDialogId: null,
                allowedTargets: [],
                emptyColumnNode() {
                    const node = document.createElement('div');

                    node.dataset.role = 'dialog-kanban-empty-column';
                    node.className = 'ac-kanban-empty-column';
                    node.textContent = 'Пусто';

                    return node;
                },
                columnCount(column) {
                    const count = column?.querySelector('[data-role=\'dialog-kanban-column-count\']');
                    const value = Number.parseInt((count?.textContent || '0').trim(), 10);

                    return Number.isFinite(value) ? value : 0;
                },
                setColumnCount(column, value) {
                    const count = column?.querySelector('[data-role=\'dialog-kanban-column-count\']');
                    const normalizedValue = Math.max(0, value);

                    if (count) {
                        count.textContent = String(normalizedValue);
                    }

                    column?.classList.toggle('ac-kanban-column--empty', normalizedValue === 0);
                },
                syncColumnEmptyState(column) {
                    const cards = column?.querySelector('[data-role=\'dialog-kanban-column-cards\']');
                    const empty = cards?.querySelector('[data-role=\'dialog-kanban-empty-column\']');
                    const hasVisibleCard = Boolean(cards?.querySelector('[data-role=\'dialog-kanban-card\']'));

                    if (! cards) {
                        return;
                    }

                    if (hasVisibleCard) {
                        empty?.remove();

                        return;
                    }

                    if (this.columnCount(column) === 0 && ! empty) {
                        cards.appendChild(this.emptyColumnNode());
                    }
                },
                optimisticMove(targetColumn, dialogId, targetStage) {
                    const root = targetColumn?.closest('[data-role=\'dialog-kanban-page\']');
                    const card = root?.querySelector(`[data-role='dialog-kanban-card'][data-dialog-id='${dialogId}']`);
                    const sourceColumn = card?.closest('[data-role=\'dialog-kanban-column\']');
                    const targetCards = targetColumn?.querySelector('[data-role=\'dialog-kanban-column-cards\']');

                    if (! root || ! card || ! sourceColumn || ! targetColumn || ! targetCards || sourceColumn === targetColumn) {
                        return;
                    }

                    targetCards.querySelector('[data-role=\'dialog-kanban-empty-column\']')?.remove();
                    targetCards.prepend(card);

                    this.setColumnCount(sourceColumn, this.columnCount(sourceColumn) - 1);
                    this.setColumnCount(targetColumn, this.columnCount(targetColumn) + 1);
                    this.syncColumnEmptyState(sourceColumn);
                    this.syncColumnEmptyState(targetColumn);

                    card.dataset.currentStage = targetStage;
                    card.setAttribute('draggable', 'false');
                    card.classList.add('ac-kanban-card--optimistic-move');
                    targetColumn.classList.add('ac-kanban-column--optimistic-target');

                    window.setTimeout(() => {
                        card.classList.remove('ac-kanban-card--optimistic-move');
                        targetColumn.classList.remove('ac-kanban-column--optimistic-target');
                    }, 700);
                },
                dropCard(targetColumn, dialogId, targetStage, persistMove) {
                    this.optimisticMove(targetColumn, dialogId, targetStage);

                    let moveRequest = null;

                    try {
                        moveRequest = typeof persistMove === 'function' ? persistMove() : null;
                    } catch (error) {
                        window.location.reload();

                        return;
                    }

                    if (moveRequest && typeof moveRequest.catch === 'function') {
                        moveRequest.catch(() => window.location.reload());
                    }
                },
            }"
            class="ac-kanban-board"
        >
            @foreach ($columns as $column)
                <section
                    data-role="dialog-kanban-column"
                    data-stage="{{ $column['stage'] }}"
                    wire:key="dialog-kanban-column-{{ $column['stage'] }}"
                    class="ac-surface ac-surface--secondary ac-kanban-column{{ $column['count'] === 0 ? ' ac-kanban-column--empty' : '' }}"
                    x-bind:class="draggingDialogId === null
                        ? ''
                        : (allowedTargets.includes('{{ $column['stage'] }}')
                            ? 'ac-kanban-column--drop-target'
                            : 'ac-kanban-column--inactive')"
                    x-on:dragover.prevent
                    x-on:drop.prevent="
                        if (draggingDialogId !== null && allowedTargets.includes('{{ $column['stage'] }}')) {
                            dropCard($el, draggingDialogId, '{{ $column['stage'] }}', () => $wire.moveDialogCard(draggingDialogId, '{{ $column['stage'] }}'));
                        }

                        draggingDialogId = null;
                        allowedTargets = [];
                    "
                >
                    <div
                        class="ac-kanban-column__header ac-kanban-column__header--colored"
                        data-stage-color="{{ $column['stage_color_hex'] }}"
                        style="--ac-kanban-stage-bg: {{ $column['stage_background_color'] }}; --ac-kanban-stage-border: {{ $column['stage_border_color'] }}; --ac-kanban-stage-text: {{ $column['stage_text_color'] }}; --ac-kanban-stage-count-bg: {{ $column['stage_count_background_color'] }};"
                    >
                        <h3 class="ac-kanban-column__title">{{ $column['label'] }}</h3>
                        <span class="ac-kanban-column__count" data-role="dialog-kanban-column-count">
                            {{ $column['count'] }}
                        </span>
                    </div>

                    <div class="ac-kanban-column__cards" data-role="dialog-kanban-column-cards">
                        @forelse ($column['cards'] as $card)
                            <article
                                data-role="dialog-kanban-card"
                                data-dialog-id="{{ $card['id'] }}"
                                data-current-stage="{{ $column['stage'] }}"
                                wire:key="dialog-kanban-card-{{ $card['id'] }}"
                                draggable="{{ $can_manage_stages && $card['allowed_target_stages'] !== [] ? 'true' : 'false' }}"
                                x-on:dragstart="
                                    if ($el.getAttribute('draggable') !== 'true') {
                                        return;
                                    }

                                    draggingDialogId = {{ $card['id'] }};
                                    allowedTargets = @js($card['allowed_target_stages']);
                                "
                                x-on:dragend="
                                    draggingDialogId = null;
                                    allowedTargets = [];
                                "
                                class="ac-kanban-card"
                            >
                                <div class="ac-kanban-card__header">
                                    <div class="ac-kanban-card__title-group">
                                        <div class="ac-kanban-card__title-row">
                                            <span class="ac-kanban-card__id">#{{ $card['id'] }}</span>
                                            <a
                                                href="{{ $card['view_url'] }}"
                                                class="ac-kanban-card__title"
                                            >
                                                {{ $card['contact_label'] }}
                                            </a>
                                        </div>
                                        <p class="ac-kanban-card__channel">
                                            {{ $card['channel_label'] }}
                                        </p>
                                    </div>

                                    <span class="ac-pill" data-tone="{{ $card['inbox_status_tone'] }}">
                                        {{ $card['inbox_status_label'] }}
                                    </span>
                                </div>

                                <div class="ac-kanban-card__body">
                                    <p class="ac-kanban-card__preview" data-role="dialog-kanban-card-preview">
                                        {{ $card['preview_text'] }}
                                    </p>

                                    <div class="ac-kanban-card__meta">
                                        <span class="ac-kanban-card__chip ac-kanban-card__chip--route" data-tone="{{ $card['route_status_tone'] }}">
                                            {{ $card['route_status_label'] }}
                                        </span>
                                        <span class="ac-kanban-card__chip ac-kanban-card__chip--responsible">
                                            {{ $card['assigned_user_label'] }}
                                        </span>
                                        <span class="ac-kanban-card__chip ac-kanban-card__chip--activity">
                                            {{ $card['activity_label'] }}
                                        </span>
                                    </div>

                                    <div class="ac-kanban-card__footer">
                                        <a href="{{ $card['view_url'] }}" class="ac-kanban-card__open-link">
                                            Открыть диалог
                                            <span aria-hidden="true">→</span>
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div
                                data-role="dialog-kanban-empty-column"
                                class="ac-kanban-empty-column"
                            >
                                Пусто
                            </div>
                        @endforelse
                    </div>

                    @if ($column['has_more'])
                        <div class="ac-kanban-column__footer">
                            <button
                                type="button"
                                wire:click="loadMoreCards('{{ $column['stage'] }}')"
                                class="ac-button ac-button--secondary ac-button--full"
                            >
                                Показать ещё
                            </button>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>

    @once
        <script>
            (() => {
                const storageKey = 'ac.dialogKanban.cardFields';
                const defaults = {
                    id: true,
                    channel: true,
                    status: true,
                    preview: true,
                    route: true,
                    responsible: true,
                    activity: true,
                    openLink: true,
                };
                const hideClasses = {
                    id: 'ac-kanban-hide-id',
                    channel: 'ac-kanban-hide-channel',
                    status: 'ac-kanban-hide-status',
                    preview: 'ac-kanban-hide-preview',
                    route: 'ac-kanban-hide-route',
                    responsible: 'ac-kanban-hide-responsible',
                    activity: 'ac-kanban-hide-activity',
                    openLink: 'ac-kanban-hide-open-link',
                };

                const readFields = () => {
                    try {
                        return { ...defaults, ...JSON.parse(window.localStorage.getItem(storageKey) || '{}') };
                    } catch (error) {
                        return { ...defaults };
                    }
                };

                const saveFields = (fields) => {
                    try {
                        window.localStorage.setItem(storageKey, JSON.stringify(fields));
                    } catch (error) {
                        // Настройка остаётся применённой в текущей сессии даже без localStorage.
                    }
                };

                const applyFields = (root, fields = readFields()) => {
                    Object.entries(hideClasses).forEach(([field, className]) => {
                        root.classList.toggle(className, fields[field] === false);
                    });

                    root.querySelectorAll('[data-kanban-card-field]').forEach((input) => {
                        input.checked = fields[input.dataset.kanbanCardField] !== false;
                    });
                };

                const closePopover = (root) => {
                    const toggle = root.querySelector('[data-role="dialog-kanban-card-fields-toggle"]');
                    const popover = root.querySelector('[data-role="dialog-kanban-card-fields-popover"]');

                    if (! toggle || ! popover) {
                        return;
                    }

                    popover.hidden = true;
                    toggle.classList.remove('is-open');
                    toggle.setAttribute('aria-expanded', 'false');
                };

                const openPopover = (root) => {
                    const toggle = root.querySelector('[data-role="dialog-kanban-card-fields-toggle"]');
                    const popover = root.querySelector('[data-role="dialog-kanban-card-fields-popover"]');

                    if (! toggle || ! popover) {
                        return;
                    }

                    popover.hidden = false;
                    toggle.classList.add('is-open');
                    toggle.setAttribute('aria-expanded', 'true');
                };

                const initKanbanCardFields = () => {
                    document.querySelectorAll('[data-role="dialog-kanban-page"]').forEach((root) => {
                        applyFields(root);
                    });
                };
                const scheduleKanbanCardFieldsInit = () => {
                    window.requestAnimationFrame(initKanbanCardFields);
                };

                document.addEventListener('DOMContentLoaded', scheduleKanbanCardFieldsInit);
                document.addEventListener('livewire:navigated', scheduleKanbanCardFieldsInit);

                if (window.__acKanbanCardFieldsBound !== true) {
                    window.__acKanbanCardFieldsBound = true;

                    const registerLivewireMorphHook = () => {
                        if (window.__acKanbanCardFieldsLivewireHooked === true || ! window.Livewire?.hook) {
                            return;
                        }

                        window.__acKanbanCardFieldsLivewireHooked = true;
                        window.Livewire.hook('morphed', scheduleKanbanCardFieldsInit);
                    };

                    document.addEventListener('livewire:initialized', registerLivewireMorphHook);
                    registerLivewireMorphHook();

                    document.addEventListener('click', (event) => {
                        const toggle = event.target.closest('[data-role="dialog-kanban-card-fields-toggle"]');

                        if (toggle) {
                            const root = toggle.closest('[data-role="dialog-kanban-page"]');
                            const popover = root?.querySelector('[data-role="dialog-kanban-card-fields-popover"]');

                            if (root && popover) {
                                event.preventDefault();
                                event.stopPropagation();
                                popover.hidden ? openPopover(root) : closePopover(root);
                            }

                            return;
                        }

                        if (event.target.closest('[data-role="dialog-kanban-card-fields-popover"]')) {
                            return;
                        }

                        document.querySelectorAll('[data-role="dialog-kanban-page"]').forEach(closePopover);
                    });

                    document.addEventListener('change', (event) => {
                        const input = event.target.closest('[data-kanban-card-field]');

                        if (! input) {
                            return;
                        }

                        const root = input.closest('[data-role="dialog-kanban-page"]');

                        if (! root) {
                            return;
                        }

                        const fields = readFields();
                        fields[input.dataset.kanbanCardField] = input.checked;
                        saveFields(fields);
                        applyFields(root, fields);
                    });

                    document.addEventListener('click', (event) => {
                        const reset = event.target.closest('[data-role="dialog-kanban-card-fields-reset"]');

                        if (! reset) {
                            return;
                        }

                        const root = reset.closest('[data-role="dialog-kanban-page"]');

                        if (! root) {
                            return;
                        }

                        event.preventDefault();
                        const fields = { ...defaults };
                        saveFields(fields);
                        applyFields(root, fields);
                    });

                    document.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape') {
                            document.querySelectorAll('[data-role="dialog-kanban-page"]').forEach(closePopover);
                        }
                    });
                }

                initKanbanCardFields();
            })();
        </script>
    @endonce
</x-filament-panels::page>
