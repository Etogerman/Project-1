@php
    $notificationSoundOptions = [
        ['value' => '01_click', 'label' => '01 Клик', 'url' => asset('audio/notifications/01_click.wav')],
        ['value' => '02_double_click', 'label' => '02 Двойной клик', 'url' => asset('audio/notifications/02_double_click.wav')],
        ['value' => '03_snap_click', 'label' => '03 Щелчок', 'url' => asset('audio/notifications/03_snap_click.wav')],
        ['value' => '04_glass_tick', 'label' => '04 Стеклянный тик', 'url' => asset('audio/notifications/04_glass_tick.wav')],
        ['value' => '05_soft_tick', 'label' => '05 Мягкий тик', 'url' => asset('audio/notifications/05_soft_tick.wav')],
        ['value' => '06_wood_tap', 'label' => '06 Глухой тап', 'url' => asset('audio/notifications/06_wood_tap.wav')],
        ['value' => '07_digital_blip', 'label' => '07 Цифровой блип', 'url' => asset('audio/notifications/07_digital_blip.wav')],
        ['value' => '08_clean_ping', 'label' => '08 Чистый пинг', 'url' => asset('audio/notifications/08_clean_ping.wav')],
        ['value' => '09_low_ping', 'label' => '09 Низкий пинг', 'url' => asset('audio/notifications/09_low_ping.wav')],
        ['value' => '10_high_ping', 'label' => '10 Высокий пинг', 'url' => asset('audio/notifications/10_high_ping.wav')],
        ['value' => '11_double_ping', 'label' => '11 Двойной пинг', 'url' => asset('audio/notifications/11_double_ping.wav')],
        ['value' => '12_rising_ping', 'label' => '12 Восходящий пинг', 'url' => asset('audio/notifications/12_rising_ping.wav')],
        ['value' => '13_falling_ping', 'label' => '13 Нисходящий пинг', 'url' => asset('audio/notifications/13_falling_ping.wav')],
        ['value' => '14_ping_tick', 'label' => '14 Пинг-тик', 'url' => asset('audio/notifications/14_ping_tick.wav')],
        ['value' => '15_pop', 'label' => '15 Поп', 'url' => asset('audio/notifications/15_pop.wav')],
        ['value' => '16_soft_pop', 'label' => '16 Мягкий поп', 'url' => asset('audio/notifications/16_soft_pop.wav')],
        ['value' => '17_bubble_pop', 'label' => '17 Пузырьковый поп', 'url' => asset('audio/notifications/17_bubble_pop.wav')],
        ['value' => '18_radio_beep', 'label' => '18 Радио-бип', 'url' => asset('audio/notifications/18_radio_beep.wav')],
        ['value' => '19_two_tone_beep', 'label' => '19 Двухтональный бип', 'url' => asset('audio/notifications/19_two_tone_beep.wav')],
        ['value' => '20_scanner_beep', 'label' => '20 Сканер-бип', 'url' => asset('audio/notifications/20_scanner_beep.wav')],
        ['value' => '21_tiny_bell', 'label' => '21 Короткий звонок', 'url' => asset('audio/notifications/21_tiny_bell.wav')],
        ['value' => '22_bright_bell', 'label' => '22 Яркий звонок', 'url' => asset('audio/notifications/22_bright_bell.wav')],
        ['value' => '23_short_chime', 'label' => '23 Короткий чайм', 'url' => asset('audio/notifications/23_short_chime.wav')],
        ['value' => '24_chime_tick', 'label' => '24 Чайм-тик', 'url' => asset('audio/notifications/24_chime_tick.wav')],
        ['value' => '25_desk_bell', 'label' => '25 Настольный звонок', 'url' => asset('audio/notifications/25_desk_bell.wav')],
        ['value' => '26_alert_tap', 'label' => '26 Сигнальный тап', 'url' => asset('audio/notifications/26_alert_tap.wav')],
        ['value' => '27_operator_beep', 'label' => '27 Операторский бип', 'url' => asset('audio/notifications/27_operator_beep.wav')],
        ['value' => '28_compact_signal', 'label' => '28 Компактный сигнал', 'url' => asset('audio/notifications/28_compact_signal.wav')],
        ['value' => '29_confirm_ding', 'label' => '29 Подтверждающий динь', 'url' => asset('audio/notifications/29_confirm_ding.wav')],
        ['value' => '30_clear_notify', 'label' => '30 Ясное уведомление', 'url' => asset('audio/notifications/30_clear_notify.wav')],
    ];
@endphp

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

            <label class="ac-admin-notifications__field">
                <span>Звук</span>
                <select data-ac-notifications-sound-choice>
                    @foreach ($notificationSoundOptions as $sound)
                        <option
                            value="{{ $sound['value'] }}"
                            data-ac-notifications-sound-option
                        >
                            {{ $sound['label'] }}
                        </option>
                    @endforeach
                </select>
            </label>

            <div class="ac-admin-notifications__volume" role="group" aria-label="Громкость уведомлений">
                <button type="button" data-ac-notifications-volume="quiet">Тихий</button>
                <button type="button" data-ac-notifications-volume="medium">Средний</button>
                <button type="button" data-ac-notifications-volume="loud">Громкий</button>
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
        const soundOptions = @json($notificationSoundOptions);
        const defaultSoundChoice = '07_digital_blip';
        const toggle = root.querySelector('[data-ac-notifications-toggle]');
        const popover = root.querySelector('[data-ac-notifications-popover]');
        const countNode = root.querySelector('[data-ac-notifications-count]');
        const statusNode = root.querySelector('[data-ac-notifications-status]');
        const listNode = root.querySelector('[data-ac-notifications-list]');
        const emptyNode = root.querySelector('[data-ac-notifications-empty]');
        const markReadButton = root.querySelector('[data-ac-notifications-mark-read]');
        const soundToggle = root.querySelector('[data-ac-notifications-sound-toggle]');
        const soundTest = root.querySelector('[data-ac-notifications-sound-test]');
        const soundChoiceSelect = root.querySelector('[data-ac-notifications-sound-choice]');
        const scopeButtons = Array.from(root.querySelectorAll('[data-ac-notifications-scope]'));
        const volumeButtons = Array.from(root.querySelectorAll('[data-ac-notifications-volume]'));
        const soundStorageKey = 'ab.dialogNotifications.soundEnabled.v1';
        const soundChoiceStorageKey = 'ab.dialogNotifications.soundChoice.v1';
        const soundLevelStorageKey = 'ab.dialogNotifications.soundLevel.v1';
        const soundDedupeStorageKey = 'ab.dialogNotifications.soundDedupe.v1';
        const lastSoundMessageStorageKey = 'ab.dialogNotifications.lastSoundMessage.v1';
        const stateStorageKey = 'ab.dialogNotifications.state.v1';
        const pollLeaseStorageKey = 'ab.dialogNotifications.pollLease.v1';
        const soundVolumeByLevel = {
            quiet: 0.3,
            medium: 0.65,
            loud: 1,
        };
        const soundDedupeWindowMs = 10000;
        const pollLeaseMs = 5000;
        const pollIntervalMs = 3000;
        const tabId = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`;

        let soundEnabled = window.localStorage.getItem(soundStorageKey) === '1';
        let soundChoice = normalizeSoundChoice(window.localStorage.getItem(soundChoiceStorageKey));
        let soundLevel = normalizeSoundLevel(window.localStorage.getItem(soundLevelStorageKey));
        let lastSoundMessageId = normalizePositiveInteger(window.localStorage.getItem(lastSoundMessageStorageKey));
        let audioContext = null;
        const soundBufferCache = new Map();
        let latestKnownNotificationMessageId = 0;
        let hasBootstrappedSoundBaseline = false;
        let isPolling = false;

        function normalizeSoundLevel(value) {
            return ['quiet', 'medium', 'loud'].includes(value) ? value : 'medium';
        }

        function normalizeSoundChoice(value) {
            return soundOptions.some((option) => option.value === value)
                ? value
                : defaultSoundChoice;
        }

        function normalizePositiveInteger(value) {
            const number = Number(value || 0);

            return Number.isFinite(number) && number > 0 ? number : 0;
        }

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

        const renderSoundLevelState = () => {
            volumeButtons.forEach((button) => {
                const isActive = button.dataset.acNotificationsVolume === soundLevel;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        };

        const renderSoundChoiceState = () => {
            if (soundChoiceSelect) {
                soundChoiceSelect.value = soundChoice;
            }
        };

        const selectedSoundOption = () => (
            soundOptions.find((option) => option.value === soundChoice) || soundOptions[0]
        );

        const selectedVolume = () => soundVolumeByLevel[soundLevel] ?? soundVolumeByLevel.medium;

        const reportSoundBlocked = () => {
            statusNode.textContent = 'Звук заблокирован. Нажмите «Проверить».';
        };

        const ensureAudioContext = async () => {
            const Context = window.AudioContext || window.webkitAudioContext;

            if (!Context) {
                throw new Error('AudioContext is not supported.');
            }

            audioContext ??= new Context();

            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            if (audioContext.state !== 'running') {
                throw new Error('AudioContext is not running.');
            }

            return audioContext;
        };

        const loadSoundBuffer = async (context, option) => {
            if (!option?.url) {
                throw new Error('Notification sound is not configured.');
            }

            if (soundBufferCache.has(option.url)) {
                return soundBufferCache.get(option.url);
            }

            const response = await fetch(option.url, { cache: 'force-cache' });

            if (!response.ok) {
                throw new Error(`Notification sound failed: ${response.status}`);
            }

            const buffer = await context.decodeAudioData(await response.arrayBuffer());

            soundBufferCache.set(option.url, buffer);

            return buffer;
        };

        const preloadSelectedSound = () => {
            const option = selectedSoundOption();

            if (!option?.url) {
                return;
            }

            if (audioContext) {
                loadSoundBuffer(audioContext, option).catch(() => {});

                return;
            }

            fetch(option.url, { cache: 'force-cache' }).catch(() => {});
        };

        const playSound = async () => {
            if (!soundEnabled) {
                return;
            }

            const option = selectedSoundOption();

            if (!option?.url) {
                return;
            }

            const context = await ensureAudioContext();
            const buffer = await loadSoundBuffer(context, option);
            const source = context.createBufferSource();
            const gain = context.createGain();
            const startedAt = context.currentTime + 0.01;

            source.buffer = buffer;
            gain.gain.setValueAtTime(selectedVolume(), startedAt);
            source.connect(gain);
            gain.connect(context.destination);
            source.start(startedAt);
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

        const claimSoundAttemptForMessage = (messageId) => {
            window.localStorage.setItem(soundDedupeStorageKey, JSON.stringify({
                messageId,
                claimedAt: Date.now(),
                tabId,
            }));
        };

        const rememberSoundMessage = (messageId) => {
            if (messageId <= lastSoundMessageId) {
                return;
            }

            lastSoundMessageId = messageId;
            window.localStorage.setItem(lastSoundMessageStorageKey, String(messageId));
        };

        const playSoundForMessage = async (messageId) => {
            if (
                !soundEnabled
                || messageId < 1
                || messageId <= lastSoundMessageId
                || soundWasRecentlyPlayed(messageId)
            ) {
                return;
            }

            try {
                await playSound();
                claimSoundForMessage(messageId);
                rememberSoundMessage(messageId);
            } catch (error) {
                reportSoundBlocked();

                throw error;
            }
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

            claimSoundAttemptForMessage(messageId);
            await sleep(80 + Math.floor(Math.random() * 80));

            const state = readSoundDedupeState();

            if (Number(state.messageId || 0) === messageId && state.tabId === tabId) {
                await playSoundForMessage(messageId);
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

        const renderCachedState = (options = {}) => {
            const cached = readJsonStorage(stateStorageKey);

            if (cached.state && typeof cached.state === 'object') {
                renderState(cached.state, {
                    allowSound: options.allowSound === true,
                });
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
                latestKnownNotificationMessageId = Math.max(
                    latestKnownNotificationMessageId,
                    lastSoundMessageId,
                );
                hasBootstrappedSoundBaseline = true;
            }

            const shouldPlaySound = allowSound
                && latestNotificationMessageId > lastSoundMessageId
                && Number(state.count || 0) > 0;

            if (latestNotificationMessageId > latestKnownNotificationMessageId) {
                latestKnownNotificationMessageId = latestNotificationMessageId;
            }

            if (shouldPlaySound) {
                playCoordinatedSound(latestNotificationMessageId).catch(() => {});
            }
        };

        const fetchState = async (initialize = false, options = {}) => {
            if (isPolling) {
                return;
            }

            if (!options.force && !acquirePollLease()) {
                renderCachedState({ allowSound: true });

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
                playSound().catch(() => {
                    reportSoundBlocked();
                });
            }
        });

        soundTest?.addEventListener('click', async () => {
            if (!soundEnabled) {
                soundEnabled = true;
                window.localStorage.setItem(soundStorageKey, '1');
                renderSoundState();
            }

            playSound().catch(() => {
                reportSoundBlocked();
            });
        });

        soundChoiceSelect?.addEventListener('change', () => {
            soundChoice = normalizeSoundChoice(soundChoiceSelect.value);
            window.localStorage.setItem(soundChoiceStorageKey, soundChoice);
            renderSoundChoiceState();
            preloadSelectedSound();

            if (soundEnabled) {
                playSound().catch(() => {});
            }
        });

        volumeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                soundLevel = normalizeSoundLevel(button.dataset.acNotificationsVolume);
                window.localStorage.setItem(soundLevelStorageKey, soundLevel);
                renderSoundLevelState();
                preloadSelectedSound();

                if (soundEnabled) {
                    playSound().catch(() => {});
                }
            });
        });

        window.addEventListener('storage', (event) => {
            if (event.key === soundStorageKey) {
                soundEnabled = event.newValue === '1';
                renderSoundState();
            }

            if (event.key === soundChoiceStorageKey) {
                soundChoice = normalizeSoundChoice(event.newValue);
                renderSoundChoiceState();
                preloadSelectedSound();
            }

            if (event.key === soundLevelStorageKey) {
                soundLevel = normalizeSoundLevel(event.newValue);
                renderSoundLevelState();
                preloadSelectedSound();
            }

            if (event.key === lastSoundMessageStorageKey) {
                lastSoundMessageId = normalizePositiveInteger(event.newValue);
            }

            if (event.key === stateStorageKey) {
                renderCachedState({ allowSound: true });
            }
        });

        renderSoundState();
        renderSoundChoiceState();
        renderSoundLevelState();
        preloadSelectedSound();
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
