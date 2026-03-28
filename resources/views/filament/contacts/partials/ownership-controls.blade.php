<section data-role="contact-ownership-controls" class="rounded-2xl border border-gray-200/80 bg-white/90 p-4 shadow-sm dark:border-white/10 dark:bg-slate-900/80">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Назначение</h3>
                <span @class([
                    'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
                    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' => $ownershipStatusColor === 'success',
                    'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300' => $ownershipStatusColor === 'warning',
                    'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => $ownershipStatusColor === 'gray',
                ])>{{ $ownershipStatusLabel }}</span>
            </div>

            <p class="text-sm text-gray-700 dark:text-gray-200">
                <span class="font-medium">Ответственный:</span>
                {{ $assignedUserLabel }}
            </p>

            @if (filled($ownershipHint))
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $ownershipHint }}
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canClaim)
                <button
                    data-role="contact-claim-button"
                    type="button"
                    wire:click="claimMountedContact"
                    wire:loading.attr="disabled"
                    wire:target="claimMountedContact"
                    class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
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
                    class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                >
                    <span wire:loading.remove wire:target="releaseMountedContact">Снять с себя</span>
                    <span wire:loading wire:target="releaseMountedContact">Снимаем...</span>
                </button>
            @endif
        </div>
    </div>
</section>
