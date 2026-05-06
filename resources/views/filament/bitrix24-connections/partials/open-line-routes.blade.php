@php
    $items = $this->getOpenLineRouteCards();
    $canEdit = $this->canEditOpenLineRoutes();
@endphp

<section data-role="bitrix24-open-line-routes" class="ac-panel-stack">
    @if ($this->openLineRouteErrorMessage)
        <div data-role="bitrix24-open-line-route-error" class="ac-empty-state">
            {{ $this->openLineRouteErrorMessage }}
        </div>
    @endif

    @if (! $this->getRecord()->profile)
        <div data-role="bitrix24-open-line-routes-no-profile" class="ac-empty-state">
            У подключения Bitrix24 нет профиля. Маршруты открытых линий пока нельзя настроить.
        </div>
    @elseif ($items === [])
        <div data-role="bitrix24-open-line-routes-empty" class="ac-empty-state">
            Каналы связи ещё не созданы.
        </div>
    @else
        <div class="ac-bitrix-table-shell">
            <table class="ac-bitrix-table ac-bitrix-table--routes">
                <colgroup>
                    <col class="ac-route-col-channel">
                    <col class="ac-route-col-config">
                    <col class="ac-route-col-diagnostics">
                </colgroup>

                <thead>
                    <tr>
                        <th scope="col">Канал</th>
                        <th scope="col">Маршрут</th>
                        <th scope="col">Диагностика</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        <tr data-role="bitrix24-open-line-route-card">
                            <td>
                                <div class="ac-route-channel-cell">
                                    <div class="ac-bitrix-cell-main" title="{{ $item['channel_title'] }}">
                                        {{ $item['channel_title'] }}
                                    </div>

                                    <div class="ac-route-channel-controls">
                                        <span data-tone="info" class="ac-pill">{{ $item['channel_type_label'] }}</span>
                                        <span
                                            data-tone="{{ $item['route_status_tone'] }}"
                                            class="ac-pill ac-route-status-pill"
                                            title="Состояние маршрута: {{ $item['route_status_label'] }}"
                                        >
                                            {{ $item['route_status_label'] }}
                                        </span>

                                        @if ($canEdit)
                                            <div class="ac-route-channel-actions">
                                                <button
                                                    type="button"
                                                    wire:click="saveOpenLineRoute({{ $item['channel_id'] }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="saveOpenLineRoute({{ $item['channel_id'] }})"
                                                    class="ac-icon-button ac-route-icon-button"
                                                    title="Сохранить маршрут: {{ $item['channel_title'] }}"
                                                    aria-label="Сохранить маршрут: {{ $item['channel_title'] }}"
                                                >
                                                    <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                                                </button>

                                                @if ($item['auto_setup_visible'])
                                                    @php
                                                        $autoSetupIcon = str_contains($item['auto_setup_label'], 'Обновить')
                                                            ? 'heroicon-m-arrow-path'
                                                            : 'heroicon-m-cog-6-tooth';
                                                        $autoSetupTitle = $item['auto_setup_enabled']
                                                            ? $item['auto_setup_label'].': '.$item['channel_title']
                                                            : $item['auto_setup_reason'];
                                                    @endphp

                                                    <button
                                                        type="button"
                                                        wire:click="setupOpenLineRoute({{ $item['channel_id'] }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="setupOpenLineRoute({{ $item['channel_id'] }})"
                                                        class="ac-icon-button ac-route-icon-button ac-route-icon-button--warning"
                                                        title="{{ $autoSetupTitle }}"
                                                        aria-label="{{ $item['auto_setup_label'] }}: {{ $item['channel_title'] }}"
                                                        @disabled(! $item['auto_setup_enabled'])
                                                    >
                                                        <x-filament::icon :icon="$autoSetupIcon" class="h-4 w-4" />
                                                    </button>

                                                    @if (! $item['auto_setup_enabled'] && filled($item['auto_setup_reason']))
                                                        <p class="ac-route-action-note" title="{{ $item['auto_setup_reason'] }}">
                                                            {{ $item['auto_setup_reason'] }}
                                                        </p>
                                                    @endif
                                                @endif
                                            </div>
                                        @else
                                            <p class="ac-route-action-note">
                                                Для изменения маршрутов нужно право bitrix24.edit.
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="ac-route-config-grid">
                                    <label class="ac-route-field">
                                        <span>Статус</span>
                                        <select
                                            aria-label="Статус маршрута {{ $item['channel_title'] }}"
                                            wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.status"
                                            class="ac-select ac-bitrix-table__control"
                                            @disabled(! $canEdit)
                                        >
                                            @foreach ($statusOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="ac-route-field">
                                        <span>Код соединителя</span>
                                        <input
                                            aria-label="Код соединителя {{ $item['channel_title'] }}"
                                            type="text"
                                            wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.connector_code"
                                            class="ac-input ac-bitrix-table__control"
                                            @disabled(! $canEdit)
                                        />
                                    </label>

                                    <label class="ac-route-field">
                                        <span class="ac-route-field-label">
                                            LINE_ID
                                            <span
                                                class="ac-route-info-icon"
                                                title="LINE_ID задаётся для конкретного канала. Если подключено несколько Telegram-ботов, у каждого должна быть отдельная строка маршрута."
                                                aria-label="Подсказка по LINE_ID"
                                            >
                                                <x-filament::icon icon="heroicon-m-information-circle" class="h-3 w-3" />
                                            </span>
                                        </span>
                                        <input
                                            aria-label="LINE_ID {{ $item['channel_title'] }}"
                                            type="text"
                                            wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.line_id"
                                            class="ac-input ac-bitrix-table__control"
                                            @disabled(! $canEdit)
                                        />
                                    </label>

                                    <label class="ac-route-field">
                                        <span>Имя ОЛ</span>
                                        <input
                                            aria-label="Имя ОЛ {{ $item['channel_title'] }}"
                                            type="text"
                                            wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.line_name"
                                            class="ac-input ac-bitrix-table__control"
                                            @disabled(! $canEdit)
                                        />
                                    </label>

                                    <label class="ac-route-field">
                                        <span>Callback owner</span>
                                        <select
                                            aria-label="Callback owner {{ $item['channel_title'] }}"
                                            wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.callback_owner_id"
                                            class="ac-select ac-bitrix-table__control"
                                            @disabled(! $canEdit)
                                        >
                                            <option value="">Не выбран</option>
                                            @foreach ($item['callback_owner_options'] as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="ac-route-field">
                                        <span>CRM source</span>
                                        <input
                                            aria-label="CRM source {{ $item['channel_title'] }}"
                                            type="text"
                                            wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.source_id"
                                            class="ac-input ac-bitrix-table__control"
                                            @disabled(! $canEdit)
                                        />
                                    </label>
                                </div>
                            </td>
                            <td>
                                <div class="ac-route-diagnostics">
                                    <div class="ac-route-diagnostic-line">
                                        <span>Route ID</span>
                                        <strong>{{ $item['route_id'] ?? '—' }}</strong>
                                    </div>
                                    <div class="ac-route-diagnostic-line" title="{{ $item['callback_owner_label'] }}">
                                        <span>Callback</span>
                                        <strong>{{ $item['callback_owner_label'] }}</strong>
                                    </div>
                                    <div class="ac-route-diagnostic-line" data-tone="{{ $item['callback_diagnostic_tone'] }}" title="{{ $item['callback_diagnostic_label'] }}">
                                        <span>Входящие</span>
                                        <strong>{{ $item['callback_diagnostic_label'] }}</strong>
                                    </div>
                                    <div class="ac-route-diagnostic-line" data-tone="{{ $item['binding_diagnostic_tone'] }}" title="{{ $item['binding_diagnostic_label'] }}">
                                        <span>Привязка</span>
                                        <strong>{{ $item['binding_diagnostic_label'] }}</strong>

                                        @if ($canEdit && $item['binding_diagnostic_can_reset'])
                                            <button
                                                type="button"
                                                wire:click="resetStaleOpenLineBindings({{ $item['channel_id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="resetStaleOpenLineBindings({{ $item['channel_id'] }})"
                                                class="ac-route-mini-action"
                                                title="Сбросить устаревшие привязки диалогов"
                                            >
                                                Сбросить
                                            </button>
                                        @endif
                                    </div>
                                    <div class="ac-route-diagnostic-line" title="{{ $item['line_owner_label'] }}">
                                        <span>Линия</span>
                                        <strong>{{ $item['line_owner_label'] }}</strong>
                                    </div>
                                    <div class="ac-route-diagnostic-line" title="{{ $item['last_error_message'] }}">
                                        <span>Ошибка</span>
                                        <strong>{{ $item['last_error_message'] }}</strong>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
