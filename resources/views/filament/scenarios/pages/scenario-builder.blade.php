<x-filament-panels::page>
    <form wire:submit.prevent="saveDraft" class="ac-scenario-builder-page">
        @if (! $this->hasDraftVersion())
            <section class="ac-surface ac-scenario-builder-empty-state">
                <strong>Активного черновика нет.</strong>
                <span>Создайте новый черновик из опубликованной версии, чтобы открыть визуальное редактирование.</span>
            </section>
        @endif

        @php($builderStartBlocks = $this->builderStartBlocks())

        <div
            class="ac-scenario-builder-shell"
            x-data="{ selectedPanel: @js($this->selectedBuilderBlockId !== null ? 'start' : null) }"
        >
            <main class="ac-scenario-builder-workspace">
                <div class="ac-scenario-builder-workspace__topbar">
                    <div>
                        <span>Полотно</span>
                        <strong>Полотно конструктора</strong>
                        <p>Основной лист. Добавляйте элементы на поле и нажимайте блок, чтобы открыть его настройки справа.</p>
                    </div>
                    <div class="ac-scenario-builder-workspace__actions">
                        <a href="{{ \App\Filament\Resources\Scenarios\ScenarioResource::getUrl() }}" class="ac-button ac-button--secondary">
                            К списку сценариев
                        </a>
                        <button type="submit" class="ac-button ac-button--primary" @disabled(! $this->hasDraftVersion())>
                            Сохранить
                        </button>
                        <button
                            type="button"
                            class="ac-button ac-button--success"
                            wire:click="addStartBuilderBlock"
                            x-on:click="selectedPanel = 'start'"
                            @disabled(! $this->hasDraftVersion())
                        >
                            Добавить стартовое условие
                        </button>
                        <span class="ac-scenario-builder-workspace__badge">draft v{{ $this->getRecord()->draftVersion?->version_number ?? '—' }}</span>
                    </div>
                </div>

                <div
                    class="ac-scenario-builder-canvas"
                    x-data="{
                        positions: @js(collect($builderStartBlocks)->mapWithKeys(fn (array $block): array => [(string) $block['id'] => $block['position']])->all()),
                        draggingBlockId: null,
                        activePointerId: null,
                        hasDragged: false,
                        startPointerX: 0,
                        startPointerY: 0,
                        startX: 64,
                        startY: 64,
                        dragThreshold: 4,
                        nodeX(blockId) {
                            const position = this.positions[String(blockId)] || {};

                            return Number(position.x || 64);
                        },
                        nodeY(blockId) {
                            const position = this.positions[String(blockId)] || {};

                            return Number(position.y || 64);
                        },
                        startPointer(event, blockId) {
                            if (event.button !== undefined && event.button !== 0) {
                                return;
                            }

                            this.draggingBlockId = String(blockId);
                            this.activePointerId = event.pointerId ?? null;
                            this.hasDragged = false;
                            this.startPointerX = event.clientX;
                            this.startPointerY = event.clientY;
                            this.startX = this.nodeX(blockId);
                            this.startY = this.nodeY(blockId);

                            if (event.currentTarget.setPointerCapture && this.activePointerId !== null) {
                                event.currentTarget.setPointerCapture(this.activePointerId);
                            }

                            event.preventDefault();
                        },
                        movePointer(event) {
                            if (this.draggingBlockId === null) {
                                return;
                            }

                            if (this.activePointerId !== null && event.pointerId !== this.activePointerId) {
                                return;
                            }

                            const diffX = event.clientX - this.startPointerX;
                            const diffY = event.clientY - this.startPointerY;

                            if (! this.hasDragged && Math.hypot(diffX, diffY) < this.dragThreshold) {
                                return;
                            }

                            this.hasDragged = true;
                            this.moveTo(diffX, diffY);
                            event.preventDefault();
                        },
                        moveTo(diffX, diffY) {
                            const x = Math.min(Math.max(24, this.startX + diffX), 1400);
                            const y = Math.min(Math.max(24, this.startY + diffY), 1000);

                            this.positions[this.draggingBlockId] = {
                                x: Math.round(x),
                                y: Math.round(y),
                            };
                        },
                        finishPointer(event) {
                            if (this.draggingBlockId === null) {
                                this.draggingBlockId = null;
                                this.activePointerId = null;
                                this.hasDragged = false;

                                return;
                            }

                            if (this.activePointerId !== null && event.pointerId !== this.activePointerId) {
                                return;
                            }

                            const blockId = this.draggingBlockId;
                            const wasDragged = this.hasDragged;
                            const position = this.positions[blockId] || { x: this.startX, y: this.startY };
                            this.draggingBlockId = null;
                            this.activePointerId = null;
                            this.hasDragged = false;

                            selectedPanel = 'start';

                            if (wasDragged) {
                                $wire.moveStartBuilderBlock(Number(blockId), Number(position.x || 64), Number(position.y || 64));
                                $wire.selectStartBuilderBlock(Number(blockId));

                                return;
                            }

                            $wire.selectStartBuilderBlock(Number(blockId));
                        },
                    }"
                    x-on:pointermove.window="movePointer($event)"
                    x-on:pointerup.window="finishPointer($event)"
                    x-on:pointercancel.window="finishPointer($event)"
                >
                    <div class="ac-scenario-builder-canvas__surface">
                        @foreach ($builderStartBlocks as $block)
                            <article
                                class="ac-scenario-builder-node ac-scenario-builder-node--green {{ $block['is_selected'] ? 'is-selected' : '' }}"
                                wire:key="scenario-builder-canvas-block-{{ $block['id'] }}"
                                role="button"
                                tabindex="0"
                                data-builder-block-id="{{ $block['id'] }}"
                                x-bind:class="{ 'is-dragging': draggingBlockId === '{{ $block['id'] }}' && hasDragged }"
                                x-bind:style="`left: ${nodeX({{ $block['id'] }})}px; top: ${nodeY({{ $block['id'] }})}px;`"
                                x-on:pointerdown="startPointer($event, {{ $block['id'] }})"
                                x-on:keydown.enter.prevent="selectedPanel = 'start'; $wire.selectStartBuilderBlock({{ $block['id'] }})"
                                x-on:keydown.space.prevent="selectedPanel = 'start'; $wire.selectStartBuilderBlock({{ $block['id'] }})"
                            >
                                <strong>{{ $block['title'] }}</strong>
                            </article>
                        @endforeach
                    </div>
                </div>
            </main>

            <aside class="ac-scenario-builder-palette">
                <div x-show="selectedPanel !== 'start'">
                    <div class="ac-scenario-builder-panel__header">
                        <span>Информация</span>
                        <strong>Элементы на поле</strong>
                    </div>

                    <div class="ac-scenario-builder-element-list">
                        <button
                            type="button"
                            class="ac-scenario-builder-element-list__item ac-scenario-builder-element-list__item--green"
                            wire:click="addStartBuilderBlock"
                            x-on:click="selectedPanel = 'start'"
                            @disabled(! $this->hasDraftVersion())
                        >
                            <span class="ac-scenario-builder-element-list__icon">⚑</span>
                            <strong>Стартовое условие</strong>
                        </button>
                        <div class="ac-scenario-builder-element-list__item is-disabled">
                            <span class="ac-scenario-builder-element-list__icon">☰</span>
                            <strong>Состояние диалога</strong>
                        </div>
                        <div class="ac-scenario-builder-element-list__item is-disabled">
                            <span class="ac-scenario-builder-element-list__icon ac-scenario-builder-element-list__icon--danger">×</span>
                            <strong>Закрыть сделку</strong>
                        </div>
                        <div class="ac-scenario-builder-element-list__item is-disabled">
                            <span class="ac-scenario-builder-element-list__icon">⚐</span>
                            <strong>Не состояние с условием</strong>
                        </div>
                        <div class="ac-scenario-builder-element-list__item is-disabled">
                            <span class="ac-scenario-builder-element-list__icon">☒</span>
                            <strong>Не состояние</strong>
                        </div>
                        <div class="ac-scenario-builder-element-list__item is-disabled">
                            <span class="ac-scenario-builder-element-list__icon">i</span>
                            <strong>Комментарий</strong>
                        </div>
                    </div>

                    @if ($builderStartBlocks !== [])
                        <div class="ac-scenario-builder-block-list">
                            <span>На полотне</span>
                            @foreach ($builderStartBlocks as $block)
                                <button
                                    type="button"
                                    class="ac-scenario-builder-block-list__item {{ $block['is_selected'] ? 'is-selected' : '' }}"
                                    wire:key="scenario-builder-list-block-{{ $block['id'] }}"
                                    wire:click="selectStartBuilderBlock({{ $block['id'] }})"
                                    x-on:click="selectedPanel = 'start'"
                                >
                                    <strong>{{ $block['title'] }}</strong>
                                    <small>
                                        ID: #{{ $block['id'] }}
                                        · {{ $block['channel_label'] }}
                                        @if ($block['is_primary'])
                                            · основной старт
                                        @endif
                                    </small>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div
                    class="ac-scenario-builder-settings"
                    x-cloak
                    x-show="selectedPanel === 'start'"
                >
                    <div class="ac-scenario-builder-panel__header ac-scenario-builder-panel__header--with-action">
                        <div>
                            <span>Настройки элемента</span>
                            <strong>Стартовое условие</strong>
                            <small class="ac-scenario-builder-inline-meta">
                                ID: #{{ $this->startBuilderBlockId() }} · тип: {{ $this->startBuilderBlockType() }}
                            </small>
                        </div>

                        <button type="button" x-on:click="selectedPanel = null">
                            Информация
                        </button>
                    </div>

                    <div class="ac-scenario-builder-block-type-selector">
                        <span>Тип блока</span>
                        <button type="button" class="ac-scenario-builder-block-type-option is-active" disabled>
                            <span class="ac-scenario-builder-block-type-option__icon">⚑</span>
                            <span>
                                <strong>{{ $this->startBuilderBlockTypeLabel() }}</strong>
                                <small>{{ $this->startBuilderBlockType() }}</small>
                            </span>
                        </button>
                    </div>

                    <div class="ac-scenario-builder-fieldset">
                        <label for="scenario-builder-start-title">Название блока</label>
                        <input
                            id="scenario-builder-start-title"
                            type="text"
                            wire:model.live="draftStartNodeTitle"
                            placeholder="Название блока"
                            @disabled(! $this->hasDraftVersion())
                        />
                    </div>

                    <div class="ac-scenario-builder-fieldset">
                        <label for="scenario-builder-runtime-start">Первый блок сценария</label>
                        <select
                            id="scenario-builder-runtime-start"
                            wire:model.live="draftStartBlockId"
                            @disabled(! $this->hasDraftVersion())
                        >
                            @foreach ($this->startBlockOptions() as $blockId => $label)
                                <option value="{{ $blockId }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p>После срабатывания стартовое условие начнёт сценарий с выбранного блока.</p>
                    </div>

                    <div class="ac-scenario-builder-fieldset">
                        <label for="scenario-builder-channels">Каналы</label>
                        <select
                            id="scenario-builder-channels"
                            wire:model.live="draftStartChannelIds"
                            multiple
                            size="4"
                            @disabled(! $this->hasDraftVersion())
                        >
                            @foreach ($this->channelOptions() as $channelId => $label)
                                <option value="{{ $channelId }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p>Стартовое условие будет работать на выбранных каналах, как правило автоответа.</p>
                    </div>

                    <div class="ac-scenario-builder-fieldset">
                        <div class="ac-scenario-builder-fieldset__title">
                            <label>Условия</label>
                            <span class="ac-scenario-builder-section-badge">как в автоответах</span>
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="scenario-builder-condition-match">Область срабатывания</label>
                            <select id="scenario-builder-condition-match" wire:model.live="draftStartConditionMatch" @disabled(! $this->hasDraftVersion())>
                                @foreach ($this->startBuilderConditionMatchOptions() as $match => $label)
                                    <option value="{{ $match }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($this->startBuilderUsesKeywordScope())
                            <div class="ac-scenario-builder-fieldset__title">
                                <label>{{ $this->startBuilderKeywordFieldLabel() }}</label>
                                <button type="button" wire:click="addDraftStartTrigger" class="ac-button ac-button--secondary ac-button--compact" @disabled(! $this->hasDraftVersion())>
                                    Добавить
                                </button>
                            </div>
                            <div class="ac-scenario-builder-triggers">
                                @foreach ($draftStartTriggers as $index => $trigger)
                                    <div class="ac-scenario-builder-trigger-row" wire:key="scenario-builder-trigger-{{ $this->startBuilderBlockId() }}-{{ $index }}">
                                        <input
                                            type="text"
                                            wire:model.live="draftStartTriggers.{{ $index }}.value"
                                            placeholder="Например: vip_ibiza_apply"
                                            @disabled(! $this->hasDraftVersion())
                                        />
                                        <button type="button" wire:click="removeDraftStartTrigger({{ $index }})" @disabled(! $this->hasDraftVersion())>
                                            Удалить
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p>Для варианта «Любое входящее» текст или параметр для срабатывания не нужен.</p>
                        @endif
                    </div>

                    <div class="ac-scenario-builder-fieldset">
                        <label for="scenario-builder-reply-text">Текст ответа</label>
                        <textarea
                            id="scenario-builder-reply-text"
                            wire:model.live="draftStartReplyText"
                            rows="5"
                            placeholder="Какой текст отправить клиенту, когда условие сработало"
                            @disabled(! $this->hasDraftVersion())
                        ></textarea>
                        <p>Этот текст будет приходить в ответ при срабатывании выбранного стартового условия.</p>
                    </div>

                    <div class="ac-scenario-builder-sale-bot-sections" aria-label="Будущие разделы блока">
                        <div>
                            <strong>Калькулятор</strong>
                            <span>будет добавлен отдельным slice</span>
                        </div>
                        <div>
                            <strong>Действия</strong>
                            <span>будет добавлен отдельным slice</span>
                        </div>
                        <div>
                            <strong>Кнопки</strong>
                            <span>будет добавлен отдельным slice</span>
                        </div>
                        <div>
                            <strong>События для аналитики</strong>
                            <span>будет добавлен отдельным slice</span>
                        </div>
                        <div>
                            <strong>Вложения</strong>
                            <span>будет добавлен отдельным slice</span>
                        </div>
                    </div>

                    @if (! $this->selectedStartBuilderBlockIsPrimary())
                        <button
                            type="button"
                            class="ac-button ac-button--danger"
                            wire:click="deleteSelectedStartBuilderBlock"
                            wire:confirm="Удалить выбранное стартовое условие?"
                            @disabled(! $this->hasDraftVersion())
                        >
                            Удалить стартовое условие
                        </button>
                    @endif

                    <div class="ac-scenario-builder-json-toggle">
                        <label>
                            <input type="checkbox" wire:model.live="showJsonFallback" />
                            Показать JSON fallback
                        </label>
                    </div>

                    @if ($showJsonFallback)
                        <div class="ac-scenario-builder-fieldset">
                            <label for="scenario-builder-json">Технический JSON fallback</label>
                            <textarea id="scenario-builder-json" wire:model.blur="draftSchemaPayloadJson" rows="12" @disabled(! $this->hasDraftVersion())></textarea>
                            <p>Если вручную изменить JSON, он будет главным источником при сохранении.</p>
                        </div>
                    @endif
                </div>
            </aside>
        </div>
    </form>
</x-filament-panels::page>
