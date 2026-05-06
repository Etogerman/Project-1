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
        <p class="ac-bitrix-readonly-note ac-bitrix-readonly-note--route-help">
            LINE_ID задаётся для конкретного канала. Если подключено несколько Telegram-ботов, у каждого должна быть отдельная строка маршрута.
        </p>

        <div class="ac-bitrix-table-shell">
            <table class="ac-bitrix-table ac-bitrix-table--routes">
                <colgroup>
                    <col class="ac-route-col-channel">
                    <col class="ac-route-col-type">
                    <col class="ac-route-col-config">
                    <col class="ac-route-col-diagnostics">
                    <col class="ac-route-col-action">
                </colgroup>

                <thead>
                    <tr>
                        <th scope="col">Канал</th>
                        <th scope="col">Тип</th>
                        <th scope="col">Маршрут</th>
                        <th scope="col">Диагностика</th>
                        <th scope="col">Действие</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        <tr data-role="bitrix24-open-line-route-card">
                            <td>
                                <div class="ac-bitrix-cell-main" title="{{ $item['channel_title'] }}">
                                    {{ $item['channel_title'] }}
                                </div>
                            </td>
                            <td>
                                <span data-tone="info" class="ac-pill">{{ $item['channel_type_label'] }}</span>
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
                                        <span>LINE_ID</span>
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
                                    <span data-tone="{{ $item['route_status_tone'] }}" class="ac-pill">
                                        {{ $item['route_status_label'] }}
                                    </span>
                                    <div class="ac-route-diagnostic-line">
                                        <span>Route ID</span>
                                        <strong>{{ $item['route_id'] ?? '—' }}</strong>
                                    </div>
                                    <div class="ac-route-diagnostic-line" title="{{ $item['line_owner_label'] }}">
                                        <span>Владелец</span>
                                        <strong>{{ $item['line_owner_label'] }}</strong>
                                    </div>
                                    <div class="ac-route-diagnostic-line" title="{{ $item['last_error_message'] }}">
                                        <span>Ошибка</span>
                                        <strong>{{ $item['last_error_message'] }}</strong>
                                    </div>
                                </div>
                            </td>
                            <td class="ac-bitrix-action-cell">
                                @if ($canEdit)
                                    <div class="ac-bitrix-action-stack">
                                        <x-filament::button
                                            type="button"
                                            size="sm"
                                            wire:click="saveOpenLineRoute({{ $item['channel_id'] }})"
                                        >
                                            Сохранить
                                        </x-filament::button>

                                        @if ($item['auto_setup_visible'])
                                            <x-filament::button
                                                type="button"
                                                size="sm"
                                                color="warning"
                                                wire:click="setupOpenLineRoute({{ $item['channel_id'] }})"
                                                :disabled="! $item['auto_setup_enabled']"
                                            >
                                                {{ $item['auto_setup_label'] }}
                                            </x-filament::button>

                                            @if (! $item['auto_setup_enabled'] && filled($item['auto_setup_reason']))
                                                <p class="ac-bitrix-readonly-note">
                                                    {{ $item['auto_setup_reason'] }}
                                                </p>
                                            @endif
                                        @endif
                                    </div>
                                @else
                                    <p class="ac-bitrix-readonly-note">
                                        Для изменения маршрутов нужно право bitrix24.edit.
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
