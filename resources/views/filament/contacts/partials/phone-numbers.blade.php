@if (($renderSurface ?? true))
    <section data-role="contact-phone-numbers" class="ac-contact-form-section ac-contact-phone-section">
        <div class="ac-contact-form-section__header">
            <h3 class="ac-contact-form-section__title">Телефоны</h3>
        </div>

        @if ($phoneNumbers === [])
            <div class="ac-contact-empty-line">
                Телефоны не указаны
            </div>
        @else
            <div class="ac-phone-list">
                @foreach ($phoneNumbers as $phoneNumber)
                    <article class="ac-phone-row" @if ($phoneNumber['is_primary']) data-primary="true" @endif>
                        <span class="ac-phone-row__number">{{ $phoneNumber['phone'] }}</span>
                        @if ($phoneNumber['is_primary'])
                            <span class="ac-phone-row__primary">Основной</span>
                        @endif
                        <span class="ac-phone-row__meta">{{ $phoneNumber['source'] }}</span>

                        @if ($canEditPhones || $canDeletePhones)
                            <div class="ac-phone-row__actions">
                                @if ($canEditPhones)
                                    <button
                                        data-role="contact-edit-phone"
                                        type="button"
                                        wire:click="openEditPhoneDialog({{ $phoneNumber['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="openEditPhoneDialog,saveMountedContactPhone"
                                        class="ac-inline-action"
                                    >
                                        Изменить
                                    </button>
                                @endif

                                @if ($canDeletePhones)
                                    <button
                                        data-role="contact-delete-phone"
                                        type="button"
                                        wire:click="openDeletePhoneDialog({{ $phoneNumber['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="openDeletePhoneDialog,deleteMountedContactPhone"
                                        class="ac-inline-action ac-inline-action--danger"
                                    >
                                        Удалить
                                    </button>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endif

@if ($canEditPhones && $this->showEditPhoneDialog)
    <div data-role="contact-phone-edit-dialog-backdrop" class="ac-modal-backdrop">
        <div data-role="contact-phone-edit-dialog" class="ac-modal ac-modal--md">
            <div class="ac-modal__body">
                <div class="ac-modal__header">
                    <div>
                        <h3 class="ac-modal__title">Изменить номер</h3>
                        <p class="ac-modal__description">
                            Обновите значение телефона для текущего контакта.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeEditPhoneDialog"
                        class="ac-modal__close"
                    >
                        Закрыть
                    </button>
                </div>

                <label for="contact-phone-edit-input" class="ac-field-label">
                    Номер телефона
                </label>
                <input
                    id="contact-phone-edit-input"
                    type="text"
                    wire:model.defer="editingPhoneRaw"
                    maxlength="64"
                    placeholder="+7 999 123 45 67"
                    class="ac-input"
                />

                @error('editingPhoneRaw')
                    <p class="ac-field-error">{{ $message }}</p>
                @enderror

                <div class="ac-actions">
                    <button
                        type="button"
                        wire:click="closeEditPhoneDialog"
                        class="ac-button ac-button--secondary"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-save-phone-button"
                        type="button"
                        wire:click="saveMountedContactPhone"
                        wire:loading.attr="disabled"
                        wire:target="saveMountedContactPhone"
                        class="ac-button ac-button--success"
                    >
                        <span wire:loading.remove wire:target="saveMountedContactPhone">Сохранить</span>
                        <span wire:loading wire:target="saveMountedContactPhone">Сохраняем...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif

@if ($canDeletePhones && $this->showDeletePhoneDialog)
    <div data-role="contact-phone-delete-dialog-backdrop" class="ac-modal-backdrop">
        <div data-role="contact-phone-delete-dialog" class="ac-modal ac-modal--sm">
            <div class="ac-modal__body">
                <div class="ac-modal__header">
                    <div>
                        <h3 class="ac-modal__title">Удалить номер</h3>
                        <p class="ac-modal__description">
                            Номер <strong>{{ $this->deletingPhoneLabel }}</strong> будет удалён из контакта.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeDeletePhoneDialog"
                        class="ac-modal__close"
                    >
                        Закрыть
                    </button>
                </div>

                <div class="ac-actions">
                    <button
                        type="button"
                        wire:click="closeDeletePhoneDialog"
                        class="ac-button ac-button--secondary"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-confirm-delete-phone-button"
                        type="button"
                        wire:click="deleteMountedContactPhone"
                        wire:loading.attr="disabled"
                        wire:target="deleteMountedContactPhone"
                        class="ac-button ac-button--danger"
                    >
                        <span wire:loading.remove wire:target="deleteMountedContactPhone">Удалить</span>
                        <span wire:loading wire:target="deleteMountedContactPhone">Удаляем...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
