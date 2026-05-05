@php
    use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
    use App\Models\Bitrix24Connection;

    $record = $this->getRecord();
    $formatDate = static fn (mixed $value): string => $value instanceof DateTimeInterface
        ? $value->format('d.m.Y H:i:s')
        : '—';
    $formatText = static fn (mixed $value, string $empty = '—'): string => filled($value) ? (string) $value : $empty;
    $bitrixBoxConfigSnippet = $this->getBitrixBoxConfigSnippet();
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
                        <td>
                            @if ($this->canEditApplicationName())
                                <div class="flex min-w-0 flex-col gap-2">
                                    <input
                                        type="text"
                                        maxlength="120"
                                        placeholder="Название приложения"
                                        wire:model.live="applicationNameForm.application_name"
                                        class="w-full min-w-0 rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
                                    />
                                    <button
                                        type="button"
                                        wire:click="saveApplicationName"
                                        wire:loading.attr="disabled"
                                        wire:target="saveApplicationName"
                                        class="w-fit rounded-md bg-amber-500 px-3 py-1 text-xs font-semibold text-slate-950 transition hover:bg-amber-400 disabled:cursor-wait disabled:opacity-60"
                                    >
                                        Сохранить имя
                                    </button>
                                </div>
                            @else
                                <div class="ac-bitrix-cell-clip" title="{{ $formatText($record->application_name) }}">{{ $formatText($record->application_name) }}</div>
                            @endif
                        </td>
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

    @if ($bitrixBoxConfigSnippet)
        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50/70 p-4 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-950/20 dark:text-amber-100">
            <div class="mb-3 font-semibold">Bitrix-box line-level config для обратных сообщений</div>
            <div class="mb-3 text-xs leading-5 text-amber-900 dark:text-amber-200">
                Обновите только блок `connectors` в `local/php_interface/include/abrikosoff_openlines/config.php`. Глобальный `laravel.openlines_callback_url` не менять. Токены здесь не показываются.
            </div>
            <pre class="max-h-80 overflow-auto rounded-md border border-amber-200 bg-white p-3 text-xs leading-5 text-slate-900 dark:border-amber-500/30 dark:bg-slate-950 dark:text-slate-100">{{ $bitrixBoxConfigSnippet }}</pre>
        </div>
    @endif
@endif
