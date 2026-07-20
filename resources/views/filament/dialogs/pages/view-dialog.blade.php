<x-filament-panels::page>
    @php
        $dialogFieldLabels = $dialogFieldLabels ?? [];
        $dialogFieldLabel = static function (string $fieldKey, string $fallback) use ($dialogFieldLabels): string {
            $label = trim((string) ($dialogFieldLabels[$fieldKey] ?? ''));

            return $label !== '' ? $label : $fallback;
        };
    @endphp

    <div data-role="dialog-page" class="ac-panel-stack ac-panel-stack--relaxed">
        <nav
            data-role="dialog-top-breadcrumbs"
            data-entry-point="{{ $dialogBreadcrumbs['entry_point'] }}"
            class="ac-dialog-top-crumbs"
            aria-label="Навигация по диалогу"
        >
            @if (filled($dialogBreadcrumbs['back_url']))
                <a
                    href="{{ $dialogBreadcrumbs['back_url'] }}"
                    class="ac-dialog-top-crumbs__back"
                    title="{{ $dialogBreadcrumbs['back_label'] }}"
                    aria-label="{{ $dialogBreadcrumbs['back_label'] }}"
                >
                    <span class="ac-sr-only">{{ $dialogBreadcrumbs['back_label'] }}</span>
                    <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                        <path d="M9 3L5 7l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            @endif

            <ol class="ac-dialog-top-crumbs__list">
                @foreach ($dialogBreadcrumbs['items'] as $breadcrumbItem)
                    <li class="ac-dialog-top-crumbs__item">
                        @if (! $loop->first)
                            <svg class="ac-dialog-top-crumbs__separator" width="10" height="10" viewBox="0 0 10 10" fill="none" aria-hidden="true">
                                <path d="M3.5 2L6.5 5L3.5 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        @endif

                        @if ($breadcrumbItem['is_current'] || blank($breadcrumbItem['url']))
                            <span class="ac-dialog-top-crumbs__link is-current">
                                {{ $breadcrumbItem['label'] }}
                            </span>
                        @else
                            <a href="{{ $breadcrumbItem['url'] }}" class="ac-dialog-top-crumbs__link">
                                {{ $breadcrumbItem['label'] }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>

        <div data-role="dialog-overview" class="ac-dialog-overview ac-dialog-overview--single">
            <section data-role="dialog-header" class="ac-surface ac-surface--hero ac-dialog-summary">
                <div class="ac-dialog-summary__top">
                    <div class="ac-dialog-summary__identity">
                        <div data-role="dialog-contact-avatar" class="ac-dialog-avatar">
                            @if (filled($dialogHeader['avatar_url']))
                                <img
                                    src="{{ $dialogHeader['avatar_url'] }}"
                                    alt="Аватар клиента"
                                    data-role="dialog-contact-avatar-image"
                                    class="ac-dialog-avatar__image"
                                >
                            @elseif (filled($dialogHeader['avatar_fallback_label']))
                                <span data-role="dialog-contact-avatar-fallback" class="ac-dialog-avatar__fallback">
                                    {{ $dialogHeader['avatar_fallback_label'] }}
                                </span>
                            @else
                                <span data-role="dialog-contact-avatar-fallback" class="ac-dialog-avatar__fallback">
                                    <x-filament::icon icon="heroicon-m-user" class="h-5 w-5" />
                                </span>
                            @endif
                        </div>

                        <div class="ac-surface__title-group">
                            <div class="ac-dialog-summary__title-row">
                                <h2 class="ac-surface__title ac-surface__title--hero">
                                    {{ $contactSummary['contact_label'] }}
                                </h2>
                                <span data-tone="info" class="ac-pill">
                                    {{ $dialogHeader['platform_label'] }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ac-dialog-summary__actions">
                        <span
                            data-role="dialog-route-status"
                            data-tone="{{ $dialogHeader['route_status_tone'] }}"
                            class="ac-pill"
                        >
                            {{ $dialogHeader['route_status_label'] }}
                        </span>

                        <div class="ac-button-group ac-button-group--end">
                            <a
                                href="{{ $contactUrl }}"
                                class="ac-button ac-button--warning"
                            >
                                Открыть контакт
                            </a>
                        </div>
                    </div>
                </div>

                @if (filled($dialogHeader['route_status_reason']))
                    <div class="ac-note-stack ac-surface__divider">
                        <p data-role="dialog-route-status-reason" class="ac-note ac-note--danger">
                            {{ $dialogHeader['route_status_reason'] }}
                        </p>
                    </div>
                @endif

                <div
                    data-role="dialog-stage-strip"
                    data-current-tone="{{ $dialogStage['current_tone'] }}"
                    class="ac-dialog-stage-strip ac-dialog-summary__stage"
                >
                    <div
                        data-role="dialog-stage-track"
                        class="ac-dialog-stage-strip__track"
                        role="group"
                        aria-label="{{ $dialogFieldLabel('stage', 'Этап') }} диалога"
                        x-data="{
                            optimisticStageIndex: null,
                            optimisticProgressStyle: null,
                            clearOptimisticStage() {
                                this.optimisticStageIndex = null;
                                this.optimisticProgressStyle = null;
                            },
                            selectOptimisticStage(index, progressStyle) {
                                this.optimisticStageIndex = index;
                                this.optimisticProgressStyle = progressStyle;
                            },
                            stageState(index, fallbackState, isClickable) {
                                if (this.optimisticStageIndex === null) {
                                    return fallbackState;
                                }

                                if (index < this.optimisticStageIndex) {
                                    return 'completed';
                                }

                                if (index === this.optimisticStageIndex) {
                                    return 'current';
                                }

                                return isClickable ? 'available' : 'locked';
                            },
                            stageStyle(index, defaultStyle, futureStyle) {
                                if (this.optimisticStageIndex === null) {
                                    return defaultStyle;
                                }

                                return index <= this.optimisticStageIndex
                                    ? this.optimisticProgressStyle
                                    : futureStyle;
                            },
                        }"
                        x-on:dialog-stage-selection-settled.window="clearOptimisticStage()"
                    >
                        @foreach ($dialogStage['steps'] as $stageIndex => $stageStep)
                            @php
                                $stageState = $stageStep['is_current']
                                    ? 'current'
                                    : ($stageStep['is_completed']
                                        ? 'completed'
                                        : ($stageStep['is_clickable'] ? 'available' : 'locked'));
                                $stageStepStyle = static fn (
                                    string $backgroundColor,
                                    string $borderColor,
                                    string $textColor,
                                    string $shadowColor,
                                    string $accentColor,
                                ): string => sprintf(
                                    '--stage-step-bg: %s; --stage-step-border: %s; --stage-step-text: %s; --stage-step-shadow: %s; --stage-step-accent: %s;',
                                    $backgroundColor,
                                    $borderColor,
                                    $textColor,
                                    $shadowColor,
                                    $accentColor,
                                );
                                $defaultStageStyle = $stageStepStyle(
                                    $stageStep['background_color'],
                                    $stageStep['border_color'],
                                    $stageStep['text_color'],
                                    $stageStep['shadow_color'],
                                    $stageStep['accent_color'],
                                );
                                $progressStageStyle = $stageStepStyle(
                                    $stageStep['active_background_color'],
                                    $stageStep['active_border_color'],
                                    $stageStep['active_text_color'],
                                    $stageStep['active_shadow_color'],
                                    $stageStep['active_accent_color'],
                                );
                                $futureStageStyle = $stageStepStyle(
                                    $stageStep['future_background_color'],
                                    $stageStep['future_border_color'],
                                    $stageStep['future_text_color'],
                                    $stageStep['future_shadow_color'],
                                    $stageStep['future_accent_color'],
                                );
                            @endphp
                            <button
                                type="button"
                                data-role="dialog-stage-step"
                                data-state="{{ $stageState }}"
                                x-bind:data-state="stageState({{ $stageIndex }}, @js($stageState), {{ $stageStep['is_clickable'] ? 'true' : 'false' }})"
                                data-tone="{{ $stageStep['tone'] }}"
                                data-stage-color="{{ $stageStep['color_hex'] }}"
                                data-stage-accent-color="{{ $stageStep['accent_color'] }}"
                                style="{{ $defaultStageStyle }}"
                                x-bind:style="stageStyle({{ $stageIndex }}, @js($defaultStageStyle), @js($futureStageStyle))"
                                x-on:click="selectOptimisticStage({{ $stageIndex }}, @js($progressStageStyle))"
                                wire:click="selectDialogStage('{{ $stageStep['value'] }}')"
                                @disabled(! $stageStep['is_clickable'])
                                class="ac-dialog-stage-step"
                            >
                                <span class="ac-dialog-stage-step__label">
                                    {{ $stageStep['label'] }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                    @if (filled($dialogStage['blocked_reason']))
                        <p class="ac-dialog-stage-strip__hint">
                            {{ $dialogStage['blocked_reason'] }}
                        </p>
                    @endif
                </div>

            </section>
        </div>

        <div data-role="dialog-workspace" class="ac-dialog-workspace">
            <div class="ac-dialog-main-column">
                <section
                    data-role="dialog-history"
                    data-poll-interval-ms="{{ $liveRefreshPollIntervalMs }}"
                    x-data="{
                        thread: null,
                        initialized: false,
                        previousHeight: null,
                        refreshIntervalId: null,
                        isRefreshing: false,
                        shouldScrollOnRefresh: false,
                        captureThread() {
                            this.thread = this.$root.querySelector('[data-role=conversation-thread]');
                        },
                        isNearBottom() {
                            this.captureThread();

                            if (! this.thread) {
                                return false;
                            }

                            return (this.thread.scrollHeight - this.thread.scrollTop - this.thread.clientHeight) <= 48;
                        },
                        scrollToBottom() {
                            this.captureThread();

                            if (! this.thread) {
                                return;
                            }

                            this.thread.scrollTop = this.thread.scrollHeight;
                        },
                        hasActiveReplyComposer() {
                            const textarea = this.$root.querySelector('[data-role=conversation-reply-textarea]');

                            if (! textarea) {
                                return false;
                            }

                            return document.activeElement === textarea
                                || textarea.value.trim() !== ''
                                || textarea.dataset.manualResized === '1';
                        },
                        hasActiveMediaPlayback() {
                            return Array.from(this.$root.querySelectorAll('audio, video'))
                                .some((media) => ! media.paused && ! media.ended);
                        },
                        hasActiveConversationSelection() {
                            const selection = window.getSelection?.();

                            if (! selection || selection.isCollapsed) {
                                return false;
                            }

                            return this.$root.contains(selection.anchorNode)
                                || this.$root.contains(selection.focusNode);
                        },
                        shouldDeferLiveRefresh() {
                            return this.hasActiveReplyComposer()
                                || this.hasActiveMediaPlayback()
                                || this.hasActiveConversationSelection();
                        },
                        rememberPositionBeforePrepend() {
                            this.captureThread();

                            if (! this.thread) {
                                return;
                            }

                            this.previousHeight = this.thread.scrollHeight;
                        },
                        restorePositionAfterPrepend() {
                            this.captureThread();

                            if (! this.thread || this.previousHeight === null) {
                                return;
                            }

                            const delta = this.thread.scrollHeight - this.previousHeight;

                            this.thread.scrollTop = this.thread.scrollTop + delta;
                            this.previousHeight = null;
                        },
                        startLiveRefresh() {
                            if (this.refreshIntervalId) {
                                window.clearInterval(this.refreshIntervalId);
                            }

                            this.refreshIntervalId = window.setInterval(async () => {
                                if (document.visibilityState !== 'visible' || this.isRefreshing || this.shouldDeferLiveRefresh()) {
                                    return;
                                }

                                this.shouldScrollOnRefresh = this.isNearBottom();
                                this.isRefreshing = true;

                                try {
                                    await this.$wire.refreshDialogViewData();
                                } catch (error) {
                                    console.warn('Не удалось обновить диалог.', error);
                                } finally {
                                    this.isRefreshing = false;
                                }
                            }, {{ $liveRefreshPollIntervalMs }});
                        },
                        scheduleInitialScroll() {
                            this.$nextTick(() => {
                                this.scrollToBottom();
                                window.requestAnimationFrame(() => this.scrollToBottom());
                                window.setTimeout(() => this.scrollToBottom(), 60);
                            });
                        },
                        handleRefreshComplete(detail = {}) {
                            this.isRefreshing = false;

                            if (((detail.appendedCount ?? 0) + (detail.updatedCount ?? 0)) < 1) {
                                return;
                            }

                            if (! this.shouldScrollOnRefresh) {
                                return;
                            }

                            this.$nextTick(() => this.scrollToBottom());
                        },
                        }"
                        x-init="if (! initialized) { scheduleInitialScroll(); initialized = true; } startLiveRefresh();"
                        x-on:dialog-history-older-messages-loaded.window="$nextTick(() => restorePositionAfterPrepend())"
                        x-on:dialog-history-refreshed.window="$nextTick(() => handleRefreshComplete($event.detail))"
                        x-on:dialog-reply-sent.window="$nextTick(() => scrollToBottom())"
                    class="ac-surface ac-dialog-chat-panel"
                >
                    <div class="ac-surface__header ac-surface__header--centered">
                        <div class="ac-surface__title-group">
                            <h3 class="ac-surface__title">
                                Сообщения диалога
                            </h3>
                        </div>

                        <div class="ac-button-group ac-button-group--end">
                            @foreach ($conversationDisplayModeOptions as $displayModeValue => $displayModeLabel)
                                <button
                                    type="button"
                                    wire:click="$set('conversationDisplayMode', '{{ $displayModeValue }}')"
                                    @class([
                                        'ac-button',
                                        'ac-button--compact',
                                        'ac-button--warning-soft' => $conversationDisplayMode === $displayModeValue,
                                        'ac-button--secondary' => $conversationDisplayMode !== $displayModeValue,
                                    ])
                                >
                                    {{ $displayModeLabel }}
                                </button>
                            @endforeach

                            @if ($hasMoreOlderMessages)
                                <button
                                    type="button"
                                    data-role="dialog-load-older"
                                    x-on:click="rememberPositionBeforePrepend()"
                                    wire:click="loadOlderMessages"
                                    wire:loading.attr="disabled"
                                    wire:target="loadOlderMessages"
                                    class="ac-button ac-button--compact ac-button--secondary"
                                >
                                    <span wire:loading.remove wire:target="loadOlderMessages">Показать более ранние</span>
                                    <span wire:loading wire:target="loadOlderMessages">Загружаем…</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    @include('filament.contacts.partials.conversation-chat', ['messages' => $conversationMessages, 'displayMode' => $conversationDisplayMode])

                    @if ($replyComposer['isVisible'])
                        @include('filament.dialogs.partials.reply-composer', array_merge($replyComposer, ['composerClass' => 'ac-composer--dialog-inline']))
                    @endif
                </section>
            </div>

            <aside data-role="dialog-side-panel" class="ac-dialog-side-column">
                <nav data-role="dialog-card-tabs" class="ac-dialog-side-tabs" aria-label="Вкладки диалога">
                    @foreach ($tabs as $tab)
                        <a
                            href="{{ $tab['url'] }}"
                            wire:click.prevent="selectTab('{{ $tab['key'] }}')"
                            data-role="dialog-card-tab-{{ $tab['key'] }}"
                            data-active="{{ $tab['isActive'] ? 'true' : 'false' }}"
                            @class([
                                'ac-dialog-side-tabs__link',
                                'is-active' => $tab['isActive'],
                            ])
                        >
                            {{ $tab['label'] }}
                        </a>
                    @endforeach
                </nav>

                @if ($activeTab === \App\Services\Dialogs\SyncSystemDialogCardViewAction::TAB_GENERAL)
            <div data-role="dialog-general-tab" class="ac-dialog-side-stack">
                @foreach ($dialogGeneralSections as $section)
                    <section
                        class="ac-surface ac-surface--secondary ac-dialog-side-card"
                        data-role="{{ ($section['section_key'] ?? '') === \App\Services\Dialogs\SyncSystemDialogCardViewAction::SECTION_DIALOG_FIELDS ? 'dialog-fields-section' : 'dialog-card-section' }}"
                        data-section-key="{{ $section['section_key'] ?? '' }}"
                    >
                        <div class="ac-surface__header ac-surface__header--centered">
                            <div class="ac-surface__title-group">
                                <h3 class="ac-surface__title">{{ $section['title'] }}</h3>
                            </div>
                        </div>

                        @if (($section['rows'] ?? []) === [] && ($section['section_key'] ?? '') === \App\Services\Dialogs\SyncSystemDialogCardViewAction::SECTION_DIALOG_FIELDS)
                            <div class="ac-surface__divider">
                                <p data-role="dialog-fields-empty" class="ac-empty-state ac-empty-state--compact">Поля диалога пока не заполнены</p>
                            </div>
                        @else
                            @include('filament.dialogs.partials.dialog-side-field-list', [
                                'rows' => $section['rows'],
                            ])
                        @endif
                    </section>
                @endforeach
            </div>
                @elseif ($activeTab === \App\Services\Dialogs\SyncSystemDialogCardViewAction::TAB_BITRIX24)
            <div data-role="dialog-bitrix24-tab" class="ac-dialog-side-stack">
                @foreach ($dialogBitrixSections as $section)
                    <section class="ac-surface ac-surface--secondary ac-dialog-side-card">
                        <div class="ac-surface__header ac-surface__header--centered">
                            <div class="ac-surface__title-group">
                                <h3 class="ac-surface__title">{{ $section['title'] }}</h3>
                            </div>
                        </div>

                        @include('filament.dialogs.partials.dialog-side-field-list', [
                            'rows' => $section['rows'],
                        ])
                    </section>
                @endforeach
            </div>
                @elseif ($activeTab === \App\Services\Dialogs\SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS)
            <div data-role="dialog-system-fields-tab" class="ac-dialog-side-stack">
                @foreach ($dialogSystemFieldSections as $section)
                    <section class="ac-surface ac-surface--secondary ac-dialog-side-card">
                        <div class="ac-surface__header ac-surface__header--centered">
                            <div class="ac-surface__title-group">
                                <h3 class="ac-surface__title">{{ $section['title'] }}</h3>
                            </div>
                        </div>

                        @include('filament.dialogs.partials.dialog-side-field-list', [
                            'rows' => $section['rows'],
                        ])
                    </section>
                @endforeach
            </div>
                @elseif ($activeTab === \App\Services\Dialogs\SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS)
            <div data-role="dialog-diagnostics-tab" class="ac-dialog-side-stack">
                @if (($dialogAutomationDiagnostics['is_visible'] ?? false) && ($dialogAutomationDiagnostics['rows'] ?? []) !== [])
                    <section class="ac-surface ac-dialog-side-card" data-role="dialog-automation-diagnostics">
                        <div class="ac-surface__header ac-surface__header--centered">
                            <div class="ac-surface__title-group">
                                <h3 class="ac-surface__title">Автоответы и V3</h3>
                            </div>
                        </div>

                        @include('filament.dialogs.partials.dialog-side-field-list', [
                            'rows' => $dialogAutomationDiagnostics['rows'],
                        ])
                    </section>
                @endif

                @foreach ($dialogDiagnosticsBlocks as $section)
                    @foreach (($section['blocks'] ?? []) as $blockKey)
                        @if ($blockKey === \App\Services\Dialogs\SyncSystemDialogCardViewAction::BLOCK_DIALOG_PEER_SYNC && $peerSyncState['is_visible'])
                            <section class="ac-surface ac-dialog-side-card">
                                <div class="ac-surface__header ac-surface__header--centered">
                                    <div class="ac-surface__title-group">
                                        <h3 class="ac-surface__title">{{ $section['title'] }}</h3>
                                    </div>
                                </div>
                                <div class="ac-dialog-side-list ac-surface__divider">
                                    <div class="ac-meta">
                                        <p class="ac-meta__label">Статус загрузки истории</p>
                                        <div class="ac-meta__value">
                                            <span data-role="dialog-peer-sync-status" data-tone="{{ $peerSyncState['status_tone'] }}" class="ac-pill">
                                                {{ $peerSyncState['status_label'] }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="ac-meta">
                                        <p class="ac-meta__label">История завершена</p>
                                        <p data-role="dialog-peer-sync-history-complete" class="ac-meta__value">{{ $peerSyncState['history_complete_label'] }}</p>
                                    </div>
                                    <div class="ac-meta">
                                        <p class="ac-meta__label">Самое раннее сообщение</p>
                                        <p data-role="dialog-peer-sync-oldest-message" class="ac-meta__value">{{ $peerSyncState['oldest_imported_message_id_label'] }}</p>
                                    </div>
                                    <div class="ac-meta">
                                        <p class="ac-meta__label">Последнее observed message</p>
                                        <p data-role="dialog-peer-sync-latest-message" class="ac-meta__value">{{ $peerSyncState['latest_observed_message_id_label'] }}</p>
                                    </div>
                                    <div class="ac-meta">
                                        <p class="ac-meta__label">Ошибка peer sync</p>
                                        <p data-role="dialog-peer-sync-error" class="ac-meta__value">{{ $peerSyncState['last_sync_error_label'] }}</p>
                                    </div>
                                </div>
                            </section>
                        @endif
                    @endforeach
                @endforeach
            </div>
                @else
            <div data-role="dialog-custom-tab" class="ac-dialog-side-stack">
                @foreach ($dialogCustomSections as $section)
                    <section class="ac-surface ac-surface--secondary ac-dialog-side-card">
                        <div class="ac-surface__header ac-surface__header--centered">
                            <div class="ac-surface__title-group">
                                <h3 class="ac-surface__title">{{ $section['title'] }}</h3>
                            </div>
                        </div>

                        @if (($section['rows'] ?? []) !== [])
                            @include('filament.dialogs.partials.dialog-side-field-list', [
                                'rows' => $section['rows'],
                            ])
                        @endif

                        @foreach (($section['blocks'] ?? []) as $blockKey)
                            @if ($blockKey === \App\Services\Dialogs\SyncSystemDialogCardViewAction::SECTION_DIALOG_FIELDS && $dialogFields['is_visible'])
                                <div class="ac-surface__divider">
                                    <p class="ac-dialog-summary__section-title">Поля диалога</p>
                                    @if ($dialogFields['fields'] === [])
                                        <p class="ac-empty-state ac-empty-state--compact">Поля диалога пока не заполнены</p>
                                    @else
                                        @include('filament.dialogs.partials.dialog-side-field-list', [
                                            'rows' => $dialogFields['fields'],
                                        ])
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </section>
                @endforeach
            </div>
                @endif
            </aside>
        </div>

        @if ($this->dialogFieldDraftDirty)
            <div data-role="dialog-field-savebar" class="ac-savebar is-visible">
                <span class="ac-savebar__status">Есть несохранённые изменения</span>

                <div class="ac-savebar__actions">
                    <button
                        type="button"
                        wire:click="resetDialogFieldDraftValues"
                        wire:loading.attr="disabled"
                        wire:target="resetDialogFieldDraftValues,saveDialogFieldDraftValues"
                        class="ac-button ac-button--secondary"
                    >
                        Отмена
                    </button>
                    <button
                        type="button"
                        wire:click="saveDialogFieldDraftValues"
                        wire:loading.attr="disabled"
                        wire:target="saveDialogFieldDraftValues"
                        class="ac-button ac-button--success"
                    >
                        <span wire:loading.remove wire:target="saveDialogFieldDraftValues">Сохранить</span>
                        <span wire:loading wire:target="saveDialogFieldDraftValues">Сохраняем...</span>
                    </button>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
