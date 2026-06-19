@php
    use App\Models\Channel;

    $renderValue = static function (array $row): string {
        $value = (string) ($row['value'] ?? '—');
        $tone = $row['tone'] ?? null;

        if (filled($tone) && $value !== '—') {
            return sprintf(
                '<span class="ac-pill" data-tone="%s">%s</span>',
                e((string) $tone),
                e($value),
            );
        }

        return sprintf(
            '<div class="ac-bitrix-cell-clip" title="%s">%s</div>',
            e($value),
            e($value),
        );
    };
@endphp

@if ($record instanceof Channel)
    <div class="ac-channel-view-sheet">
        <section class="ac-channel-view-panel">
            <header class="ac-channel-view-panel__header">Сводка канала</header>
            <div class="ac-channel-view-panel__body">
                <div class="ac-bitrix-overview-grid">
                    @foreach ($summaryTables as $tableRows)
                        <div class="ac-bitrix-table-shell ac-bitrix-overview-table-shell">
                            <table class="ac-bitrix-table ac-bitrix-table--overview">
                                <tbody>
                                    @foreach ($tableRows as $row)
                                        <tr>
                                            <th scope="row">{{ $row['label'] }}</th>
                                            <td>{!! $renderValue($row) !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="ac-channel-view-panel">
            <header class="ac-channel-view-panel__header">Последнее входящее событие</header>
            <div class="ac-channel-view-panel__body">
                <div class="ac-bitrix-overview-grid">
                    @foreach ($latestMessageTables as $tableRows)
                        <div class="ac-bitrix-table-shell ac-bitrix-overview-table-shell">
                            <table class="ac-bitrix-table ac-bitrix-table--overview">
                                <tbody>
                                    @foreach ($tableRows as $row)
                                        <tr>
                                            <th scope="row">{{ $row['label'] }}</th>
                                            <td>{!! $renderValue($row) !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <div class="ac-channel-view-panel ac-channel-view-panel--collapsible" x-data="{ open: false }" x-bind:data-open="open ? 'true' : 'false'">
            <button type="button" class="ac-channel-view-panel__header ac-channel-view-panel__summary" x-bind:aria-expanded="open ? 'true' : 'false'" x-on:click="open = ! open">
                <span>Лента сообщений</span>
                <span class="ac-channel-view-panel__summary-state" aria-hidden="true" x-text="open ? 'Свернуть' : 'Развернуть'">Развернуть</span>
            </button>
            <div class="ac-channel-view-panel__body" x-show="open" x-cloak>
                <div class="ac-bitrix-table-shell">
                    <table class="ac-bitrix-table ac-channel-view-feed-table">
                        <tbody>
                            <tr>
                                <th scope="row">Последние сохранённые сообщения</th>
                                <td>{!! $recentMessagesFeed !!}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ac-channel-view-panel ac-channel-view-panel--collapsible" x-data="{ open: false }" x-bind:data-open="open ? 'true' : 'false'">
            <button type="button" class="ac-channel-view-panel__header ac-channel-view-panel__summary" x-bind:aria-expanded="open ? 'true' : 'false'" x-on:click="open = ! open">
                <span>Техжурнал</span>
                <span class="ac-channel-view-panel__summary-state" aria-hidden="true" x-text="open ? 'Свернуть' : 'Развернуть'">Развернуть</span>
            </button>
            <div class="ac-channel-view-panel__body" x-show="open" x-cloak>
                <div class="ac-bitrix-table-shell">
                    <table class="ac-bitrix-table ac-channel-view-feed-table">
                        <tbody>
                            <tr>
                                <th scope="row">Последние события канала</th>
                                <td>{!! $recentActivityFeed !!}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endif
