@if (($renderSurface ?? true))
    <section data-role="contact-phone-numbers" class="ac-contact-form-section ac-contact-phone-section">
        <div class="ac-contact-form-section__header">
            <h3 class="ac-contact-form-section__title">{{ $sectionTitle ?? 'Телефоны' }}</h3>
        </div>

        @if ($phoneNumbers === [])
            <div class="ac-contact-empty-line">
                {{ $sectionTitle ?? 'Телефоны' }} не указаны
            </div>
        @else
            <div class="ac-phone-list">
                @foreach ($phoneNumbers as $phoneNumber)
                    @if ($canEditPhones && $this->showEditPhoneDialog && (string) $this->editingPhoneId === (string) $phoneNumber['id'])
                        <article data-role="contact-phone-inline-edit" class="ac-contact-form-row ac-contact-form-row--wide">
                            <p class="ac-contact-form-row__label">Номер телефона</p>

                            <div class="ac-contact-form-row__value-shell ac-contact-form-row__value-shell--with-actions">
                                <input
                                    id="contact-phone-edit-input-{{ $phoneNumber['id'] }}"
                                    type="text"
                                    wire:model.defer="editingPhoneRaw"
                                    maxlength="64"
                                    placeholder="+7 999 123 45 67"
                                    class="ac-contact-form-row__value ac-inline-profile-field"
                                />

                                @error('editingPhoneRaw')
                                    <p class="ac-contact-form-row__error">{{ $message }}</p>
                                @enderror

                                <div class="ac-contact-form-row__inline-actions">
                                    <button
                                        type="button"
                                        wire:click="closeEditPhoneDialog"
                                        class="ac-inline-action"
                                    >
                                        Отмена
                                    </button>
                                    <button
                                        data-role="contact-save-phone-button"
                                        type="button"
                                        wire:click="saveMountedContactPhone"
                                        wire:loading.attr="disabled"
                                        wire:target="saveMountedContactPhone"
                                        class="ac-inline-action"
                                    >
                                        <span wire:loading.remove wire:target="saveMountedContactPhone">Сохранить</span>
                                        <span wire:loading wire:target="saveMountedContactPhone">Сохраняем...</span>
                                    </button>
                                </div>
                            </div>
                        </article>
                    @else
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
                    @endif
                @endforeach
            </div>
        @endif
    </section>
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
