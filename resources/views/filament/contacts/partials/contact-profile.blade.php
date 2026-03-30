<section
    data-role="contact-profile"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    <div style="display: grid; gap: 0.85rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));">
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Имя</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $firstName ?: '—' }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Фамилия</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $lastName ?: '—' }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Пол</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $genderLabel }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Возраст</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $effectiveAgeLabel }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Возрастной диапазон</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $ageRangeLabel }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Дата рождения</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $birthDateLabel }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Страна</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $country }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Город</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $city }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Регион</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $region }}</p>
        </div>
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Статус региона</p>
            <p style="margin: 0; font-size: 0.95rem; color: #111827;">{{ $regionStatusLabel }}</p>
        </div>
    </div>

    <div style="margin-top: 0.9rem; border-top: 1px solid #e5e7eb; padding-top: 0.8rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
        <div>
            <p style="margin: 0 0 0.25rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">Имя из мессенджера</p>
            <p style="margin: 0; font-size: 0.9rem; color: #4b5563;">{{ $messengerName }}</p>
        </div>

        <button
            data-role="contact-edit-profile"
            type="button"
            wire:click="openEditProfileDialog"
            wire:loading.attr="disabled"
            wire:target="openEditProfileDialog,saveMountedContactProfile"
            style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #1d4ed8; border-radius: 10px; background: #eff6ff; color: #1d4ed8; font-size: 0.8125rem; font-weight: 700; padding: 0.55rem 0.8rem; cursor: pointer;"
        >
            Изменить профиль
        </button>
    </div>

    @if ($this->showEditProfileDialog)
        <div
            data-role="contact-profile-edit-dialog-backdrop"
            style="position: fixed; inset: 0; z-index: 75; background: rgba(15, 23, 42, 0.35); display: flex; align-items: center; justify-content: center; padding: 1.5rem;"
        >
            <div
                data-role="contact-profile-edit-dialog"
                style="width: min(100%, 38rem); border-radius: 20px; background: #ffffff; box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2); border: 1px solid #d1d5db; padding: 1.25rem;"
            >
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <h3 style="margin: 0 0 0.35rem; font-size: 1rem; font-weight: 700; color: #111827;">Редактировать профиль</h3>
                        <p style="margin: 0; font-size: 0.875rem; color: #4b5563;">
                            Операторские данные контакта хранятся отдельно от имени из мессенджера.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeEditProfileDialog"
                        style="border: 0; background: transparent; color: #6b7280; font-size: 1rem; cursor: pointer;"
                    >
                        Закрыть
                    </button>
                </div>

                <div style="display: grid; gap: 0.9rem; grid-template-columns: repeat(2, minmax(0, 1fr));">
                    <div>
                        <label for="contact-profile-first-name" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Имя</label>
                        <input
                            id="contact-profile-first-name"
                            type="text"
                            wire:model.defer="editingFirstName"
                            maxlength="255"
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        />
                        @error('editingFirstName')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact-profile-last-name" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Фамилия</label>
                        <input
                            id="contact-profile-last-name"
                            type="text"
                            wire:model.defer="editingLastName"
                            maxlength="255"
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        />
                        @error('editingLastName')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact-profile-birth-date" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Дата рождения</label>
                        <input
                            id="contact-profile-birth-date"
                            type="date"
                            wire:model.defer="editingBirthDate"
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        />
                        @error('editingBirthDate')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact-profile-age-years" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Возраст</label>
                        <input
                            id="contact-profile-age-years"
                            type="number"
                            min="0"
                            max="150"
                            wire:model.defer="editingAgeYears"
                            @disabled(filled($this->editingBirthDate))
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: {{ filled($this->editingBirthDate) ? '#f3f4f6' : '#ffffff' }}; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        />
                        <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #6b7280;">
                            Если указана дата рождения, возраст рассчитывается автоматически.
                        </p>
                        @error('editingAgeYears')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact-profile-gender" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Пол</label>
                        <select
                            id="contact-profile-gender"
                            wire:model.defer="editingGender"
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        >
                            <option value="">Не указан</option>
                            @foreach ($genderOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('editingGender')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact-profile-age-range" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Возрастной диапазон</label>
                        <select
                            id="contact-profile-age-range"
                            wire:model.defer="editingAgeRange"
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        >
                            <option value="">Не указан</option>
                            @foreach ($ageRangeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('editingAgeRange')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact-profile-country" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Страна</label>
                        <input
                            id="contact-profile-country"
                            type="text"
                            wire:model.defer="editingCountry"
                            maxlength="255"
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        />
                        @error('editingCountry')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact-profile-city" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Город</label>
                        <input
                            id="contact-profile-city"
                            type="text"
                            wire:model.defer="editingCity"
                            maxlength="255"
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        />
                        @error('editingCity')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>

                    <div style="grid-column: span 2;">
                        <label for="contact-profile-region" style="display: block; margin-bottom: 0.45rem; font-size: 0.875rem; font-weight: 600; color: #111827;">Регион</label>
                        <select
                            id="contact-profile-region"
                            wire:model.defer="editingRegion"
                            style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                        >
                            <option value="">Не указан</option>
                            @foreach ($regionOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #6b7280;">
                            Для России регион можно задать вручную. Если меняются страна или город, регион пересчитывается автоматически.
                        </p>
                        @error('editingRegion')
                            <p style="margin: 0.45rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                    <button
                        type="button"
                        wire:click="closeEditProfileDialog"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db; border-radius: 10px; background: #ffffff; color: #374151; font-size: 0.875rem; font-weight: 600; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-save-profile-button"
                        type="button"
                        wire:click="saveMountedContactProfile"
                        wire:loading.attr="disabled"
                        wire:target="saveMountedContactProfile"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #15803d; border-radius: 10px; background: #16a34a; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        <span wire:loading.remove wire:target="saveMountedContactProfile">Сохранить</span>
                        <span wire:loading wire:target="saveMountedContactProfile">Сохраняем...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</section>
