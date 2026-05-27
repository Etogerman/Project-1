<div class="ac-admin-topbar-end">
    <label class="ac-admin-search" aria-label="Глобальный поиск">
        <x-filament::icon icon="heroicon-m-magnifying-glass" class="ac-admin-search__icon" />
        <input
            type="search"
            class="ac-admin-search__input"
            placeholder="Поиск контакта или диалога"
            autocomplete="off"
        />
    </label>

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
