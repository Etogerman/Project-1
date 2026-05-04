@php($items = $this->getWebhookEventCards())

<section data-role="bitrix24-webhook-events" class="ac-panel-stack">
    <div class="ac-bitrix-filters">
        <div>
            <label for="bitrix24-webhook-callback-type-filter" class="ac-field-label">Тип callback</label>
            <select
                id="bitrix24-webhook-callback-type-filter"
                wire:model.live="webhookEventCallbackTypeFilter"
                class="ac-select ac-bitrix-table__control"
            >
                <option value="">Все типы</option>
                @foreach ($callbackTypeOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="bitrix24-webhook-status-filter" class="ac-field-label">Статус обработки</label>
            <select
                id="bitrix24-webhook-status-filter"
                wire:model.live="webhookEventProcessingStatusFilter"
                class="ac-select ac-bitrix-table__control"
            >
                <option value="">Все статусы</option>
                @foreach ($processingStatusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($items === [])
        <div data-role="bitrix24-webhook-events-empty" class="ac-empty-state">
            Callback-событий по текущим фильтрам не найдено.
        </div>
    @else
        <div class="ac-bitrix-table-shell">
            <table class="ac-bitrix-table ac-bitrix-table--callbacks">
                <colgroup>
                    <col style="width: 16rem">
                    <col style="width: 8rem">
                    <col style="width: 8.5rem">
                    <col style="width: 10rem">
                    <col style="width: 6rem">
                    <col style="width: 10rem">
                    <col style="width: 24rem">
                </colgroup>

                <thead>
                    <tr>
                        <th scope="col">Событие</th>
                        <th scope="col">Тип</th>
                        <th scope="col">Статус</th>
                        <th scope="col">Создано</th>
                        <th scope="col">Попытки</th>
                        <th scope="col">Ошибка в</th>
                        <th scope="col">Причина</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        <tr data-role="bitrix24-webhook-event">
                            <td>
                                <div class="ac-bitrix-cell-main" title="{{ $item['event_name'] }}">
                                    {{ $item['event_name'] }}
                                </div>
                            </td>
                            <td>
                                <span data-tone="info" class="ac-pill">{{ $item['callback_type_label'] }}</span>
                            </td>
                            <td>
                                <span data-tone="{{ $item['processing_status_tone'] }}" class="ac-pill">
                                    {{ $item['processing_status_label'] }}
                                </span>
                            </td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['created_at_label'] }}">{{ $item['created_at_label'] }}</div></td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['attempts'] }}">{{ $item['attempts'] }}</div></td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['failed_at_label'] }}">{{ $item['failed_at_label'] }}</div></td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['failure_reason'] }}">{{ $item['failure_reason'] }}</div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
