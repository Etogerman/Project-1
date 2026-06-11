@if (($renderSurface ?? true))
    <section data-role="contact-tags" class="ac-contact-form-section ac-contact-tag-section">
        <div class="ac-contact-form-section__header">
            <h3 class="ac-contact-form-section__title">Теги</h3>

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
    </section>
@endif

@if ($canManageTags && $this->showAddTagDialog)
    <div data-role="contact-tag-dialog-backdrop" class="ac-modal-backdrop">
        <div data-role="contact-tag-dialog" class="ac-modal ac-modal--md">
            <div class="ac-modal__body">
                <div class="ac-modal__header">
                    <div>
                        <h3 class="ac-modal__title">Добавить тег</h3>
                        <p class="ac-modal__description">
                            Выберите активный тег из справочника и назначьте его текущему контакту.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeAddTagDialog"
                        class="ac-modal__close"
                    >
                        Закрыть
                    </button>
                </div>

                @if ($availableTags === [])
                    <div class="ac-note-box ac-note-box--info ac-copy--spaced">
                        <p class="ac-copy">Нет доступных активных тегов для назначения.</p>
                    </div>
                @else
                    <label for="contact-tag-select" class="ac-field-label">
                        Тег
                    </label>
                    <select
                        id="contact-tag-select"
                        wire:model="selectedTagId"
                        class="ac-select"
                    >
                        <option value="">Выберите тег</option>
                        @foreach ($availableTags as $tagId => $tagLabel)
                            <option value="{{ $tagId }}">{{ $tagLabel }}</option>
                        @endforeach
                    </select>

                    @error('selectedTagId')
                        <p class="ac-field-error">{{ $message }}</p>
                    @enderror
                @endif

                <div class="ac-actions">
                    <button
                        type="button"
                        wire:click="closeAddTagDialog"
                        class="ac-button ac-button--secondary"
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
                        class="ac-button ac-button--success"
                    >
                        <span wire:loading.remove wire:target="saveMountedContactTag">Сохранить</span>
                        <span wire:loading wire:target="saveMountedContactTag">Сохраняем...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
