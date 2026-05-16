<x-filament-panels::page>
    <div data-role="dialog-kanban-page" class="ac-panel-stack ac-panel-stack--relaxed">
        @if ($filtersPanelOpen)
            <section class="ac-surface ac-surface--hero ac-kanban-filters-panel">
                <div class="ac-card-grid ac-card-grid--kanban-filters">
                    <div class="ac-meta">
                        <label for="kanban-search" class="ac-meta__label">Поиск</label>
                        <div class="ac-kanban-search-control">
                            <input
                                id="kanban-search"
                                type="search"
                                wire:model.live.debounce.350ms="search"
                                class="ac-input"
                                placeholder="Имя, @username, телефон или ID чата"
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
            x-data="{ draggingDialogId: null, allowedTargets: [] }"
            class="ac-kanban-board"
        >
            @foreach ($columns as $column)
                <section
                    data-role="dialog-kanban-column"
                    data-stage="{{ $column['stage'] }}"
                    class="ac-surface ac-surface--secondary ac-kanban-column{{ $column['count'] === 0 ? ' ac-kanban-column--empty' : '' }}"
                    x-bind:class="draggingDialogId === null
                        ? ''
                        : (allowedTargets.includes('{{ $column['stage'] }}')
                            ? 'ac-kanban-column--drop-target'
                            : 'ac-kanban-column--inactive')"
                    x-on:dragover.prevent
                    x-on:drop.prevent="
                        if (draggingDialogId !== null && allowedTargets.includes('{{ $column['stage'] }}')) {
                            $wire.moveDialogCard(draggingDialogId, '{{ $column['stage'] }}');
                        }

                        draggingDialogId = null;
                        allowedTargets = [];
                    "
                >
                    <div class="ac-surface__header ac-surface__header--centered">
                        <div class="ac-surface__title-group">
                            <h3 class="ac-surface__title">{{ $column['label'] }}</h3>
                        </div>

                        <span class="ac-pill" data-tone="{{ $column['tone'] }}">
                            {{ $column['count'] }}
                        </span>
                    </div>

                    <div class="ac-kanban-column__cards">
                        @forelse ($column['cards'] as $card)
                            <article
                                data-role="dialog-kanban-card"
                                data-dialog-id="{{ $card['id'] }}"
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
                                        <a
                                            href="{{ $card['view_url'] }}"
                                            class="ac-kanban-card__title"
                                        >
                                            {{ $card['contact_label'] }}
                                        </a>
                                        <p class="ac-kanban-card__channel">
                                            {{ $card['channel_label'] }}
                                        </p>
                                    </div>

                                    <span class="ac-pill" data-tone="{{ $card['inbox_status_tone'] }}">
                                        {{ $card['inbox_status_label'] }}
                                    </span>
                                </div>

                                <div class="ac-kanban-card__body">
                                    <p class="ac-kanban-card__preview">
                                        {{ $card['preview_text'] }}
                                    </p>

                                    <div class="ac-kanban-card__facts">
                                        <div class="ac-kanban-card__fact ac-kanban-card__fact--route">
                                            <span class="ac-kanban-card__fact-label">Маршрут</span>
                                            <span class="ac-kanban-card__route-status" data-tone="{{ $card['route_status_tone'] }}">
                                                {{ $card['route_status_label'] }}
                                            </span>
                                        </div>

                                        <div class="ac-kanban-card__fact">
                                            <span class="ac-kanban-card__fact-label">Ответственный</span>
                                            <span class="ac-kanban-card__fact-value">{{ $card['assigned_user_label'] }}</span>
                                        </div>

                                        <div class="ac-kanban-card__fact">
                                            <span class="ac-kanban-card__fact-label">Активность</span>
                                            <span class="ac-kanban-card__fact-value">{{ $card['activity_label'] }}</span>
                                        </div>
                                    </div>

                                    <div class="ac-kanban-card__footer">
                                        <a href="{{ $card['view_url'] }}" class="ac-button ac-button--secondary ac-button--compact">
                                            Открыть диалог
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div
                                data-role="dialog-kanban-empty-column"
                                class="ac-kanban-empty-column"
                            >
                                В этой колонке пока нет карточек.
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
</x-filament-panels::page>
