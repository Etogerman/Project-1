<section data-role="contact-phone-numbers" class="ac-surface ac-surface--secondary">
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Телефоны</p>
            <h3 class="ac-surface__title">Номера контакта</h3>
            <p class="ac-surface__subtitle">
                Основной номер отмечен отдельно. Остальные остаются в карточке как дополнительные.
            </p>
        </div>
    </div>

    @if ($phoneNumbers === [])
        <div class="ac-empty-state ac-surface__divider">
            Номера телефонов для этого контакта ещё не сохранены.
        </div>
    @else
        <div class="ac-list-stack ac-surface__divider">
            @foreach ($phoneNumbers as $phoneNumber)
                <article class="ac-list-card ac-list-card--soft">
                    <div class="ac-inline-split">
                        <div class="ac-surface__title-group">
                            <p class="ac-list-card__title">
                                {{ $phoneNumber['phone'] }}
                            </p>
                            <p class="ac-list-card__body">
                                Источник: {{ $phoneNumber['source'] }}
                            </p>
                        </div>

                        <span class="ac-pill" data-tone="{{ $phoneNumber['is_primary'] ? 'success' : 'neutral' }}">
                            {{ $phoneNumber['is_primary'] ? 'Основной' : 'Дополнительный' }}
                        </span>
                    </div>

                    @if ($canManagePhones)
                        <div class="ac-actions ac-surface__divider">
                            <button
                                data-role="contact-edit-phone"
                                type="button"
                                wire:click="openEditPhoneDialog({{ $phoneNumber['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="openEditPhoneDialog,saveMountedContactPhone"
                                class="ac-button ac-button--primary-soft"
                            >
                                Изменить
                            </button>

                            <button
                                data-role="contact-delete-phone"
                                type="button"
                                wire:click="openDeletePhoneDialog({{ $phoneNumber['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="openDeletePhoneDialog,deleteMountedContactPhone"
                                class="ac-button ac-button--danger-soft"
                            >
                                Удалить
                            </button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    @if ($canManagePhones && $this->showEditPhoneDialog)
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

    @if ($canManagePhones && $this->showDeletePhoneDialog)
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
</section>
