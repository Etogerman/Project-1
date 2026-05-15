<x-filament-panels::page>
    @php($blocks = $this->blocks())
    @php($arrows = $this->arrows())

    <form wire:submit.prevent="saveSelectedElement" class="ac-scenario-builder-page">
        <div
            class="ac-scenario-builder-shell"
            x-data="{
                positions: @js(collect($blocks)->mapWithKeys(fn (array $block): array => [(string) $block['id'] => $block['position']])->all()),
                draggingBlockId: null,
                activePointerId: null,
                hasDragged: false,
                isSavingPosition: false,
                startPointerX: 0,
                startPointerY: 0,
                startX: 64,
                startY: 64,
                dragNodeWidth: 0,
                dragNodeHeight: 0,
                startScrollLeft: 0,
                startScrollTop: 0,
                dragThreshold: 4,
                nodeX(blockId) {
                    const position = this.positions[String(blockId)] || {};
                    const x = Number(position.x);

                    return Number.isFinite(x) ? x : 64;
                },
                nodeY(blockId) {
                    const position = this.positions[String(blockId)] || {};
                    const y = Number(position.y);

                    return Number.isFinite(y) ? y : 64;
                },
                nodeWidth() {
                    return 224;
                },
                nodeHeight() {
                    return 84;
                },
                gridSize() {
                    return 32;
                },
                arrowPath(sourceBlockId, targetBlockId) {
                    const sourceX = this.nodeX(sourceBlockId);
                    const sourceY = this.nodeY(sourceBlockId);
                    const targetX = this.nodeX(targetBlockId);
                    const targetY = this.nodeY(targetBlockId);
                    const width = this.nodeWidth();
                    const height = this.nodeHeight();
                    const grid = this.gridSize();
                    const startX = sourceX + width;
                    const startY = sourceY + (height / 2);

                    if (Number(sourceBlockId) === Number(targetBlockId)) {
                        const loopGap = grid / 2;
                        const exitX = sourceX + (width * 0.56);
                        const exitY = sourceY;
                        const topY = Math.max(8, sourceY - loopGap);
                        const rightX = sourceX + width + loopGap;
                        const cornerRadius = 12;
                        const entryX = sourceX + width;
                        const entryY = sourceY + (height / 2);

                        return `M ${exitX} ${exitY} L ${exitX} ${topY + cornerRadius} Q ${exitX} ${topY}, ${exitX + cornerRadius} ${topY} L ${rightX - cornerRadius} ${topY} Q ${rightX} ${topY}, ${rightX} ${topY + cornerRadius} L ${rightX} ${entryY - cornerRadius} Q ${rightX} ${entryY}, ${entryX} ${entryY}`;
                    }

                    return `M ${startX} ${startY} L ${targetX} ${targetY + (height / 2)}`;
                },
                arrowLabelX(sourceBlockId, targetBlockId) {
                    if (Number(sourceBlockId) === Number(targetBlockId)) {
                        const width = this.nodeWidth();
                        const height = this.nodeHeight();
                        const loopGap = this.gridSize() / 2;
                        const cornerRadius = 12;
                        const exitX = this.nodeX(sourceBlockId) + (width * 0.56);
                        const rightX = this.nodeX(sourceBlockId) + width + loopGap;
                        const topStartX = exitX + cornerRadius;
                        const topEndX = rightX - cornerRadius;
                        const topLength = topEndX - topStartX;
                        const beforeTopLength = loopGap + cornerRadius;
                        const afterTopLength = (height / 2) + loopGap + cornerRadius;
                        const halfLength = (beforeTopLength + topLength + afterTopLength) / 2;

                        return topStartX + Math.min(topLength, Math.max(0, halfLength - beforeTopLength));
                    }

                    return (this.nodeX(sourceBlockId) + this.nodeX(targetBlockId) + this.nodeWidth()) / 2;
                },
                arrowLabelY(sourceBlockId, targetBlockId) {
                    if (Number(sourceBlockId) === Number(targetBlockId)) {
                        return Math.max(20, this.nodeY(sourceBlockId) - (this.gridSize() / 2) - 8);
                    }

                    return (this.nodeY(sourceBlockId) + this.nodeY(targetBlockId) + this.nodeHeight()) / 2 - 8;
                },
                startPointer(event, blockId) {
                    if (this.isSavingPosition) {
                        return;
                    }

                    if (event.button !== undefined && event.button !== 0) {
                        return;
                    }

                    this.draggingBlockId = String(blockId);
                    this.activePointerId = event.pointerId ?? null;
                    this.hasDragged = false;
                    this.dragNodeWidth = event.currentTarget.offsetWidth || 0;
                    this.dragNodeHeight = event.currentTarget.offsetHeight || 0;
                    this.startPointerX = event.clientX;
                    this.startPointerY = event.clientY;
                    this.startX = event.currentTarget.offsetLeft;
                    this.startY = event.currentTarget.offsetTop;
                    this.startScrollLeft = this.$refs.canvas?.scrollLeft || 0;
                    this.startScrollTop = this.$refs.canvas?.scrollTop || 0;

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
                    const surface = this.$refs.surface;
                    const canvas = this.$refs.canvas;
                    const maxX = Math.max(0, (surface?.clientWidth || 5000) - this.dragNodeWidth);
                    const maxY = Math.max(0, (surface?.clientHeight || 5000) - this.dragNodeHeight);
                    const scrollDiffX = (canvas?.scrollLeft || 0) - this.startScrollLeft;
                    const scrollDiffY = (canvas?.scrollTop || 0) - this.startScrollTop;
                    const nextX = this.startX + diffX + scrollDiffX;
                    const nextY = this.startY + diffY + scrollDiffY;
                    const x = Math.min(Math.max(0, nextX), maxX);
                    const y = Math.min(Math.max(0, nextY), maxY);

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
                    this.draggingBlockId = null;
                    this.activePointerId = null;
                    this.hasDragged = false;
                    this.dragNodeWidth = 0;
                    this.dragNodeHeight = 0;
                    this.startScrollLeft = 0;
                    this.startScrollTop = 0;

                    if (wasDragged) {
                        const x = this.nodeX(blockId);
                        const y = this.nodeY(blockId);
                        const request = $wire.moveBlock(Number(blockId), x, y);
                        this.isSavingPosition = true;

                        if (request && typeof request.finally === 'function') {
                            request.finally(() => {
                                this.isSavingPosition = false;
                            });
                        } else {
                            this.isSavingPosition = false;
                        }

                        return;
                    }

                    $wire.selectBlock(Number(blockId));
                },
            }"
        >
            <main class="ac-scenario-builder-workspace">
                <div class="ac-scenario-builder-workspace__topbar">
                    <div>
                        <span>Полотно</span>
                        <strong>Стартовые условия</strong>
                    </div>
                    <div class="ac-scenario-builder-workspace__actions">
                        <button type="submit" class="ac-button ac-button--primary" @disabled(! $this->hasSelectedElement())>
                            Сохранить
                        </button>
                        <button type="button" class="ac-button ac-button--secondary" wire:click="addArrow" @disabled(! $this->canCreateArrow())>
                            Добавить стрелку
                        </button>
                        <button type="button" class="ac-button ac-button--success" wire:click="addBlock">
                            Добавить стартовое условие
                        </button>
                    </div>
                </div>

                <div
                    class="ac-scenario-builder-canvas"
                    x-ref="canvas"
                    x-on:pointermove.window="movePointer($event)"
                    x-on:pointerup.window="finishPointer($event)"
                    x-on:pointercancel.window="finishPointer($event)"
                >
                    <div class="ac-scenario-builder-canvas__surface" x-ref="surface">
                        <svg class="ac-bot-constructor-arrows" width="100%" height="100%" aria-label="Стрелки конструктора">
                            <defs>
                                <marker id="ac-bot-constructor-arrow-head" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="userSpaceOnUse">
                                    <path d="M 0 0 L 8 4 L 0 8 z" />
                                </marker>
                                <marker id="ac-bot-constructor-arrow-head-selected" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="userSpaceOnUse">
                                    <path d="M 0 0 L 8 4 L 0 8 z" />
                                </marker>
                            </defs>
                            @foreach ($arrows as $arrow)
                                <g
                                    wire:key="bot-constructor-canvas-arrow-{{ $arrow['id'] }}"
                                    class="ac-bot-constructor-arrow {{ (int) $arrow['source_block_id'] === (int) $arrow['target_block_id'] ? 'is-self-loop' : '' }} {{ $arrow['is_selected'] ? 'is-selected' : '' }} {{ $arrow['is_active'] ? '' : 'is-inactive' }}"
                                >
                                    <path
                                        class="ac-bot-constructor-arrow__hit"
                                        x-bind:d="arrowPath({{ $arrow['source_block_id'] }}, {{ $arrow['target_block_id'] }})"
                                        wire:click.stop="selectArrow({{ $arrow['id'] }})"
                                    />
                                    <path
                                        class="ac-bot-constructor-arrow__line"
                                        x-bind:d="arrowPath({{ $arrow['source_block_id'] }}, {{ $arrow['target_block_id'] }})"
                                        marker-end="{{ $arrow['is_selected'] ? 'url(#ac-bot-constructor-arrow-head-selected)' : 'url(#ac-bot-constructor-arrow-head)' }}"
                                    />
                                    <text
                                        class="ac-bot-constructor-arrow__label"
                                        x-bind:x="arrowLabelX({{ $arrow['source_block_id'] }}, {{ $arrow['target_block_id'] }})"
                                        x-bind:y="arrowLabelY({{ $arrow['source_block_id'] }}, {{ $arrow['target_block_id'] }})"
                                        wire:click.stop="selectArrow({{ $arrow['id'] }})"
                                    >
                                        {{ $arrow['delay_label'] }}
                                    </text>
                                </g>
                            @endforeach
                        </svg>

                        @forelse ($blocks as $block)
                            <article
                                class="ac-scenario-builder-node ac-scenario-builder-node--green ac-bot-constructor-node {{ $block['is_selected'] ? 'is-selected' : '' }} {{ $block['is_active'] ? '' : 'is-inactive' }}"
                                wire:key="bot-constructor-canvas-block-{{ $block['id'] }}"
                                role="button"
                                tabindex="0"
                                data-builder-block-id="{{ $block['id'] }}"
                                x-bind:class="{ 'is-dragging': draggingBlockId === '{{ $block['id'] }}' && hasDragged }"
                                x-bind:style="`left: ${nodeX({{ $block['id'] }})}px; top: ${nodeY({{ $block['id'] }})}px;`"
                                x-on:pointerdown="startPointer($event, {{ $block['id'] }})"
                                x-on:keydown.enter.prevent="$wire.selectBlock({{ $block['id'] }})"
                                x-on:keydown.space.prevent="$wire.selectBlock({{ $block['id'] }})"
                            >
                                <span class="ac-bot-constructor-node__id">ID #{{ $block['id'] }}</span>
                                <strong>{{ $block['title'] }}</strong>
                            </article>
                        @empty
                            <div class="ac-surface ac-scenario-builder-empty-state">
                                <strong>Стартовые условия ещё не созданы.</strong>
                            </div>
                        @endforelse
                    </div>
                </div>
            </main>

            <aside class="ac-scenario-builder-palette">
                @if (! $this->hasSelectedBlock() && ! $this->hasSelectedArrow())
                    <div class="ac-scenario-builder-panel__header">
                        <span>Элемент</span>
                        <strong>Блок не выбран</strong>
                    </div>
                @elseif ($this->hasSelectedArrow())
                    <div class="ac-scenario-builder-settings">
                        <div class="ac-scenario-builder-panel__header ac-scenario-builder-panel__header--with-action">
                            <div>
                                <span>Настройки соединения</span>
                                <strong>Стрелка #{{ $selectedArrowId }}</strong>
                            </div>
                            <button
                                type="button"
                                class="ac-scenario-builder-panel__danger-action"
                                wire:click="deleteArrow"
                                wire:confirm="Удалить стрелку?"
                            >
                                Удалить
                            </button>
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label>
                                <input type="checkbox" wire:model.live="draftArrowIsActive" />
                                Стрелка включена
                            </label>
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-arrow-source">Откуда</label>
                            <select id="bot-constructor-arrow-source" wire:model.live="draftArrowSourceBlockId">
                                @foreach ($this->blockOptions() as $blockId => $label)
                                    <option value="{{ $blockId }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('draftArrowSourceBlockId')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-arrow-target">Куда</label>
                            <select id="bot-constructor-arrow-target" wire:model.live="draftArrowTargetBlockId">
                                @foreach ($this->blockOptions() as $blockId => $label)
                                    <option value="{{ $blockId }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('draftArrowTargetBlockId')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-arrow-delay-value">Задержка перед переходом</label>
                            <div class="ac-bot-constructor-inline-grid">
                                <input
                                    id="bot-constructor-arrow-delay-value"
                                    type="number"
                                    min="0"
                                    wire:model.live="draftArrowDelayValue"
                                />
                                <select wire:model.live="draftArrowDelayUnit" aria-label="Единица задержки">
                                    @foreach ($this->arrowDelayUnitOptions() as $unit => $label)
                                        <option value="{{ $unit }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @error('draftArrowDelayValue')
                                <p>{{ $message }}</p>
                            @enderror
                            @error('draftArrowDelayUnit')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label>
                                <input type="checkbox" wire:model.live="draftArrowCancelIfLeftSourceBlock" />
                                Отменить, если диалог ушёл из исходного блока
                            </label>
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-arrow-condition-type">Условие стрелки</label>
                            <select id="bot-constructor-arrow-condition-type" wire:model.live="draftArrowConditionMatchType">
                                @foreach ($this->arrowConditionTypeOptions() as $type => $label)
                                    <option value="{{ $type }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('draftArrowConditionMatchType')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-arrow-condition-value">Значение условия</label>
                            <input
                                id="bot-constructor-arrow-condition-value"
                                type="text"
                                wire:model.live="draftArrowConditionValue"
                                placeholder="1"
                            />
                            @error('draftArrowConditionValue')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-arrow-priority">Приоритет</label>
                            <input
                                id="bot-constructor-arrow-priority"
                                type="number"
                                wire:model.live="draftArrowPriority"
                            />
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-arrow-limit-mode" title="Лимит переходов клиента по этой стрелке">Лимит переходов</label>
                            <select id="bot-constructor-arrow-limit-mode" wire:model.live="draftArrowPassLimitMode">
                                @foreach ($this->arrowPassLimitModeOptions() as $mode => $label)
                                    <option value="{{ $mode }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p>Константа: {{ $this->arrowPassLimitConstantLabel() }}</p>
                            @error('draftArrowPassLimitMode')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-arrow-limit-constant" title="Лимит переходов клиента по стрелке">Константа лимита</label>
                            <div class="ac-bot-constructor-inline-grid">
                                <input
                                    id="bot-constructor-arrow-limit-constant"
                                    type="number"
                                    min="1"
                                    wire:model.live="draftArrowPassLimitConstant"
                                />
                                <button type="button" class="ac-button ac-button--secondary" wire:click="saveArrowPassLimitConstant">
                                    Сохранить
                                </button>
                            </div>
                            @error('draftArrowPassLimitConstant')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        @if ($draftArrowPassLimitMode === \App\Models\BotConstructorArrow::PASS_LIMIT_MODE_MANUAL)
                            <div class="ac-scenario-builder-fieldset">
                                <label for="bot-constructor-arrow-limit-value" title="Лимит переходов клиента по этой стрелке">Ручной лимит</label>
                                <input
                                    id="bot-constructor-arrow-limit-value"
                                    type="number"
                                    min="1"
                                    wire:model.live="draftArrowPassLimitValue"
                                />
                                @error('draftArrowPassLimitValue')
                                    <p>{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    </div>
                @else
                    <div class="ac-scenario-builder-settings">
                        <div class="ac-scenario-builder-panel__header ac-scenario-builder-panel__header--with-action">
                            <div>
                                <span>Настройки элемента</span>
                                <strong>Стартовое условие #{{ $selectedBlockId }}</strong>
                            </div>
                            <button
                                type="button"
                                class="ac-scenario-builder-panel__danger-action"
                                wire:click="deleteBlock"
                                wire:confirm="Удалить блок? Связанные стрелки будут удалены, запланированные переходы отменены. История сохранится."
                            >
                                Удалить
                            </button>
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-title">Название блока</label>
                            <input
                                id="bot-constructor-title"
                                type="text"
                                wire:model.live="draftTitle"
                                placeholder="Стартовое условие"
                            />
                            @error('draftTitle')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label>
                                <input type="checkbox" wire:model.live="draftIsActive" />
                                Блок включён
                            </label>
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-channels">Каналы</label>
                            <select id="bot-constructor-channels" wire:model.live="draftChannelIds" multiple size="6">
                                @foreach ($this->channelOptions() as $channel)
                                    <option value="{{ $channel['id'] }}">
                                        {{ $channel['label'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('draftChannelIds')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-match-type">Тип совпадения</label>
                            <select id="bot-constructor-match-type" wire:model.live="draftMatchType">
                                @foreach ($this->matchTypeOptions() as $type => $label)
                                    <option value="{{ $type }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('draftMatchType')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-match-values">Условие</label>
                            <input
                                id="bot-constructor-match-values"
                                type="text"
                                wire:model.live="draftMatchValuesInput"
                                placeholder="привет; здравствуйте"
                            />
                            @error('draftMatchValuesInput')
                                <p>{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-scenario-builder-fieldset">
                            <label for="bot-constructor-response">Сообщение</label>
                            <textarea
                                id="bot-constructor-response"
                                wire:model.live="draftResponseText"
                                rows="6"
                                placeholder="#{none}"
                            ></textarea>
                        </div>
                    </div>
                @endif
            </aside>
        </div>
    </form>
</x-filament-panels::page>
