<x-filament-panels::page>
    @php($blocks = $this->blocks())

    <form wire:submit.prevent="saveBlock" class="ac-scenario-builder-page">
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
                        <button type="submit" class="ac-button ac-button--primary" @disabled(! $this->hasSelectedBlock())>
                            Сохранить
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
                @if (! $this->hasSelectedBlock())
                    <div class="ac-scenario-builder-panel__header">
                        <span>Элемент</span>
                        <strong>Блок не выбран</strong>
                    </div>
                @else
                    <div class="ac-scenario-builder-settings">
                        <div class="ac-scenario-builder-panel__header">
                            <span>Настройки элемента</span>
                            <strong>Стартовое условие #{{ $selectedBlockId }}</strong>
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
