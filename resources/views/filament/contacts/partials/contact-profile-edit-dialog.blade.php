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
