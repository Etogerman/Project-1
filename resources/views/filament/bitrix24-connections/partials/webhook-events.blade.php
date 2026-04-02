@php($items = $this->getWebhookEventCards())

<section data-role="bitrix24-webhook-events" class="ac-panel-stack">
    <div class="ac-inline-split">
        <div class="ac-form-grid">
            <div>
                <label for="bitrix24-webhook-callback-type-filter" class="ac-field-label">Тип callback</label>
                <select
                    id="bitrix24-webhook-callback-type-filter"
                    wire:model.live="webhookEventCallbackTypeFilter"
                    class="ac-select"
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
                    class="ac-select"
                >
                    <option value="">Все статусы</option>
                    @foreach ($processingStatusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @if ($items === [])
        <div data-role="bitrix24-webhook-events-empty" class="ac-empty-state">
            Callback-событий по текущим фильтрам не найдено.
        </div>
    @else
        <div class="ac-list-stack">
            @foreach ($items as $item)
                <div data-role="bitrix24-webhook-event" class="ac-list-card">
                    <div class="ac-surface__header">
                        <div class="ac-surface__title-group">
                            <p class="ac-list-card__title">{{ $item['event_name'] }}</p>
                            <p class="ac-surface__subtitle">{{ $item['created_at_label'] }}</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <span data-tone="info" class="ac-pill">{{ $item['callback_type_label'] }}</span>
                            <span data-tone="{{ $item['processing_status_tone'] }}" class="ac-pill">{{ $item['processing_status_label'] }}</span>
                        </div>
                    </div>

                    <div class="ac-meta-grid">
                        <div class="ac-meta">
                            <p class="ac-meta__label">Попытки</p>
                            <p class="ac-meta__value">{{ $item['attempts'] }}</p>
                        </div>
                        <div class="ac-meta">
                            <p class="ac-meta__label">Failed at</p>
                            <p class="ac-meta__value">{{ $item['failed_at_label'] }}</p>
                        </div>
                        <div class="ac-meta ac-form-field--full">
                            <p class="ac-meta__label">Причина</p>
                            <p class="ac-meta__value">{{ $item['failure_reason'] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
