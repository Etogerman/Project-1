@php
    $statusStyles = match ($ownershipStatusColor) {
        'success' => 'background: #dcfce7; color: #166534; border: 1px solid #86efac;',
        'warning' => 'background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;',
        default => 'background: #f3f4f6; color: #374151; border: 1px solid #d1d5db;',
    };
@endphp

<section
    data-role="contact-ownership-controls"
    style="border: 1px solid #d1d5db; border-radius: 16px; background: #ffffff; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05); padding: 1rem;"
>
    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
        <div style="min-width: 18rem; flex: 1 1 24rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                <h3 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #111827;">Назначение</h3>
                <span style="display: inline-flex; align-items: center; border-radius: 999px; padding: 0.22rem 0.65rem; font-size: 0.75rem; font-weight: 600; {{ $statusStyles }}">
                    {{ $ownershipStatusLabel }}
                </span>
            </div>

            <p style="margin: 0 0 0.35rem; font-size: 0.92rem; color: #374151;">
                <strong>Ответственный:</strong> {{ $assignedUserLabel }}
            </p>

            @if ($canClaim)
                <p style="margin: 0; font-size: 0.8125rem; color: #92400e;">
                    Контакт сейчас свободен. Чтобы стать ответственным, нажмите кнопку «Взять в работу».
                </p>
            @elseif (filled($ownershipHint))
                <p style="margin: 0; font-size: 0.8125rem; color: #6b7280;">
                    {{ $ownershipHint }}
                </p>
            @endif
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            @if ($canClaim)
                <button
                    data-role="contact-claim-button"
                    type="button"
                    wire:click="claimMountedContact"
                    wire:loading.attr="disabled"
                    wire:target="claimMountedContact"
                    style="display: inline-flex; align-items: center; justify-content: center; min-width: 10.5rem; border: 1px solid #1d4ed8; border-radius: 10px; background: #2563eb; color: #ffffff; font-size: 0.875rem; font-weight: 700; padding: 0.72rem 1rem; box-shadow: 0 8px 18px rgba(37, 99, 235, 0.22); cursor: pointer;"
                >
                    <span wire:loading.remove wire:target="claimMountedContact">Взять в работу</span>
                    <span wire:loading wire:target="claimMountedContact">Берём...</span>
                </button>
            @endif

            @if ($canRelease)
                <button
                    data-role="contact-release-button"
                    type="button"
                    wire:click="releaseMountedContact"
                    wire:loading.attr="disabled"
                    wire:target="releaseMountedContact"
                    style="display: inline-flex; align-items: center; justify-content: center; min-width: 9.5rem; border: 1px solid #9ca3af; border-radius: 10px; background: #ffffff; color: #374151; font-size: 0.875rem; font-weight: 600; padding: 0.72rem 1rem; cursor: pointer;"
                >
                    <span wire:loading.remove wire:target="releaseMountedContact">Снять с себя</span>
                    <span wire:loading wire:target="releaseMountedContact">Снимаем...</span>
                </button>
            @endif
        </div>
    </div>
</section>
