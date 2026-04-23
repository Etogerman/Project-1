<x-filament-panels::page>
    <div data-role="dialog-kanban-page" class="ac-panel-stack ac-panel-stack--relaxed">
        <section class="ac-surface ac-surface--hero">
            <div class="ac-surface__header ac-surface__header--centered">
                <div class="ac-surface__title-group">
                    <p class="ac-surface__eyebrow">Рабочая доска</p>
                    <h2 class="ac-surface__title ac-surface__title--hero">Канбан диалогов</h2>
                    <p class="ac-surface__subtitle">
                        Все доступные оператору диалоги по этапам без фильтра «Требует ответа» по умолчанию.
                    </p>
                </div>
            </div>

            <div class="ac-card-grid ac-surface__divider">
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
        </section>

        <div
            data-role="dialog-kanban-board"
            x-data="{ draggingDialogId: null, allowedTargets: [] }"
            class="grid gap-4 xl:grid-cols-3"
        >
            @foreach ($columns as $column)
                <section
                    data-role="dialog-kanban-column"
                    data-stage="{{ $column['stage'] }}"
                    class="ac-surface ac-surface--secondary flex min-h-[20rem] flex-col"
                    x-bind:class="draggingDialogId === null
                        ? ''
                        : (allowedTargets.includes('{{ $column['stage'] }}')
                            ? 'ring-2 ring-amber-400/80'
                            : 'opacity-70')"
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
                            <p class="ac-surface__subtitle">Диалогов: {{ $column['count'] }}</p>
                        </div>

                        <span class="ac-pill" data-tone="{{ $column['tone'] }}">
                            {{ $column['count'] }}
                        </span>
                    </div>

                    <div class="mt-4 flex flex-1 flex-col gap-3">
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
                                class="rounded-2xl border border-white/10 bg-slate-950/35 p-4 shadow-sm transition hover:border-amber-300/30"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a
                                            href="{{ $card['view_url'] }}"
                                            class="block truncate text-base font-semibold text-white hover:text-amber-200"
                                        >
                                            {{ $card['contact_label'] }}
                                        </a>
                                        <p class="mt-1 text-sm text-slate-300">
                                            {{ $card['channel_label'] }}
                                        </p>
                                    </div>

                                    <span class="ac-pill shrink-0" data-tone="{{ $card['inbox_status_tone'] }}">
                                        {{ $card['inbox_status_label'] }}
                                    </span>
                                </div>

                                <div class="mt-4 space-y-3">
                                    <p class="line-clamp-3 text-sm leading-6 text-slate-100">
                                        {{ $card['preview_text'] }}
                                    </p>

                                    <div class="grid gap-3 text-sm md:grid-cols-2">
                                        <div>
                                            <p class="text-xs font-medium uppercase tracking-[0.18em] text-slate-400">Маршрут</p>
                                            <p class="mt-1">
                                                <span class="ac-pill" data-tone="{{ $card['route_status_tone'] }}">
                                                    {{ $card['route_status_label'] }}
                                                </span>
                                            </p>
                                        </div>

                                        <div>
                                            <p class="text-xs font-medium uppercase tracking-[0.18em] text-slate-400">Ответственный</p>
                                            <p class="mt-1 text-slate-200">{{ $card['assigned_user_label'] }}</p>
                                        </div>

                                        <div>
                                            <p class="text-xs font-medium uppercase tracking-[0.18em] text-slate-400">Активность</p>
                                            <p class="mt-1 text-slate-200">{{ $card['activity_label'] }}</p>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div
                                data-role="dialog-kanban-empty-column"
                                class="flex flex-1 items-center justify-center rounded-2xl border border-dashed border-white/10 bg-slate-950/20 px-4 py-10 text-center text-sm text-slate-400"
                            >
                                В этой колонке пока нет карточек.
                            </div>
                        @endforelse
                    </div>

                    @if ($column['has_more'])
                        <div class="mt-4">
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
