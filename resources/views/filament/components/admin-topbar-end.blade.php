<div class="ac-admin-topbar-end">
    @include('filament.components.environment-indicator')

    <button
        type="button"
        class="ac-admin-icon-button"
        aria-label="Переключить тему"
        onclick="document.documentElement.classList.toggle('dark')"
    >
        <x-filament::icon icon="heroicon-m-moon" class="h-5 w-5" />
    </button>

    <button type="button" class="ac-admin-icon-button" aria-label="Уведомления">
        <x-filament::icon icon="heroicon-m-bell" class="h-5 w-5" />
    </button>
</div>
