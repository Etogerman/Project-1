@php($items = $this->getSyncLogCards())

<section data-role="bitrix24-sync-logs" class="ac-panel-stack">
    <div class="ac-bitrix-filters">
        <div>
            <label for="bitrix24-sync-log-status-filter" class="ac-field-label">Статус</label>
            <select
                id="bitrix24-sync-log-status-filter"
                wire:model.live="syncLogStatusFilter"
                class="ac-select ac-bitrix-table__control"
            >
                <option value="">Все статусы</option>
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($items === [])
        <div data-role="bitrix24-sync-logs-empty" class="ac-empty-state">
            Sync-логов по текущим фильтрам не найдено.
        </div>
    @else
        <div class="ac-bitrix-table-shell">
            <table class="ac-bitrix-table ac-bitrix-table--sync">
                <colgroup>
                    <col style="width: 16rem">
                    <col style="width: 8rem">
                    <col style="width: 8rem">
                    <col style="width: 10rem">
                    <col style="width: 10rem">
                    <col style="width: 9rem">
                    <col style="width: 7rem">
                    <col style="width: 9rem">
                    <col style="width: 24rem">
                </colgroup>

                <thead>
                    <tr>
                        <th scope="col">Операция</th>
                        <th scope="col">Направление</th>
                        <th scope="col">Статус</th>
                        <th scope="col">Создано</th>
                        <th scope="col">Сущность</th>
                        <th scope="col">ID сущности</th>
                        <th scope="col">HTTP</th>
                        <th scope="col">Код ошибки</th>
                        <th scope="col">Ошибка</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        <tr data-role="bitrix24-sync-log">
                            <td><div class="ac-bitrix-cell-main" title="{{ $item['operation'] }}">{{ $item['operation'] }}</div></td>
                            <td><span data-tone="info" class="ac-pill">{{ $item['direction_label'] }}</span></td>
                            <td>
                                <span data-tone="{{ $item['status_tone'] }}" class="ac-pill">
                                    {{ $item['status_label'] }}
                                </span>
                            </td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['created_at_label'] }}">{{ $item['created_at_label'] }}</div></td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['entity_type'] }}">{{ $item['entity_type'] }}</div></td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['entity_id'] }}">{{ $item['entity_id'] }}</div></td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['http_status'] ?? '—' }}">{{ $item['http_status'] ?? '—' }}</div></td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['error_code'] }}">{{ $item['error_code'] }}</div></td>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $item['error_message'] }}">{{ $item['error_message'] }}</div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
