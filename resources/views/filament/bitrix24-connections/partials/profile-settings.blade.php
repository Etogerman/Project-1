@php
    use App\Models\Bitrix24Connection;
    use App\Models\Bitrix24Profile;

    $record = $this->getRecord();
    $profile = $record instanceof Bitrix24Connection ? $record->profile : null;
    $formatText = static fn (mixed $value, string $empty = '—'): string => filled($value) ? (string) $value : $empty;
    $callbackOwners = $this->getCallbackOwnerCards();
    $callbackOwnerStatusOptions = $this->getCallbackOwnerStatusOptions();
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

        @if ($this->callbackOwnersErrorMessage)
            <div data-role="bitrix24-callback-owners-error" class="ac-bitrix-error">
                {{ $this->callbackOwnersErrorMessage }}
            </div>
        @endif

        <div class="ac-bitrix-profile-hint">
            <span class="ac-bitrix-profile-hint__label">Настройки профиля</span>
            <span>Хранятся в админке. Пустое поле временно берётся из .env.</span>
        </div>

        <div class="ac-bitrix-profile-grid">
            <div class="ac-bitrix-table-shell ac-bitrix-overview-table-shell">
                <table class="ac-bitrix-table ac-bitrix-table--overview">
                    <tbody>
                        <tr>
                            <th scope="row">Ключ профиля</th>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $profile->profile_key }}">{{ $profile->profile_key }}</div></td>
                        </tr>
                        <tr>
                            <th scope="row">Тип</th>
                            <td><div class="ac-bitrix-cell-clip" title="{{ $profile->profile_type }}">{{ $profile->profile_type }}</div></td>
                        </tr>
                        <tr>
                            <th scope="row">Callback URL</th>
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
                <section class="ac-bitrix-profile-section ac-bitrix-profile-section--static">
                    <div class="ac-bitrix-profile-section-title">
                        <span>Callback-владельцы</span>

                        @if ($this->canEditCallbackOwners())
                            <button
                                type="button"
                                wire:click="createLocalCallbackOwner"
                                wire:loading.attr="disabled"
                                wire:target="createLocalCallbackOwner"
                                class="ac-profile-inline-action"
                            >
                                Создать local-1
                            </button>
                        @endif
                    </div>

                    <div class="ac-bitrix-profile-section-body">
                        @if ($callbackOwners === [])
                            <div class="ac-empty-state ac-empty-state--compact">
                                Callback-владельцы ещё не созданы.
                            </div>
                        @else
                            <div class="ac-callback-owner-list">
                                @foreach ($callbackOwners as $owner)
                                    <div class="ac-callback-owner-card" data-role="bitrix24-callback-owner-card">
                                        <div class="ac-callback-owner-card__header">
                                            <strong title="{{ $owner['label'] }}">{{ $owner['label'] }}</strong>
                                            <span data-tone="{{ $owner['status_tone'] }}" class="ac-pill">{{ $owner['status_label'] }}</span>
                                        </div>

                                        <div class="ac-route-config-grid">
                                            <label class="ac-route-field">
                                                <span>Ключ</span>
                                                <input
                                                    type="text"
                                                    wire:model.live="callbackOwnerForms.{{ $owner['id'] }}.owner_key"
                                                    @disabled(! $this->canEditCallbackOwners())
                                                />
                                            </label>

                                            <label class="ac-route-field">
                                                <span>Название</span>
                                                <input
                                                    type="text"
                                                    wire:model.live="callbackOwnerForms.{{ $owner['id'] }}.display_name"
                                                    @disabled(! $this->canEditCallbackOwners())
                                                />
                                            </label>

                                            <label class="ac-route-field">
                                                <span>Статус</span>
                                                <select
                                                    wire:model.live="callbackOwnerForms.{{ $owner['id'] }}.status"
                                                    class="ac-select"
                                                    @disabled(! $this->canEditCallbackOwners())
                                                >
                                                    @foreach ($callbackOwnerStatusOptions as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>

                                            <label class="ac-route-field ac-route-field--wide">
                                                <span>Callback URL</span>
                                                <input
                                                    type="text"
                                                    wire:model.live="callbackOwnerForms.{{ $owner['id'] }}.callback_base_url"
                                                    @disabled(! $this->canEditCallbackOwners())
                                                />
                                            </label>

                                            @if ($this->canEditCallbackOwners())
                                                <div class="ac-route-field ac-route-field--action">
                                                    <span>Действие</span>
                                                    <button
                                                        type="button"
                                                        wire:click="saveCallbackOwner({{ $owner['id'] }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="saveCallbackOwner({{ $owner['id'] }})"
                                                        class="ac-profile-secondary-button"
                                                    >
                                                        Сохранить
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                <details class="ac-bitrix-profile-section ac-bitrix-profile-section--primary">
                    <summary class="ac-bitrix-profile-section-title">
                        <span>Маршруты и CRM source</span>
                        <small>Развернуть</small>
                    </summary>

                    <div class="ac-bitrix-profile-section-body">
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
                    </div>
                </details>

                <details class="ac-bitrix-profile-section">
                    <summary class="ac-bitrix-profile-section-title">
                        <span>CRM поля Bitrix24</span>
                        <small>Развернуть</small>
                    </summary>

                    <div class="ac-bitrix-profile-section-body">
                        <div class="ac-route-config-grid">
                            <label class="ac-route-field">
                                <span>Name source field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_name_source" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Age exact field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_age_exact" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Gender field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_gender" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Age range field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_age_range" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Contact ID field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_contact_id" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Channel ID field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_channel_id" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Channel name field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_channel_name" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Platform field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_platform" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Bot code field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_bot_code" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Bot name field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_bot_name" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Alt first name field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_alt_first_name" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Alt last name field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_alt_last_name" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Name conflict field</span>
                                <input type="text" wire:model.live="profileSettingsForm.crm_field_name_conflict" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                        </div>
                    </div>
                </details>

                <details class="ac-bitrix-profile-section">
                    <summary class="ac-bitrix-profile-section-title">
                        <span>CRM значения enum</span>
                        <small>Развернуть</small>
                    </summary>

                    <div class="ac-bitrix-profile-section-body">
                        <div class="ac-route-config-grid">
                            <label class="ac-route-field">
                                <span>Name automatic ID</span>
                                <input type="text" inputmode="numeric" wire:model.live="profileSettingsForm.crm_name_source_automatic_id" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Name self reported ID</span>
                                <input type="text" inputmode="numeric" wire:model.live="profileSettingsForm.crm_name_source_self_reported_id" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Name training ID</span>
                                <input type="text" inputmode="numeric" wire:model.live="profileSettingsForm.crm_name_source_training_verified_id" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Gender male ID</span>
                                <input type="text" inputmode="numeric" wire:model.live="profileSettingsForm.crm_gender_male_id" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Gender female ID</span>
                                <input type="text" inputmode="numeric" wire:model.live="profileSettingsForm.crm_gender_female_id" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                            <label class="ac-route-field">
                                <span>Gender unknown ID</span>
                                <input type="text" inputmode="numeric" wire:model.live="profileSettingsForm.crm_gender_unknown_id" @disabled(! $this->canEditProfileSettings()) />
                            </label>
                        </div>
                    </div>
                </details>

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
