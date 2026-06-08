@php
    $fieldLabels = $fieldLabels ?? [];
    $dialogFieldLabel = static function (string $fieldKey, string $fallback) use ($fieldLabels): string {
        $label = trim((string) ($fieldLabels[$fieldKey] ?? ''));

        return $label !== '' ? $label : $fallback;
    };

    $dialogColumns = [
        ['key' => 'id', 'label' => $dialogFieldLabel('id', 'ID'), 'width' => 72, 'min' => 52],
        ['key' => 'channel', 'label' => $dialogFieldLabel('channel_id', 'Канал'), 'width' => 304, 'min' => 150],
        ['key' => 'name', 'label' => 'Имя в мессенджере', 'width' => 220, 'min' => 120],
        ['key' => 'phone', 'label' => $dialogFieldLabel('phone', 'Телефон канала'), 'width' => 200, 'min' => 104],
        ['key' => 'stage', 'label' => $dialogFieldLabel('stage', 'Стадия'), 'width' => 176, 'min' => 96],
        ['key' => 'message', 'label' => $dialogFieldLabel('last_message_at', 'Последнее сообщение'), 'width' => 420, 'min' => 160],
        ['key' => 'date', 'label' => 'Дата', 'width' => 176, 'min' => 96],
        ['key' => 'status', 'label' => $dialogFieldLabel('status', 'Статус'), 'width' => 168, 'min' => 96],
    ];
    $dialogsCount = count($dialogs);
@endphp

<section
    data-role="contact-dialogs"
    class="ac-surface ac-surface--secondary ac-contact-modal-surface ac-contact-modal-surface--dialogs ac-contact-dialogs"
>
    <div class="ac-contact-dialogs__toolbar">
        <p class="ac-contact-dialogs__summary">
            Всего диалогов:
            <b>{{ $dialogsCount }}</b>
        </p>

        @if ($dialogsCount > 0)
            <div class="ac-contact-dialogs__tools">
                <div class="ac-contact-dialogs__scroll-buttons" aria-label="Прокрутка таблицы">
                    <button type="button" data-role="contact-dialogs-scroll-left" aria-label="Прокрутить таблицу влево">‹</button>
                    <button type="button" data-role="contact-dialogs-scroll-right" aria-label="Прокрутить таблицу вправо">›</button>
                </div>

                <details class="ac-contact-dialogs__columns">
                    <summary class="ac-contact-dialogs__columns-button">
                        <svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                            <circle cx="7" cy="7" r="1.7" stroke="currentColor" stroke-width="1.4"/>
                            <path d="M7 1.5v1.5M7 11v1.5M12.5 7H11M3 7H1.5M10.9 3.1l-1 1M4.1 9.9l-1 1M10.9 10.9l-1-1M4.1 4.1l-1-1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        </svg>
                        <span>Колонки</span>
                        <span data-role="contact-dialogs-visible-columns">{{ count($dialogColumns) }}/{{ count($dialogColumns) }}</span>
                    </summary>

                    <div class="ac-contact-dialogs__columns-popover" role="menu">
                        <p class="ac-contact-dialogs__columns-title">Колонки и порядок</p>
                        <div class="ac-contact-dialogs__columns-list" data-role="contact-dialogs-columns-list">
                            @foreach ($dialogColumns as $column)
                                <div
                                    class="ac-contact-dialogs__columns-row"
                                    data-role="contact-dialogs-column-order-row"
                                    data-column-key="{{ $column['key'] }}"
                                    draggable="true"
                                >
                                    <span class="ac-contact-dialogs__drag-handle" aria-hidden="true">⋮⋮</span>
                                    <label class="ac-contact-dialogs__columns-check">
                                        <input
                                            type="checkbox"
                                            value="{{ $column['key'] }}"
                                            data-role="contact-dialogs-column-toggle"
                                            checked
                                        >
                                        <span>{{ $column['label'] }}</span>
                                    </label>
                                    <span class="ac-contact-dialogs__order-buttons">
                                        <button
                                            type="button"
                                            data-role="contact-dialogs-move-column"
                                            data-direction="up"
                                            aria-label="Поднять колонку {{ $column['label'] }}"
                                        >↑</button>
                                        <button
                                            type="button"
                                            data-role="contact-dialogs-move-column"
                                            data-direction="down"
                                            aria-label="Опустить колонку {{ $column['label'] }}"
                                        >↓</button>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        <div class="ac-contact-dialogs__columns-actions">
                            <button type="button" data-role="contact-dialogs-show-all" class="ac-contact-dialogs__columns-action">
                                Показать все
                            </button>
                            <button type="button" data-role="contact-dialogs-reset-order" class="ac-contact-dialogs__columns-action">
                                Сбросить
                            </button>
                            <button type="button" data-role="contact-dialogs-apply-columns" class="ac-contact-dialogs__columns-apply">
                                Применить
                            </button>
                        </div>
                    </div>
                </details>
            </div>
        @endif
    </div>

    @if ($dialogsCount === 0)
        <div data-role="contact-dialogs-empty" class="ac-empty-state ac-surface__divider">
            Диалоги ещё не появились.
        </div>
    @else
        <div class="ac-contact-dialogs__table-wrap" data-role="contact-dialogs-table-wrap">
            <table class="ac-contact-dialogs__table">
                <colgroup>
                    @foreach ($dialogColumns as $column)
                        <col
                            data-column-col="{{ $column['key'] }}"
                            data-column-min-width="{{ $column['min'] }}"
                            style="width: {{ $column['width'] }}px"
                        >
                    @endforeach
                </colgroup>
                <thead>
                    <tr>
                        @foreach ($dialogColumns as $column)
                            <th scope="col" data-column="{{ $column['key'] }}">
                                <span class="ac-contact-dialogs__th-label">{{ $column['label'] }}</span>
                                <button
                                    type="button"
                                    class="ac-contact-dialogs__resize"
                                    data-role="contact-dialogs-column-resize"
                                    data-column-resize="{{ $column['key'] }}"
                                    aria-label="Изменить ширину колонки {{ $column['label'] }}"
                                    title="Изменить ширину"
                                ></button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dialogs as $dialog)
                        @php
                            $channelLabel = (string) $dialog['channel_label'];
                            $channelInitial = mb_strtoupper(mb_substr($channelLabel, 0, 1));
                        @endphp

                        <tr
                            data-role="contact-dialog"
                            data-dialog-id="{{ $dialog['id'] }}"
                            data-href="{{ $dialog['url'] }}"
                        >
                            <td data-column="id">
                                <a
                                    href="{{ $dialog['url'] }}"
                                    data-role="dialog-card-link"
                                    data-dialog-id="{{ $dialog['id'] }}"
                                    class="ac-contact-dialogs__id"
                                >
                                    #{{ $dialog['id'] }}
                                </a>
                            </td>

                            <td data-column="channel">
                                <a
                                    href="{{ $dialog['url'] }}"
                                    data-role="dialog-channel"
                                    class="ac-contact-dialogs__channel"
                                >
                                    <span class="ac-contact-dialogs__channel-icon">{{ $channelInitial ?: '•' }}</span>
                                    <span class="ac-contact-dialogs__channel-body">
                                        <span class="ac-contact-dialogs__main-line">{{ $channelLabel }}</span>
                                        <span class="ac-contact-dialogs__muted-line">Источник маршрута: {{ $dialog['route_identity_label'] }}</span>
                                        <span data-role="dialog-chat-id" class="ac-contact-dialogs__muted-line">ID чата: {{ $dialog['external_chat_id_label'] }}</span>
                                    </span>
                                </a>
                            </td>

                            <td data-column="name">
                                <span data-role="dialog-messenger-name" class="ac-contact-dialogs__main-line">
                                    {{ $dialog['messenger_name_label'] }}
                                </span>
                            </td>

                            <td data-column="phone">
                                <span data-role="dialog-phone" class="ac-contact-dialogs__mono">
                                    {{ $dialog['phone_label'] }}
                                </span>
                            </td>

                            <td data-column="stage">
                                <span class="ac-pill" data-tone="{{ $dialog['stage_tone'] ?? 'gray' }}">
                                    {{ $dialog['stage_label'] ?? 'Новый диалог' }}
                                </span>
                            </td>

                            <td data-column="message">
                                <div data-role="dialog-preview" class="ac-contact-dialogs__preview">
                                    @if (filled($dialog['preview_sender_label']))
                                        <div class="ac-contact-dialogs__preview-meta">
                                            <span
                                                data-role="dialog-preview-sender"
                                                class="ac-pill"
                                                data-tone="{{ $dialog['preview_sender_tone'] }}"
                                            >
                                                {{ $dialog['preview_sender_label'] }}
                                            </span>
                                        </div>
                                    @endif

                                    <p class="ac-contact-dialogs__preview-text">{{ $dialog['preview_text'] }}</p>

                                    @if (! empty($dialog['preview_media_state_badges'] ?? []))
                                        <div data-role="dialog-preview-media-state" class="ac-contact-dialogs__badges">
                                            @foreach ($dialog['preview_media_state_badges'] as $mediaStateBadge)
                                                <span
                                                    data-role="dialog-preview-media-state-badge"
                                                    class="ac-pill"
                                                    data-tone="{{ $mediaStateBadge['tone'] ?? 'gray' }}"
                                                >
                                                    {{ $mediaStateBadge['label'] ?? 'Статус не определён' }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </td>

                            <td data-column="date">
                                <span class="ac-contact-dialogs__mono">{{ $dialog['last_message_label'] }}</span>
                            </td>

                            <td data-column="status">
                                <span
                                    data-role="dialog-route-status"
                                    data-tone="{{ $dialog['route_status_tone'] }}"
                                    class="ac-pill"
                                >
                                    {{ $dialog['route_status_label'] }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

@if ($dialogsCount > 0)
    <script>
        (() => {
            const initContactDialogsTable = (root) => {
                if (!root || root.dataset.columnsReady === '1') {
                    return;
                }

                root.dataset.columnsReady = '1';

                const toggles = Array.from(root.querySelectorAll('[data-role="contact-dialogs-column-toggle"]'));
                const countNode = root.querySelector('[data-role="contact-dialogs-visible-columns"]');
                const table = root.querySelector('.ac-contact-dialogs__table');
                const tableWrap = root.querySelector('[data-role="contact-dialogs-table-wrap"]');
                const scrollLeftButton = root.querySelector('[data-role="contact-dialogs-scroll-left"]');
                const scrollRightButton = root.querySelector('[data-role="contact-dialogs-scroll-right"]');
                const columnsList = root.querySelector('[data-role="contact-dialogs-columns-list"]');
                const columnsDetails = root.querySelector('.ac-contact-dialogs__columns');
                const widthStorageKey = 'ac-contact-dialogs-column-widths-v1';
                const orderStorageKey = 'ac-contact-dialogs-column-order-v1';
                const defaultOrder = Array.from(root.querySelectorAll('col[data-column-col]'))
                    .map((col) => col.dataset.columnCol)
                    .filter(Boolean);

                const readStoredWidths = () => {
                    try {
                        return JSON.parse(window.localStorage.getItem(widthStorageKey) || '{}');
                    } catch (error) {
                        return {};
                    }
                };

                const storedWidths = readStoredWidths();

                const readStoredOrder = () => {
                    try {
                        const parsed = JSON.parse(window.localStorage.getItem(orderStorageKey) || '[]');

                        return Array.isArray(parsed) ? parsed : [];
                    } catch (error) {
                        return [];
                    }
                };

                const normalizeOrder = (order) => {
                    const requested = Array.isArray(order) ? order : [];
                    const known = new Set(defaultOrder);
                    const normalized = requested.filter((column) => known.has(column));

                    defaultOrder.forEach((column) => {
                        if (!normalized.includes(column)) {
                            normalized.push(column);
                        }
                    });

                    return normalized;
                };

                const getRowsOrder = () => {
                    return Array.from(root.querySelectorAll('[data-role="contact-dialogs-column-order-row"]'))
                        .map((row) => row.dataset.columnKey)
                        .filter(Boolean);
                };

                const applyColumnOrder = (order, persist = false) => {
                    const normalized = normalizeOrder(order);
                    const colgroup = table?.querySelector('colgroup');
                    const headRow = table?.querySelector('thead tr');
                    const bodyRows = Array.from(table?.querySelectorAll('tbody tr') || []);

                    normalized.forEach((column) => {
                        const col = root.querySelector(`col[data-column-col="${column}"]`);
                        const head = root.querySelector(`thead [data-column="${column}"]`);
                        const menuRow = root.querySelector(`[data-role="contact-dialogs-column-order-row"][data-column-key="${column}"]`);

                        if (col && colgroup) {
                            colgroup.appendChild(col);
                        }

                        if (head && headRow) {
                            headRow.appendChild(head);
                        }

                        bodyRows.forEach((row) => {
                            const cell = row.querySelector(`[data-column="${column}"]`);

                            if (cell) {
                                row.appendChild(cell);
                            }
                        });

                        if (menuRow && columnsList) {
                            columnsList.appendChild(menuRow);
                        }
                    });

                    if (persist) {
                        window.localStorage.setItem(orderStorageKey, JSON.stringify(normalized));
                    }

                    setTableWidth();
                    updateScrollButtons();
                };

                root.querySelectorAll('col[data-column-col]').forEach((col) => {
                    const column = col.dataset.columnCol;
                    const storedWidth = Number(storedWidths[column]);
                    const minWidth = Number(col.dataset.columnMinWidth || 80);

                    if (Number.isFinite(storedWidth) && storedWidth >= minWidth) {
                        col.style.width = `${storedWidth}px`;
                    }
                });

                const getColumnWidth = (column) => {
                    const col = root.querySelector(`col[data-column-col="${column}"]`);

                    if (!col) {
                        return 0;
                    }

                    const parsedWidth = parseFloat(col.style.width || '0');
                    const renderedWidth = col.getBoundingClientRect().width;

                    return Number.isFinite(parsedWidth) && parsedWidth > 0 ? parsedWidth : renderedWidth;
                };

                const setTableWidth = () => {
                    if (!table) {
                        return;
                    }

                    const visibleWidth = toggles.reduce((sum, toggle) => {
                        return toggle.checked ? sum + getColumnWidth(toggle.value) : sum;
                    }, 0);
                    const minimumWidth = tableWrap?.clientWidth || 0;

                    table.style.width = `${Math.max(visibleWidth, minimumWidth)}px`;
                };

                const updateScrollButtons = () => {
                    if (!tableWrap) {
                        return;
                    }

                    const maxScroll = Math.max(0, tableWrap.scrollWidth - tableWrap.clientWidth - 1);
                    const canScroll = maxScroll > 0;

                    if (scrollLeftButton) {
                        scrollLeftButton.disabled = !canScroll || tableWrap.scrollLeft <= 0;
                    }

                    if (scrollRightButton) {
                        scrollRightButton.disabled = !canScroll || tableWrap.scrollLeft >= maxScroll;
                    }
                };

                const scrollTableBy = (offset) => {
                    if (!tableWrap) {
                        return;
                    }

                    tableWrap.scrollBy({ left: offset, behavior: 'smooth' });
                    window.setTimeout(updateScrollButtons, 260);
                };

                const applyVisibility = () => {
                    let visibleCount = 0;
                    let lastVisibleColumn = null;

                    toggles.forEach((toggle) => {
                        const column = toggle.value;
                        const isVisible = toggle.checked;

                        if (isVisible) {
                            visibleCount += 1;
                            lastVisibleColumn = column;
                        }

                        root.querySelectorAll(`[data-column="${column}"]`).forEach((cell) => {
                            cell.hidden = !isVisible;
                            cell.dataset.isLastVisible = '0';
                        });

                        root.querySelectorAll(`col[data-column-col="${column}"]`).forEach((col) => {
                            col.style.visibility = isVisible ? '' : 'collapse';
                        });
                    });

                    if (countNode) {
                        countNode.textContent = `${visibleCount}/${toggles.length}`;
                    }

                    if (lastVisibleColumn) {
                        root.querySelectorAll(`[data-column="${lastVisibleColumn}"]`).forEach((cell) => {
                            cell.dataset.isLastVisible = '1';
                        });
                    }

                    setTableWidth();
                    updateScrollButtons();
                };

                const moveColumnRow = (row, direction) => {
                    if (!row) {
                        return;
                    }

                    if (direction === 'up' && row.previousElementSibling) {
                        row.previousElementSibling.before(row);
                    }

                    if (direction === 'down' && row.nextElementSibling) {
                        row.nextElementSibling.after(row);
                    }
                };

                toggles.forEach((toggle) => toggle.addEventListener('change', applyVisibility));

                root.querySelector('[data-role="contact-dialogs-show-all"]')?.addEventListener('click', () => {
                    toggles.forEach((toggle) => {
                        toggle.checked = true;
                    });
                    applyVisibility();
                });

                root.querySelector('[data-role="contact-dialogs-apply-columns"]')?.addEventListener('click', () => {
                    applyColumnOrder(getRowsOrder(), true);
                    applyVisibility();

                    if (columnsDetails) {
                        columnsDetails.open = false;
                    }
                });

                root.querySelector('[data-role="contact-dialogs-reset-order"]')?.addEventListener('click', () => {
                    window.localStorage.removeItem(orderStorageKey);
                    applyColumnOrder(defaultOrder, false);
                    applyVisibility();
                });

                scrollLeftButton?.addEventListener('click', () => scrollTableBy(-420));
                scrollRightButton?.addEventListener('click', () => scrollTableBy(420));
                tableWrap?.addEventListener('scroll', updateScrollButtons, { passive: true });
                tableWrap?.addEventListener('wheel', (event) => {
                    const horizontalDelta = Math.abs(event.deltaX) >= Math.abs(event.deltaY)
                        ? event.deltaX
                        : (event.shiftKey ? event.deltaY : 0);

                    if (horizontalDelta === 0 || tableWrap.scrollWidth <= tableWrap.clientWidth) {
                        return;
                    }

                    event.preventDefault();
                    tableWrap.scrollLeft += horizontalDelta;
                    updateScrollButtons();
                }, { passive: false });

                columnsList?.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-role="contact-dialogs-move-column"]');

                    if (!button) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();
                    moveColumnRow(
                        button.closest('[data-role="contact-dialogs-column-order-row"]'),
                        button.dataset.direction,
                    );
                });

                columnsList?.addEventListener('dragstart', (event) => {
                    const row = event.target.closest('[data-role="contact-dialogs-column-order-row"]');

                    if (!row) {
                        return;
                    }

                    row.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', row.dataset.columnKey || '');
                });

                columnsList?.addEventListener('dragover', (event) => {
                    const dragging = columnsList.querySelector('.is-dragging');
                    const target = event.target.closest('[data-role="contact-dialogs-column-order-row"]');

                    if (!dragging || !target || dragging === target) {
                        return;
                    }

                    event.preventDefault();

                    const targetRect = target.getBoundingClientRect();
                    const isAfter = event.clientY > targetRect.top + targetRect.height / 2;

                    if (isAfter) {
                        target.after(dragging);
                    } else {
                        target.before(dragging);
                    }
                });

                columnsList?.addEventListener('drop', (event) => {
                    event.preventDefault();
                });

                columnsList?.addEventListener('dragend', () => {
                    columnsList
                        .querySelectorAll('[data-role="contact-dialogs-column-order-row"]')
                        .forEach((row) => row.classList.remove('is-dragging'));
                });

                root.querySelectorAll('[data-role="contact-dialogs-column-resize"]').forEach((handle) => {
                    handle.addEventListener('pointerdown', (event) => {
                        event.preventDefault();
                        event.stopPropagation();

                        const column = handle.dataset.columnResize;
                        const col = root.querySelector(`col[data-column-col="${column}"]`);

                        if (!col) {
                            return;
                        }

                        const startX = event.clientX;
                        const startWidth = getColumnWidth(column);
                        const minWidth = Number(col.dataset.columnMinWidth || 80);

                        document.body.classList.add('ac-contact-dialogs-is-resizing');
                        handle.setPointerCapture?.(event.pointerId);

                        const onMove = (moveEvent) => {
                            const nextWidth = Math.max(minWidth, startWidth + moveEvent.clientX - startX);

                            col.style.width = `${nextWidth}px`;
                            setTableWidth();
                        };

                        const onUp = () => {
                            document.removeEventListener('pointermove', onMove);
                            document.removeEventListener('pointerup', onUp);
                            document.body.classList.remove('ac-contact-dialogs-is-resizing');

                            const widths = readStoredWidths();
                            root.querySelectorAll('col[data-column-col]').forEach((columnNode) => {
                                widths[columnNode.dataset.columnCol] = Math.round(parseFloat(columnNode.style.width || '0'));
                            });

                            window.localStorage.setItem(widthStorageKey, JSON.stringify(widths));
                        };

                        document.addEventListener('pointermove', onMove);
                        document.addEventListener('pointerup', onUp, { once: true });
                    });
                });

                root.addEventListener('click', (event) => {
                    if (event.target.closest('a, button, input, label, summary, details')) {
                        return;
                    }

                    const row = event.target.closest('[data-role="contact-dialog"]');

                    if (row?.dataset.href) {
                        window.location.href = row.dataset.href;
                    }
                });

                applyColumnOrder(readStoredOrder(), false);
                applyVisibility();
                updateScrollButtons();
                window.addEventListener('resize', () => {
                    setTableWidth();
                    updateScrollButtons();
                });
            };

            const initAllContactDialogsTables = () => {
                document
                    .querySelectorAll('[data-role="contact-dialogs"]')
                    .forEach(initContactDialogsTable);
            };

            initAllContactDialogsTables();
            document.addEventListener('DOMContentLoaded', initAllContactDialogsTables);
            document.addEventListener('livewire:navigated', initAllContactDialogsTables);
        })();
    </script>
@endif
