@php($items = $this->getSyncLogCards())

<section data-role="bitrix24-sync-logs" class="ac-panel-stack">
    <div class="ac-inline-split">
        <div class="ac-form-grid">
            <div>
                <label for="bitrix24-sync-log-status-filter" class="ac-field-label">Статус</label>
                <select
                    id="bitrix24-sync-log-status-filter"
                    wire:model.live="syncLogStatusFilter"
                    class="ac-select"
                >
                    <option value="">Все статусы</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @if ($items === [])
        <div data-role="bitrix24-sync-logs-empty" class="ac-empty-state">
            Sync-логов по текущим фильтрам не найдено.
        </div>
    @else
        <div class="ac-list-stack">
            @foreach ($items as $item)
                <div data-role="bitrix24-sync-log" class="ac-list-card">
                    <div class="ac-surface__header">
                        <div class="ac-surface__title-group">
                            <p class="ac-list-card__title">{{ $item['operation'] }}</p>
                            <p class="ac-surface__subtitle">{{ $item['created_at_label'] }}</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <span data-tone="info" class="ac-pill">{{ $item['direction_label'] }}</span>
                            <span data-tone="{{ $item['status_tone'] }}" class="ac-pill">{{ $item['status_label'] }}</span>
                        </div>
                    </div>

                    <div class="ac-meta-grid">
                        <div class="ac-meta">
                            <p class="ac-meta__label">Entity type</p>
                            <p class="ac-meta__value">{{ $item['entity_type'] }}</p>
                        </div>
                        <div class="ac-meta">
                            <p class="ac-meta__label">Entity ID</p>
                            <p class="ac-meta__value">{{ $item['entity_id'] }}</p>
                        </div>
                        <div class="ac-meta">
                            <p class="ac-meta__label">HTTP status</p>
                            <p class="ac-meta__value">{{ $item['http_status'] ?? '—' }}</p>
                        </div>
                        <div class="ac-meta">
                            <p class="ac-meta__label">Error code</p>
                            <p class="ac-meta__value">{{ $item['error_code'] }}</p>
                        </div>
                        <div class="ac-meta ac-form-field--full">
                            <p class="ac-meta__label">Ошибка</p>
                            <p class="ac-meta__value">{{ $item['error_message'] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
