<section
    data-role="contact-ownership-controls"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
        <div style="min-width: 18rem; flex: 1 1 24rem;">
            <p style="margin: 0 0 0.35rem; font-size: 0.92rem; color: #374151;">
                Чтобы изменить ответственного, откройте выбор сотрудника.
            </p>

            @if (filled($ownershipHint))
                <p style="margin: 0; font-size: 0.8125rem; color: #6b7280;">
                    {{ $ownershipHint }}
                </p>
            @endif
        </div>

        <button
            data-role="contact-open-assignee-dialog"
            type="button"
            wire:click="openAssignContactDialog"
            wire:loading.attr="disabled"
            wire:target="openAssignContactDialog,saveMountedContactAssignee"
            style="display: inline-flex; align-items: center; justify-content: center; min-width: 13.5rem; border: 1px solid #1d4ed8; border-radius: 10px; background: #2563eb; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; box-shadow: 0 8px 18px rgba(37, 99, 235, 0.22); cursor: pointer;"
        >
            <span wire:loading.remove wire:target="openAssignContactDialog,saveMountedContactAssignee">Выбрать ответственного</span>
            <span wire:loading wire:target="openAssignContactDialog,saveMountedContactAssignee">Открываем...</span>
        </button>
    </div>

    @if ($this->showAssignContactDialog)
        <div
            data-role="contact-assignee-dialog-backdrop"
            style="position: fixed; inset: 0; z-index: 70; background: rgba(15, 23, 42, 0.35); display: flex; align-items: center; justify-content: center; padding: 1.5rem;"
        >
            <div
                data-role="contact-assignee-dialog"
                style="width: min(100%, 28rem); border-radius: 18px; background: #ffffff; box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2); border: 1px solid #d1d5db; padding: 1.25rem;"
            >
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <h3 style="margin: 0 0 0.35rem; font-size: 1rem; font-weight: 700; color: #111827;">Ответственный</h3>
                        <p style="margin: 0; font-size: 0.875rem; color: #4b5563;">
                            Выберите сотрудника, который будет вести этот контакт.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeAssignContactDialog"
                        style="border: 0; background: transparent; color: #6b7280; font-size: 1rem; cursor: pointer;"
                    >
                        Закрыть
                    </button>
                </div>

                <label for="contact-assignee-select" style="display: block; margin-bottom: 0.5rem; font-size: 0.875rem; font-weight: 600; color: #111827;">
                    Ответственный
                </label>
                <select
                    id="contact-assignee-select"
                    wire:model="selectedAssigneeId"
                    style="display: block; width: 100%; box-sizing: border-box; border: 1px solid #9ca3af; border-radius: 12px; background: #ffffff; color: #111827; padding: 0.85rem 0.95rem; font-size: 0.95rem;"
                >
                    <option value="">Свободен</option>
                    @foreach ($availableAssignees as $userId => $userName)
                        <option value="{{ $userId }}">{{ $userName }}</option>
                    @endforeach
                </select>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                    <button
                        type="button"
                        wire:click="closeAssignContactDialog"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db; border-radius: 10px; background: #ffffff; color: #374151; font-size: 0.875rem; font-weight: 600; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-save-assignee-button"
                        type="button"
                        wire:click="saveMountedContactAssignee"
                        wire:loading.attr="disabled"
                        wire:target="saveMountedContactAssignee"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #15803d; border-radius: 10px; background: #16a34a; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        <span wire:loading.remove wire:target="saveMountedContactAssignee">Сохранить</span>
                        <span wire:loading wire:target="saveMountedContactAssignee">Сохраняем...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</section>
