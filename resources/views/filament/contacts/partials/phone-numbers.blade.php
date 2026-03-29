<section
    data-role="contact-phone-numbers"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    @if ($phoneNumbers === [])
        <p style="margin: 0; font-size: 0.95rem; color: #6b7280;">
            Номера телефонов ещё не сохранены.
        </p>
    @else
        <div style="display: grid; gap: 0.75rem;">
            @foreach ($phoneNumbers as $phoneNumber)
                <div style="border: 1px solid #e5e7eb; border-radius: 12px; padding: 0.85rem 0.95rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
                        <div>
                            <p style="margin: 0; font-size: 1rem; font-weight: 700; color: #111827;">
                                {{ $phoneNumber['phone'] }}
                            </p>
                            <p style="margin: 0.3rem 0 0; font-size: 0.875rem; color: #6b7280;">
                                Источник: {{ $phoneNumber['source'] }}
                            </p>
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end;">
                            <span
                                style="display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.35rem 0.7rem; font-size: 0.75rem; font-weight: 700; background: {{ $phoneNumber['is_primary'] ? '#dcfce7' : '#f3f4f6' }}; color: {{ $phoneNumber['is_primary'] ? '#166534' : '#4b5563' }};"
                            >
                                {{ $phoneNumber['is_primary'] ? 'Основной' : 'Дополнительный' }}
                            </span>

                            <button
                                data-role="contact-edit-phone"
                                type="button"
                                wire:click="openEditPhoneDialog({{ $phoneNumber['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="openEditPhoneDialog,saveMountedContactPhone"
                                style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #1d4ed8; border-radius: 10px; background: #eff6ff; color: #1d4ed8; font-size: 0.8125rem; font-weight: 700; padding: 0.55rem 0.8rem; cursor: pointer;"
                            >
                                Изменить
                            </button>

                            <button
                                data-role="contact-delete-phone"
                                type="button"
                                wire:click="openDeletePhoneDialog({{ $phoneNumber['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="openDeletePhoneDialog,deleteMountedContactPhone"
                                style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #dc2626; border-radius: 10px; background: #ffffff; color: #b91c1c; font-size: 0.8125rem; font-weight: 700; padding: 0.55rem 0.8rem; cursor: pointer;"
                            >
                                Удалить
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($this->showEditPhoneDialog)
        <div
            data-role="contact-phone-edit-dialog-backdrop"
            style="position: fixed; inset: 0; z-index: 75; background: rgba(15, 23, 42, 0.35); display: flex; align-items: center; justify-content: center; padding: 1.5rem;"
        >
            <div
                data-role="contact-phone-edit-dialog"
                style="width: min(100%, 32rem); border-radius: 20px; background: #ffffff; box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2); border: 1px solid #d1d5db; padding: 1.25rem;"
            >
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <h3 style="margin: 0 0 0.35rem; font-size: 1rem; font-weight: 700; color: #111827;">Изменить номер</h3>
                        <p style="margin: 0; font-size: 0.875rem; color: #4b5563;">
                            Обновите значение телефона для текущего контакта.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeEditPhoneDialog"
                        style="border: 0; background: transparent; color: #6b7280; font-size: 1rem; cursor: pointer;"
                    >
                        Закрыть
                    </button>
                </div>

                <label for="contact-phone-edit-input" style="display: block; margin-bottom: 0.5rem; font-size: 0.875rem; font-weight: 600; color: #111827;">
                    Номер телефона
                </label>
                <input
                    id="contact-phone-edit-input"
                    type="text"
                    wire:model.defer="editingPhoneRaw"
                    maxlength="64"
                    placeholder="+7 999 123 45 67"
                    style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                />

                @error('editingPhoneRaw')
                    <p style="margin: 0.5rem 0 0; font-size: 0.75rem; color: #dc2626;">{{ $message }}</p>
                @enderror

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                    <button
                        type="button"
                        wire:click="closeEditPhoneDialog"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db; border-radius: 10px; background: #ffffff; color: #374151; font-size: 0.875rem; font-weight: 600; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-save-phone-button"
                        type="button"
                        wire:click="saveMountedContactPhone"
                        wire:loading.attr="disabled"
                        wire:target="saveMountedContactPhone"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #15803d; border-radius: 10px; background: #16a34a; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        <span wire:loading.remove wire:target="saveMountedContactPhone">Сохранить</span>
                        <span wire:loading wire:target="saveMountedContactPhone">Сохраняем...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($this->showDeletePhoneDialog)
        <div
            data-role="contact-phone-delete-dialog-backdrop"
            style="position: fixed; inset: 0; z-index: 75; background: rgba(15, 23, 42, 0.35); display: flex; align-items: center; justify-content: center; padding: 1.5rem;"
        >
            <div
                data-role="contact-phone-delete-dialog"
                style="width: min(100%, 30rem); border-radius: 20px; background: #ffffff; box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2); border: 1px solid #d1d5db; padding: 1.25rem;"
            >
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <h3 style="margin: 0 0 0.35rem; font-size: 1rem; font-weight: 700; color: #111827;">Удалить номер</h3>
                        <p style="margin: 0; font-size: 0.875rem; color: #4b5563;">
                            Номер <strong>{{ $this->deletingPhoneLabel }}</strong> будет удалён из контакта.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeDeletePhoneDialog"
                        style="border: 0; background: transparent; color: #6b7280; font-size: 1rem; cursor: pointer;"
                    >
                        Закрыть
                    </button>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button
                        type="button"
                        wire:click="closeDeletePhoneDialog"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db; border-radius: 10px; background: #ffffff; color: #374151; font-size: 0.875rem; font-weight: 600; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-confirm-delete-phone-button"
                        type="button"
                        wire:click="deleteMountedContactPhone"
                        wire:loading.attr="disabled"
                        wire:target="deleteMountedContactPhone"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #dc2626; border-radius: 10px; background: #dc2626; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        <span wire:loading.remove wire:target="deleteMountedContactPhone">Удалить</span>
                        <span wire:loading wire:target="deleteMountedContactPhone">Удаляем...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</section>
