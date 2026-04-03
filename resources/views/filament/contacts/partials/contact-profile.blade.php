<section data-role="contact-profile" class="ac-surface ac-surface--secondary">
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Операторский профиль</p>
            <h3 class="ac-surface__title">Что уже известно о контакте</h3>
            <p class="ac-surface__subtitle">
                Здесь собраны подтверждённые оператором данные и локация контакта.
            </p>
        </div>

        @if ($canEditProfile)
            <button
                data-role="contact-edit-profile"
                type="button"
                wire:click="openEditProfileDialog"
                wire:loading.attr="disabled"
                wire:target="openEditProfileDialog,saveMountedContactProfile"
                class="ac-button ac-button--primary-soft"
            >
                Изменить профиль
            </button>
        @endif
    </div>

    <div class="ac-card-grid ac-surface__divider">
        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Основное</p>

            <div class="ac-meta-grid ac-meta-grid--compact ac-list-card__section">
                <div class="ac-meta">
                    <p class="ac-meta__label">Имя</p>
                    <p class="ac-meta__value">{{ $firstName ?: '—' }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Фамилия</p>
                    <p class="ac-meta__value">{{ $lastName ?: '—' }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Пол</p>
                    <p class="ac-meta__value">{{ $genderLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Возраст</p>
                    <p class="ac-meta__value">{{ $effectiveAgeLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Возрастной диапазон</p>
                    <p class="ac-meta__value">{{ $ageRangeLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Дата рождения</p>
                    <p class="ac-meta__value">{{ $birthDateLabel }}</p>
                </div>
            </div>
        </article>

        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Локация и квалификация</p>

            <div class="ac-meta-grid ac-meta-grid--compact ac-list-card__section">
                <div class="ac-meta">
                    <p class="ac-meta__label">Страна</p>
                    <p class="ac-meta__value">{{ $country }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Город</p>
                    <p class="ac-meta__value">{{ $city }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Регион</p>
                    <p class="ac-meta__value">{{ $region }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Статус региона</p>
                    <p class="ac-meta__value">{{ $regionStatusLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Расстояние до Москвы</p>
                    <p class="ac-meta__value">{{ $distanceToMoscowLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Статус расчёта</p>
                    <p class="ac-meta__value">{{ $distanceToMoscowStatusLabel }}</p>
                </div>
            </div>
        </article>
    </div>

    @if ($canEditProfile && $this->showEditProfileDialog)
        <div data-role="contact-profile-edit-dialog-backdrop" class="ac-modal-backdrop">
            <div data-role="contact-profile-edit-dialog" class="ac-modal">
                <div class="ac-modal__body">
                    <div class="ac-modal__header">
                        <div>
                            <h3 class="ac-modal__title">Редактировать профиль</h3>
                            <p class="ac-modal__description">
                                Операторские данные контакта хранятся отдельно от имени из мессенджера.
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="closeEditProfileDialog"
                            class="ac-modal__close"
                        >
                            Закрыть
                        </button>
                    </div>

                    <div class="ac-form-grid">
                        <div>
                            <label for="contact-profile-first-name" class="ac-field-label">Имя</label>
                            <input
                                id="contact-profile-first-name"
                                type="text"
                                wire:model.defer="editingFirstName"
                                maxlength="255"
                                class="ac-input"
                            />
                            @error('editingFirstName')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="contact-profile-last-name" class="ac-field-label">Фамилия</label>
                            <input
                                id="contact-profile-last-name"
                                type="text"
                                wire:model.defer="editingLastName"
                                maxlength="255"
                                class="ac-input"
                            />
                            @error('editingLastName')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="contact-profile-birth-date" class="ac-field-label">Дата рождения</label>
                            <input
                                id="contact-profile-birth-date"
                                type="date"
                                wire:model.defer="editingBirthDate"
                                class="ac-input"
                            />
                            @error('editingBirthDate')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="contact-profile-age-years" class="ac-field-label">Возраст</label>
                            <input
                                id="contact-profile-age-years"
                                type="number"
                                min="0"
                                max="150"
                                wire:model.defer="editingAgeYears"
                                @disabled(filled($this->editingBirthDate))
                                class="ac-input"
                            />
                            <p class="ac-field-help">
                                Если указана дата рождения, возраст рассчитывается автоматически.
                            </p>
                            @error('editingAgeYears')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="contact-profile-gender" class="ac-field-label">Пол</label>
                            <select
                                id="contact-profile-gender"
                                wire:model.defer="editingGender"
                                class="ac-select"
                            >
                                <option value="">Не указан</option>
                                @foreach ($genderOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('editingGender')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="contact-profile-age-range" class="ac-field-label">Возрастной диапазон</label>
                            <select
                                id="contact-profile-age-range"
                                wire:model.defer="editingAgeRange"
                                class="ac-select"
                            >
                                <option value="">Не указан</option>
                                @foreach ($ageRangeOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('editingAgeRange')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="contact-profile-country" class="ac-field-label">Страна</label>
                            <input
                                id="contact-profile-country"
                                type="text"
                                wire:model.defer="editingCountry"
                                maxlength="255"
                                class="ac-input"
                            />
                            @error('editingCountry')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="contact-profile-city" class="ac-field-label">Город</label>
                            <input
                                id="contact-profile-city"
                                type="text"
                                wire:model.defer="editingCity"
                                maxlength="255"
                                class="ac-input"
                            />
                            @error('editingCity')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="ac-form-field--full">
                            <label for="contact-profile-region" class="ac-field-label">Регион</label>
                            <select
                                id="contact-profile-region"
                                wire:model.defer="editingRegion"
                                class="ac-select"
                            >
                                <option value="">Не указан</option>
                                @foreach ($regionOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="ac-field-help">
                                Для России регион можно задать вручную. Если меняются страна или город, регион пересчитывается автоматически.
                            </p>
                            @error('editingRegion')
                                <p class="ac-field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="ac-actions">
                        <button
                            type="button"
                            wire:click="closeEditProfileDialog"
                            class="ac-button ac-button--secondary"
                        >
                            Отмена
                        </button>
                        <button
                            data-role="contact-save-profile-button"
                            type="button"
                            wire:click="saveMountedContactProfile"
                            wire:loading.attr="disabled"
                            wire:target="saveMountedContactProfile"
                            class="ac-button ac-button--success"
                        >
                            <span wire:loading.remove wire:target="saveMountedContactProfile">Сохранить</span>
                            <span wire:loading wire:target="saveMountedContactProfile">Сохраняем...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</section>
