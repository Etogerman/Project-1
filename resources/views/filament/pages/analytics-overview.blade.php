<x-filament-panels::page>
    <div class="ac-analytics" data-role="analytics-overview">
        <section class="ac-analytics-panel ac-analytics-hero">
            <div class="ac-analytics-hero__copy">
                <p class="ac-analytics-eyebrow">Обзор</p>
                <h2 class="ac-analytics-hero__title">Рабочие показатели</h2>
                <p class="ac-analytics-hero__period">Период: {{ $period['label'] }}</p>
            </div>

            <div class="ac-analytics-period" aria-label="Период аналитики">
                <div class="ac-analytics-period__presets">
                    @foreach ([
                        'today' => 'Сегодня',
                        '7_days' => '7 дней',
                        '30_days' => '30 дней',
                    ] as $preset => $label)
                        <button
                            type="button"
                            wire:click="selectPeriod('{{ $preset }}')"
                            @class([
                                'ac-analytics-period__button',
                                'ac-analytics-period__button--active' => $periodPreset === $preset,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="ac-analytics-period__dates">
                    <label class="ac-analytics-period__date">
                        <span>С</span>
                        <input type="date" wire:model.live="periodFrom">
                    </label>

                    <label class="ac-analytics-period__date">
                        <span>По</span>
                        <input type="date" wire:model.live="periodUntil">
                    </label>
                </div>
            </div>
        </section>

        <section class="ac-analytics-section">
            <header class="ac-analytics-section__header">
                <h2>За период</h2>
                <span>события</span>
            </header>

            <div class="ac-analytics-metric-grid ac-analytics-metric-grid--period">
                @foreach ($periodMetrics as $metric)
                    <article class="ac-analytics-card ac-analytics-metric" data-metric="{{ $metric['key'] }}" data-value="{{ $metric['value'] }}">
                        <p class="ac-analytics-metric__label">{{ $metric['label'] }}</p>
                        <p class="ac-analytics-metric__value">{{ number_format($metric['value'], 0, ',', ' ') }}</p>
                        <p class="ac-analytics-metric__caption">{{ $metric['caption'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="ac-analytics-section">
            <header class="ac-analytics-section__header">
                <h2>Сейчас</h2>
                <span>текущее состояние</span>
            </header>

            <div class="ac-analytics-metric-grid ac-analytics-metric-grid--snapshot">
                @foreach ($snapshotMetrics as $metric)
                    <article class="ac-analytics-card ac-analytics-metric" data-metric="{{ $metric['key'] }}" data-value="{{ $metric['value'] }}">
                        <p class="ac-analytics-metric__label">{{ $metric['label'] }}</p>
                        <p class="ac-analytics-metric__value">{{ number_format($metric['value'], 0, ',', ' ') }}</p>
                        <p class="ac-analytics-metric__caption">{{ $metric['caption'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <div class="ac-analytics-layout">
            <section class="ac-analytics-panel ac-analytics-stage-panel">
                <header class="ac-analytics-panel__header">
                    <h2>Текущие этапы диалогов</h2>
                    <span>сейчас</span>
                </header>

                <div class="ac-analytics-stage-list">
                    @foreach ($stageRows as $row)
                        <div class="ac-analytics-stage" data-stage="{{ $row['stage'] }}" data-count="{{ $row['count'] }}">
                            <div class="ac-analytics-stage__meta">
                                <span>{{ $row['label'] }}</span>
                                <strong>{{ $row['count'] }}</strong>
                            </div>
                            <div class="ac-analytics-stage__track">
                                <div class="ac-analytics-stage__bar" style="width: {{ $row['share'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="ac-analytics-panel">
                <header class="ac-analytics-panel__header">
                    <h2>Топ тегов за период</h2>
                    <span>назначения</span>
                </header>

                <div class="ac-analytics-list">
                    @forelse ($tagRows as $row)
                        <div class="ac-analytics-list__row" data-tag-id="{{ $row['tag_id'] }}" data-count="{{ $row['count'] }}">
                            <span>{{ $row['label'] }}</span>
                            <strong>{{ $row['count'] }}</strong>
                        </div>
                    @empty
                        <div class="ac-analytics-empty">За выбранный период теги не назначались.</div>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="ac-analytics-panel">
            <header class="ac-analytics-panel__header">
                <h2>Каналы за период</h2>
                <span>диалоги и блокировки</span>
            </header>

            <div class="ac-analytics-table-wrap">
                <table class="ac-analytics-table" data-role="analytics-channel-table">
                    <thead>
                        <tr>
                            <th>Канал</th>
                            <th class="ac-analytics-table__num">Новые диалоги</th>
                            <th class="ac-analytics-table__num">Телефон получен</th>
                            <th class="ac-analytics-table__num">Блокировки</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($channelRows as $row)
                            <tr data-channel-id="{{ $row['channel_id'] }}">
                                <td>{{ $row['label'] }}</td>
                                <td class="ac-analytics-table__num">{{ $row['new_dialogs'] }}</td>
                                <td class="ac-analytics-table__num">{{ $row['phones_received'] }}</td>
                                <td class="ac-analytics-table__num">{{ $row['bot_blocks'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="ac-analytics-table__empty">За выбранный период событий по каналам нет.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ac-analytics-panel">
            <header class="ac-analytics-panel__header">
                <h2>Проблемные диалоги сейчас</h2>
                <span>очередь внимания</span>
            </header>

            <div class="ac-analytics-table-wrap">
                <table class="ac-analytics-table" data-role="analytics-problem-dialogs">
                    <thead>
                        <tr>
                            <th>Диалог</th>
                            <th>Канал</th>
                            <th>Причины</th>
                            <th class="ac-analytics-table__num">Активность</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($problemDialogs as $dialog)
                            <tr data-dialog-id="{{ $dialog['id'] }}">
                                <td>
                                    <a href="{{ $dialog['url'] }}" class="ac-analytics-link">
                                        #{{ $dialog['id'] }} {{ $dialog['contact'] }}
                                    </a>
                                </td>
                                <td>{{ $dialog['channel'] }}</td>
                                <td>
                                    <div class="ac-analytics-badge-list">
                                        @foreach ($dialog['reasons'] as $reason)
                                            <span class="ac-analytics-badge">{{ $reason }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="ac-analytics-table__num">{{ $dialog['last_activity'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="ac-analytics-table__empty">Проблемных диалогов сейчас нет.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
