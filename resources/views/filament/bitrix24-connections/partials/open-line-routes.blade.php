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
                    <col class="ac-route-col-type">
                    <col class="ac-route-col-state">
                    <col class="ac-route-col-status">
                    <col class="ac-route-col-connector">
                    <col class="ac-route-col-line">
                    <col class="ac-route-col-source">
                    <col class="ac-route-col-id">
                    <col class="ac-route-col-owner">
                    <col class="ac-route-col-error">
                    <col class="ac-route-col-action">
                </colgroup>

                <thead>
                    <tr>
                        <th scope="col">Канал</th>
                        <th scope="col">Тип</th>
                        <th scope="col">Состояние</th>
                        <th scope="col">Статус</th>
                        <th scope="col">Код соединителя</th>
                        <th scope="col">Открытая линия</th>
                        <th scope="col">CRM source</th>
                        <th scope="col">Route ID</th>
                        <th scope="col">Владелец линии</th>
                        <th scope="col">Ошибка</th>
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
                                <span data-tone="{{ $item['route_status_tone'] }}" class="ac-pill">
                                    {{ $item['route_status_label'] }}
                                </span>
                            </td>
                            <td>
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
                            </td>
                            <td>
                                <input
                                    aria-label="Код соединителя {{ $item['channel_title'] }}"
                                    type="text"
                                    wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.connector_code"
                                    class="ac-input ac-bitrix-table__control"
                                    @disabled(! $canEdit)
                                />
                            </td>
                            <td>
                                <input
                                    aria-label="Открытая линия {{ $item['channel_title'] }}"
                                    type="text"
                                    wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.line_id"
                                    class="ac-input ac-bitrix-table__control"
                                    @disabled(! $canEdit)
                                />
                            </td>
                            <td>
                                <input
                                    aria-label="CRM source {{ $item['channel_title'] }}"
                                    type="text"
                                    wire:model.live="openLineRouteForms.{{ $item['channel_id'] }}.source_id"
                                    class="ac-input ac-bitrix-table__control"
                                    @disabled(! $canEdit)
                                />
                            </td>
                            <td>
                                <div class="ac-bitrix-cell-clip" title="{{ $item['route_id'] ?? '—' }}">
                                    {{ $item['route_id'] ?? '—' }}
                                </div>
                            </td>
                            <td>
                                <div class="ac-bitrix-cell-clip" title="{{ $item['line_owner_label'] }}">
                                    {{ $item['line_owner_label'] }}
                                </div>
                            </td>
                            <td>
                                <div class="ac-bitrix-cell-clip" title="{{ $item['last_error_message'] }}">
                                    {{ $item['last_error_message'] }}
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
