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

                    @if ($item['can_view_raw_payloads'])
                        <div class="mt-4 grid gap-3 xl:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <p class="text-sm font-semibold text-slate-900">Request payload</p>
                                    <span class="text-xs text-slate-500">
                                        {{ $item['has_request_payload'] ? 'raw' : 'empty' }}
                                    </span>
                                </div>

                                <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-xl bg-slate-900 p-3 text-xs text-slate-100">{{ $item['request_payload_pretty'] }}</pre>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <p class="text-sm font-semibold text-slate-900">Response payload</p>
                                    <span class="text-xs text-slate-500">
                                        {{ $item['has_response_payload'] ? 'raw' : 'empty' }}
                                    </span>
                                </div>

                                <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-xl bg-slate-900 p-3 text-xs text-slate-100">{{ $item['response_payload_pretty'] }}</pre>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
