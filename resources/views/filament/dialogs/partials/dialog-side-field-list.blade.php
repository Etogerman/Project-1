<div class="ac-dialog-side-list ac-surface__divider">
    @foreach ($rows as $row)
        @php
            $isStatusRow = ($row['key'] ?? '') === 'status' && isset($dialogInboxStatus);
            $isAssigneeRow = ($row['key'] ?? '') === 'assigned_user_id' && isset($dialogAssignee);
            $canEdit = (bool) ($row['can_edit'] ?? false);
            $rowValue = trim((string) ($row['value'] ?? ''));
            $rowDetail = trim((string) ($row['detail'] ?? ''));
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
                    $statusBlockedReason = trim((string) ($dialogInboxStatus['blocked_reason'] ?? ''));
                    $statusTitle = $statusBlockedReason !== ''
                        ? $statusBlockedReason
                        : ($nextStatusLabel ? 'Сменить на: '.$nextStatusLabel : $currentStatusLabel);
                @endphp

                <div
                    class="ac-meta__value-wrap"
                    @if ($statusBlockedReason !== '')
                        title="{{ $statusBlockedReason }}"
                    @endif
                >
                    <button
                        type="button"
                        class="ac-dialog-status-toggle"
                        data-role="dialog-inbox-status-toggle"
                        wire:click="setDialogInboxStatus(@js($nextStatusValue))"
                        wire:loading.attr="disabled"
                        wire:target="setDialogInboxStatus"
                        aria-label="{{ $row['label'] }}"
                        title="{{ $statusTitle }}"
                        @disabled(! ($dialogInboxStatus['is_editable'] ?? false) || $nextStatusValue === null)
                    >
                        <span data-role="dialog-inbox-status-current">{{ $currentStatusLabel }}</span>
                    </button>
                </div>
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
                        <span data-role="dialog-assignee-current">{{ $rowValue }}</span>
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
                <div class="ac-meta__value-wrap">
                    <p
                        @class([
                            'ac-meta__value',
                            'ac-meta__value--muted' => ($row['tone'] ?? null) === 'muted',
                            'ac-meta__value--warning' => ($row['tone'] ?? null) === 'warning',
                            'ac-meta__value--success' => ($row['tone'] ?? null) === 'success',
                        ])
                        title="{{ $rowValue }}"
                    >
                        {{ $rowValue }}
                    </p>

                    @if ($rowDetail !== '')
                        <p class="ac-meta__detail">{{ $rowDetail }}</p>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
