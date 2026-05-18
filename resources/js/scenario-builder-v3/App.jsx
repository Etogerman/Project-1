import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { loadScenarioBuilderState, publishScenarioBuilderState, saveScenarioBuilderState } from './api.js';

const MAIN_SHEET = {
    id: 'main',
    name: 'Главный',
    color: 'none',
    view: { tx: 120, ty: 88, zoom: 1 },
};

const NODE_WIDTH = 286;
const NODE_HEADER_HEIGHT = 54;
const MODULE_STRIP_HEIGHT = 26;
const NODE_BODY_PADDING = 10;
const MODULE_PREVIEW_HEIGHT = 53;
const MODULE_PREVIEW_GAP = 7;
const PORT_ROW_HEIGHT = 30;
const PORT_ROW_GAP = 4;
const PORT_DOT_CENTER_X = NODE_WIDTH - 6;
const EDGE_TARGET_CLEARANCE = 4;
const CANVAS_MIN_WIDTH = 1800;
const CANVAS_MIN_HEIGHT = 1100;
const CANVAS_EXPAND_PADDING = 720;
const DEFAULT_OUTPUT = { id: null, label: 'Дальше', kind: 'default', caption: 'Авто' };
const MODULE_ORDER = ['start_condition', 'message', 'buttons'];
const MATCH_OPTIONS = [
    ['exact_keyword', 'Точный текст'],
    ['contains_text', 'Содержит текст'],
    ['exact_parameter', 'Точный параметр'],
    ['exact_text_or_parameter', 'Текст или параметр'],
    ['exact_callback', 'Точный callback'],
    ['any_inbound', 'Любое входящее'],
];
const EDGE_MATCH_OPTIONS = [
    ['any_inbound', 'Любое входящее'],
    ['exact_text', 'Точный текст'],
    ['contains_text', 'Содержит текст'],
];
const EDGE_DATA_TYPE_OPTIONS = [
    ['any_text', 'Любой текст'],
    ['phone', 'Телефон'],
    ['email', 'Email'],
    ['number', 'Число'],
];
const EDGE_CAPTURE_SCOPE_OPTIONS = [
    ['dialog', 'Диалог'],
    ['contact', 'Контакт'],
];
const EDGE_CONTACT_FIELD_OPTIONS = [
    ['phone', 'Телефон', 'phone'],
    ['first_name', 'Имя', 'any_text'],
    ['last_name', 'Фамилия', 'any_text'],
    ['country', 'Страна', 'any_text'],
    ['city', 'Город', 'any_text'],
    ['gender', 'Пол', 'any_text'],
    ['age_years', 'Возраст', 'number'],
    ['age_range', 'Возрастной диапазон', 'any_text'],
];
const EDGE_DELAY_UNIT_OPTIONS = [
    ['sec', 'секунды'],
    ['min', 'минуты'],
];
const EDGE_DELAY_TYPE_OPTIONS = [
    ['immediate', 'Сразу'],
    ['relative', 'Через время'],
    ['scheduled', 'В дату и время'],
];
const DIAGNOSTICS_REFRESH_INTERVAL_MS = 10000;
const LOG_STATUS_FILTERS = [
    ['all', 'Все'],
    ['active', 'В работе'],
    ['passed', 'Выполнены'],
    ['attention', 'Внимание'],
];
const LOG_ACTIVE_STATUSES = ['scheduled', 'processing'];
const LOG_ATTENTION_STATUSES = ['cancelled', 'failed', 'limit_reached'];
const PHONE_CONDITION_OPTIONS = [
    ['', 'Неважно'],
    ['has_phone', 'Телефон заполнен'],
    ['missing_phone', 'Телефон не заполнен'],
];
const BUTTON_TYPE_TEXT = 'text';
const BUTTON_TYPE_REQUEST_PHONE = 'request_phone';
const BUTTON_TYPE_OPTIONS = [
    [BUTTON_TYPE_TEXT, 'Текстовая'],
    [BUTTON_TYPE_REQUEST_PHONE, 'Запросить телефон'],
];
const BUTTON_PLACEMENT_AUTO = 'auto';
const BUTTON_PLACEMENT_REPLY = 'reply_keyboard';
const BUTTON_PLACEMENT_INLINE = 'inline_message';
const BUTTON_PLACEMENT_OPTIONS = [
    [BUTTON_PLACEMENT_AUTO, 'Авто'],
    [BUTTON_PLACEMENT_REPLY, 'Клавиатура'],
    [BUTTON_PLACEMENT_INLINE, 'В сообщении'],
];
const REQUEST_PHONE_BUTTON_TEXT = 'Поделиться номером телефона';
const BUTTON_COLOR_OPTIONS = [
    [null, 'Без цвета', null],
    ['blue', 'Синий', '#2ea3db'],
    ['red', 'Красный', '#ef3d3d'],
    ['green', 'Зелёный', '#43a047'],
];

const MODULE_META = {
    start_condition: { label: 'Старт', short: 'ST', className: 'is-start' },
    message: { label: 'Сообщение', short: 'MSG', className: 'is-message' },
    buttons: { label: 'Кнопки', short: 'BTN', className: 'is-buttons' },
};

const FUTURE_MODULE_META = [
    { type: 'attachment', label: 'Вложение' },
    { type: 'ai', label: 'AI' },
    { type: 'bot', label: 'Бот' },
    { type: 'code', label: 'Код' },
    { type: 'cloud', label: 'Интеграция' },
    { type: 'analytics', label: 'Аналитика' },
];

const BLOCK_TYPE_META = {
    state: {
        label: 'Состояние',
        hint: 'Клиент находится в этом блоке, следующий ответ проверяется из него.',
    },
    non_state: {
        label: 'Не состояние',
        hint: 'Бот отправит сообщение, но клиент останется в текущем состоянии.',
    },
};

export default function App({ stateUrl, saveUrl, publishUrl, csrfToken }) {
    const canvasRef = useRef(null);
    const dragRef = useRef(null);
    const [state, setState] = useState(null);
    const [status, setStatus] = useState('loading');
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const [validationIssue, setValidationIssue] = useState(null);
    const [mode, setMode] = useState('design');
    const [logStatusFilter, setLogStatusFilter] = useState('all');
    const [tool, setTool] = useState('select');
    const [isSaving, setIsSaving] = useState(false);
    const [isPublishing, setIsPublishing] = useState(false);
    const [selectedBlockKey, setSelectedBlockKey] = useState(null);
    const [selectedEdgeKey, setSelectedEdgeKey] = useState(null);
    const [isPanelCollapsed, setIsPanelCollapsed] = useState(false);
    const [pendingButtonFocus, setPendingButtonFocus] = useState(null);
    const [pendingConnection, setPendingConnection] = useState(null);
    const [anchors, setAnchors] = useState({ ports: {} });

    useEffect(() => {
        let isMounted = true;

        setStatus('loading');
        setError(null);
        setNotice(null);
        setValidationIssue(null);

        loadScenarioBuilderState(stateUrl)
            .then((data) => {
                if (! isMounted) {
                    return;
                }

                setState(data);
                setSelectedBlockKey(data.builder?.blocks?.[0]?.client_key ?? null);
                setStatus('ready');
            })
            .catch((requestError) => {
                if (! isMounted) {
                    return;
                }

                setError(errorText(requestError));
                setStatus('error');
            });

        return () => {
            isMounted = false;
        };
    }, [stateUrl]);

    const refreshBuilderDiagnostics = useCallback(async ({ quiet = false } = {}) => {
        if (! quiet) {
            setError(null);
            setNotice(null);
        }

        try {
            const response = await loadScenarioBuilderState(stateUrl);

            setState((current) => mergeBuilderDiagnostics(current, response));
            setStatus('ready');

            if (! quiet) {
                setNotice('Статусы переходов обновлены');
            }

            return response;
        } catch (requestError) {
            if (! quiet) {
                setError(errorText(requestError));

                if (requestError.status === 409) {
                    setStatus('conflict');
                }
            }

            return null;
        }
    }, [stateUrl]);

    useEffect(() => {
        if (status !== 'ready' || isSaving || isPublishing) {
            return undefined;
        }

        let disposed = false;
        let inFlight = false;

        const refresh = async () => {
            if (
                disposed
                || inFlight
                || (typeof document !== 'undefined' && document.visibilityState === 'hidden')
            ) {
                return;
            }

            inFlight = true;

            try {
                await refreshBuilderDiagnostics({ quiet: true });
            } finally {
                inFlight = false;
            }
        };

        const interval = window.setInterval(refresh, DIAGNOSTICS_REFRESH_INTERVAL_MS);

        return () => {
            disposed = true;
            window.clearInterval(interval);
        };
    }, [status, isSaving, isPublishing, refreshBuilderDiagnostics]);

    const builder = state?.builder ?? null;
    const blocks = builder?.blocks ?? [];
    const edges = builder?.edges ?? [];
    const channels = state?.catalogs?.channels ?? [];
    const scheduledTransitions = builder?.diagnostics?.scheduled_transitions ?? [];
    const activeSheet = activeSheetFrom(builder);
    const view = activeSheet.view ?? MAIN_SHEET.view;
    const revision = builder?.revision ?? null;
    const serverClock = state?.server ?? null;
    const serverTimezone = serverClock?.timezone || '';
    const serverTimezoneLabel = serverClock?.timezone_abbr || serverClock?.utc_offset || '';
    const selectedBlock = blocks.find((block) => block.client_key === selectedBlockKey) ?? null;
    const selectedEdge = edges.find((edge) => edge.client_key === selectedEdgeKey) ?? null;
    const canSave = state?.permissions?.can_update === true && status === 'ready' && ! isSaving && ! isPublishing;
    const canPublish = state?.permissions?.can_publish === true && status === 'ready' && ! isSaving && ! isPublishing && Boolean(publishUrl);
    const canvasBounds = useMemo(() => graphBounds(blocks), [blocks]);
    const hasPanelSelection = Boolean(selectedBlock || selectedEdge);
    const isPanelOpen = mode === 'design' && hasPanelSelection && ! isPanelCollapsed;

    useLayoutEffect(() => {
        if (status !== 'ready' || ! canvasRef.current) {
            return;
        }

        const canvasRect = canvasRef.current.getBoundingClientRect();
        const ports = {};
        const nodes = {};

        canvasRef.current.querySelectorAll('[data-port-key]').forEach((element) => {
            const rect = element.getBoundingClientRect();

            ports[element.dataset.portKey] = {
                x: (rect.left + (rect.width / 2) - canvasRect.left - view.tx) / view.zoom,
                y: (rect.top + (rect.height / 2) - canvasRect.top - view.ty) / view.zoom,
            };
        });

        canvasRef.current.querySelectorAll('[data-node-key]').forEach((element) => {
            const rect = element.getBoundingClientRect();

            nodes[element.dataset.nodeKey] = {
                x: (rect.left - canvasRect.left - view.tx) / view.zoom,
                y: (rect.top - canvasRect.top - view.ty) / view.zoom,
                width: rect.width / view.zoom,
                height: rect.height / view.zoom,
            };
        });

        const next = { ports, nodes };

        setAnchors((current) => (JSON.stringify(current) === JSON.stringify(next) ? current : next));
    }, [status, blocks, edges, view.tx, view.ty, view.zoom]);

    function updateBuilder(nextBuilder) {
        setState((current) => ({
            ...current,
            builder: {
                ...current.builder,
                ...nextBuilder,
            },
        }));
    }

    function updateBlocks(nextBlocks) {
        setState((current) => ({
            ...current,
            builder: {
                ...current.builder,
                blocks: typeof nextBlocks === 'function' ? nextBlocks(current.builder?.blocks ?? []) : nextBlocks,
            },
        }));
    }

    function updateEdges(nextEdges) {
        setState((current) => ({
            ...current,
            builder: {
                ...current.builder,
                edges: typeof nextEdges === 'function' ? nextEdges(current.builder?.edges ?? []) : nextEdges,
            },
        }));
    }

    function updateView(nextView) {
        const sheets = (builder?.sheets?.length ? builder.sheets : [MAIN_SHEET]).map((sheet) => (
            sheet.id === activeSheet.id
                ? { ...sheet, view: typeof nextView === 'function' ? nextView(sheet.view ?? MAIN_SHEET.view) : nextView }
                : sheet
        ));

        updateBuilder({ sheets });
    }

    function selectBlock(clientKey) {
        setSelectedBlockKey(clientKey);
        setSelectedEdgeKey(null);
        setIsPanelCollapsed(false);
        cancelConnection();
    }

    function closePanelSelection() {
        setSelectedBlockKey(null);
        setSelectedEdgeKey(null);
        setIsPanelCollapsed(false);
    }

    function collapsePanel() {
        setIsPanelCollapsed(true);
    }

    function expandPanel() {
        if (hasPanelSelection) {
            setIsPanelCollapsed(false);
        }
    }

    function addBlock(kind) {
        const index = blocks.length + 1;
        const clientKey = `tmp_block_${Date.now().toString(36)}_${index}`;
        const position = {
            x: snap(Math.round((-view.tx + 140) / view.zoom) + index * 34),
            y: snap(Math.round((-view.ty + 116) / view.zoom) + index * 26),
        };
        const block = {
            id: null,
            client_key: clientKey,
            type: 'state',
            title: kind === 'start' ? `Старт ${index}` : `Блок ${index}`,
            position,
            settings_payload: messageSettingsPayload('Новый текст сообщения'),
        };

        if (kind === 'start') {
            block.settings_payload = startSettingsPayload(channels);
        }

        if (kind === 'buttons') {
            block.settings_payload = buttonsSettingsPayload();
        }

        updateBlocks([...blocks, block]);
        selectBlock(clientKey);
    }

    function updateBlock(clientKey, patch) {
        updateBlocks((currentBlocks) => currentBlocks.map((block) => (
            block.client_key === clientKey
                ? { ...block, ...(typeof patch === 'function' ? patch(block) : patch) }
                : block
        )));
    }

    function updateBlockSettings(clientKey, reducer) {
        updateBlock(clientKey, (block) => ({
            settings_payload: reducer(normalizeSettings(block.settings_payload)),
        }));
    }

    function removeBlock(clientKey) {
        updateBlocks(blocks.filter((block) => block.client_key !== clientKey));
        updateEdges(edges.filter((edge) => edge.source?.client_key !== clientKey && edge.target?.client_key !== clientKey));
        setSelectedBlockKey((current) => (current === clientKey ? null : current));
        setSelectedEdgeKey(null);
        setIsPanelCollapsed(false);
        cancelConnection();
    }

    function startBlockDrag(event, clientKey) {
        event.preventDefault();
        event.stopPropagation();

        const block = blocks.find((item) => item.client_key === clientKey);

        if (! block) {
            return;
        }

        dragRef.current = {
            type: 'block',
            clientKey,
            start: { x: event.clientX, y: event.clientY },
            origin: blockPosition(block),
        };
        setSelectedBlockKey(clientKey);
        setSelectedEdgeKey(null);
        setIsPanelCollapsed(false);
        cancelConnection();

        window.addEventListener('pointermove', handleGlobalPointerMove);
        window.addEventListener('pointerup', stopGlobalDrag, { once: true });
    }

    function startCanvasPan(event) {
        if (event.button !== 0 || event.target.closest('[data-node], [data-edge-action], button, input, textarea, select')) {
            return;
        }

        event.preventDefault();
        dragRef.current = {
            type: 'pan',
            start: { x: event.clientX, y: event.clientY },
            origin: { ...view },
        };
        setSelectedBlockKey(null);
        setSelectedEdgeKey(null);
        setIsPanelCollapsed(false);

        window.addEventListener('pointermove', handleGlobalPointerMove);
        window.addEventListener('pointerup', stopGlobalDrag, { once: true });
    }

    function handleGlobalPointerMove(event) {
        const drag = dragRef.current;

        if (! drag) {
            return;
        }

        if (drag.type === 'block') {
            const dx = (event.clientX - drag.start.x) / view.zoom;
            const dy = (event.clientY - drag.start.y) / view.zoom;

            updateBlock(drag.clientKey, {
                position: {
                    x: snap(drag.origin.x + dx),
                    y: snap(drag.origin.y + dy),
                },
            });

            return;
        }

        updateView({
            ...drag.origin,
            tx: Math.round(drag.origin.tx + event.clientX - drag.start.x),
            ty: Math.round(drag.origin.ty + event.clientY - drag.start.y),
        });
    }

    function stopGlobalDrag() {
        dragRef.current = null;
        window.removeEventListener('pointermove', handleGlobalPointerMove);
    }

    function handleWheel(event) {
        if (! (event.ctrlKey || event.metaKey)) {
            return;
        }

        event.preventDefault();

        const rect = canvasRef.current.getBoundingClientRect();
        const pivot = {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        };
        const nextZoom = clamp(view.zoom * (event.deltaY > 0 ? 0.9 : 1.1), 0.35, 2.2);

        updateView({
            tx: pivot.x - ((pivot.x - view.tx) / view.zoom) * nextZoom,
            ty: pivot.y - ((pivot.y - view.ty) / view.zoom) * nextZoom,
            zoom: nextZoom,
        });
    }

    function setZoom(nextZoom) {
        updateView((currentView) => ({
            ...currentView,
            zoom: clamp(nextZoom, 0.35, 2.2),
        }));
    }

    function fitCanvas() {
        if (blocks.length === 0) {
            updateView(MAIN_SHEET.view);

            return;
        }

        updateView({
            tx: 132 - canvasBounds.minX,
            ty: 100 - canvasBounds.minY,
            zoom: 1,
        });
    }

    function beginConnection(block, output) {
        const connection = {
            sourceKey: block.client_key,
            sourceId: block.id,
            outputId: output.id,
            label: output.label,
            kind: output.kind ?? (output.id === null ? 'default' : 'button'),
            from: outputAnchor(block, output.id),
        };

        cancelConnection();
        setPendingConnection(connection);
        setSelectedBlockKey(block.client_key);
        setSelectedEdgeKey(null);
        setIsPanelCollapsed(false);
        setNotice(null);
    }

    function startConnection(event, block, output) {
        event.preventDefault();
        event.stopPropagation();

        beginConnection(block, output);
    }

    function startPanelConnection(block, output) {
        beginConnection(block, output);
    }

    function completeConnection(targetBlock) {
        if (! pendingConnection) {
            selectBlock(targetBlock.client_key);

            return;
        }

        createConnectionEdge(pendingConnection, targetBlock);
    }

    function createConnectionEdge(connection, targetBlock) {
        if (! connection || targetBlock.client_key === connection.sourceKey) {
            return;
        }

        const target = {
            block_id: targetBlock.id,
            client_key: targetBlock.client_key,
        };
        const source = {
            block_id: connection.sourceId,
            client_key: connection.sourceKey,
            output_id: connection.outputId,
        };
        const edge = {
            id: null,
            client_key: `tmp_edge_${Date.now().toString(36)}`,
            source,
            target,
            condition_payload: edgePayload(connection.outputId, connection.label),
        };

        updateEdges((currentEdges) => [
            ...currentEdges.filter((item) => connection.outputId === null || ! sameSource(item.source, source)),
            edge,
        ]);
        setSelectedEdgeKey(edge.client_key);
        setSelectedBlockKey(null);
        setIsPanelCollapsed(false);
        cancelConnection();
    }

    function cancelConnection() {
        setPendingConnection(null);
    }

    function removeEdge(edgeKey) {
        updateEdges(edges.filter((edge) => edge.client_key !== edgeKey));
        setSelectedEdgeKey(null);
        setIsPanelCollapsed(false);
    }

    function updateEdgeConditionPayload(edgeKey, nextPayload) {
        updateEdges((currentEdges) => currentEdges.map((edge) => {
            if (edge.client_key !== edgeKey) {
                return edge;
            }

            return {
                ...edge,
                condition_payload: typeof nextPayload === 'function'
                    ? nextPayload(edge.condition_payload ?? {})
                    : {
                        ...(edge.condition_payload ?? {}),
                        ...nextPayload,
                    },
            };
        }));
    }

    function toggleModule(clientKey, type, enabled) {
        updateBlockSettings(clientKey, (settings) => {
            let modules = modulesFrom(settings);

            if (enabled && ! modules.some((module) => module.type === type)) {
                modules = [...modules, moduleTemplate(type, channels)];
            }

            if (! enabled) {
                modules = modules.filter((module) => module.type !== type);
            }

            let next = {
                ...settings,
                modules: sortModules(modules),
            };

            if (type === 'buttons' && enabled && ! findModule(next, 'message')) {
                next = {
                    ...next,
                    modules: sortModules([...modulesFrom(next), moduleTemplate('message', channels)]),
                };
            }

            if (type === 'buttons') {
                next = syncOutputs(next);
            }

            return next;
        });

        if (type === 'buttons' && ! enabled) {
            updateEdges(edges.filter((edge) => ! edge.source?.output_id || edge.source?.client_key !== clientKey));
        }
    }

    function updateModulePayload(clientKey, type, patch) {
        updateBlockSettings(clientKey, (settings) => ({
            ...settings,
            modules: sortModules(modulesFrom(settings).map((module) => (
                module.type === type
                    ? { ...module, payload: { ...module.payload, ...patch } }
                    : module
            ))),
        }));
    }

    function addButton(clientKey, rowIndex = null) {
        const currentBlock = blocks.find((block) => block.client_key === clientKey);
        const currentSettings = normalizeSettings(currentBlock?.settings_payload);
        const currentButtons = findModule(currentSettings, 'buttons') ?? moduleTemplate('buttons', channels);
        const preferredButtonId = nextButtonId(buttonRows(currentButtons));

        updateBlockSettings(clientKey, (settings) => {
            const buttons = findModule(settings, 'buttons') ?? moduleTemplate('buttons', channels);
            const rows = buttonRows(buttons);
            const ids = new Set(rows.flat().map((button) => button.id));
            const id = ids.has(preferredButtonId) ? nextButtonId(rows) : preferredButtonId;
            const nextRows = rows.map((row) => [...row]);
            const button = { id, text: '', type: BUTTON_TYPE_TEXT, fn: 'default', url: null, color: null };

            if (Number.isInteger(rowIndex) && nextRows[rowIndex]) {
                nextRows[rowIndex] = [...nextRows[rowIndex], button];
            } else {
                nextRows.push([button]);
            }

            const modules = modulesFrom(settings).filter((module) => module.type !== 'buttons');

            return syncOutputs({
                ...settings,
                modules: sortModules([
                    ...modules,
                    { ...buttons, payload: { ...buttons.payload, rows: nextRows } },
                ]),
            });
        });

        setPendingButtonFocus({ blockKey: clientKey, buttonId: preferredButtonId });
    }

    function updateButton(clientKey, buttonId, patch) {
        const normalizedPatch = { ...patch };

        if (
            normalizedPatch.type === BUTTON_TYPE_REQUEST_PHONE
            && ! String(normalizedPatch.text ?? currentButtonText(blocks, clientKey, buttonId)).trim()
        ) {
            normalizedPatch.text = REQUEST_PHONE_BUTTON_TEXT;
        }

        updateBlockSettings(clientKey, (settings) => syncOutputs({
            ...settings,
            modules: sortModules(modulesFrom(settings).map((module) => {
                if (module.type !== 'buttons') {
                    return module;
                }

                return {
                    ...module,
                    payload: {
                        ...module.payload,
                        rows: buttonRows(module).map((row) => row.map((button) => (
                            button.id === buttonId ? { ...button, ...normalizedPatch } : button
                        ))),
                    },
                };
            })),
        }));

        if (Object.prototype.hasOwnProperty.call(normalizedPatch, 'text')) {
            if (String(normalizedPatch.text ?? '').trim() !== '') {
                setValidationIssue((current) => (
                    current?.blockKey === clientKey && current?.buttonId === buttonId ? null : current
                ));
            }

            updateEdges(edges.map((edge) => {
                if (edge.source?.client_key !== clientKey || edge.source?.output_id !== buttonId) {
                    return edge;
                }

                return {
                    ...edge,
                    condition_payload: {
                        ...edge.condition_payload,
                        label: normalizedPatch.text,
                        match: {
                            ...(edge.condition_payload?.match ?? {}),
                            value: normalizedPatch.text,
                        },
                    },
                };
            }));
        }
    }

    function reorderButtons(clientKey, nextRows) {
        updateBlockSettings(clientKey, (settings) => syncOutputs({
            ...settings,
            modules: sortModules(modulesFrom(settings).map((module) => {
                if (module.type !== 'buttons') {
                    return module;
                }

                return {
                    ...module,
                    payload: {
                        ...module.payload,
                        rows: normalizeButtonRows(nextRows),
                    },
                };
            })),
        }));
    }

    function removeButton(clientKey, buttonId) {
        updateBlockSettings(clientKey, (settings) => syncOutputs({
            ...settings,
            modules: sortModules(modulesFrom(settings).map((module) => {
                if (module.type !== 'buttons') {
                    return module;
                }

                return {
                    ...module,
                    payload: {
                        ...module.payload,
                        rows: buttonRows(module)
                            .map((row) => row.filter((button) => button.id !== buttonId))
                            .filter((row) => row.length > 0),
                    },
                };
            })),
        }));

        updateEdges(edges.filter((edge) => edge.source?.client_key !== clientKey || edge.source?.output_id !== buttonId));
    }

    function updateStartChannels(clientKey, channelId, checked) {
        const block = blocks.find((item) => item.client_key === clientKey);
        const current = findModule(block?.settings_payload, 'start_condition')?.payload?.channels?.ids ?? [];
        const ids = checked
            ? [...new Set([...current, channelId])]
            : current.filter((id) => Number(id) !== Number(channelId));

        updateModulePayload(clientKey, 'start_condition', {
            channels: { mode: 'selected', ids },
        });
    }

    function savePayload() {
        const blocksForSave = blocks.map((block) => ({
            ...block,
            settings_payload: syncOutputs(normalizeSettings(block.settings_payload)),
        }));

        return {
            draft_version_id: state.scenario.draft_version_id,
            base_revision: state.builder.revision,
            builder: {
                schema_version: 3,
                active_sheet_id: state.builder.active_sheet_id || 'main',
                sheets: state.builder.sheets?.length ? state.builder.sheets : [MAIN_SHEET],
                blocks: blocksForSave,
                edges,
                visible_scope: state.builder.visible_scope || { block_ids: [], edge_ids: [] },
            },
        };
    }

    async function persistCurrentState(successNotice = 'Сохранено') {
        if (! state) {
            return null;
        }

        const emptyButtonIssue = firstEmptyButtonIssue(blocks);

        if (emptyButtonIssue) {
            setError(null);
            setNotice(null);
            setValidationIssue(emptyButtonIssue);

            return null;
        }

        setIsSaving(true);
        setError(null);
        setNotice(null);
        setValidationIssue(null);

        try {
            const selectedBefore = selectedBlockKey;
            const edgeBefore = selectedEdgeKey;
            const response = await saveScenarioBuilderState(saveUrl, csrfToken, savePayload());

            setState(response);
            setSelectedBlockKey(resolveReturnedKey(selectedBefore, response.id_map?.blocks, 'block'));
            setSelectedEdgeKey(resolveReturnedKey(edgeBefore, response.id_map?.edges, 'edge'));
            cancelConnection();
            setStatus('ready');
            if (successNotice) {
                setNotice(successNotice);
            }

            return response;
        } catch (requestError) {
            setError(errorText(requestError));

            if (requestError.status === 409) {
                setStatus('conflict');
            }

            throw requestError;
        } finally {
            setIsSaving(false);
        }
    }

    async function save() {
        try {
            await persistCurrentState();
        } catch {
            // Error state is already rendered by persistCurrentState.
        }
    }

    async function publish() {
        if (! state || ! publishUrl) {
            return;
        }

        const blockBeforePublish = selectedBlockKey;
        const edgeBeforePublish = selectedEdgeKey;

        setIsPublishing(true);
        setError(null);
        setNotice(null);

        try {
            const savedState = await persistCurrentState(null);

            if (! savedState) {
                return;
            }

            const response = await publishScenarioBuilderState(publishUrl, csrfToken, {
                draft_version_id: savedState.scenario.draft_version_id,
                base_revision: savedState.builder.revision,
            });
            const selection = resolvePublishedSelection(savedState, response, blockBeforePublish, edgeBeforePublish);

            setState(response);
            setSelectedBlockKey(selection.blockKey);
            setSelectedEdgeKey(selection.edgeKey);
            cancelConnection();
            setStatus('ready');
            setNotice(`Опубликовано v${response.published?.version_number ?? ''}`.trim());
        } catch (requestError) {
            setError(errorText(requestError));

            if (requestError.status === 409) {
                setStatus('conflict');
            }
        } finally {
            setIsPublishing(false);
        }
    }

    function openTransitionEdge(transition) {
        const edge = findEdgeByTransition(edges, transition);

        if (! edge) {
            setNotice(null);
            setError('Связь из этой записи не найдена в текущем черновике.');

            return;
        }

        setMode('design');
        setSelectedEdgeKey(edge.client_key);
        setSelectedBlockKey(null);
        setIsPanelCollapsed(false);
        setPendingConnection(null);
        setError(null);
        setNotice(`Открыта связь ${shortEdgeId(edge)}`);
    }

    function openValidationIssueBlock(issue) {
        setSelectedBlockKey(issue.blockKey);
        setSelectedEdgeKey(null);
        setIsPanelCollapsed(false);
        setTool('select');
        setPendingButtonFocus({ blockKey: issue.blockKey, buttonId: issue.buttonId });
    }

    function clearPendingButtonFocus() {
        setPendingButtonFocus(null);
    }

    async function copyBlockId(block) {
        const value = copyableBlockId(block);

        if (! value) {
            return;
        }

        try {
            await copyTextToClipboard(value);
            setError(null);
            setNotice(`ID #${value} скопирован`);
        } catch {
            setNotice(null);
            setError('Не удалось скопировать ID. Скопируйте номер вручную.');
        }
    }

    async function copyEdgeId(edge) {
        const value = copyableEdgeId(edge);

        if (! value) {
            return;
        }

        try {
            await copyTextToClipboard(value);
            setError(null);
            setNotice(`ID связи #${value} скопирован`);
        } catch {
            setNotice(null);
            setError('Не удалось скопировать ID связи. Скопируйте номер вручную.');
        }
    }

    if (status === 'loading') {
        return (
            <section className="ac-v3-builder" data-status="loading">
                <div className="ac-v3-builder__state">Конструктор загружается...</div>
            </section>
        );
    }

    if (status === 'error') {
        return (
            <section className="ac-v3-builder" data-status="error">
                <div className="ac-v3-builder__state ac-v3-builder__state--error">{error}</div>
            </section>
        );
    }

    return (
        <section className="ac-v3-builder">
            <header className="ac-v3-builder__topbar">
                <div className="ac-v3-builder__crumb">
                    <strong>{state?.scenario?.name ?? 'Сценарий'}</strong>
                    {revision ? <em>{revision}</em> : null}
                    {serverClock?.time ? (
                        <span className="ac-v3-builder__server-time">
                            Время сервера: {formatDateTime(serverClock.time, serverTimezoneLabel, serverTimezone)}
                        </span>
                    ) : null}
                </div>

                <div className="ac-v3-builder__top-actions">
                    <button type="button" className="ac-v3-builder__run" onClick={() => setNotice('Симулятор будет подключен отдельным шагом.')}>
                        <PlayIcon />
                        Прогнать
                    </button>
                    <div className="ac-v3-builder__mode" role="tablist" aria-label="Режим конструктора">
                        <button type="button" className={mode === 'design' ? 'is-active' : ''} onClick={() => setMode('design')}>Сценарий</button>
                        <button type="button" className={mode === 'logs' ? 'is-active' : ''} onClick={() => setMode('logs')}>Логи</button>
                    </div>
                    <button type="button" className="ac-v3-builder__icon-button" title="История версий" onClick={() => setNotice('История версий не входит в текущий шаг.')}>
                        <HistoryIcon />
                    </button>
                    <button type="button" className="ac-v3-builder__primary" disabled={! canSave} onClick={save}>
                        {isSaving ? 'Сохраняю...' : 'Сохранить'}
                    </button>
                    <button type="button" className="ac-v3-builder__publish" disabled={! canPublish} onClick={publish}>
                        {isPublishing ? 'Публикую...' : 'Опубликовать'}
                    </button>
                </div>
            </header>

            <div className="ac-v3-builder__tabs">
                <button type="button" className="is-active">
                    <span>{activeSheet.name}</span>
                    <b>{blocks.length}</b>
                    <GearIcon />
                </button>
                <button type="button" className="ac-v3-builder__tab-add" onClick={() => setNotice('В первом релизе используется один лист.')}>+</button>
            </div>

            {error ? (
                <Notice kind={status === 'conflict' ? 'conflict' : 'error'} onClose={() => setError(null)}>
                    {error}
                </Notice>
            ) : null}

            {notice ? (
                <Notice kind="info" onClose={() => setNotice(null)}>
                    {notice}
                </Notice>
            ) : null}

            {validationIssue ? (
                <Notice kind="error" onClose={() => setValidationIssue(null)}>
                    <span>Нельзя сохранить: в блоке «{validationIssue.blockTitle}» пустой текст кнопки.</span>
                    <button type="button" className="ac-v3-builder__notice-action" onClick={() => openValidationIssueBlock(validationIssue)}>
                        Открыть блок
                    </button>
                </Notice>
            ) : null}

            {pendingConnection ? (
                <Notice kind="connection" onClose={cancelConnection}>
                    {connectionNotice(pendingConnection)}
                </Notice>
            ) : null}

            <div className={`ac-v3-builder__workbench ${isPanelOpen ? 'is-panel-open' : ''}`}>
                <ToolRail tool={tool} onTool={setTool} onAddBlock={addBlock} />

                {mode === 'logs' ? (
                    <ScenarioLogs
                        transitions={scheduledTransitions}
                        edges={edges}
                        statusFilter={logStatusFilter}
                        onStatusFilter={setLogStatusFilter}
                        onRefresh={refreshBuilderDiagnostics}
                        onOpenEdge={openTransitionEdge}
                        timezone={serverTimezone}
                        timezoneLabel={serverTimezoneLabel}
                    />
                ) : (
                    <main
                        ref={canvasRef}
                        className="ac-v3-builder__canvas"
                        onPointerDown={startCanvasPan}
                        onWheel={handleWheel}
                        style={canvasGridStyle(view)}
                    >
                        <div
                            className="ac-v3-builder__world"
                            style={{
                                width: `${canvasBounds.width}px`,
                                height: `${canvasBounds.height}px`,
                                transform: `translate(${view.tx}px, ${view.ty}px) scale(${view.zoom})`,
                            }}
                        >
                            <svg className="ac-v3-builder__edges" width={canvasBounds.width} height={canvasBounds.height}>
                                <defs>
                                    <marker id="ac-v3-arrow-wait" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                        <path d="M 0 0 L 10 5 L 0 10 z" />
                                    </marker>
                                    <marker id="ac-v3-arrow-button" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                        <path d="M 0 0 L 10 5 L 0 10 z" />
                                    </marker>
                                    <marker id="ac-v3-arrow-auto" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                        <path d="M 0 0 L 10 5 L 0 10 z" />
                                    </marker>
                                    <marker id="ac-v3-arrow-selected" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                        <path d="M 0 0 L 10 5 L 0 10 z" />
                                    </marker>
                                </defs>
                                {edges.map((edge) => (
                                    <EdgePath
                                        key={edge.client_key}
                                        edge={edge}
                                        blocks={blocks}
                                        anchors={anchors}
                                        selected={edge.client_key === selectedEdgeKey}
                                        onSelect={() => {
                                            setSelectedEdgeKey(edge.client_key);
                                            setSelectedBlockKey(null);
                                            setIsPanelCollapsed(false);
                                            setPendingConnection(null);
                                        }}
                                    />
                                ))}
                            </svg>

                            {blocks.length === 0 ? (
                                <div className="ac-v3-builder__empty">
                                    <strong>Пустой лист</strong>
                                    <span>Создайте первый блок через панель слева.</span>
                                </div>
                            ) : blocks.map((block) => (
                                <ScenarioNode
                                    key={block.client_key}
                                    block={block}
                                    selected={block.client_key === selectedBlockKey}
                                    pendingTarget={pendingConnection !== null && block.client_key !== pendingConnection.sourceKey}
                                    connectedOutputIds={connectedOutputIds(block, edges)}
                                    onSelect={() => completeConnection(block)}
                                    onDragStart={(event) => startBlockDrag(event, block.client_key)}
                                    onStartConnection={startConnection}
                                    onStartDefaultConnection={() => startPanelConnection(block, DEFAULT_OUTPUT)}
                                />
                            ))}
                        </div>

                        <div className="ac-v3-builder__canvas-meta">
                            <span>{blocks.length} блоков</span>
                            <span>{edges.length} связей</span>
                        </div>

                        <div className="ac-v3-builder__zoom">
                            <button type="button" title="Приблизить" onClick={() => setZoom(view.zoom * 1.15)}>+</button>
                            <span>{Math.round(view.zoom * 100)}%</span>
                            <button type="button" title="Отдалить" onClick={() => setZoom(view.zoom / 1.15)}>−</button>
                            <button type="button" title="По размеру" onClick={fitCanvas}>⊡</button>
                        </div>
                    </main>
                )}

                {isPanelOpen ? (
                    <aside className="ac-v3-builder__panel">
                        {selectedEdge ? (
                            <EdgePanel
                                edge={selectedEdge}
                                blocks={blocks}
                                onCollapse={collapsePanel}
                                onClose={closePanelSelection}
                                onRemove={() => removeEdge(selectedEdge.client_key)}
                                onUpdateConditionPayload={(nextPayload) => updateEdgeConditionPayload(selectedEdge.client_key, nextPayload)}
                                onCopyEdgeId={copyEdgeId}
                                onRefreshDiagnostics={refreshBuilderDiagnostics}
                                timezone={serverTimezone}
                                timezoneLabel={serverTimezoneLabel}
                            />
                        ) : (
                            <BlockPanel
                                block={selectedBlock}
                                channels={channels}
                                blocks={blocks}
                                onCollapse={collapsePanel}
                                onClose={closePanelSelection}
                                onSelectBlock={selectBlock}
                                onUpdateBlock={updateBlock}
                                onUpdateModulePayload={updateModulePayload}
                                onToggleModule={toggleModule}
                                onAddButton={addButton}
                                onUpdateButton={updateButton}
                                onReorderButtons={reorderButtons}
                                onRemoveButton={removeButton}
                                onUpdateStartChannels={updateStartChannels}
                                validationIssue={validationIssue}
                                pendingButtonFocus={pendingButtonFocus}
                                onButtonFocused={clearPendingButtonFocus}
                                onCopyBlockId={copyBlockId}
                            />
                        )}
                    </aside>
                ) : null}

                {mode === 'design' && hasPanelSelection && isPanelCollapsed ? (
                    <button
                        type="button"
                        className="ac-v3-builder__panel-reopen"
                        title="Развернуть панель"
                        onClick={expandPanel}
                    >
                        ‹
                    </button>
                ) : null}
            </div>
        </section>
    );
}

function Notice({ kind, children, onClose }) {
    return (
        <div className="ac-v3-builder__notice" data-kind={kind}>
            <div className="ac-v3-builder__notice-body">{children}</div>
            <button type="button" onClick={onClose}>Закрыть</button>
        </div>
    );
}

function ScenarioLogs({ transitions, edges, statusFilter, onStatusFilter, onRefresh, onOpenEdge, timezone, timezoneLabel }) {
    const items = Array.isArray(transitions) ? transitions : [];
    const filteredItems = items.filter((transition) => logTransitionMatchesFilter(transition, statusFilter));
    const counts = logTransitionCounts(items);

    return (
        <main className="ac-v3-builder__logs">
            <div className="ac-v3-builder__logs-head">
                <div>
                    <span>Логи сценария</span>
                    <strong>Отложенные переходы</strong>
                </div>
                <button type="button" onClick={onRefresh}>Обновить</button>
            </div>

            {items.length > 0 ? (
                <div className="ac-v3-builder__logs-filters" role="group" aria-label="Фильтр логов">
                    {LOG_STATUS_FILTERS.map(([value, label]) => (
                        <button
                            type="button"
                            key={value}
                            className={statusFilter === value ? 'is-active' : ''}
                            aria-pressed={statusFilter === value}
                            onClick={() => onStatusFilter(value)}
                        >
                            <span>{label}</span>
                            <b>{counts[value] ?? 0}</b>
                        </button>
                    ))}
                </div>
            ) : null}

            {filteredItems.length > 0 ? (
                <div className="ac-v3-builder__logs-list">
                    {filteredItems.map((transition) => (
                        <ScenarioLogRow
                            key={transition.id}
                            transition={transition}
                            edge={findEdgeByTransition(edges, transition)}
                            onOpenEdge={onOpenEdge}
                            timezone={timezone}
                            timezoneLabel={timezoneLabel}
                        />
                    ))}
                </div>
            ) : (
                <div className="ac-v3-builder__logs-empty">
                    <strong>{items.length > 0 ? 'Под этот фильтр записей нет' : 'Пока нет delayed-переходов'}</strong>
                    <span>
                        {items.length > 0
                            ? 'Выберите другой статус или обновите логи.'
                            : 'Когда automatic-стрелка с задержкой сработает в опубликованном сценарии, запись появится здесь.'}
                    </span>
                </div>
            )}
        </main>
    );
}

function ScenarioLogRow({ transition, edge, onOpenEdge, timezone, timezoneLabel }) {
    return (
        <article className={`ac-v3-builder__log-row is-${transition.status ?? 'unknown'}`}>
            <div className="ac-v3-builder__log-main">
                <strong>{transition.status_label ?? edgeTransitionStatusLabel(transition.status)}</strong>
                <span>Переход #{transition.id} · v{transition.published_version_id} · run #{transition.scenario_run_id}</span>
            </div>
            <div className="ac-v3-builder__log-route">
                <span>Блок #{transition.source_block_id}</span>
                <b>→</b>
                <span>Блок #{transition.target_block_id}</span>
            </div>
            <div className="ac-v3-builder__log-time">
                <span>План: {formatDateTime(transition.scheduled_for, timezoneLabel, timezone)}</span>
                <span>Финиш: {formatDateTime(transition.finished_at, timezoneLabel, timezone)}</span>
            </div>
            <div className="ac-v3-builder__log-actions">
                <a href={`/admin/dialogs/${transition.dialog_id}`}>Диалог #{transition.dialog_id}</a>
                <button type="button" disabled={! edge} onClick={() => onOpenEdge(transition)}>
                    Открыть связь
                </button>
            </div>
            {transition.error_message ? (
                <p>{transition.error_message}</p>
            ) : null}
        </article>
    );
}

function connectionNotice(connection) {
    if (connection?.kind === 'default' || connection?.outputId === null) {
        return 'Теперь кликните по блоку, куда должен вести автопереход «Дальше».';
    }

    return `Теперь кликните по блоку, куда должна вести кнопка «${connection.label}».`;
}

function firstEmptyButtonIssue(blocks) {
    for (const block of blocks) {
        const buttons = findModule(block.settings_payload, 'buttons');
        const emptyButton = flatButtons(buttons).find((button) => ! String(button.text ?? '').trim());

        if (emptyButton) {
            return {
                blockKey: block.client_key,
                blockTitle: block.title || 'Блок',
                buttonId: emptyButton.id,
            };
        }
    }

    return null;
}

function ToolRail({ tool, onTool, onAddBlock }) {
    return (
        <aside className="ac-v3-builder__toolrail" aria-label="Инструменты конструктора">
            <button type="button" className={tool === 'select' ? 'is-active' : ''} title="Выбор" onClick={() => onTool('select')}>
                <CursorIcon />
            </button>
            <button type="button" className={tool === 'pan' ? 'is-active' : ''} title="Перемещение холста" onClick={() => onTool('pan')}>
                <PanIcon />
            </button>
            <hr />
            <button type="button" title="Создать стартовый блок" onClick={() => onAddBlock('start')}>
                <TriggerIcon />
            </button>
            <button type="button" title="Создать сообщение" onClick={() => onAddBlock('message')}>
                <MessageIcon />
            </button>
            <button type="button" title="Создать блок с кнопками" onClick={() => onAddBlock('buttons')}>
                <ButtonIcon />
            </button>
        </aside>
    );
}

function ScenarioNode({
    block,
    selected,
    pendingTarget,
    connectedOutputIds,
    onSelect,
    onDragStart,
    onStartConnection,
    onStartDefaultConnection,
}) {
    const outputs = blockOutputs(block);
    const start = findModule(block.settings_payload, 'start_condition');
    const message = findModule(block.settings_payload, 'message');
    const buttons = findModule(block.settings_payload, 'buttons');
    const position = blockPosition(block);
    const modules = modulesFrom(block.settings_payload);
    const blockKind = block.settings_payload?.kind === 'non_state' ? 'non_state' : 'state';

    return (
        <article
            data-node
            data-node-key={block.client_key}
            className={[
                'ac-v3-builder__node',
                selected ? 'is-selected' : '',
                pendingTarget ? 'is-targetable' : '',
                start ? 'has-start' : '',
                blockKind === 'non_state' ? 'is-non-state' : 'is-state',
            ].filter(Boolean).join(' ')}
            style={{ left: `${position.x}px`, top: `${position.y}px` }}
            onPointerDown={(event) => event.stopPropagation()}
            onClick={onSelect}
        >
            {selected ? (
                <div className="ac-v3-builder__node-toolbar" onPointerDown={(event) => event.stopPropagation()}>
                    <button
                        type="button"
                        title="Связать блок с другим блоком"
                        onClick={(event) => {
                            event.stopPropagation();
                            onStartDefaultConnection();
                        }}
                    >
                        <LinkIcon />
                        <span>Связать</span>
                    </button>
                </div>
            ) : null}

            <header onPointerDown={onDragStart}>
                <div className="ac-v3-builder__node-icon">{start ? <TriggerIcon /> : (blockKind === 'non_state' ? <BlockTypeIcon type="non_state" /> : <MessageIcon />)}</div>
                <div>
                    <span>{nodeTypeLabel(Boolean(start), blockKind)}</span>
                    <strong>{block.title}</strong>
                </div>
                <button type="button" title="Действия" onPointerDown={(event) => event.stopPropagation()} onClick={(event) => event.stopPropagation()}>
                    <MoreIcon />
                </button>
            </header>

            <div className="ac-v3-builder__module-strip">
                {MODULE_ORDER.map((type) => (
                    modules.some((module) => module.type === type)
                        ? <b key={type} className={MODULE_META[type].className}>{MODULE_META[type].short}</b>
                        : null
                ))}
            </div>

            <div className="ac-v3-builder__node-body">
                {start ? (
                    <ModulePreview type="start_condition" label="Старт" value={start.payload?.command || 'Любое сообщение'} />
                ) : null}
                {message ? (
                    <ModulePreview type="message" label="Сообщение" value={message.payload?.text || 'Пустое сообщение'} />
                ) : null}
                {buttons ? (
                    <ModulePreview type="buttons" label="Кнопки" value={`${flatButtons(buttons).length} шт.`} />
                ) : null}
            </div>

            <div className="ac-v3-builder__ports">
                {outputs.map((output) => (
                    <button
                        key={output.id ?? 'default'}
                        type="button"
                        className={[
                            connectedOutputIds.has(output.id ?? 'default') ? 'is-connected' : '',
                            output.kind === 'default' ? 'is-default-output' : 'is-button-output',
                        ].filter(Boolean).join(' ')}
                        title={output.kind === 'default' ? 'Связать автопереход с блоком' : 'Связать кнопку с блоком'}
                        onPointerDown={(event) => onStartConnection(event, block, output)}
                        onClick={(event) => event.stopPropagation()}
                    >
                        <span>
                            <strong>{output.label}</strong>
                            {output.caption ? <em>{output.caption}</em> : null}
                        </span>
                        <i data-port-key={portAnchorKey(block.client_key, output.id)} />
                    </button>
                ))}
            </div>
        </article>
    );
}

function ModulePreview({ type, label, value }) {
    return (
        <div className={`ac-v3-builder__module-preview ${MODULE_META[type]?.className ?? ''}`}>
            <span>{label}</span>
            <strong>{truncate(value, 78)}</strong>
        </div>
    );
}

function EdgePath({ edge, blocks, anchors, selected, onSelect }) {
    const sourceBlock = blocks.find((block) => block.client_key === edge.source?.client_key);
    const targetBlock = blocks.find((block) => block.client_key === edge.target?.client_key);

    if (! sourceBlock || ! targetBlock) {
        return null;
    }

    const source = edgeSourceAnchor(edge, sourceBlock, targetBlock, anchors);
    const target = edgeTargetAnchor(targetBlock, source, anchors);
    const d = edgeCurvePath(source, target);
    const labelX = (source.x + target.x) / 2;
    const labelY = (source.y + target.y) / 2 - 8;
    const isButton = isButtonEdge(edge);
    const visualKind = edgeVisualKind(edge);
    const edgeClassName = [
        selected ? 'is-selected' : '',
        `is-${visualKind}-edge`,
    ].filter(Boolean).join(' ');
    const markerId = selected ? 'ac-v3-arrow-selected' : `ac-v3-arrow-${visualKind}`;
    const label = edgeLabel(edge, isButton);
    const title = edgeVisualTitle(visualKind);

    return (
        <g className={edgeClassName}>
            <title>{title}: {label}</title>
            <path data-edge-action d={d} className="ac-v3-builder__edge-hit" onClick={onSelect} />
            {selected ? <path d={d} className="ac-v3-builder__edge-selection" /> : null}
            <path d={d} className="ac-v3-builder__edge" markerEnd={`url(#${markerId})`} />
            {! isButton ? (
                <circle
                    cx={source.x}
                    cy={source.y}
                    r={selected ? 5 : 4}
                    className="ac-v3-builder__edge-source-dot"
                />
            ) : null}
            {label ? (
                <text x={labelX} y={labelY} className="ac-v3-builder__edge-label">{label}</text>
            ) : null}
        </g>
    );
}

function edgeSourceAnchor(edge, sourceBlock, targetBlock, anchors) {
    const outputId = edge.source?.output_id ?? null;

    if (outputId === null) {
        return nearestBlockSideAnchor(sourceBlock, blockCenter(targetBlock, anchors), anchors);
    }

    const portAnchor = anchors.ports[portAnchorKey(sourceBlock.client_key, outputId)];

    if (portAnchor) {
        return { ...portAnchor, side: 'right' };
    }

    return outputAnchor(sourceBlock, outputId);
}

function edgeTargetAnchor(targetBlock, source, anchors) {
    return shiftAnchorOutside(nearestBlockSideAnchor(targetBlock, source, anchors), EDGE_TARGET_CLEARANCE);
}

function edgeCurvePath(source, target) {
    const sourceVector = sideVector(source.side);
    const targetVector = sideVector(target.side);
    const distance = Math.hypot(target.x - source.x, target.y - source.y);
    const curve = clamp(distance * 0.38, 56, 190);
    const c1 = {
        x: source.x + (sourceVector.x * curve),
        y: source.y + (sourceVector.y * curve),
    };
    const c2 = {
        x: target.x + (targetVector.x * curve),
        y: target.y + (targetVector.y * curve),
    };

    return `M ${source.x} ${source.y} C ${c1.x} ${c1.y}, ${c2.x} ${c2.y}, ${target.x} ${target.y}`;
}

function sideVector(side) {
    if (side === 'left') {
        return { x: -1, y: 0 };
    }

    if (side === 'top') {
        return { x: 0, y: -1 };
    }

    if (side === 'bottom') {
        return { x: 0, y: 1 };
    }

    return { x: 1, y: 0 };
}

function shiftAnchorOutside(anchor, distance) {
    const vector = sideVector(anchor.side);

    return {
        ...anchor,
        x: anchor.x + (vector.x * distance),
        y: anchor.y + (vector.y * distance),
    };
}

function isButtonEdge(edge) {
    return Boolean(edge?.source?.output_id ?? edge?.condition_payload?.from_output_id);
}

function isDefaultEdge(edge) {
    return ! isButtonEdge(edge);
}

function edgeVisualKind(edge) {
    if (isButtonEdge(edge)) {
        return 'button';
    }

    return edge?.condition_payload?.mode === 'automatic' ? 'auto' : 'wait';
}

function edgeVisualTitle(kind) {
    if (kind === 'button') {
        return 'Связь от кнопки';
    }

    if (kind === 'auto') {
        return 'Автоматическая связь';
    }

    return 'Связь ждёт ответ клиента';
}

function defaultEdgeForBlock(block, edges) {
    return edges.find((edge) => edge.source?.client_key === block.client_key && isDefaultEdge(edge)) ?? null;
}

function edgeLabel(edge, isButton = isButtonEdge(edge)) {
    const label = String(edge?.condition_payload?.label ?? '').trim();

    if (isButton) {
        return label;
    }

    return label || 'Дальше';
}

function shortBlockId(block) {
    const displayId = blockDisplayId(block);

    if (displayId) {
        return `#${displayId}`;
    }

    return `#${String(block?.client_key ?? 'draft').replace(/^tmp_block_/, '').slice(0, 8)}`;
}

function copyableBlockId(block) {
    const displayId = blockDisplayId(block);

    if (displayId) {
        return displayId;
    }

    return String(block?.client_key ?? '').replace(/^tmp_block_/, '').slice(0, 8);
}

function shortEdgeId(edge) {
    const displayId = copyableEdgeId(edge);

    return displayId ? `#${displayId}` : '#draft';
}

function copyableEdgeId(edge) {
    const displayId = String(edge?.condition_payload?.ui?.edge_id ?? '').trim();

    if (displayId) {
        return displayId;
    }

    if (edge?.id) {
        return String(edge.id);
    }

    return String(edge?.client_key ?? '').replace(/^tmp_edge_/, '').replace(/^edge_/, '').slice(0, 8);
}

function edgeScheduledTransitions(edge) {
    const transitions = edge?.diagnostics?.scheduled_transitions;

    return Array.isArray(transitions) ? transitions : [];
}

function findEdgeByTransition(edges, transition) {
    const edgeKey = String(transition?.edge_key ?? '').trim();
    const edgeId = String(transition?.edge_id ?? '').trim();

    return (Array.isArray(edges) ? edges : []).find((edge) => {
        const candidateKey = String(edge?.condition_payload?.edge_key ?? '').trim();
        const candidateId = String(edge?.id ?? '').trim();

        return (edgeKey !== '' && candidateKey === edgeKey)
            || (edgeId !== '' && candidateId === edgeId);
    }) ?? null;
}

function edgeTransitionStatusLabel(status) {
    const labels = {
        scheduled: 'Запланирован',
        processing: 'Выполняется',
        passed: 'Выполнен',
        cancelled: 'Отменён',
        failed: 'Ошибка',
        limit_reached: 'Лимит достигнут',
    };

    return labels[status] ?? 'Неизвестно';
}

function logTransitionMatchesFilter(transition, filter) {
    const status = transition?.status;

    if (filter === 'active') {
        return LOG_ACTIVE_STATUSES.includes(status);
    }

    if (filter === 'passed') {
        return status === 'passed';
    }

    if (filter === 'attention') {
        return LOG_ATTENTION_STATUSES.includes(status);
    }

    return true;
}

function logTransitionCounts(items) {
    return (Array.isArray(items) ? items : []).reduce((counts, transition) => {
        counts.all += 1;

        if (LOG_ACTIVE_STATUSES.includes(transition?.status)) {
            counts.active += 1;
        }

        if (transition?.status === 'passed') {
            counts.passed += 1;
        }

        if (LOG_ATTENTION_STATUSES.includes(transition?.status)) {
            counts.attention += 1;
        }

        return counts;
    }, {
        all: 0,
        active: 0,
        passed: 0,
        attention: 0,
    });
}

function formatDateTime(value, timezoneLabel = '', timezone = '') {
    if (! value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const options = {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    };

    if (timezone) {
        options.timeZone = timezone;
    }

    let formatted = '';

    try {
        formatted = date.toLocaleString('ru-RU', options);
    } catch {
        formatted = date.toLocaleString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    return timezoneLabel ? `${formatted} ${timezoneLabel}` : formatted;
}

function blockDisplayId(block) {
    return String(block?.display_id ?? block?.settings_payload?.ui?.card_id ?? '').trim();
}

async function copyTextToClipboard(text) {
    if (window.navigator?.clipboard?.writeText) {
        try {
            await window.navigator.clipboard.writeText(text);
            return;
        } catch {
            // Some browser shells expose clipboard API but reject writes by policy.
        }
    }

    const element = document.createElement('textarea');

    element.value = text;
    element.setAttribute('readonly', '');
    element.style.position = 'fixed';
    element.style.top = '-1000px';
    element.style.left = '-1000px';

    document.body.appendChild(element);
    element.select();

    try {
        if (! document.execCommand('copy')) {
            throw new Error('Copy command was rejected.');
        }
    } finally {
        document.body.removeChild(element);
    }
}

function BlockPanel({
    block,
    channels,
    blocks,
    onCollapse,
    onClose,
    onSelectBlock,
    onUpdateBlock,
    onUpdateModulePayload,
    onToggleModule,
    onAddButton,
    onUpdateButton,
    onReorderButtons,
    onRemoveButton,
    onUpdateStartChannels,
    validationIssue,
    pendingButtonFocus,
    onButtonFocused,
    onCopyBlockId,
}) {
    const [isTypeMenuOpen, setIsTypeMenuOpen] = useState(false);

    if (! block) {
        return (
            <div className="ac-v3-builder__panel-empty">
                <span>Свойства</span>
                <strong>Выберите блок</strong>
                <p>На холсте можно выделить блок или связь. Быстрые инструменты находятся слева.</p>
                {blocks.length > 0 ? (
                    <div className="ac-v3-builder__mini-list">
                        {blocks.map((item) => (
                            <button type="button" key={item.client_key} onClick={() => onSelectBlock(item.client_key)}>
                                <span>{item.title}</span>
                                <small>{modulesFrom(item.settings_payload).map((module) => moduleLabel(module.type)).join(', ') || 'Без модулей'}</small>
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
        );
    }

    const start = findModule(block.settings_payload, 'start_condition');
    const message = findModule(block.settings_payload, 'message');
    const buttons = findModule(block.settings_payload, 'buttons');
    const startChannels = start?.payload?.channels?.ids ?? [];
    const activeModules = modulesFrom(block.settings_payload).length;
    const blockKind = block.settings_payload?.kind === 'non_state' ? 'non_state' : 'state';
    const activeModuleTypes = new Set(modulesFrom(block.settings_payload).map((module) => module.type));

    function updateBlockKind(kind) {
        onUpdateBlock(block.client_key, (current) => {
            const settings = normalizeSettings(current.settings_payload);

            return {
                settings_payload: {
                    ...settings,
                    kind,
                },
            };
        });
        setIsTypeMenuOpen(false);
    }

    return (
        <div className="ac-v3-builder__inspector">
            <div className={[
                'ac-v3-builder__panel-head',
                blockKind === 'non_state' ? 'is-kind-non-state' : 'is-kind-state',
            ].filter(Boolean).join(' ')}
            >
                <div className="ac-v3-builder__panel-title-row">
                    <div className="ac-v3-builder__panel-type-wrap">
                        <button
                            type="button"
                            className={[
                                'ac-v3-builder__panel-type-btn',
                                blockKind === 'non_state' ? 'is-kind-non-state' : 'is-kind-state',
                                isTypeMenuOpen ? 'is-open' : '',
                            ].filter(Boolean).join(' ')}
                            title="Сменить тип блока"
                            onClick={() => setIsTypeMenuOpen((value) => ! value)}
                        >
                            <BlockTypeIcon type={blockKind} />
                            <span>⌄</span>
                        </button>
                        {isTypeMenuOpen ? (
                            <div className="ac-v3-builder__panel-type-menu">
                                {Object.entries(BLOCK_TYPE_META).map(([kind, meta]) => (
                                    <button
                                        key={kind}
                                        type="button"
                                        className={[
                                            kind === blockKind ? 'is-active' : '',
                                            kind === 'non_state' ? 'is-kind-non-state' : 'is-kind-state',
                                        ].filter(Boolean).join(' ')}
                                        onClick={() => updateBlockKind(kind)}
                                    >
                                        <BlockTypeIcon type={kind} />
                                        <span>
                                            <strong>{meta.label}</strong>
                                            <small>{meta.hint}</small>
                                        </span>
                                    </button>
                                ))}
                            </div>
                        ) : null}
                    </div>
                    <input
                        className="ac-v3-builder__panel-title-input"
                        aria-label="Название блока"
                        value={block.title}
                        onChange={(event) => onUpdateBlock(block.client_key, { title: event.target.value })}
                    />
                    <button
                        type="button"
                        className="ac-v3-builder__panel-id"
                        title="Скопировать ID блока"
                        onClick={() => onCopyBlockId(block)}
                    >
                        <CopyIcon />
                        {shortBlockId(block)}
                    </button>
                    <button type="button" className="ac-v3-builder__panel-icon-btn" title="Свернуть панель" onClick={onCollapse}>
                        ›
                    </button>
                    <button type="button" className="ac-v3-builder__panel-icon-btn" title="Закрыть панель" onClick={onClose}>
                        ×
                    </button>
                </div>
            </div>

            <section className="ac-v3-builder__module-picker">
                <div className="ac-v3-builder__module-grid" aria-label="Модули блока">
                    <ModuleSwitch
                        type="start_condition"
                        checked={Boolean(start)}
                        onChange={(checked) => onToggleModule(block.client_key, 'start_condition', checked)}
                    />
                    <ModuleSwitch
                        type="message"
                        checked={Boolean(message)}
                        disabled={Boolean(buttons)}
                        onChange={(checked) => onToggleModule(block.client_key, 'message', checked)}
                    />
                    <ModuleSwitch
                        type="buttons"
                        checked={Boolean(buttons)}
                        onChange={(checked) => onToggleModule(block.client_key, 'buttons', checked)}
                    />
                    {FUTURE_MODULE_META.map((module) => (
                        <FutureModuleSlot key={module.type} type={module.type} label={module.label} />
                    ))}
                </div>
            </section>

            {activeModules > 0 ? (
                <div className="ac-v3-builder__panel-section">
                    <div className="ac-v3-builder__section-head">
                        <span>Настройка модулей</span>
                        <em>{activeModules} активн.</em>
                    </div>
                    <div className="ac-v3-builder__module-stack">
                        {MODULE_ORDER.filter((type) => activeModuleTypes.has(type)).map((type) => (
                            <ModuleConfigCard
                                key={type}
                                type={type}
                                onRemove={type === 'message' && buttons ? null : () => onToggleModule(block.client_key, type, false)}
                            >
                                {type === 'start_condition' ? (
                                    <StartConditionFields
                                        start={start}
                                        channels={channels}
                                        startChannels={startChannels}
                                        blockKey={block.client_key}
                                        onUpdateModulePayload={onUpdateModulePayload}
                                        onUpdateStartChannels={onUpdateStartChannels}
                                    />
                                ) : null}
                                {type === 'message' ? (
                                    <AutoGrowTextarea
                                        value={message?.payload?.text ?? ''}
                                        onChange={(event) => onUpdateModulePayload(block.client_key, 'message', { text: event.target.value })}
                                    />
                                ) : null}
                                {type === 'buttons' ? (
                                    <ButtonsFields
                                        buttons={buttons}
                                        blockKey={block.client_key}
                                        onAddButton={onAddButton}
                                        onUpdatePlacement={(placement) => onUpdateModulePayload(block.client_key, 'buttons', { placement })}
                                        onUpdateButton={onUpdateButton}
                                        onReorderButtons={onReorderButtons}
                                        onRemoveButton={onRemoveButton}
                                        invalidButtonId={validationIssue?.blockKey === block.client_key ? validationIssue.buttonId : null}
                                        focusButtonId={pendingButtonFocus?.blockKey === block.client_key ? pendingButtonFocus.buttonId : null}
                                        onButtonFocused={onButtonFocused}
                                    />
                                ) : null}
                            </ModuleConfigCard>
                        ))}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function ButtonsFields({
    buttons,
    blockKey,
    onAddButton,
    onUpdatePlacement,
    onUpdateButton,
    onReorderButtons,
    onRemoveButton,
    invalidButtonId,
    focusButtonId,
    onButtonFocused,
}) {
    const [editingButtonId, setEditingButtonId] = useState(null);
    const [dragState, setDragState] = useState(null);
    const [dropTarget, setDropTarget] = useState(null);
    const rows = buttonRows(buttons);
    const flat = rows.flat();
    const editingButton = flat.find((button) => button.id === editingButtonId) ?? null;
    const totalButtons = rows.reduce((sum, row) => sum + row.length, 0);
    const placement = buttonPlacement(buttons);

    useEffect(() => {
        if (! focusButtonId) {
            return;
        }

        setEditingButtonId(focusButtonId);
        onButtonFocused?.();
    }, [focusButtonId, onButtonFocused, rows]);

    useEffect(() => {
        if (editingButtonId && ! flat.some((button) => button.id === editingButtonId)) {
            setEditingButtonId(null);
        }
    }, [editingButtonId, flat]);

    function beginDrag(event, rowIndex, buttonIndex, buttonId) {
        setDragState({ rowIndex, buttonIndex, buttonId });
        setDropTarget({ rowIndex, buttonIndex });
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', buttonId);
    }

    function clearDrag() {
        setDragState(null);
        setDropTarget(null);
    }

    function markDropTarget(event, rowIndex, buttonIndex) {
        if (! dragState) {
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();
        const insertIndex = buttonIndex + (event.clientX > rect.left + rect.width / 2 ? 1 : 0);

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        setDropTarget({ rowIndex, buttonIndex: insertIndex });
    }

    function markRowEnd(event, rowIndex) {
        if (! dragState) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        setDropTarget({ rowIndex, buttonIndex: rows[rowIndex]?.length ?? 0 });
    }

    function markNewRow(event) {
        if (! dragState) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        setDropTarget({ mode: 'new-row', rowIndex: rows.length, buttonIndex: 0 });
    }

    function dropButton(event, rowIndex, buttonIndex) {
        if (! dragState) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        const rect = event.currentTarget.getBoundingClientRect();
        const insertIndex = buttonIndex + (event.clientX > rect.left + rect.width / 2 ? 1 : 0);

        onReorderButtons(blockKey, moveButtonRows(rows, dragState, { rowIndex, buttonIndex: insertIndex }));
        clearDrag();
    }

    function dropRowEnd(event, rowIndex) {
        if (! dragState) {
            return;
        }

        event.preventDefault();
        onReorderButtons(blockKey, moveButtonRows(rows, dragState, { rowIndex, buttonIndex: rows[rowIndex]?.length ?? 0 }));
        clearDrag();
    }

    function dropNewRow(event) {
        if (! dragState) {
            return;
        }

        event.preventDefault();
        onReorderButtons(blockKey, moveButtonRows(rows, dragState, { rowIndex: rows.length, buttonIndex: 0 }));
        clearDrag();
    }

    return (
        <div className="ac-v3-builder__buttons-module">
            <div className="ac-v3-builder__buttons-placement" role="group" aria-label="Размещение кнопок">
                {BUTTON_PLACEMENT_OPTIONS.map(([value, label]) => (
                    <button
                        key={value}
                        type="button"
                        className={placement === value ? 'is-active' : ''}
                        title={buttonPlacementHint(value)}
                        aria-pressed={placement === value ? 'true' : 'false'}
                        onClick={() => onUpdatePlacement(value)}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div className="ac-v3-builder__buttons-meta">
                {totalButtons > 0 ? (
                    <span><b>{totalButtons}</b> / 100 · {rows.length} {pluralRows(rows.length)}</span>
                ) : (
                    <span>Пока нет ни одной кнопки</span>
                )}
            </div>

            <div className="ac-v3-builder__buttons-editor">
                {rows.map((row, rowIndex) => (
                    <div key={row.map((button) => button.id).join('-') || rowIndex} className="ac-v3-builder__buttons-row">
                        <div
                            className={[
                                'ac-v3-builder__buttons-row-items',
                                dropTarget?.rowIndex === rowIndex && dropTarget.buttonIndex === row.length ? 'is-drop-end' : '',
                            ].filter(Boolean).join(' ')}
                            onDragOver={(event) => markRowEnd(event, rowIndex)}
                            onDrop={(event) => dropRowEnd(event, rowIndex)}
                        >
                            {row.map((button, buttonIndex) => (
                                <div
                                    key={button.id}
                                    className={[
                                        'ac-v3-builder__button-input-wrap',
                                        dragState?.buttonId === button.id ? 'is-dragging' : '',
                                        dropTarget?.rowIndex === rowIndex && dropTarget.buttonIndex === buttonIndex ? 'is-drop-before' : '',
                                        dropTarget?.rowIndex === rowIndex && dropTarget.buttonIndex === buttonIndex + 1 ? 'is-drop-after' : '',
                                    ].filter(Boolean).join(' ')}
                                    draggable
                                    onDragStart={(event) => beginDrag(event, rowIndex, buttonIndex, button.id)}
                                    onDragOver={(event) => {
                                        event.stopPropagation();
                                        markDropTarget(event, rowIndex, buttonIndex);
                                    }}
                                    onDrop={(event) => dropButton(event, rowIndex, buttonIndex)}
                                    onDragEnd={clearDrag}
                                >
                                    <button
                                        type="button"
                                        className={[
                                            'ac-v3-builder__button-edit-trigger',
                                            button.id === invalidButtonId ? 'is-invalid' : '',
                                        ].filter(Boolean).join(' ')}
                                        aria-invalid={button.id === invalidButtonId ? 'true' : undefined}
                                        onClick={() => setEditingButtonId(button.id)}
                                    >
                                        <span>{button.text || 'Текст кнопки'}</span>
                                        <small>{buttonTypeLabel(button.type)}</small>
                                    </button>
                                    <button
                                        type="button"
                                        className="ac-v3-builder__button-delete"
                                        title="Удалить кнопку"
                                        onClick={() => onRemoveButton(blockKey, button.id)}
                                    >
                                        ×
                                    </button>
                                </div>
                            ))}
                        </div>
                        <button
                            type="button"
                            className="ac-v3-builder__button-row-add"
                            title="Добавить кнопку в этот ряд"
                            onClick={() => onAddButton(blockKey, rowIndex)}
                        >
                            +
                        </button>
                    </div>
                ))}

                {dragState ? (
                    <div
                        className={[
                            'ac-v3-builder__buttons-new-row-drop',
                            dropTarget?.mode === 'new-row' ? 'is-active' : '',
                        ].filter(Boolean).join(' ')}
                        onDragOver={markNewRow}
                        onDrop={dropNewRow}
                    >
                        Новый ряд
                    </div>
                ) : null}
            </div>

            <button
                type="button"
                className={[
                    'ac-v3-builder__add-row-btn',
                    dropTarget?.mode === 'new-row' ? 'is-drop-target' : '',
                ].filter(Boolean).join(' ')}
                onDragOver={markNewRow}
                onDrop={dropNewRow}
                onClick={() => onAddButton(blockKey)}
            >
                Добавить кнопку
            </button>

            {editingButton ? (
                <ButtonEditDialog
                    button={editingButton}
                    onClose={() => setEditingButtonId(null)}
                    onSave={(patch) => {
                        onUpdateButton(blockKey, editingButton.id, patch);
                        setEditingButtonId(null);
                    }}
                />
            ) : null}
        </div>
    );
}

function ButtonEditDialog({ button, onClose, onSave }) {
    const inputRef = useRef(null);
    const [text, setText] = useState(button.text ?? '');
    const [type, setType] = useState(button.type === BUTTON_TYPE_REQUEST_PHONE ? BUTTON_TYPE_REQUEST_PHONE : BUTTON_TYPE_TEXT);
    const [color, setColor] = useState(button.color ?? null);

    useEffect(() => {
        const input = inputRef.current;

        function focusAndSelectInput() {
            if (! inputRef.current) {
                return;
            }

            const input = inputRef.current;
            input.focus();
            input.select();
        }

        focusAndSelectInput();
        const focusTimer = window.setTimeout(focusAndSelectInput, 0);

        function handleKeyDown(event) {
            if (event.key === 'Escape') {
                onClose();
            }
        }

        document.addEventListener('keydown', handleKeyDown);

        return () => {
            window.clearTimeout(focusTimer);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [onClose]);

    return (
        <div className="ac-v3-builder__dialog-backdrop" role="presentation" onMouseDown={onClose}>
            <section
                className="ac-v3-builder__button-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="button-dialog-title"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <div className="ac-v3-builder__button-dialog-head">
                    <h2 id="button-dialog-title">Редактировать кнопку</h2>
                    <button type="button" title="Закрыть" onClick={onClose}>×</button>
                </div>

                <label className="ac-v3-builder__button-dialog-field">
                    <span>Текст</span>
                    <div className="ac-v3-builder__button-dialog-input-wrap">
                        <input
                            ref={inputRef}
                            value={text}
                            maxLength={100}
                            placeholder="Текст кнопки"
                            onChange={(event) => setText(event.target.value)}
                        />
                        <small>{text.length}</small>
                    </div>
                </label>

                <label className="ac-v3-builder__button-dialog-field">
                    <span>Функция</span>
                    <select value={type} onChange={(event) => setType(event.target.value)}>
                        {BUTTON_TYPE_OPTIONS.map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                </label>

                <div className="ac-v3-builder__button-dialog-field">
                    <span>Цвет кнопки</span>
                    <div className="ac-v3-builder__button-color-grid" role="radiogroup" aria-label="Цвет кнопки">
                        {BUTTON_COLOR_OPTIONS.map(([value, label, swatch]) => (
                            <button
                                key={value ?? 'none'}
                                type="button"
                                className={color === value ? 'is-active' : ''}
                                aria-pressed={color === value ? 'true' : 'false'}
                                onClick={() => setColor(value)}
                            >
                                {swatch ? <i style={{ backgroundColor: swatch }} /> : <b>⊘</b>}
                                <span>{label}</span>
                            </button>
                        ))}
                    </div>
                </div>

                <div className="ac-v3-builder__button-dialog-footer">
                    <button
                        type="button"
                        className="ac-v3-builder__primary-btn"
                        onClick={() => onSave({ text, type, color })}
                    >
                        Сохранить
                    </button>
                </div>
            </section>
        </div>
    );
}

function buttonTypeLabel(type) {
    return BUTTON_TYPE_OPTIONS.find(([value]) => value === type)?.[1] ?? 'Текстовая';
}

function buttonPlacement(buttonsModule) {
    const placement = buttonsModule?.payload?.placement;

    return BUTTON_PLACEMENT_OPTIONS.some(([value]) => value === placement) ? placement : BUTTON_PLACEMENT_AUTO;
}

function buttonPlacementHint(placement) {
    if (placement === BUTTON_PLACEMENT_REPLY) {
        return 'Telegram покажет клавиатуру; MAX может скрыть кнопки.';
    }

    if (placement === BUTTON_PLACEMENT_INLINE) {
        return 'MAX покажет кнопки в сообщении; Telegram не покажет запрос телефона.';
    }

    return 'Telegram выберет клавиатуру, MAX — кнопки в сообщении.';
}

function pluralRows(count) {
    if (count === 1) {
        return 'ряд';
    }

    if (count >= 2 && count <= 4) {
        return 'ряда';
    }

    return 'рядов';
}

function StartConditionFields({
    start,
    channels,
    startChannels,
    blockKey,
    onUpdateModulePayload,
    onUpdateStartChannels,
}) {
    const selectedMatch = startMatchForUi(start?.payload?.match);
    const usesCommandValue = selectedMatch !== 'any_inbound';

    return (
        <>
            <label>
                Совпадение
                <select
                    value={selectedMatch}
                    onChange={(event) => onUpdateModulePayload(blockKey, 'start_condition', { match: event.target.value })}
                >
                    {MATCH_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
            </label>
            {usesCommandValue ? (
                <label>
                    Фраза или команда
                    <AutoGrowTextarea
                        value={start?.payload?.command ?? ''}
                        placeholder="/start"
                        maxHeight={130}
                        onChange={(event) => onUpdateModulePayload(blockKey, 'start_condition', { command: event.target.value })}
                    />
                </label>
            ) : null}
            <label>
                Условие по телефону
                <select
                    value={start?.payload?.contact_phone_condition ?? ''}
                    onChange={(event) => onUpdateModulePayload(blockKey, 'start_condition', { contact_phone_condition: event.target.value })}
                >
                    {PHONE_CONDITION_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
            </label>
            <label>
                Приоритет
                <input
                    type="number"
                    value={start?.payload?.priority ?? 10}
                    onChange={(event) => onUpdateModulePayload(blockKey, 'start_condition', { priority: Number(event.target.value) })}
                />
            </label>
            <div className="ac-v3-builder__channels">
                <div>
                    <button
                        type="button"
                        onClick={() => onUpdateModulePayload(blockKey, 'start_condition', {
                            channels: { mode: 'selected', ids: channels.map((channel) => channel.id) },
                        })}
                    >
                        Все
                    </button>
                    <button
                        type="button"
                        onClick={() => onUpdateModulePayload(blockKey, 'start_condition', {
                            channels: { mode: 'selected', ids: [] },
                        })}
                    >
                        Снять
                    </button>
                </div>
                {channels.map((channel) => (
                    <label key={channel.id} className="ac-v3-builder__check">
                        <input
                            type="checkbox"
                            checked={startChannels.map(Number).includes(Number(channel.id))}
                            onChange={(event) => onUpdateStartChannels(blockKey, channel.id, event.target.checked)}
                        />
                        <span>{channel.name}</span>
                    </label>
                ))}
            </div>
        </>
    );
}

function ModuleConfigCard({ type, children, onRemove }) {
    const [collapsed, setCollapsed] = useState(false);
    const meta = MODULE_META[type];

    return (
        <section className={`ac-v3-builder__module-card ${meta.className} ${collapsed ? 'is-collapsed' : ''}`}>
            <div className="ac-v3-builder__module-card-head">
                <span className="ac-v3-builder__module-card-icon">
                    <ModuleIcon type={type} />
                </span>
                <span className="ac-v3-builder__module-card-title">{meta.label}</span>
                <button
                    type="button"
                    className="ac-v3-builder__module-fold"
                    title={collapsed ? 'Развернуть модуль' : 'Свернуть модуль'}
                    onClick={() => setCollapsed((value) => ! value)}
                >
                    <ChevronIcon collapsed={collapsed} />
                </button>
                {onRemove ? (
                    <button
                        type="button"
                        className="ac-v3-builder__module-remove"
                        title="Удалить модуль"
                        onClick={onRemove}
                    >
                        ×
                    </button>
                ) : null}
            </div>
            {! collapsed ? (
                <div className="ac-v3-builder__module-card-body">
                    {children}
                </div>
            ) : null}
        </section>
    );
}

function ChevronIcon({ collapsed }) {
    return (
        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" style={{ transform: collapsed ? 'rotate(-90deg)' : 'none' }}>
            <path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function AutoGrowTextarea({ value, maxHeight = 180, className = '', ...props }) {
    const ref = useRef(null);

    useLayoutEffect(() => {
        const element = ref.current;

        if (! element) {
            return;
        }

        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight, maxHeight)}px`;
        element.style.overflowY = element.scrollHeight > maxHeight ? 'auto' : 'hidden';
    }, [value, maxHeight]);

    return (
        <textarea
            {...props}
            ref={ref}
            rows={1}
            value={value}
            className={['ac-v3-builder__textarea-auto', className].filter(Boolean).join(' ')}
        />
    );
}

function ModuleSwitch({ type, checked, disabled, onChange }) {
    const meta = MODULE_META[type];

    return (
        <label
            className={`ac-v3-builder__module-switch ${meta.className} ${checked ? 'is-on' : ''} ${disabled ? 'is-disabled' : ''}`}
            aria-label={meta.label}
            data-tooltip={meta.label}
        >
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
            />
            <ModuleIcon type={type} />
            <span>{meta.label}</span>
        </label>
    );
}

function FutureModuleSlot({ type, label }) {
    return (
        <button
            type="button"
            className="ac-v3-builder__module-switch is-placeholder"
            aria-label={`${label} появится позже`}
            data-tooltip={`${label} появится позже`}
            disabled
        >
            <ModuleIcon type={type} />
            <span>{label}</span>
        </button>
    );
}

function ModuleIcon({ type }) {
    if (type === 'start_condition') {
        return <TriggerIcon />;
    }

    if (type === 'message') {
        return <MessageIcon />;
    }

    if (type === 'buttons') {
        return <ButtonIcon />;
    }

    if (type === 'attachment') {
        return <AttachmentIcon />;
    }

    if (type === 'ai') {
        return <SparkleIcon />;
    }

    if (type === 'bot') {
        return <BotIcon />;
    }

    if (type === 'code') {
        return <CodeIcon />;
    }

    if (type === 'cloud') {
        return <CloudIcon />;
    }

    return <AnalyticsIcon />;
}

function edgeDataTypeLabel(value) {
    return EDGE_DATA_TYPE_OPTIONS.find(([optionValue]) => optionValue === value)?.[1] ?? value;
}

function contactCaptureField(fieldKey) {
    return EDGE_CONTACT_FIELD_OPTIONS.find(([value]) => value === fieldKey) ?? EDGE_CONTACT_FIELD_OPTIONS[0];
}

function EdgePanel({ edge, blocks, onCollapse, onClose, onRemove, onUpdateConditionPayload, onCopyEdgeId, onRefreshDiagnostics, timezone, timezoneLabel }) {
    const source = blocks.find((block) => block.client_key === edge.source?.client_key);
    const target = blocks.find((block) => block.client_key === edge.target?.client_key);
    const isButton = isButtonEdge(edge);
    const payload = edge.condition_payload ?? {};
    const edgeMode = isButton ? 'button' : (payload.mode === 'automatic' ? 'automatic' : 'wait_reply');
    const match = payload.match ?? {};
    const capture = payload.input_capture ?? {};
    const matchType = match.type ?? 'any_inbound';
    const captureEnabled = capture.enabled === true;
    const captureScope = capture.field_scope === 'contact' ? 'contact' : 'dialog';
    const selectedContactField = contactCaptureField(capture.field_key);
    const delay = normalizedEdgeDelay(payload.delay);
    const scheduledTransitions = edgeScheduledTransitions(edge);

    function updatePayload(patch) {
        onUpdateConditionPayload((current) => ({
            ...current,
            ...patch,
        }));
    }

    function updateMatch(patch) {
        onUpdateConditionPayload((current) => ({
            ...current,
            match: {
                ...(current.match ?? {}),
                ...patch,
            },
        }));
    }

    function updateCapture(patch) {
        onUpdateConditionPayload((current) => ({
            ...current,
            input_capture: {
                ...(current.input_capture ?? {}),
                ...patch,
            },
        }));
    }

    function updateDelay(patch) {
        onUpdateConditionPayload((current) => ({
            ...current,
            delay: normalizedEdgeDelay({
                ...normalizedEdgeDelay(current.delay),
                ...patch,
            }),
        }));
    }

    return (
        <div className="ac-v3-builder__inspector">
            <div className="ac-v3-builder__panel-head">
                <div className="ac-v3-builder__panel-title-row">
                    <span className="ac-v3-builder__panel-type-icon" title="Связь">
                        <LinkIcon />
                    </span>
                    <div className="ac-v3-builder__panel-title-field">
                        <span>Свойства связи</span>
                        <strong className="ac-v3-builder__panel-title-static">
                            {source?.title ?? 'Источник'} → {target?.title ?? 'Цель'}
                        </strong>
                    </div>
                    <button
                        type="button"
                        className="ac-v3-builder__panel-id"
                        title="Скопировать ID связи"
                        onClick={() => onCopyEdgeId(edge)}
                    >
                        <CopyIcon />
                        {shortEdgeId(edge)}
                    </button>
                    <button type="button" className="ac-v3-builder__panel-icon-btn" title="Свернуть панель" onClick={onCollapse}>
                        ›
                    </button>
                    <button type="button" className="ac-v3-builder__panel-icon-btn" title="Закрыть панель" onClick={onClose}>
                        ×
                    </button>
                </div>
            </div>
            <section className="ac-v3-builder__module-section">
                <span>Режим</span>
                {isButton ? (
                    <p className="ac-v3-builder__readonly">Переход по кнопке</p>
                ) : (
                    <div className="ac-v3-builder__edge-mode" role="group" aria-label="Режим связи">
                        <button
                            type="button"
                            className={edgeMode === 'wait_reply' ? 'is-active' : ''}
                            onClick={() => updatePayload({ mode: 'wait_reply' })}
                        >
                            Ждёт ответ
                        </button>
                        <button
                            type="button"
                            className={edgeMode === 'automatic' ? 'is-active' : ''}
                            onClick={() => updatePayload({
                                mode: 'automatic',
                                delay,
                                input_capture: {
                                    enabled: false,
                                    field_scope: 'dialog',
                                    field_key: '',
                                    data_type: 'any_text',
                                },
                            })}
                        >
                            Автоматически
                        </button>
                    </div>
                )}
                {isButton ? (
                    <>
                        <span>Кнопка</span>
                        <p className="ac-v3-builder__readonly">{edgeLabel(edge, isButton)}</p>
                        <label className="ac-v3-builder__field-row">
                            <span>Приоритет</span>
                            <input
                                type="number"
                                value={payload.priority ?? 10}
                                onChange={(event) => updatePayload({ priority: Number(event.target.value) })}
                            />
                        </label>
                    </>
                ) : edgeMode === 'wait_reply' ? (
                    <>
                        <label>
                            <span>Совпадение</span>
                            <select value={matchType} onChange={(event) => updateMatch({ type: event.target.value })}>
                                {EDGE_MATCH_OPTIONS.map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </label>
                        {matchType !== 'any_inbound' ? (
                            <label>
                                <span>Текст условия</span>
                                <textarea
                                    className="ac-v3-builder__textarea-auto"
                                    rows={1}
                                    value={match.text ?? ''}
                                    onChange={(event) => updateMatch({ text: event.target.value })}
                                />
                            </label>
                        ) : null}
                        <label className="ac-v3-builder__field-row">
                            <span>Приоритет</span>
                            <input
                                type="number"
                                value={payload.priority ?? 10}
                                onChange={(event) => updatePayload({ priority: Number(event.target.value) })}
                            />
                        </label>
                        <label className="ac-v3-builder__field-row">
                            <span>Лимит переходов</span>
                            <input
                                type="number"
                                min="0"
                                value={payload.transition_limit ?? 0}
                                onChange={(event) => updatePayload({ transition_limit: Math.max(0, Number(event.target.value)) })}
                            />
                        </label>
                        <label className="ac-v3-builder__check">
                            <input
                                type="checkbox"
                                checked={captureEnabled}
                                onChange={(event) => updateCapture({ enabled: event.target.checked })}
                            />
                            <span>Пользователь вводит данные</span>
                        </label>
                        {captureEnabled ? (
                            <>
                                <span>Сохранить в</span>
                                <div className="ac-v3-builder__edge-mode" role="group" aria-label="Куда сохранить данные">
                                    {EDGE_CAPTURE_SCOPE_OPTIONS.map(([value, label]) => (
                                        <button
                                            type="button"
                                            key={value}
                                            className={captureScope === value ? 'is-active' : ''}
                                            onClick={() => {
                                                if (value === 'contact') {
                                                    const [fieldKey, , dataType] = selectedContactField;

                                                    updateCapture({ field_scope: 'contact', field_key: fieldKey, data_type: dataType });

                                                    return;
                                                }

                                                updateCapture({ field_scope: 'dialog', field_key: '', data_type: 'any_text' });
                                            }}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                                {captureScope === 'contact' ? (
                                    <>
                                        <label>
                                            <span>Поле контакта</span>
                                            <select
                                                value={selectedContactField[0]}
                                                onChange={(event) => {
                                                    const [fieldKey, , dataType] = contactCaptureField(event.target.value);

                                                    updateCapture({ field_scope: 'contact', field_key: fieldKey, data_type: dataType });
                                                }}
                                            >
                                                {EDGE_CONTACT_FIELD_OPTIONS.map(([value, label]) => (
                                                    <option key={value} value={value}>{label}</option>
                                                ))}
                                            </select>
                                        </label>
                                        <label>
                                            <span>Тип данных</span>
                                            <input readOnly value={edgeDataTypeLabel(selectedContactField[2])} />
                                        </label>
                                    </>
                                ) : (
                                    <>
                                        <label>
                                            <span>Поле диалога</span>
                                            <input
                                                value={capture.field_key ?? ''}
                                                placeholder="client_phone"
                                                onChange={(event) => updateCapture({ field_scope: 'dialog', field_key: event.target.value })}
                                            />
                                        </label>
                                        <label>
                                            <span>Тип данных</span>
                                            <select
                                                value={capture.data_type ?? 'any_text'}
                                                onChange={(event) => updateCapture({ field_scope: 'dialog', data_type: event.target.value })}
                                            >
                                                {EDGE_DATA_TYPE_OPTIONS.map(([value, label]) => (
                                                    <option key={value} value={value}>{label}</option>
                                                ))}
                                            </select>
                                        </label>
                                    </>
                                )}
                            </>
                        ) : null}
                    </>
                ) : edgeMode === 'automatic' ? (
                    <>
                        <div className="ac-v3-builder__edge-mode" role="group" aria-label="Запуск automatic-связи">
                            {EDGE_DELAY_TYPE_OPTIONS.map(([value, label]) => (
                                <button
                                    type="button"
                                    key={value}
                                    className={delay.type === value ? 'is-active' : ''}
                                    onClick={() => {
                                        if (value === 'immediate') {
                                            updateDelay({ type: 'immediate', value: 0, unit: 'sec', scheduled_at: null });

                                            return;
                                        }

                                        if (value === 'relative') {
                                            updateDelay({
                                                type: 'relative',
                                                value: Math.max(1, delay.value || 1),
                                                unit: delay.unit || 'sec',
                                                scheduled_at: null,
                                            });

                                            return;
                                        }

                                        updateDelay({
                                            type: 'scheduled',
                                            value: 0,
                                            unit: 'sec',
                                            scheduled_at: delay.scheduled_at || defaultScheduledAtIso(),
                                        });
                                    }}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        {delay.type === 'relative' ? (
                            <>
                                <label className="ac-v3-builder__field-row">
                                    <span>Задержка</span>
                                    <input
                                        type="number"
                                        min="1"
                                        max="100000"
                                        value={Math.max(1, delay.value || 1)}
                                        onChange={(event) => {
                                            const value = Math.max(1, Math.floor(Number(event.target.value) || 1));

                                            updateDelay({
                                                type: 'relative',
                                                value,
                                                unit: delay.unit,
                                                scheduled_at: null,
                                            });
                                        }}
                                    />
                                </label>
                                <label className="ac-v3-builder__field-row">
                                    <span>Единица</span>
                                    <select
                                        value={delay.unit}
                                        onChange={(event) => updateDelay({ type: 'relative', unit: event.target.value })}
                                    >
                                        {EDGE_DELAY_UNIT_OPTIONS.map(([value, label]) => (
                                            <option key={value} value={value}>{label}</option>
                                        ))}
                                    </select>
                                </label>
                            </>
                        ) : null}
                        {delay.type === 'scheduled' ? (
                            <label>
                                <span>Дата и время запуска</span>
                                <input
                                    type="datetime-local"
                                    value={datetimeLocalValue(delay.scheduled_at)}
                                    onChange={(event) => updateDelay({
                                        type: 'scheduled',
                                        scheduled_at: scheduledIsoFromLocalInput(event.target.value),
                                        value: 0,
                                        unit: 'sec',
                                    })}
                                />
                            </label>
                        ) : null}
                        <label className="ac-v3-builder__field-row">
                            <span>Лимит переходов</span>
                            <input
                                type="number"
                                min="0"
                                value={payload.transition_limit ?? 0}
                                onChange={(event) => updatePayload({ transition_limit: Math.max(0, Number(event.target.value)) })}
                            />
                        </label>
                        <label className="ac-v3-builder__check">
                            <input
                                type="checkbox"
                                checked={delay.cancel_if_left_source_block !== false}
                                onChange={(event) => updateDelay({ cancel_if_left_source_block: event.target.checked })}
                            />
                            <span>Выполнить только если клиент всё ещё в этом блоке</span>
                        </label>
                        <div className="ac-v3-builder__edge-diagnostics">
                            <div className="ac-v3-builder__edge-diagnostics-head">
                                <span>Отложенные переходы</span>
                                <button type="button" onClick={onRefreshDiagnostics}>Обновить</button>
                            </div>
                            {delay.type !== 'immediate' ? (
                                scheduledTransitions.length > 0 ? (
                                    <div className="ac-v3-builder__edge-diagnostics-list">
                                        {scheduledTransitions.map((transition) => (
                                            <div
                                                key={transition.id}
                                                className={`ac-v3-builder__edge-diagnostics-item is-${transition.status ?? 'unknown'}`}
                                            >
                                                <div>
                                                    <strong>{transition.status_label ?? edgeTransitionStatusLabel(transition.status)}</strong>
                                                    <span>#{transition.id} · v{transition.published_version_id} · диалог #{transition.dialog_id}</span>
                                                </div>
                                                <div>
                                                    <span>План: {formatDateTime(transition.scheduled_for, timezoneLabel, timezone)}</span>
                                                    {transition.finished_at ? (
                                                        <span>Финиш: {formatDateTime(transition.finished_at, timezoneLabel, timezone)}</span>
                                                    ) : null}
                                                </div>
                                                {transition.error_message ? (
                                                    <p>{transition.error_message}</p>
                                                ) : null}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="ac-v3-builder__edge-diagnostics-empty">Переходов по этой стрелке пока нет.</p>
                                )
                            ) : (
                                <p className="ac-v3-builder__edge-diagnostics-empty">Для режима «Сразу» переход выполняется без очереди.</p>
                            )}
                        </div>
                    </>
                ) : null}
                <button type="button" className="ac-v3-builder__danger" onClick={onRemove}>Удалить связь</button>
            </section>
        </div>
    );
}

function mergeBuilderDiagnostics(current, refreshed) {
    if (! current) {
        return refreshed;
    }

    if (! refreshed?.builder) {
        return current;
    }

    const refreshedEdges = refreshed.builder.edges ?? [];

    return {
        ...current,
        server: refreshed.server ?? current.server,
        builder: {
            ...current.builder,
            diagnostics: refreshed.builder.diagnostics ?? current.builder?.diagnostics,
            edges: (current.builder?.edges ?? []).map((edge) => {
                const diagnostics = findRefreshedEdgeDiagnostics(edge, refreshedEdges);

                return diagnostics ? { ...edge, diagnostics } : edge;
            }),
        },
    };
}

function findRefreshedEdgeDiagnostics(edge, refreshedEdges) {
    const key = edgeDiagnosticsKey(edge);

    if (! key) {
        return null;
    }

    const refreshedEdge = refreshedEdges.find((item) => edgeDiagnosticsKey(item) === key);

    return refreshedEdge?.diagnostics ?? null;
}

function edgeDiagnosticsKey(edge) {
    return edge?.condition_payload?.edge_key
        || edge?.client_key
        || (edge?.id ? `id:${edge.id}` : null);
}

function activeSheetFrom(builder) {
    const sheets = builder?.sheets?.length ? builder.sheets : [MAIN_SHEET];

    return sheets.find((sheet) => sheet.id === builder?.active_sheet_id) ?? sheets[0] ?? MAIN_SHEET;
}

function errorText(error) {
    if (error.status === 409) {
        return 'Состояние изменилось в другой вкладке. Обновите страницу и повторите сохранение.';
    }

    if (error.status === 422 && error.data?.errors) {
        return Object.values(error.data.errors).flat().join(' ');
    }

    return error.message || 'Не удалось выполнить запрос.';
}

function blockPosition(block) {
    return {
        x: Number(block?.position?.x ?? 64),
        y: Number(block?.position?.y ?? 64),
    };
}

function blockOutputs(block) {
    const outputs = Array.isArray(block?.settings_payload?.outputs) ? block.settings_payload.outputs : [];

    if (outputs.length > 0) {
        return outputs.map((output) => ({
            id: output.id,
            label: output.label || output.id,
            kind: output.source || 'button',
        }));
    }

    const buttons = findModule(block?.settings_payload, 'buttons');
    const buttonOutputs = flatButtons(buttons).map((button) => ({
        id: button.id,
        label: button.text || button.id,
        kind: 'button',
    }));

    if (buttonOutputs.length > 0) {
        return buttonOutputs;
    }

    return [DEFAULT_OUTPUT];
}

function outputAnchor(block, outputId) {
    const position = blockPosition(block);
    const outputs = blockOutputs(block);
    const index = Math.max(0, outputs.findIndex((output) => output.id === outputId));

    return {
        x: position.x + PORT_DOT_CENTER_X,
        y: position.y + portsTopOffset(block) + (index * (PORT_ROW_HEIGHT + PORT_ROW_GAP)) + (PORT_ROW_HEIGHT / 2),
        side: 'right',
    };
}

function nearestBlockSideAnchor(block, targetPoint, anchors = {}) {
    const center = blockCenter(block, anchors);
    const dx = targetPoint.x - center.x;
    const dy = targetPoint.y - center.y;

    if (Math.abs(dx) >= Math.abs(dy)) {
        return blockSideAnchor(block, dx >= 0 ? 'right' : 'left', anchors);
    }

    return blockSideAnchor(block, dy >= 0 ? 'bottom' : 'top', anchors);
}

function blockSideAnchor(block, side, anchors = {}) {
    const rect = blockRect(block, anchors);

    if (side === 'left') {
        return {
            x: rect.x - 2,
            y: rect.y + (rect.height / 2),
            side,
        };
    }

    if (side === 'top') {
        return {
            x: rect.x + (rect.width / 2),
            y: rect.y - 2,
            side,
        };
    }

    if (side === 'bottom') {
        return {
            x: rect.x + (rect.width / 2),
            y: rect.y + rect.height + 2,
            side,
        };
    }

    return {
        x: rect.x + rect.width + 2,
        y: rect.y + (rect.height / 2),
        side: 'right',
    };
}

function inputAnchor(block) {
    const position = blockPosition(block);

    return {
        x: position.x - 2,
        y: position.y + NODE_HEADER_HEIGHT + 34,
        side: 'left',
    };
}

function blockCenter(block, anchors = {}) {
    const rect = blockRect(block, anchors);

    return {
        x: rect.x + (rect.width / 2),
        y: rect.y + (rect.height / 2),
    };
}

function blockRect(block, anchors = {}) {
    const measured = anchors.nodes?.[block?.client_key];

    if (measured) {
        return measured;
    }

    const position = blockPosition(block);

    return {
        x: position.x,
        y: position.y,
        width: NODE_WIDTH,
        height: blockHeight(block),
    };
}

function blockHeight(block) {
    const outputs = blockOutputs(block);
    const portsHeight = (outputs.length * PORT_ROW_HEIGHT)
        + (Math.max(0, outputs.length - 1) * PORT_ROW_GAP)
        + 12;

    return Math.max(186, portsTopOffset(block) + portsHeight);
}

function portsTopOffset(block) {
    const modulesCount = modulesFrom(block?.settings_payload).length;
    const previewCount = Math.max(1, modulesCount);

    return NODE_HEADER_HEIGHT
        + MODULE_STRIP_HEIGHT
        + NODE_BODY_PADDING
        + (previewCount * MODULE_PREVIEW_HEIGHT)
        + ((previewCount - 1) * MODULE_PREVIEW_GAP)
        + NODE_BODY_PADDING;
}

function graphBounds(blocks) {
    if (blocks.length === 0) {
        return { minX: 0, minY: 0, width: CANVAS_MIN_WIDTH, height: CANVAS_MIN_HEIGHT };
    }

    const xs = blocks.map((block) => blockPosition(block).x);
    const ys = blocks.map((block) => blockPosition(block).y);
    const minX = Math.min(...xs);
    const minY = Math.min(...ys);
    const worldMinX = Math.min(0, minX - CANVAS_EXPAND_PADDING);
    const worldMinY = Math.min(0, minY - CANVAS_EXPAND_PADDING);
    const maxX = Math.max(...xs) + NODE_WIDTH + 420;
    const maxY = Math.max(...ys) + 520;

    return {
        minX,
        minY,
        width: Math.max(CANVAS_MIN_WIDTH, maxX - worldMinX),
        height: Math.max(CANVAS_MIN_HEIGHT, maxY - worldMinY),
    };
}

function canvasGridStyle(view) {
    const majorGrid = 96 * view.zoom;
    const minorGrid = 16 * view.zoom;
    const majorX = view.tx % majorGrid;
    const majorY = view.ty % majorGrid;
    const minorX = view.tx % minorGrid;
    const minorY = view.ty % minorGrid;

    return {
        backgroundPosition: `${majorX}px ${majorY}px, ${minorX}px ${minorY}px`,
        backgroundSize: `${majorGrid}px ${majorGrid}px, ${minorGrid}px ${minorGrid}px`,
    };
}

function normalizeSettings(settingsPayload) {
    return {
        schema_version: 3,
        kind: settingsPayload?.kind === 'non_state' ? 'non_state' : 'state',
        ui: {
            sheet_id: settingsPayload?.ui?.sheet_id ?? 'main',
            width: settingsPayload?.ui?.width ?? 320,
            collapsed: Boolean(settingsPayload?.ui?.collapsed ?? false),
            card_id: settingsPayload?.ui?.card_id ?? '',
        },
        modules: sortModules(modulesFrom(settingsPayload)),
        outputs: Array.isArray(settingsPayload?.outputs) ? settingsPayload.outputs : [],
    };
}

function nodeTypeLabel(hasStartCondition, blockKind) {
    if (blockKind === 'non_state') {
        return hasStartCondition ? 'Не состояние с условием' : 'Не состояние';
    }

    return hasStartCondition ? 'Стартовый блок' : 'Состояние';
}

function modulesFrom(settingsPayload) {
    return Array.isArray(settingsPayload?.modules) ? settingsPayload.modules : [];
}

function findModule(settingsPayload, type) {
    return modulesFrom(settingsPayload).find((module) => module.type === type) ?? null;
}

function moduleTemplate(type, channels) {
    if (type === 'start_condition') {
        return {
            id: 'mod_start',
            type,
            enabled: true,
            payload: {
                command: '/start',
                values: [],
                match: 'exact_keyword',
                variable: '',
                exclude: '',
                contact_phone_condition: '',
                priority: 10,
                once: false,
                channels: { mode: 'selected', ids: channels.map((channel) => channel.id) },
            },
        };
    }

    if (type === 'buttons') {
        return {
            id: 'mod_buttons',
            type,
            enabled: true,
            payload: {
                placement: 'auto',
                rows: [[{ id: 'btn_1', text: '', type: BUTTON_TYPE_TEXT, fn: 'default', url: null, color: null }]],
            },
        };
    }

    return {
        id: 'mod_message',
        type: 'message',
        enabled: true,
        payload: { text: 'Новый текст сообщения', text_format: 'plain_text' },
    };
}

function messageSettingsPayload(text) {
    return {
        schema_version: 3,
        kind: 'state',
        ui: { sheet_id: 'main', width: 320, collapsed: false },
        modules: [
            {
                id: 'mod_message',
                type: 'message',
                enabled: true,
                payload: { text, text_format: 'plain_text' },
            },
        ],
        outputs: [],
    };
}

function startSettingsPayload(channels) {
    return {
        ...messageSettingsPayload('Здравствуйте! Чем помочь?'),
        modules: sortModules([
            moduleTemplate('start_condition', channels),
            {
                id: 'mod_message',
                type: 'message',
                enabled: true,
                payload: { text: 'Здравствуйте! Чем помочь?', text_format: 'plain_text' },
            },
        ]),
    };
}

function buttonsSettingsPayload() {
    return syncOutputs({
        ...messageSettingsPayload('Выберите действие:'),
        modules: sortModules([
            {
                id: 'mod_message',
                type: 'message',
                enabled: true,
                payload: { text: 'Выберите действие:', text_format: 'plain_text' },
            },
            moduleTemplate('buttons', []),
        ]),
    });
}

function sortModules(modules) {
    return [...modules].sort((left, right) => MODULE_ORDER.indexOf(left.type) - MODULE_ORDER.indexOf(right.type));
}

function buttonRows(buttonsModule) {
    return Array.isArray(buttonsModule?.payload?.rows)
        ? buttonsModule.payload.rows.map((row) => (Array.isArray(row) ? row : [])).filter((row) => row.length > 0)
        : [];
}

function normalizeButtonRows(rows) {
    return Array.isArray(rows)
        ? rows
            .map((row) => (Array.isArray(row) ? row.filter(Boolean) : []))
            .filter((row) => row.length > 0)
        : [];
}

function moveButtonRows(rows, from, to) {
    const nextRows = normalizeButtonRows(rows).map((row) => [...row]);
    const sourceRow = nextRows[from.rowIndex];

    if (! sourceRow || ! sourceRow[from.buttonIndex]) {
        return nextRows;
    }

    let targetRowIndex = to.rowIndex;
    let targetButtonIndex = to.buttonIndex;

    if (from.rowIndex === targetRowIndex) {
        const [button] = sourceRow.splice(from.buttonIndex, 1);

        if (from.buttonIndex < targetButtonIndex) {
            targetButtonIndex -= 1;
        }

        sourceRow.splice(Math.max(0, Math.min(targetButtonIndex, sourceRow.length)), 0, button);

        return normalizeButtonRows(nextRows);
    }

    const [button] = sourceRow.splice(from.buttonIndex, 1);

    if (sourceRow.length === 0) {
        nextRows.splice(from.rowIndex, 1);

        if (from.rowIndex < targetRowIndex) {
            targetRowIndex -= 1;
        }
    }

    const targetRow = nextRows[targetRowIndex];

    if (! targetRow) {
        nextRows.push([button]);

        return normalizeButtonRows(nextRows);
    }

    targetRow.splice(Math.max(0, Math.min(targetButtonIndex, targetRow.length)), 0, button);

    return normalizeButtonRows(nextRows);
}

function flatButtons(buttonsModule) {
    return buttonRows(buttonsModule).flat();
}

function syncOutputs(settingsPayload) {
    const buttons = findModule(settingsPayload, 'buttons');
    const outputs = buttons
        ? flatButtons(buttons).map((button) => ({
            id: button.id,
            label: button.text,
            source: 'button',
            module_id: buttons.id,
            button_id: button.id,
            button_type: button.type === BUTTON_TYPE_REQUEST_PHONE ? BUTTON_TYPE_REQUEST_PHONE : BUTTON_TYPE_TEXT,
        }))
        : [];

    return {
        ...settingsPayload,
        outputs,
    };
}

function nextButtonId(rows) {
    const ids = new Set(rows.flat().map((button) => button.id));
    let index = ids.size + 1;
    let id = `btn_${index}`;

    while (ids.has(id)) {
        index += 1;
        id = `btn_${index}`;
    }

    return id;
}

function normalizedEdgeDelay(delay) {
    const raw = delay && typeof delay === 'object' ? delay : {};
    const type = EDGE_DELAY_TYPE_OPTIONS.some(([option]) => option === raw.type)
        ? raw.type
        : null;
    const rawUnit = typeof raw.unit === 'string' ? raw.unit : 'sec';
    const value = Math.max(0, Math.floor(Number(raw.value) || 0));
    const unit = value > 0 && EDGE_DELAY_UNIT_OPTIONS.some(([option]) => option === rawUnit)
        ? rawUnit
        : 'sec';
    const scheduledAt = typeof raw.scheduled_at === 'string' && raw.scheduled_at.trim() !== ''
        ? raw.scheduled_at.trim()
        : null;

    if (type === 'scheduled') {
        return {
            type: 'scheduled',
            value: 0,
            unit: 'sec',
            scheduled_at: scheduledAt || defaultScheduledAtIso(),
            cancel_if_left_source_block: raw.cancel_if_left_source_block !== false,
        };
    }

    return {
        type: type === 'relative' && value > 0 ? 'relative' : (value > 0 ? 'relative' : 'immediate'),
        value: value > 0 ? value : 0,
        unit,
        scheduled_at: null,
        cancel_if_left_source_block: raw.cancel_if_left_source_block !== false,
    };
}

function defaultScheduledAtIso() {
    return new Date(Date.now() + 60 * 60 * 1000).toISOString();
}

function datetimeLocalValue(value) {
    if (! value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const offset = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function scheduledIsoFromLocalInput(value) {
    if (! value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function edgePayload(outputId, label) {
    const isButton = outputId !== null;

    return {
        schema_version: 3,
        edge_schema_version: 3,
        edge_key: null,
        from_output_id: outputId,
        label,
        mode: isButton ? 'button' : 'wait_reply',
        priority: 10,
        transition_limit: 0,
        match: {
            type: isButton ? 'exact_text' : 'any_inbound',
            text: isButton ? label : '',
        },
        input_capture: {
            enabled: false,
            field_scope: 'dialog',
            field_key: '',
            data_type: 'any_text',
        },
        delay: normalizedEdgeDelay(),
        flags: {
            as_button: false,
            one_time: false,
            user_input: false,
        },
        ui: {
            from_anchor: null,
            to_anchor: { side: 'left', t: 0.5 },
            waypoints: [],
            style: 'curve',
        },
    };
}

function sameSource(left, right) {
    return left?.client_key === right?.client_key && (left?.output_id ?? null) === (right?.output_id ?? null);
}

function portAnchorKey(blockKey, outputId) {
    return `${blockKey}:${outputId ?? 'default'}`;
}

function connectedOutputIds(block, edges) {
    return new Set(edges
        .filter((edge) => edge.source?.client_key === block.client_key)
        .map((edge) => edge.source?.output_id ?? 'default'));
}

function currentButtonText(blocks, clientKey, buttonId) {
    const block = blocks.find((item) => item.client_key === clientKey);
    const buttons = findModule(block?.settings_payload, 'buttons');
    const button = flatButtons(buttons).find((item) => item.id === buttonId);

    return button?.text ?? '';
}

function moduleLabel(type) {
    return MODULE_META[type]?.label ?? type;
}

function startMatchForUi(match) {
    if (match === 'strict') {
        return 'exact_text_or_parameter';
    }

    if (match === 'contains') {
        return 'contains_text';
    }

    if (MATCH_OPTIONS.some(([value]) => value === match)) {
        return match;
    }

    return 'exact_keyword';
}

function truncate(text, limit) {
    const normalized = String(text).replace(/\s+/g, ' ').trim();

    return normalized.length > limit ? `${normalized.slice(0, limit - 1)}…` : normalized;
}

function resolveReturnedKey(previousKey, idMap, prefix) {
    if (! previousKey) {
        return null;
    }

    const id = idMap?.[previousKey];

    return id ? `${prefix}_${id}` : previousKey;
}

function resolvePublishedSelection(savedState, publishedState, blockKey, edgeKey) {
    const savedBlocks = savedState?.builder?.blocks ?? [];
    const savedEdges = savedState?.builder?.edges ?? [];
    const publishedBlocks = publishedState?.builder?.blocks ?? [];
    const publishedEdges = publishedState?.builder?.edges ?? [];
    const savedEdgeKey = resolveReturnedKey(edgeKey, savedState?.id_map?.edges, 'edge');
    const savedBlockKey = resolveReturnedKey(blockKey, savedState?.id_map?.blocks, 'block');
    const savedEdge = savedEdges.find((edge) => edge.client_key === savedEdgeKey);

    if (savedEdge) {
        const edge = findMatchingPublishedEdge(savedEdge, savedBlocks, publishedEdges, publishedBlocks);

        if (edge) {
            return { blockKey: null, edgeKey: edge.client_key };
        }
    }

    const savedBlock = savedBlocks.find((block) => block.client_key === savedBlockKey);

    if (savedBlock) {
        const cardId = stableBlockCardId(savedBlock);
        const block = publishedBlocks.find((item) => stableBlockCardId(item) === cardId);

        if (block) {
            return { blockKey: block.client_key, edgeKey: null };
        }
    }

    return {
        blockKey: publishedBlocks[0]?.client_key ?? null,
        edgeKey: null,
    };
}

function findMatchingPublishedEdge(savedEdge, savedBlocks, publishedEdges, publishedBlocks) {
    const edgeKey = savedEdge?.condition_payload?.edge_key;

    if (edgeKey) {
        const edge = publishedEdges.find((item) => item.condition_payload?.edge_key === edgeKey);

        if (edge) {
            return edge;
        }
    }

    const sourceCardId = stableBlockCardId(savedBlocks.find((block) => block.client_key === savedEdge.source?.client_key));
    const targetCardId = stableBlockCardId(savedBlocks.find((block) => block.client_key === savedEdge.target?.client_key));

    if (! sourceCardId || ! targetCardId) {
        return null;
    }

    return publishedEdges.find((edge) => (
        edge.source?.output_id === savedEdge.source?.output_id
        && stableBlockCardId(publishedBlocks.find((block) => block.client_key === edge.source?.client_key)) === sourceCardId
        && stableBlockCardId(publishedBlocks.find((block) => block.client_key === edge.target?.client_key)) === targetCardId
    )) ?? null;
}

function stableBlockCardId(block) {
    return block?.settings_payload?.ui?.card_id
        ?? block?.display_id
        ?? null;
}

function snap(value) {
    return Math.round(value / 8) * 8;
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function BlockTypeIcon({ type }) {
    if (type === 'non_state') {
        return (
            <svg className="ac-v3-builder__block-kind-icon is-non-state" width="23" height="23" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M5.4 4.8h11.2c1.4 0 2.5 1.1 2.5 2.5v6.1c0 1.4-1.1 2.5-2.5 2.5H10l-4.2 3.3v-3.3h-.4c-1.4 0-2.5-1.1-2.5-2.5V7.3c0-1.4 1.1-2.5 2.5-2.5Z" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" />
                <path d="m8.4 8.7 4.2 4.2M12.6 8.7l-4.2 4.2" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
            </svg>
        );
    }

    return (
        <svg className="ac-v3-builder__block-kind-icon is-state" width="23" height="23" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M5.4 4.8h11.2c1.4 0 2.5 1.1 2.5 2.5v6.1c0 1.4-1.1 2.5-2.5 2.5H10l-4.2 3.3v-3.3h-.4c-1.4 0-2.5-1.1-2.5-2.5V7.3c0-1.4 1.1-2.5 2.5-2.5Z" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" />
        </svg>
    );
}

function CopyIcon() {
    return (
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <rect x="5.2" y="4.2" width="6.6" height="7.6" rx="1.2" stroke="currentColor" strokeWidth="1.3" />
            <path d="M4 9.8H3.2C2.5 9.8 2 9.3 2 8.6V3.2C2 2.5 2.5 2 3.2 2h5.2c.7 0 1.2.5 1.2 1.2V4" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" />
        </svg>
    );
}

function CursorIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="M3 2l3.5 10 1.5-4 4-1.5L3 2z" stroke="currentColor" strokeWidth="1.4" strokeLinejoin="round" />
        </svg>
    );
}

function PanIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="M8 1.8v12.4M1.8 8h12.4M5.6 4.1 8 1.8l2.4 2.3M5.6 11.9 8 14.2l2.4-2.3M4.1 5.6 1.8 8l2.3 2.4M11.9 5.6 14.2 8l-2.3 2.4" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function TriggerIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="M8.8 1.8 3.4 9h4.1l-.5 5.2L12.6 6.4H8.3l.5-4.6z" stroke="currentColor" strokeWidth="1.4" strokeLinejoin="round" />
        </svg>
    );
}

function MessageIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="M2.5 3.5A1.5 1.5 0 0 1 4 2h8a1.5 1.5 0 0 1 1.5 1.5v5.8a1.5 1.5 0 0 1-1.5 1.5H7l-3.2 3v-3H4a1.5 1.5 0 0 1-1.5-1.5V3.5z" stroke="currentColor" strokeWidth="1.4" strokeLinejoin="round" />
        </svg>
    );
}

function ButtonIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <rect x="2.5" y="3" width="11" height="4" rx="1.5" stroke="currentColor" strokeWidth="1.4" />
            <rect x="2.5" y="9" width="11" height="4" rx="1.5" stroke="currentColor" strokeWidth="1.4" />
        </svg>
    );
}

function AttachmentIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="m5 8.4 4.7-4.7a2 2 0 1 1 2.8 2.8l-5.6 5.6a3 3 0 0 1-4.2-4.2l5.4-5.4" stroke="currentColor" strokeWidth="1.35" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function SparkleIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="M7.5 2.2 8.6 5.4l3.2 1.1-3.2 1.1-1.1 3.2-1.1-3.2-3.2-1.1 3.2-1.1 1.1-3.2Z" stroke="currentColor" strokeWidth="1.25" strokeLinejoin="round" />
            <path d="M12.3 9.7 12.8 11l1.3.5-1.3.5-.5 1.3-.5-1.3-1.3-.5 1.3-.5.5-1.3Z" stroke="currentColor" strokeWidth="1.1" strokeLinejoin="round" />
        </svg>
    );
}

function BotIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <rect x="3" y="5" width="10" height="7.5" rx="2" stroke="currentColor" strokeWidth="1.35" />
            <path d="M8 5V2.8M5.8 8.4h.1M10.1 8.4h.1M6.2 12.5 5.5 14M9.8 12.5l.7 1.5" stroke="currentColor" strokeWidth="1.35" strokeLinecap="round" />
        </svg>
    );
}

function CodeIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="m5.6 4.2-3 3.8 3 3.8M10.4 4.2l3 3.8-3 3.8M9 3.2 7 12.8" stroke="currentColor" strokeWidth="1.35" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function CloudIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="M5.1 12.4h6a2.7 2.7 0 0 0 .6-5.3 4 4 0 0 0-7.6-1.2A3.3 3.3 0 0 0 5.1 12.4Z" stroke="currentColor" strokeWidth="1.35" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function AnalyticsIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="M3.2 12.8V8.5M6.4 12.8V5.8M9.6 12.8V3.2M12.8 12.8V6.9" stroke="currentColor" strokeWidth="1.45" strokeLinecap="round" />
        </svg>
    );
}

function PlayIcon() {
    return (
        <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor">
            <path d="M3 2.2v7.6L9.4 6 3 2.2z" />
        </svg>
    );
}

function HistoryIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <path d="M3 8a5 5 0 1 0 5-5M3 3v3h3M8 5v3l2 1.5" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
        </svg>
    );
}

function GearIcon() {
    return (
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
            <circle cx="8" cy="8" r="2" stroke="currentColor" strokeWidth="1.4" />
            <path d="M8 1.8v2M8 12.2v2M3.6 3.6 5 5M11 11l1.4 1.4M1.8 8h2M12.2 8h2M3.6 12.4 5 11M11 5l1.4-1.4" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" />
        </svg>
    );
}

function MoreIcon() {
    return (
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <circle cx="4" cy="8" r="1.2" />
            <circle cx="8" cy="8" r="1.2" />
            <circle cx="12" cy="8" r="1.2" />
        </svg>
    );
}

function LinkIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M3 8h9.2M8.7 4.5 12.2 8l-3.5 3.5" stroke="currentColor" strokeWidth="1.45" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M2.8 4.1h2.4M2.8 11.9h2.4" stroke="currentColor" strokeWidth="1.45" strokeLinecap="round" />
        </svg>
    );
}
