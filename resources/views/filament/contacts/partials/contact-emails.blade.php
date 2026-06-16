@if (($renderSurface ?? true))
    <section data-role="contact-emails" class="ac-contact-form-section ac-contact-phone-section">
        <div class="ac-contact-form-section__header">
            <h3 class="ac-contact-form-section__title">{{ $sectionTitle ?? 'Email' }}</h3>

            @if ($canEditEmails)
                <button
                    data-role="contact-add-email"
                    type="button"
                    wire:click="openAddEmailDialog"
                    wire:loading.attr="disabled"
                    wire:target="openAddEmailDialog,saveMountedContactEmail"
                    class="ac-inline-action"
                >
                    Добавить
                </button>
            @endif
        </div>

        @if ($emails === [])
            <div class="ac-contact-empty-line">
                Email не указан
            </div>
        @else
            <div class="ac-phone-list">
                @foreach ($emails as $email)
                    @if ($canEditEmails && $this->showEditEmailDialog && (string) $this->editingEmailId === (string) $email['id'])
                        <article data-role="contact-email-inline-edit" class="ac-contact-form-row ac-contact-form-row--wide">
                            <p class="ac-contact-form-row__label">Email</p>

                            <div class="ac-contact-form-row__value-shell ac-contact-form-row__value-shell--with-actions">
                                <input
                                    id="contact-email-edit-input-{{ $email['id'] }}"
                                    type="email"
                                    wire:model.defer="editingEmailRaw"
                                    maxlength="255"
                                    placeholder="client@example.com"
                                    class="ac-contact-form-row__value ac-inline-profile-field"
                                />

                                @error('editingEmailRaw')
                                    <p class="ac-contact-form-row__error">{{ $message }}</p>
                                @enderror

                                <div class="ac-contact-form-row__inline-actions">
                                    <button
                                        type="button"
                                        wire:click="closeEditEmailDialog"
                                        class="ac-inline-action"
                                    >
                                        Отмена
                                    </button>
                                    <button
                                        data-role="contact-save-email-button"
                                        type="button"
                                        wire:click="saveMountedContactEmail"
                                        wire:loading.attr="disabled"
                                        wire:target="saveMountedContactEmail"
                                        class="ac-inline-action"
                                    >
                                        <span wire:loading.remove wire:target="saveMountedContactEmail">Сохранить</span>
                                        <span wire:loading wire:target="saveMountedContactEmail">Сохраняем...</span>
                                    </button>
                                </div>
                            </div>
                        </article>
                    @else
                        <article class="ac-phone-row" @if ($email['is_primary']) data-primary="true" @endif>
                            <span class="ac-phone-row__number">{{ $email['email'] }}</span>
                            @if ($email['is_primary'])
                                <span class="ac-phone-row__primary">Основной</span>
                            @endif
                            <span class="ac-phone-row__meta">{{ $email['source'] }}</span>

                            @if ($canEditEmails || $canDeleteEmails)
                                <div class="ac-phone-row__actions">
                                    @if ($canEditEmails)
                                        <button
                                            data-role="contact-edit-email"
                                            type="button"
                                            wire:click="openEditEmailDialog({{ $email['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openEditEmailDialog,saveMountedContactEmail"
                                            class="ac-inline-action"
                                        >
                                            Изменить
                                        </button>
                                    @endif

                                    @if ($canDeleteEmails)
                                        <button
                                            data-role="contact-delete-email"
                                            type="button"
                                            wire:click="openDeleteEmailDialog({{ $email['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openDeleteEmailDialog,deleteMountedContactEmail"
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

        @if ($canEditEmails && $this->showEditEmailDialog && $this->editingEmailId === '')
            <div class="ac-contact-form-grid">
                <article data-role="contact-email-inline-add" class="ac-contact-form-row ac-contact-form-row--wide">
                    <p class="ac-contact-form-row__label">Email</p>

                    <div class="ac-contact-form-row__value-shell ac-contact-form-row__value-shell--with-actions">
                        <input
                            id="contact-email-add-input"
                            type="email"
                            wire:model.defer="editingEmailRaw"
                            maxlength="255"
                            placeholder="client@example.com"
                            class="ac-contact-form-row__value ac-inline-profile-field"
                        />

                        @error('editingEmailRaw')
                            <p class="ac-contact-form-row__error">{{ $message }}</p>
                        @enderror

                        <div class="ac-contact-form-row__inline-actions">
                            <button
                                type="button"
                                wire:click="closeEditEmailDialog"
                                class="ac-inline-action"
                            >
                                Отмена
                            </button>
                            <button
                                data-role="contact-save-email-button"
                                type="button"
                                wire:click="saveMountedContactEmail"
                                wire:loading.attr="disabled"
                                wire:target="saveMountedContactEmail"
                                class="ac-inline-action"
                            >
                                <span wire:loading.remove wire:target="saveMountedContactEmail">Сохранить</span>
                                <span wire:loading wire:target="saveMountedContactEmail">Сохраняем...</span>
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        @endif
    </section>
@endif

@if ($canDeleteEmails && $this->showDeleteEmailDialog)
    <div data-role="contact-email-delete-dialog-backdrop" class="ac-modal-backdrop">
        <div data-role="contact-email-delete-dialog" class="ac-modal ac-modal--sm">
            <div class="ac-modal__body">
                <div class="ac-modal__header">
                    <div>
                        <h3 class="ac-modal__title">Удалить email</h3>
                        <p class="ac-modal__description">
                            Email <strong>{{ $this->deletingEmailLabel }}</strong> будет удалён из контакта.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeDeleteEmailDialog"
                        class="ac-modal__close"
                    >
                        Закрыть
                    </button>
                </div>

                <div class="ac-actions">
                    <button
                        type="button"
                        wire:click="closeDeleteEmailDialog"
                        class="ac-button ac-button--secondary"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-confirm-delete-email-button"
                        type="button"
                        wire:click="deleteMountedContactEmail"
                        wire:loading.attr="disabled"
                        wire:target="deleteMountedContactEmail"
                        class="ac-button ac-button--danger"
                    >
                        <span wire:loading.remove wire:target="deleteMountedContactEmail">Удалить</span>
                        <span wire:loading wire:target="deleteMountedContactEmail">Удаляем...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
