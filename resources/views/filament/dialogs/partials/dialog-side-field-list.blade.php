<div class="ac-dialog-side-list ac-surface__divider">
    @foreach ($rows as $row)
        @php
            $isStatusRow = ($row['key'] ?? '') === 'status' && isset($dialogInboxStatus);
            $isAssigneeRow = ($row['key'] ?? '') === 'assigned_user_id' && isset($dialogAssignee);
            $canEdit = (bool) ($row['can_edit'] ?? false);
            $rowValue = trim((string) ($row['value'] ?? ''));
            $canCopy = ! $isStatusRow
                && ! $isAssigneeRow
                && ! $canEdit
                && ($row['can_copy'] ?? true)
                && $rowValue !== ''
                && $rowValue !== '—';
        @endphp

        <div
            class="ac-meta{{ $canCopy ? ' is-copyable' : '' }}"
            data-role="dialog-side-field-row"
            data-field-key="{{ $row['key'] ?? '' }}"
            @if ($canCopy)
                data-copy-value="{{ $rowValue }}"
                role="button"
                tabindex="0"
                aria-label="Скопировать: {{ $row['label'] }}"
                title="Нажмите, чтобы скопировать: {{ $rowValue }}"
            @endif
        >
            <p class="ac-meta__label">
                {{ $row['label'] }}
            </p>
            @if ($isStatusRow)
                @php
                    $statusOptions = $dialogInboxStatus['options'] ?? [];
                    $statusSelection = $dialogInboxStatusSelection ?? ($this->dialogInboxStatusSelection ?? '');
                    $currentStatusLabel = $statusOptions[$statusSelection] ?? (array_values($statusOptions)[0] ?? $rowValue);
                    $nextStatusValue = collect($statusOptions)
                        ->keys()
                        ->first(fn ($statusValue) => $statusValue !== $statusSelection);
                    $nextStatusLabel = $nextStatusValue !== null ? ($statusOptions[$nextStatusValue] ?? null) : null;
                @endphp

                <button
                    type="button"
                    class="ac-dialog-status-toggle"
                    data-role="dialog-inbox-status-toggle"
                    wire:click="setDialogInboxStatus(@js($nextStatusValue))"
                    wire:loading.attr="disabled"
                    wire:target="setDialogInboxStatus"
                    aria-label="{{ $row['label'] }}"
                    title="{{ $nextStatusLabel ? 'Сменить на: '.$nextStatusLabel : $currentStatusLabel }}"
                    @disabled(! ($dialogInboxStatus['is_editable'] ?? false) || $nextStatusValue === null)
                >
                    <span data-role="dialog-inbox-status-current">{{ $currentStatusLabel }}</span>
                </button>
            @elseif ($isAssigneeRow && ($dialogAssignee['can_manage'] ?? false))
                @if ($this->isDialogAssigneeEditing)
                    <div class="ac-dialog-assignee-editor" data-role="dialog-assignee-editor">
                        <select
                            class="ac-select"
                            data-role="dialog-assignee-select"
                            wire:model.live="selectedDialogAssigneeId"
                            wire:loading.attr="disabled"
                            wire:target="saveDialogFieldDraftValues,resetDialogFieldDraftValues"
                        >
                            <option value="">Свободен</option>
                            @foreach (($dialogAssignee['available_assignees'] ?? []) as $assigneeId => $assigneeLabel)
                                <option value="{{ $assigneeId }}">{{ $assigneeLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <button
                        type="button"
                        class="ac-dialog-status-toggle"
                        data-role="dialog-assignee-toggle"
                        wire:click="openDialogAssigneeEditor"
                        wire:loading.attr="disabled"
                        wire:target="openDialogAssigneeEditor"
                        aria-label="{{ $row['label'] }}"
                        title="Изменить ответственного"
                    >
                        <span data-role="dialog-assignee-current">{{ $row['value'] }}</span>
                    </button>
                @endif
            @elseif ($canEdit)
                <div
                    class="ac-dialog-field-row"
                    data-role="dialog-field-editor"
                >
                    <input
                        type="text"
                        class="ac-dialog-field-row__input"
                        data-role="dialog-field-value-input"
                        aria-label="Значение поля {{ $row['label'] }}"
                        value="{{ $row['editable_value'] ?? '' }}"
                        wire:model.live.debounce.300ms="dialogFieldDraftValues.{{ $row['key'] ?? '' }}"
                        wire:keydown.enter.prevent="saveDialogFieldDraftValues"
                        wire:loading.attr="disabled"
                        wire:target="saveDialogFieldDraftValues,resetDialogFieldDraftValues"
                    >
                </div>
            @else
                <p class="ac-meta__value" title="{{ $row['value'] }}">
                    {{ $row['value'] }}
                </p>
            @endif
        </div>
    @endforeach
</div>
