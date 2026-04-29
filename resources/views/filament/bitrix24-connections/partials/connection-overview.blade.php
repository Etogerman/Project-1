@php
    use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
    use App\Models\Bitrix24Connection;

    $record = $this->getRecord();
    $formatDate = static fn (mixed $value): string => $value instanceof DateTimeInterface
        ? $value->format('d.m.Y H:i:s')
        : '—';
    $formatText = static fn (mixed $value, string $empty = '—'): string => filled($value) ? (string) $value : $empty;
@endphp

@if ($record instanceof Bitrix24Connection)
    <div class="ac-bitrix-overview-grid">
        <div class="ac-bitrix-table-shell ac-bitrix-overview-table-shell">
            <table class="ac-bitrix-table ac-bitrix-table--overview">
                <tbody>
                    <tr>
                        <th scope="row">ID</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $record->id }}">{{ $record->id }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Client ID</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatText($record->client_id) }}">{{ $formatText($record->client_id) }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Refresh</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatDate($record->last_refreshed_at) }}">{{ $formatDate($record->last_refreshed_at) }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Последняя ошибка</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatDate($record->last_error_at) }}">{{ $formatDate($record->last_error_at) }}</div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="ac-bitrix-table-shell ac-bitrix-overview-table-shell">
            <table class="ac-bitrix-table ac-bitrix-table--overview">
                <tbody>
                    <tr>
                        <th scope="row">Портал</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatText($record->portal_domain) }}">{{ $formatText($record->portal_domain) }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Member ID</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatText($record->member_id) }}">{{ $formatText($record->member_id) }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Install callback</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatDate($record->last_install_callback_at) }}">{{ $formatDate($record->last_install_callback_at) }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Текст ошибки</th>
                        <td>
                            <div class="ac-bitrix-cell-clip" title="{{ $formatText($record->last_error_message, 'Ошибок не было') }}">
                                {{ $formatText($record->last_error_message, 'Ошибок не было') }}
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="ac-bitrix-table-shell ac-bitrix-overview-table-shell">
            <table class="ac-bitrix-table ac-bitrix-table--overview">
                <tbody>
                    <tr>
                        <th scope="row">Приложение</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatText($record->application_name) }}">{{ $formatText($record->application_name) }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Установлено</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatDate($record->installed_at) }}">{{ $formatDate($record->installed_at) }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Events callback</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatDate($record->last_events_callback_at) }}">{{ $formatDate($record->last_events_callback_at) }}</div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="ac-bitrix-table-shell ac-bitrix-overview-table-shell">
            <table class="ac-bitrix-table ac-bitrix-table--overview">
                <tbody>
                    <tr>
                        <th scope="row">Статус</th>
                        <td>
                            <span class="ac-pill" data-tone="{{ Bitrix24ConnectionResource::getConnectionStatusColor($record->status) }}">
                                {{ Bitrix24ConnectionResource::formatConnectionStatus($record->status) }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Токен истекает</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatDate($record->access_token_expires_at) }}">{{ $formatDate($record->access_token_expires_at) }}</div></td>
                    </tr>
                    <tr>
                        <th scope="row">Openlines callback</th>
                        <td><div class="ac-bitrix-cell-clip" title="{{ $formatDate($record->last_openlines_callback_at) }}">{{ $formatDate($record->last_openlines_callback_at) }}</div></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endif
