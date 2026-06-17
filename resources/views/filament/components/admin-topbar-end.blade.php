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

    <div
        class="ac-admin-notifications"
        data-ac-dialog-notifications
        data-state-url="{{ route('admin.dialog-notifications.show') }}"
        data-preferences-url="{{ route('admin.dialog-notifications.preferences.update') }}"
        data-mark-read-url="{{ route('admin.dialog-notifications.mark-read') }}"
        data-csrf-token="{{ csrf_token() }}"
    >
        <button
            type="button"
            class="ac-admin-icon-button ac-admin-notifications__toggle"
            aria-label="Уведомления"
            aria-expanded="false"
            data-ac-notifications-toggle
        >
            <x-filament::icon icon="heroicon-m-bell" class="h-5 w-5" />
            <span class="ac-admin-notifications__badge" data-ac-notifications-count hidden>0</span>
        </button>

        <section class="ac-admin-notifications__popover" data-ac-notifications-popover hidden>
            <header class="ac-admin-notifications__head">
                <div>
                    <p class="ac-admin-notifications__title">Уведомления</p>
                    <p class="ac-admin-notifications__status" data-ac-notifications-status>Проверяем новые сообщения</p>
                </div>
                <button type="button" class="ac-admin-notifications__link" data-ac-notifications-mark-read>
                    Прочитано
                </button>
            </header>

            <div class="ac-admin-notifications__scope" role="group" aria-label="Охват уведомлений">
                <button type="button" data-ac-notifications-scope="mine">Мои</button>
                <button type="button" data-ac-notifications-scope="mine_unassigned">Мои и свободные</button>
                <button type="button" data-ac-notifications-scope="all">Все</button>
            </div>

            <div class="ac-admin-notifications__sound">
                <button type="button" class="ac-admin-notifications__sound-toggle" data-ac-notifications-sound-toggle>
                    Включить звук
                </button>
                <button type="button" class="ac-admin-notifications__link" data-ac-notifications-sound-test>
                    Проверить
                </button>
            </div>

            <div class="ac-admin-notifications__list" data-ac-notifications-list></div>

            <p class="ac-admin-notifications__empty" data-ac-notifications-empty>
                Новых уведомлений нет
            </p>
        </section>
    </div>
</div>

<script>
    (() => {
        if (window.acDialogNotificationsInitialized) {
            return;
        }

        window.acDialogNotificationsInitialized = true;

        const root = document.querySelector('[data-ac-dialog-notifications]');

        if (!root) {
            return;
        }

        const stateUrl = root.dataset.stateUrl;
        const preferencesUrl = root.dataset.preferencesUrl;
        const markReadUrl = root.dataset.markReadUrl;
        const csrfToken = root.dataset.csrfToken;
        const toggle = root.querySelector('[data-ac-notifications-toggle]');
        const popover = root.querySelector('[data-ac-notifications-popover]');
        const countNode = root.querySelector('[data-ac-notifications-count]');
        const statusNode = root.querySelector('[data-ac-notifications-status]');
        const listNode = root.querySelector('[data-ac-notifications-list]');
        const emptyNode = root.querySelector('[data-ac-notifications-empty]');
        const markReadButton = root.querySelector('[data-ac-notifications-mark-read]');
        const soundToggle = root.querySelector('[data-ac-notifications-sound-toggle]');
        const soundTest = root.querySelector('[data-ac-notifications-sound-test]');
        const scopeButtons = Array.from(root.querySelectorAll('[data-ac-notifications-scope]'));
        const soundStorageKey = 'ab.dialogNotifications.soundEnabled.v1';
        const soundDedupeStorageKey = 'ab.dialogNotifications.soundDedupe.v1';
        const stateStorageKey = 'ab.dialogNotifications.state.v1';
        const pollLeaseStorageKey = 'ab.dialogNotifications.pollLease.v1';
        const soundDedupeWindowMs = 10000;
        const pollLeaseMs = 11000;
        const pollIntervalMs = 8000;
        const tabId = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`;

        let soundEnabled = window.localStorage.getItem(soundStorageKey) === '1';
        let audioContext = null;
        let latestKnownNotificationMessageId = 0;
        let hasBootstrappedSoundBaseline = false;
        let isPolling = false;

        const jsonHeaders = () => ({
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        });

        const readJsonStorage = (key) => {
            try {
                const state = JSON.parse(window.localStorage.getItem(key) || '{}');

                return state && typeof state === 'object' ? state : {};
            } catch (error) {
                return {};
            }
        };

        const setPopoverOpen = (isOpen) => {
            popover.hidden = !isOpen;
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        };

        const renderSoundState = () => {
            soundToggle.textContent = soundEnabled ? 'Выключить звук' : 'Включить звук';
            root.classList.toggle('is-sound-enabled', soundEnabled);
        };

        const ensureAudio = async () => {
            const Context = window.AudioContext || window.webkitAudioContext;

            if (!Context) {
                throw new Error('AudioContext is not supported.');
            }

            audioContext ??= new Context();

            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            return audioContext;
        };

        const playSound = async () => {
            if (!soundEnabled) {
                return;
            }

            const context = await ensureAudio();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            const now = context.currentTime;

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(880, now);
            oscillator.frequency.exponentialRampToValueAtTime(660, now + 0.16);
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.16, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.2);
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start(now);
            oscillator.stop(now + 0.22);
        };

        const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

        const readSoundDedupeState = () => readJsonStorage(soundDedupeStorageKey);

        const soundWasRecentlyPlayed = (messageId) => {
            const state = readSoundDedupeState();

            return Number(state.messageId || 0) === messageId
                && Date.now() - Number(state.playedAt || 0) < soundDedupeWindowMs;
        };

        const claimSoundForMessage = (messageId) => {
            window.localStorage.setItem(soundDedupeStorageKey, JSON.stringify({
                messageId,
                playedAt: Date.now(),
                tabId,
            }));
        };

        const playSoundForMessage = async (messageId) => {
            if (!soundEnabled || messageId < 1 || soundWasRecentlyPlayed(messageId)) {
                return;
            }

            claimSoundForMessage(messageId);
            await playSound();
        };

        const playCoordinatedSound = async (messageId) => {
            if (!soundEnabled || messageId < 1) {
                return;
            }

            const visibilityDelay = document.visibilityState === 'visible' ? 30 : 420;

            await sleep(visibilityDelay + Math.floor(Math.random() * 120));

            if (navigator.locks?.request) {
                await navigator.locks.request(
                    'ab-dialog-notifications-sound',
                    { ifAvailable: true },
                    async (lock) => {
                        if (!lock) {
                            return;
                        }

                        await playSoundForMessage(messageId);
                    },
                );

                return;
            }

            if (soundWasRecentlyPlayed(messageId)) {
                return;
            }

            claimSoundForMessage(messageId);
            await sleep(80 + Math.floor(Math.random() * 80));

            const state = readSoundDedupeState();

            if (Number(state.messageId || 0) === messageId && state.tabId === tabId) {
                await playSound();
            }
        };

        const readPollLease = () => readJsonStorage(pollLeaseStorageKey);

        const acquirePollLease = () => {
            const now = Date.now();
            const currentLease = readPollLease();

            if (
                currentLease.tabId
                && currentLease.tabId !== tabId
                && Number(currentLease.expiresAt || 0) > now
            ) {
                return false;
            }

            const nextLease = {
                tabId,
                expiresAt: now + pollLeaseMs,
                visible: document.visibilityState === 'visible',
            };

            window.localStorage.setItem(pollLeaseStorageKey, JSON.stringify(nextLease));

            return readPollLease().tabId === tabId;
        };

        const writeCachedState = (state) => {
            window.localStorage.setItem(stateStorageKey, JSON.stringify({
                state,
                writtenAt: Date.now(),
                tabId,
            }));
        };

        const renderCachedState = () => {
            const cached = readJsonStorage(stateStorageKey);

            if (cached.state && typeof cached.state === 'object') {
                renderState(cached.state, { allowSound: false });
            }
        };

        const setStatus = (state) => {
            const count = Number(state.count || 0);

            statusNode.textContent = count > 0
                ? `Новых: ${count}`
                : 'Новых уведомлений нет';
        };

        const renderBadge = (count) => {
            const normalizedCount = Number(count || 0);

            if (normalizedCount < 1) {
                countNode.hidden = true;
                countNode.textContent = '0';
                toggle.classList.remove('is-active');

                return;
            }

            countNode.hidden = false;
            countNode.textContent = normalizedCount > 99 ? '99+' : String(normalizedCount);
            toggle.classList.add('is-active');
        };

        const renderScopes = (activeScope) => {
            scopeButtons.forEach((button) => {
                const isActive = button.dataset.acNotificationsScope === activeScope;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        };

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const renderItems = (items) => {
            listNode.innerHTML = '';
            emptyNode.hidden = items.length > 0;

            items.forEach((item) => {
                const link = document.createElement('a');
                link.className = 'ac-admin-notifications__item';
                link.href = item.url;
                link.dataset.acNotificationsOpen = String(item.message_id || '');
                link.innerHTML = `
                    <span class="ac-admin-notifications__item-head">
                        <span class="ac-admin-notifications__contact">${escapeHtml(item.contact)}</span>
                        <span class="ac-admin-notifications__time">${escapeHtml(item.received_at)}</span>
                    </span>
                    <span class="ac-admin-notifications__channel">${escapeHtml(item.channel)}</span>
                    <span class="ac-admin-notifications__text">${escapeHtml(item.text)}</span>
                `;
                listNode.append(link);
            });
        };

        const renderState = (state, options = {}) => {
            const allowSound = options.allowSound !== false;
            const items = Array.isArray(state.items) ? state.items : [];
            const latestNotificationMessageId = Number(state.latest_notification_message_id || 0);

            renderBadge(state.count);
            renderScopes(state.scope);
            renderItems(items);
            setStatus(state);

            if (!hasBootstrappedSoundBaseline) {
                latestKnownNotificationMessageId = latestNotificationMessageId;
                hasBootstrappedSoundBaseline = true;

                return;
            }

            if (
                allowSound
                && document.visibilityState === 'visible'
                &&
                latestNotificationMessageId > latestKnownNotificationMessageId
                && Number(state.count || 0) > 0
            ) {
                latestKnownNotificationMessageId = latestNotificationMessageId;
                playCoordinatedSound(latestNotificationMessageId).catch(() => {});
            }
        };

        const fetchState = async (initialize = false, options = {}) => {
            if (isPolling) {
                return;
            }

            if (!options.force && !acquirePollLease()) {
                renderCachedState();

                return;
            }

            isPolling = true;

            try {
                const url = new URL(stateUrl, window.location.origin);

                if (initialize) {
                    url.searchParams.set('initialize', '1');
                }

                const response = await fetch(url, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error(`Notification state failed: ${response.status}`);
                }

                const state = await response.json();

                writeCachedState(state);
                renderState(state);
            } catch (error) {
                statusNode.textContent = 'Нет связи с уведомлениями';
            } finally {
                isPolling = false;
            }
        };

        const postJson = async (url, payload = {}) => {
            const response = await fetch(url, {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                throw new Error(`Notification update failed: ${response.status}`);
            }

            const state = await response.json();

            writeCachedState(state);
            renderState(state);
        };

        toggle?.addEventListener('click', () => {
            setPopoverOpen(popover.hidden);
        });

        document.addEventListener('click', (event) => {
            if (popover.hidden || root.contains(event.target)) {
                return;
            }

            setPopoverOpen(false);
        });

        scopeButtons.forEach((button) => {
            button.addEventListener('click', async () => {
                const scope = button.dataset.acNotificationsScope;

                try {
                    const response = await fetch(preferencesUrl, {
                        method: 'PATCH',
                        headers: jsonHeaders(),
                        body: JSON.stringify({ scope }),
                    });

                    if (!response.ok) {
                        throw new Error(`Scope update failed: ${response.status}`);
                    }

                    renderState(await response.json());
                } catch (error) {
                    statusNode.textContent = 'Режим не сохранён';
                }
            });
        });

        markReadButton?.addEventListener('click', () => {
            postJson(markReadUrl).catch(() => {
                statusNode.textContent = 'Не удалось отметить прочитанным';
            });
        });

        listNode?.addEventListener('click', (event) => {
            const link = event.target.closest('[data-ac-notifications-open]');

            if (!link) {
                return;
            }

            event.preventDefault();

            const messageId = Number(link.dataset.acNotificationsOpen || 0);

            postJson(markReadUrl, { message_id: messageId })
                .catch(() => {})
                .finally(() => {
                    window.location.href = link.href;
                });
        });

        soundToggle?.addEventListener('click', async () => {
            soundEnabled = !soundEnabled;
            window.localStorage.setItem(soundStorageKey, soundEnabled ? '1' : '0');
            renderSoundState();

            if (soundEnabled) {
                playSound().catch(() => {});
            }
        });

        soundTest?.addEventListener('click', async () => {
            if (!soundEnabled) {
                soundEnabled = true;
                window.localStorage.setItem(soundStorageKey, '1');
                renderSoundState();
            }

            playSound().catch(() => {});
        });

        window.addEventListener('storage', (event) => {
            if (event.key === soundStorageKey) {
                soundEnabled = event.newValue === '1';
                renderSoundState();
            }

            if (event.key === stateStorageKey) {
                renderCachedState();
            }
        });

        renderSoundState();
        renderCachedState();
        fetchState(true, { force: document.visibilityState === 'visible' });
        window.setInterval(() => fetchState(false), pollIntervalMs);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                fetchState(false, { force: true });
            }
        });
    })();
</script>
