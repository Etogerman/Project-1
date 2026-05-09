<section data-role="user-overview" class="ac-surface ac-surface--secondary">
    <div class="ac-surface__header ac-surface__header--centered">
        <div class="ac-surface__title-group">
            <p class="ac-surface__eyebrow">Команда</p>
            <h3 class="ac-surface__title">{{ $name }}</h3>
            <p class="ac-surface__subtitle">
                Профиль сотрудника и права доступа в админке.
            </p>
        </div>

        <div class="ac-button-group">
            <span class="ac-pill" data-tone="{{ $activeTone }}">
                {{ $activeLabel }}
            </span>
            <span class="ac-pill" data-tone="{{ $roleTone }}">
                {{ $roleLabel }}
            </span>
        </div>
    </div>

    <div class="ac-card-grid ac-surface__divider">
        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Основное</p>

            <div class="ac-meta-grid ac-meta-grid--compact ac-list-card__section">
                <div class="ac-meta">
                    <p class="ac-meta__label">ID</p>
                    <p class="ac-meta__value ac-meta__value--emphasis">{{ $idLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Имя</p>
                    <p class="ac-meta__value">{{ $name }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Фамилия</p>
                    <p class="ac-meta__value">{{ $lastNameLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Email</p>
                    <p class="ac-meta__value">{{ $email }}</p>
                </div>
            </div>
        </article>

        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Доступ</p>
            <p class="ac-list-card__body">
                Статус входа и роль сотрудника в панели управления.
            </p>

            <div class="ac-button-group ac-list-card__section">
                <span class="ac-pill" data-tone="{{ $activeTone }}">
                    {{ $activeLabel }}
                </span>
                <span class="ac-pill" data-tone="{{ $roleTone }}">
                    {{ $roleLabel }}
                </span>
            </div>
        </article>

        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Активность</p>

            <div class="ac-meta-grid ac-meta-grid--compact ac-list-card__section">
                <div class="ac-meta">
                    <p class="ac-meta__label">Последний вход</p>
                    <p class="ac-meta__value">{{ $lastLoginAtLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Последняя активность</p>
                    <p class="ac-meta__value">{{ $lastSeenAtLabel }}</p>
                </div>
            </div>
        </article>

        <article class="ac-list-card ac-list-card--soft">
            <p class="ac-list-card__title">Служебное</p>

            <div class="ac-meta-grid ac-meta-grid--compact ac-list-card__section">
                <div class="ac-meta">
                    <p class="ac-meta__label">Создан</p>
                    <p class="ac-meta__value">{{ $createdAtLabel }}</p>
                </div>
                <div class="ac-meta">
                    <p class="ac-meta__label">Обновлён</p>
                    <p class="ac-meta__value">{{ $updatedAtLabel }}</p>
                </div>
            </div>
        </article>
    </div>
</section>
