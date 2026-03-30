<section
    data-role="contact-ownership-controls"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
        <div style="min-width: 18rem; flex: 1 1 24rem;">
            <p style="margin: 0 0 0.35rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                Ответственный
            </p>
            <p style="margin: 0; font-size: 1rem; font-weight: 700; color: #111827;">
                {{ $assignedUserLabel }}
            </p>
            <p style="margin: 0.75rem 0 0.35rem; font-size: 0.8125rem; font-weight: 600; letter-spacing: 0.01em; color: #6b7280; text-transform: uppercase;">
                Автоответы
            </p>
            <p style="margin: 0; font-size: 0.95rem; font-weight: 700; color: {{ $autoReplyEnabled ? '#166534' : '#991b1b' }};">
                {{ $autoReplyStatusLabel }}
            </p>
        </div>

        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <button
                data-role="contact-open-assignee-dialog"
                type="button"
                wire:click="openAssignContactDialog"
                wire:loading.attr="disabled"
                wire:target="openAssignContactDialog,saveMountedContactAssignee"
                style="display: inline-flex; align-items: center; justify-content: center; min-width: 13.5rem; border: 1px solid #1d4ed8; border-radius: 10px; background: #2563eb; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; box-shadow: 0 8px 18px rgba(37, 99, 235, 0.22); cursor: pointer;"
            >
                <span wire:loading.remove wire:target="openAssignContactDialog,saveMountedContactAssignee">Изменить</span>
                <span wire:loading wire:target="openAssignContactDialog,saveMountedContactAssignee">Открываем...</span>
            </button>

            @if ($autoReplyEnabled)
                <button
                    data-role="contact-disable-auto-reply"
                    type="button"
                    wire:click="disableMountedContactAutoReply"
                    wire:loading.attr="disabled"
                    wire:target="disableMountedContactAutoReply,enableMountedContactAutoReply"
                    style="display: inline-flex; align-items: center; justify-content: center; min-width: 12rem; border: 1px solid #dc2626; border-radius: 10px; background: #ffffff; color: #b91c1c; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; cursor: pointer;"
                >
                    Отключить автоответы
                </button>
            @else
                <button
                    data-role="contact-enable-auto-reply"
                    type="button"
                    wire:click="enableMountedContactAutoReply"
                    wire:loading.attr="disabled"
                    wire:target="disableMountedContactAutoReply,enableMountedContactAutoReply"
                    style="display: inline-flex; align-items: center; justify-content: center; min-width: 12rem; border: 1px solid #15803d; border-radius: 10px; background: #16a34a; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; cursor: pointer;"
                >
                    Включить автоответы
                </button>
            @endif

            <button
                data-role="contact-open-delete-dialog"
                type="button"
                wire:click="openDeleteContactDialog"
                wire:loading.attr="disabled"
                wire:target="openDeleteContactDialog,deleteMountedContact"
                style="display: inline-flex; align-items: center; justify-content: center; min-width: 10rem; border: 1px solid #dc2626; border-radius: 10px; background: #ffffff; color: #b91c1c; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; cursor: pointer;"
            >
                <span wire:loading.remove wire:target="openDeleteContactDialog,deleteMountedContact">Удалить клиента</span>
                <span wire:loading wire:target="openDeleteContactDialog,deleteMountedContact">Удаляем...</span>
            </button>
        </div>
    </div>

    @if ($this->showAssignContactDialog)
        <div
            data-role="contact-assignee-dialog-backdrop"
            style="position: fixed; inset: 0; z-index: 70; background: rgba(15, 23, 42, 0.35); display: flex; align-items: stretch; justify-content: flex-start; padding: 0;"
        >
            <div
                data-role="contact-assignee-dialog"
                style="width: min(100%, 28rem); height: 100%; border-radius: 0 20px 20px 0; background: #ffffff; box-shadow: 24px 0 60px rgba(15, 23, 42, 0.2); border-right: 1px solid #d1d5db; padding: 1.25rem;"
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

                @if (filled($ownershipHint))
                    <p style="margin: 0 0 1rem; font-size: 0.875rem; color: #4b5563;">
                        {{ $ownershipHint }}
                    </p>
                @endif

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

    @if ($this->showDeleteContactDialog)
        <div
            data-role="contact-delete-dialog-backdrop"
            style="position: fixed; inset: 0; z-index: 80; background: rgba(15, 23, 42, 0.35); display: flex; align-items: center; justify-content: center; padding: 1.5rem;"
        >
            <div
                data-role="contact-delete-dialog"
                style="width: min(100%, 30rem); border-radius: 20px; background: #ffffff; box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2); border: 1px solid #d1d5db; padding: 1.25rem;"
            >
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <h3 style="margin: 0 0 0.35rem; font-size: 1rem; font-weight: 700; color: #111827;">Удалить клиента</h3>
                        <p style="margin: 0; font-size: 0.875rem; color: #4b5563;">
                            Контакт <strong>{{ $this->deletingContactLabel }}</strong> будет удалён вместе с телефонами, сообщениями и идентичностями.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeDeleteContactDialog"
                        style="border: 0; background: transparent; color: #6b7280; font-size: 1rem; cursor: pointer;"
                    >
                        Закрыть
                    </button>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button
                        type="button"
                        wire:click="closeDeleteContactDialog"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d1d5db; border-radius: 10px; background: #ffffff; color: #374151; font-size: 0.875rem; font-weight: 600; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        Отмена
                    </button>
                    <button
                        data-role="contact-confirm-delete-button"
                        type="button"
                        wire:click="deleteMountedContact"
                        wire:loading.attr="disabled"
                        wire:target="deleteMountedContact"
                        style="display: inline-flex; align-items: center; justify-content: center; border: 1px solid #dc2626; border-radius: 10px; background: #dc2626; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; cursor: pointer;"
                    >
                        <span wire:loading.remove wire:target="deleteMountedContact">Удалить</span>
                        <span wire:loading wire:target="deleteMountedContact">Удаляем...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</section>
