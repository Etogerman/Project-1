@if (($renderSurface ?? true))
    <section data-role="contact-tags" class="ac-contact-form-section ac-contact-tag-section">
        <div class="ac-contact-form-section__header">
            <h3 class="ac-contact-form-section__title">{{ $sectionTitle ?? 'Теги' }}</h3>

            @if ($canManageTags)
                <button
                    data-role="contact-open-tag-dialog"
                    type="button"
                    wire:click="openAddTagDialog"
                    wire:loading.attr="disabled"
                    wire:target="openAddTagDialog,saveMountedContactTag"
                    class="ac-inline-action"
                >
                    Добавить тег
                </button>
            @endif
        </div>

        @if ($tags === [])
            <div data-role="contact-tags-empty" class="ac-contact-empty-line">
                Теги не назначены
            </div>
        @else
            <div class="ac-tag-list">
                @foreach ($tags as $tag)
                    <article class="ac-tag-row">
                        <div class="ac-tag-row__body">
                            <span class="ac-pill" data-tone="{{ $tag['color'] }}">{{ $tag['name'] }}</span>
                            <span class="ac-tag-row__meta">
                                {{ $tag['slug'] }}
                                @if (! $tag['is_active'])
                                    · отключён
                                @endif
                            </span>
                        </div>

                        @if ($canManageTags)
                            <button
                                data-role="contact-remove-tag"
                                type="button"
                                wire:click="removeMountedContactTag({{ $tag['id'] }})"
                                wire:loading.attr="disabled"
                                wire:target="removeMountedContactTag"
                                class="ac-inline-action ac-inline-action--danger"
                            >
                                Снять
                            </button>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        @if ($canManageTags && $this->showAddTagDialog)
            <div class="ac-contact-form-grid">
                <article data-role="contact-tag-inline-add" class="ac-contact-form-row ac-contact-form-row--wide">
                    <p class="ac-contact-form-row__label">Тег</p>

                    <div class="ac-contact-form-row__value-shell ac-contact-form-row__value-shell--with-actions">
                        @if ($availableTags === [])
                            <span class="ac-contact-form-row__value">
                                Нет доступных активных тегов
                            </span>
                        @else
                            <select
                                id="contact-tag-select"
                                wire:model.defer="selectedTagId"
                                class="ac-contact-form-row__value ac-inline-profile-field ac-inline-profile-field--select"
                            >
                                <option value="">Выберите тег</option>
                                @foreach ($availableTags as $tagId => $tagLabel)
                                    <option value="{{ $tagId }}">{{ $tagLabel }}</option>
                                @endforeach
                            </select>
                        @endif

                        @error('selectedTagId')
                            <p class="ac-contact-form-row__error">{{ $message }}</p>
                        @enderror

                        <div class="ac-contact-form-row__inline-actions">
                            <button
                                type="button"
                                wire:click="closeAddTagDialog"
                                class="ac-inline-action"
                            >
                                Отмена
                            </button>
                            <button
                                data-role="contact-save-tag-button"
                                type="button"
                                wire:click="saveMountedContactTag"
                                wire:loading.attr="disabled"
                                wire:target="saveMountedContactTag"
                                @disabled($availableTags === [])
                                class="ac-inline-action"
                            >
                                <span wire:loading.remove wire:target="saveMountedContactTag">Сохранить</span>
                                <span wire:loading wire:target="saveMountedContactTag">Сохраняем...</span>
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        @endif
    </section>
@endif
