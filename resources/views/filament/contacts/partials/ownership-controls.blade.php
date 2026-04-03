<section data-role="contact-ownership-controls" class="ac-surface ac-surface--secondary ac-contact-modal-surface ac-contact-modal-surface--secondary-block ac-contact-modal-surface--ownership">
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Работа с контактом</p>
            <h3 class="ac-surface__title">Кто ведёт клиента</h3>
            <p class="ac-surface__subtitle">
                Здесь задаётся ответственный и определяется, можно ли оставлять автоответы включёнными.
            </p>
        </div>
    </div>

    <div class="ac-card-grid ac-surface__divider">
        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Ответственный</p>
            <p class="ac-list-card__body">Текущий оператор, который ведёт контакт.</p>

            <div class="ac-list-card__section">
                <p class="ac-meta__value ac-meta__value--emphasis">{{ $assignedUserLabel }}</p>
            </div>
        </article>

        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Автоответы</p>
            <p class="ac-list-card__body">Можно оставить включёнными или полностью отключить для этого клиента.</p>

            <div class="ac-list-card__section">
                <span class="ac-pill" data-tone="{{ $autoReplyEnabled ? 'success' : 'danger' }}">
                    {{ $autoReplyStatusLabel }}
                </span>
            </div>
        </article>
    </div>

    @if (filled($ownershipHint))
        <div class="ac-note-box ac-note-box--info ac-surface__divider">
            <p class="ac-copy">{{ $ownershipHint }}</p>
        </div>
    @endif

    <div class="ac-actions ac-actions--between ac-surface__divider">
        <p class="ac-note ac-actions__hint">
            Основное действие здесь — назначить ответственного. Остальные действия меняют только режим сопровождения контакта.
        </p>

        @if ($canManageOwnership)
            <div class="ac-button-group">
                <button
                    data-role="contact-open-assignee-dialog"
                    type="button"
                    wire:click="openAssignContactDialog"
                    wire:loading.attr="disabled"
                    wire:target="openAssignContactDialog,saveMountedContactAssignee"
                    class="ac-button ac-button--primary"
                >
                    <span wire:loading.remove wire:target="openAssignContactDialog,saveMountedContactAssignee">Изменить ответственного</span>
                    <span wire:loading wire:target="openAssignContactDialog,saveMountedContactAssignee">Открываем...</span>
                </button>

                @if ($canManageAutoReply && $autoReplyEnabled)
                    <button
                        data-role="contact-disable-auto-reply"
                        type="button"
                        wire:click="disableMountedContactAutoReply"
                        wire:loading.attr="disabled"
                        wire:target="disableMountedContactAutoReply,enableMountedContactAutoReply"
                        class="ac-button ac-button--danger-soft"
                    >
                        Отключить автоответы
                    </button>
                @elseif ($canManageAutoReply)
                    <button
                        data-role="contact-enable-auto-reply"
                        type="button"
                        wire:click="enableMountedContactAutoReply"
                        wire:loading.attr="disabled"
                        wire:target="disableMountedContactAutoReply,enableMountedContactAutoReply"
                        class="ac-button ac-button--success"
                    >
                        Включить автоответы
                    </button>
                @endif

                @if ($canDeleteContact)
                    <button
                        data-role="contact-open-delete-dialog"
                        type="button"
                        wire:click="openDeleteContactDialog"
                        wire:loading.attr="disabled"
                        wire:target="openDeleteContactDialog,deleteMountedContact"
                        class="ac-button ac-button--danger-soft"
                    >
                        <span wire:loading.remove wire:target="openDeleteContactDialog,deleteMountedContact">Удалить клиента</span>
                        <span wire:loading wire:target="openDeleteContactDialog,deleteMountedContact">Удаляем...</span>
                    </button>
                @endif
            </div>
        @endif
    </div>

    @if (filled($deleteBlockedReason))
        <div data-role="contact-delete-blocked-reason" class="ac-note-box ac-note-box--danger ac-note--offset">
            <p class="ac-copy"><strong>Удаление недоступно.</strong> {{ $deleteBlockedReason }}</p>
        </div>
    @endif

    @if ($canManageOwnership && $this->showAssignContactDialog)
        <div data-role="contact-assignee-dialog-backdrop" class="ac-modal-backdrop ac-modal-backdrop--drawer">
            <div data-role="contact-assignee-dialog" class="ac-modal ac-modal--drawer">
                <div class="ac-modal__body">
                    <div class="ac-modal__header">
                        <div>
                            <h3 class="ac-modal__title">Ответственный</h3>
                            <p class="ac-modal__description">
                                Выберите сотрудника, который будет вести этот контакт.
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="closeAssignContactDialog"
                            class="ac-modal__close"
                        >
                            Закрыть
                        </button>
                    </div>

                    @if (filled($ownershipHint))
                        <div class="ac-note-box ac-note-box--info ac-copy--spaced">
                            <p class="ac-copy">{{ $ownershipHint }}</p>
                        </div>
                    @endif

                    <label for="contact-assignee-select" class="ac-field-label">
                        Ответственный
                    </label>
                    <select
                        id="contact-assignee-select"
                        wire:model="selectedAssigneeId"
                        class="ac-select"
                    >
                        <option value="">Свободен</option>
                        @foreach ($availableAssignees as $userId => $userName)
                            <option value="{{ $userId }}">{{ $userName }}</option>
                        @endforeach
                    </select>

                    <div class="ac-actions">
                        <button
                            type="button"
                            wire:click="closeAssignContactDialog"
                            class="ac-button ac-button--secondary"
                        >
                            Отмена
                        </button>
                        <button
                            data-role="contact-save-assignee-button"
                            type="button"
                            wire:click="saveMountedContactAssignee"
                            wire:loading.attr="disabled"
                            wire:target="saveMountedContactAssignee"
                            class="ac-button ac-button--success"
                        >
                            <span wire:loading.remove wire:target="saveMountedContactAssignee">Сохранить</span>
                            <span wire:loading wire:target="saveMountedContactAssignee">Сохраняем...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($canDeleteContact && $this->showDeleteContactDialog)
        <div data-role="contact-delete-dialog-backdrop" class="ac-modal-backdrop">
            <div data-role="contact-delete-dialog" class="ac-modal ac-modal--sm">
                <div class="ac-modal__body">
                    <div class="ac-modal__header">
                        <div>
                            <h3 class="ac-modal__title">
                                {{ $this->deletingContactHasMergeHistory ? 'Удалить клиента целиком?' : 'Удалить клиента?' }}
                            </h3>
                        </div>

                        <button
                            type="button"
                            wire:click="closeDeleteContactDialog"
                            class="ac-modal__close"
                        >
                            Закрыть
                        </button>
                    </div>

                    @include('filament.contacts.partials.delete-contact-preview', [
                        'contactLabel' => $this->deletingContactLabel,
                        'hasMergeHistory' => $this->deletingContactHasMergeHistory,
                        'counts' => $this->deletingContactCounts,
                    ])

                    <div class="ac-actions">
                        <button
                            type="button"
                            wire:click="closeDeleteContactDialog"
                            class="ac-button ac-button--secondary"
                        >
                            Отмена
                        </button>
                        <button
                            data-role="contact-confirm-delete-button"
                            type="button"
                            wire:click="deleteMountedContact"
                            wire:loading.attr="disabled"
                            wire:target="deleteMountedContact"
                            class="ac-button ac-button--danger"
                        >
                            <span wire:loading.remove wire:target="deleteMountedContact">Удалить</span>
                            <span wire:loading wire:target="deleteMountedContact">Удаляем...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</section>
