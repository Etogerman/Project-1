@php
    use App\Models\Bitrix24Connection;
    use App\Models\Bitrix24Profile;

    $record = $this->getRecord();
    $profile = $record instanceof Bitrix24Connection ? $record->profile : null;
    $formatText = static fn (mixed $value, string $empty = '—'): string => filled($value) ? (string) $value : $empty;
@endphp

@if (! $profile instanceof Bitrix24Profile)
    <div data-role="bitrix24-profile-settings-no-profile" class="ac-empty-state">
        Профиль Bitrix24 не привязан к подключению.
    </div>
@else
    <div class="ac-bitrix-profile-settings">
        @if ($this->profileSettingsErrorMessage)
            <div data-role="bitrix24-profile-settings-error" class="ac-bitrix-error">
                {{ $this->profileSettingsErrorMessage }}
            </div>
        @endif

        <div class="ac-bitrix-readonly-note ac-bitrix-readonly-note--route-help">
            Эти значения теперь хранятся в админке. Пустое поле означает временный fallback из .env.
        </div>

        <div class="ac-bitrix-profile-grid">
            <div class="ac-bitrix-table-shell ac-bitrix-overview-table-shell">
                <table class="ac-bitrix-table ac-bitrix-table--overview">
                    <tbody>
                        <tr>
                            <th scope="row">Профиль</th>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $profile->profile_key }}">{{ $profile->profile_key }}</div></td>
                        </tr>
                        <tr>
                            <th scope="row">Тип профиля</th>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $profile->profile_type }}">{{ $profile->profile_type }}</div></td>
                        </tr>
                        <tr>
                            <th scope="row">Callback base URL</th>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $profile->callback_base_url }}">{{ $profile->callback_base_url }}</div></td>
                        </tr>
                        <tr>
                            <th scope="row">Client ID</th>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $formatText($profile->client_id) }}">{{ $formatText($profile->client_id) }}</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="ac-bitrix-profile-form">
                <div class="ac-route-config-grid">
                    <label class="ac-route-field">
                        <span>Telegram CRM source</span>
                        <input
                            type="text"
                            wire:model.live="profileSettingsForm.telegram_source_id"
                            @disabled(! $this->canEditProfileSettings())
                        />
                    </label>
                    <label class="ac-route-field">
                        <span>MAX CRM source</span>
                        <input
                            type="text"
                            wire:model.live="profileSettingsForm.max_source_id"
                            @disabled(! $this->canEditProfileSettings())
                        />
                    </label>
                    <label class="ac-route-field">
                        <span>Telegram connector_code</span>
                        <input
                            type="text"
                            wire:model.live="profileSettingsForm.telegram_connector_code"
                            @disabled(! $this->canEditProfileSettings())
                        />
                    </label>
                    <label class="ac-route-field">
                        <span>MAX connector_code</span>
                        <input
                            type="text"
                            wire:model.live="profileSettingsForm.max_connector_code"
                            @disabled(! $this->canEditProfileSettings())
                        />
                    </label>
                    <label class="ac-route-field">
                        <span>Default assigned user ID</span>
                        <input
                            type="text"
                            inputmode="numeric"
                            wire:model.live="profileSettingsForm.default_assigned_user_id"
                            @disabled(! $this->canEditProfileSettings())
                        />
                    </label>
                    <label class="ac-route-field">
                        <span>Default deal category ID</span>
                        <input
                            type="text"
                            inputmode="numeric"
                            wire:model.live="profileSettingsForm.default_deal_category_id"
                            @disabled(! $this->canEditProfileSettings())
                        />
                    </label>
                    <label class="ac-route-field">
                        <span>Default deal stage ID</span>
                        <input
                            type="text"
                            wire:model.live="profileSettingsForm.default_deal_stage_id"
                            @disabled(! $this->canEditProfileSettings())
                        />
                    </label>
                </div>

                @if ($this->canEditProfileSettings())
                    <x-filament::button
                        type="button"
                        wire:click="saveProfileSettings"
                        wire:loading.attr="disabled"
                        wire:target="saveProfileSettings"
                        size="sm"
                    >
                        Сохранить настройки
                    </x-filament::button>
                @endif
            </div>
        </div>
    </div>
@endif
