import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { loadScenarioBuilderState, saveScenarioBuilderState } from './api.js';

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
const MODULE_ORDER = ['start_condition', 'message', 'buttons'];
const MATCH_OPTIONS = [
    ['strict', 'Точно'],
    ['contains', 'Содержит'],
    ['starts', 'Начинается'],
    ['regex', 'Regex'],
];

const MODULE_META = {
    start_condition: { label: 'Старт', short: 'ST', className: 'is-start' },
    message: { label: 'Сообщение', short: 'MSG', className: 'is-message' },
    buttons: { label: 'Кнопки', short: 'BTN', className: 'is-buttons' },
};

export default function App({ stateUrl, saveUrl, csrfToken }) {
    const canvasRef = useRef(null);
    const dragRef = useRef(null);
    const [state, setState] = useState(null);
    const [status, setStatus] = useState('loading');
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const [mode, setMode] = useState('design');
    const [tool, setTool] = useState('select');
    const [isSaving, setIsSaving] = useState(false);
    const [selectedBlockKey, setSelectedBlockKey] = useState(null);
    const [selectedEdgeKey, setSelectedEdgeKey] = useState(null);
    const [pendingConnection, setPendingConnection] = useState(null);
    const [anchors, setAnchors] = useState({ ports: {} });

    useEffect(() => {
        let isMounted = true;

        setStatus('loading');
        setError(null);
        setNotice(null);

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

    const builder = state?.builder ?? null;
    const blocks = builder?.blocks ?? [];
    const edges = builder?.edges ?? [];
    const channels = state?.catalogs?.channels ?? [];
    const activeSheet = activeSheetFrom(builder);
    const view = activeSheet.view ?? MAIN_SHEET.view;
    const revision = builder?.revision ?? null;
    const selectedBlock = blocks.find((block) => block.client_key === selectedBlockKey) ?? null;
    const selectedEdge = edges.find((edge) => edge.client_key === selectedEdgeKey) ?? null;
    const canSave = state?.permissions?.can_update === true && status === 'ready' && ! isSaving;
    const canvasBounds = useMemo(() => graphBounds(blocks), [blocks]);

    useLayoutEffect(() => {
        if (status !== 'ready' || ! canvasRef.current) {
            return;
        }

        const canvasRect = canvasRef.current.getBoundingClientRect();
        const ports = {};

        canvasRef.current.querySelectorAll('[data-port-key]').forEach((element) => {
            const rect = element.getBoundingClientRect();

            ports[element.dataset.portKey] = {
                x: (rect.left + (rect.width / 2) - canvasRect.left - view.tx) / view.zoom,
                y: (rect.top + (rect.height / 2) - canvasRect.top - view.ty) / view.zoom,
            };
        });

        const next = { ports };

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
        cancelConnection();
    }

    function addBlock(kind) {
        const index = blocks.length + 1;
        const clientKey = `tmp_block_${Date.now().toString(36)}_${index}`;
        const position = {
            x: Math.max(64, Math.round((-view.tx + 140) / view.zoom) + index * 34),
            y: Math.max(64, Math.round((-view.ty + 116) / view.zoom) + index * 26),
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
                    x: snap(Math.max(0, drag.origin.x + dx)),
                    y: snap(Math.max(0, drag.origin.y + dy)),
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
            tx: Math.max(48, 132 - canvasBounds.minX),
            ty: Math.max(48, 100 - canvasBounds.minY),
            zoom: 1,
        });
    }

    function beginConnection(block, output) {
        const connection = {
            sourceKey: block.client_key,
            sourceId: block.id,
            outputId: output.id,
            label: output.label,
            from: outputAnchor(block, output.id),
        };

        cancelConnection();
        setPendingConnection(connection);
        setSelectedBlockKey(block.client_key);
        setSelectedEdgeKey(null);
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

        updateEdges((currentEdges) => [...currentEdges.filter((item) => ! sameSource(item.source, source)), edge]);
        setSelectedEdgeKey(edge.client_key);
        setSelectedBlockKey(null);
        cancelConnection();
    }

    function cancelConnection() {
        setPendingConnection(null);
    }

    function removeEdge(edgeKey) {
        updateEdges(edges.filter((edge) => edge.client_key !== edgeKey));
        setSelectedEdgeKey(null);
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

    function addButton(clientKey) {
        updateBlockSettings(clientKey, (settings) => {
            const buttons = findModule(settings, 'buttons') ?? moduleTemplate('buttons', channels);
            const rows = buttonRows(buttons);
            const id = nextButtonId(rows);
            const nextRows = rows.length > 0 ? rows : [[]];

            nextRows[nextRows.length - 1] = [
                ...nextRows[nextRows.length - 1],
                { id, text: 'Новая кнопка', fn: 'default', url: null, color: null },
            ];

            const modules = modulesFrom(settings).filter((module) => module.type !== 'buttons');

            return syncOutputs({
                ...settings,
                modules: sortModules([
                    ...modules,
                    { ...buttons, payload: { ...buttons.payload, rows: nextRows } },
                ]),
            });
        });
    }

    function updateButton(clientKey, buttonId, patch) {
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
                            button.id === buttonId ? { ...button, ...patch } : button
                        ))),
                    },
                };
            })),
        }));

        if (Object.prototype.hasOwnProperty.call(patch, 'text')) {
            updateEdges(edges.map((edge) => {
                if (edge.source?.client_key !== clientKey || edge.source?.output_id !== buttonId) {
                    return edge;
                }

                return {
                    ...edge,
                    condition_payload: {
                        ...edge.condition_payload,
                        label: patch.text,
                        match: {
                            ...(edge.condition_payload?.match ?? {}),
                            value: patch.text,
                        },
                    },
                };
            }));
        }
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

    async function save() {
        if (! state) {
            return;
        }

        setIsSaving(true);
        setError(null);
        setNotice(null);

        try {
            const selectedBefore = selectedBlockKey;
            const edgeBefore = selectedEdgeKey;
            const blocksForSave = blocks.map((block) => ({
                ...block,
                settings_payload: syncOutputs(normalizeSettings(block.settings_payload)),
            }));

            const response = await saveScenarioBuilderState(saveUrl, csrfToken, {
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
            });

            setState(response);
            setSelectedBlockKey(resolveReturnedKey(selectedBefore, response.id_map?.blocks, 'block'));
            setSelectedEdgeKey(resolveReturnedKey(edgeBefore, response.id_map?.edges, 'edge'));
            cancelConnection();
            setStatus('ready');
            setNotice('Сохранено');
        } catch (requestError) {
            setError(errorText(requestError));

            if (requestError.status === 409) {
                setStatus('conflict');
            }
        } finally {
            setIsSaving(false);
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
                    <span>Конструктор</span>
                    <i>/</i>
                    <strong>{state?.scenario?.name ?? 'Сценарий'}</strong>
                    {revision ? <em>{revision}</em> : null}
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
                    <button type="button" className="ac-v3-builder__publish" disabled={! state?.permissions?.can_publish} onClick={() => setNotice('Публикация будет подключена следующим шагом.')}>
                        Опубликовать
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

            {pendingConnection ? (
                <Notice kind="connection" onClose={cancelConnection}>
                    Теперь кликните по блоку, куда должна вести кнопка «{pendingConnection.label}».
                </Notice>
            ) : null}

            <div className="ac-v3-builder__workbench">
                <ToolRail tool={tool} onTool={setTool} onAddBlock={addBlock} />

                <main
                    ref={canvasRef}
                    className="ac-v3-builder__canvas"
                    onPointerDown={startCanvasPan}
                    onWheel={handleWheel}
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
                                <marker id="ac-v3-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
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

                <aside className="ac-v3-builder__panel">
                    {selectedEdge ? (
                        <EdgePanel edge={selectedEdge} blocks={blocks} onRemove={() => removeEdge(selectedEdge.client_key)} />
                    ) : (
                        <BlockPanel
                            block={selectedBlock}
                            channels={channels}
                            blocks={blocks}
                            edges={edges}
                            onSelectBlock={selectBlock}
                            onUpdateBlock={updateBlock}
                            onUpdateModulePayload={updateModulePayload}
                            onToggleModule={toggleModule}
                            onRemoveBlock={removeBlock}
                            onAddButton={addButton}
                            onUpdateButton={updateButton}
                            onRemoveButton={removeButton}
                            onUpdateStartChannels={updateStartChannels}
                            onStartPanelConnection={startPanelConnection}
                        />
                    )}
                </aside>
            </div>
        </section>
    );
}

function Notice({ kind, children, onClose }) {
    return (
        <div className="ac-v3-builder__notice" data-kind={kind}>
            <span>{children}</span>
            <button type="button" onClick={onClose}>Закрыть</button>
        </div>
    );
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

function ScenarioNode({ block, selected, pendingTarget, connectedOutputIds, onSelect, onDragStart, onStartConnection }) {
    const outputs = blockOutputs(block);
    const start = findModule(block.settings_payload, 'start_condition');
    const message = findModule(block.settings_payload, 'message');
    const buttons = findModule(block.settings_payload, 'buttons');
    const position = blockPosition(block);
    const modules = modulesFrom(block.settings_payload);

    return (
        <article
            data-node
            data-node-key={block.client_key}
            className={[
                'ac-v3-builder__node',
                selected ? 'is-selected' : '',
                pendingTarget ? 'is-targetable' : '',
                start ? 'has-start' : '',
            ].filter(Boolean).join(' ')}
            style={{ left: `${position.x}px`, top: `${position.y}px` }}
            onPointerDown={(event) => event.stopPropagation()}
            onClick={onSelect}
        >
            <header onPointerDown={onDragStart}>
                <div className="ac-v3-builder__node-icon">{start ? <TriggerIcon /> : <MessageIcon />}</div>
                <div>
                    <span>{start ? 'Стартовый блок' : 'Состояние'}</span>
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
                        className={connectedOutputIds.has(output.id ?? 'default') ? 'is-connected' : ''}
                        title="Связать с блоком"
                        onPointerDown={(event) => onStartConnection(event, block, output)}
                        onClick={(event) => event.stopPropagation()}
                    >
                        <span>{output.label}</span>
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

    const source = anchors.ports[portAnchorKey(sourceBlock.client_key, edge.source?.output_id ?? null)]
        ?? outputAnchor(sourceBlock, edge.source?.output_id ?? null);
    const target = inputAnchor(targetBlock);
    const curve = Math.max(72, Math.abs(target.x - source.x) * 0.42);
    const d = `M ${source.x} ${source.y} C ${source.x + curve} ${source.y}, ${target.x - curve} ${target.y}, ${target.x} ${target.y}`;
    const labelX = (source.x + target.x) / 2;
    const labelY = (source.y + target.y) / 2 - 8;

    return (
        <g className={selected ? 'is-selected' : ''}>
            <path data-edge-action d={d} className="ac-v3-builder__edge-hit" onClick={onSelect} />
            <path d={d} className="ac-v3-builder__edge" />
            {edge.condition_payload?.label ? (
                <text x={labelX} y={labelY} className="ac-v3-builder__edge-label">{edge.condition_payload.label}</text>
            ) : null}
        </g>
    );
}

function BlockPanel({
    block,
    channels,
    blocks,
    edges,
    onSelectBlock,
    onUpdateBlock,
    onUpdateModulePayload,
    onToggleModule,
    onRemoveBlock,
    onAddButton,
    onUpdateButton,
    onRemoveButton,
    onUpdateStartChannels,
    onStartPanelConnection,
}) {
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

    return (
        <div className="ac-v3-builder__inspector">
            <div className="ac-v3-builder__panel-head">
                <span>Свойства блока</span>
                <strong>{block.title}</strong>
            </div>

            <section>
                <label>
                    Название
                    <input
                        value={block.title}
                        onChange={(event) => onUpdateBlock(block.client_key, { title: event.target.value })}
                    />
                </label>
            </section>

            <section>
                <span>Модули</span>
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
            </section>

            {start ? (
                <section>
                    <span>Старт</span>
                    <label>
                        Фраза или команда
                        <input
                            value={start.payload?.command ?? ''}
                            placeholder="/start"
                            onChange={(event) => onUpdateModulePayload(block.client_key, 'start_condition', { command: event.target.value })}
                        />
                    </label>
                    <label>
                        Совпадение
                        <select
                            value={start.payload?.match ?? 'strict'}
                            onChange={(event) => onUpdateModulePayload(block.client_key, 'start_condition', { match: event.target.value })}
                        >
                            {MATCH_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                    <label>
                        Приоритет
                        <input
                            type="number"
                            value={start.payload?.priority ?? 10}
                            onChange={(event) => onUpdateModulePayload(block.client_key, 'start_condition', { priority: Number(event.target.value) })}
                        />
                    </label>
                    <div className="ac-v3-builder__channels">
                        <div>
                            <button
                                type="button"
                                onClick={() => onUpdateModulePayload(block.client_key, 'start_condition', {
                                    channels: { mode: 'selected', ids: channels.map((channel) => channel.id) },
                                })}
                            >
                                Все
                            </button>
                            <button
                                type="button"
                                onClick={() => onUpdateModulePayload(block.client_key, 'start_condition', {
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
                                    onChange={(event) => onUpdateStartChannels(block.client_key, channel.id, event.target.checked)}
                                />
                                <span>{channel.name}</span>
                            </label>
                        ))}
                    </div>
                </section>
            ) : null}

            {message ? (
                <section>
                    <span>Сообщение</span>
                    <textarea
                        value={message.payload?.text ?? ''}
                        onChange={(event) => onUpdateModulePayload(block.client_key, 'message', { text: event.target.value })}
                    />
                </section>
            ) : null}

            {buttons ? (
                <section>
                    <span>Кнопки</span>
                    <div className="ac-v3-builder__buttons-editor">
                        {flatButtons(buttons).map((button) => {
                            const connected = edges.some((edge) => edge.source?.client_key === block.client_key && edge.source?.output_id === button.id);
                            const output = { id: button.id, label: button.text || button.id };

                            return (
                                <div key={button.id}>
                                    <input
                                        value={button.text}
                                        onChange={(event) => onUpdateButton(block.client_key, button.id, { text: event.target.value })}
                                    />
                                    <span className={connected ? 'is-connected' : ''}>{connected ? 'связь' : 'без связи'}</span>
                                    <button type="button" title="Связать кнопку с блоком" onClick={() => onStartPanelConnection(block, output)}>
                                        Связь
                                    </button>
                                    <button type="button" title="Удалить кнопку" onClick={() => onRemoveButton(block.client_key, button.id)}>×</button>
                                </div>
                            );
                        })}
                    </div>
                    <button type="button" onClick={() => onAddButton(block.client_key)}>Добавить кнопку</button>
                </section>
            ) : null}

            <section>
                <button type="button" className="ac-v3-builder__danger" onClick={() => onRemoveBlock(block.client_key)}>
                    Удалить блок
                </button>
            </section>
        </div>
    );
}

function ModuleSwitch({ type, checked, disabled, onChange }) {
    const meta = MODULE_META[type];

    return (
        <label className={`ac-v3-builder__module-switch ${meta.className} ${checked ? 'is-on' : ''}`}>
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
            />
            <b>{meta.short}</b>
            <span>{meta.label}</span>
        </label>
    );
}

function EdgePanel({ edge, blocks, onRemove }) {
    const source = blocks.find((block) => block.client_key === edge.source?.client_key);
    const target = blocks.find((block) => block.client_key === edge.target?.client_key);

    return (
        <div className="ac-v3-builder__inspector">
            <div className="ac-v3-builder__panel-head">
                <span>Свойства связи</span>
                <strong>{source?.title ?? 'Источник'} → {target?.title ?? 'Цель'}</strong>
            </div>
            <section>
                <span>Условие</span>
                <p className="ac-v3-builder__readonly">{edge.condition_payload?.label || 'Дальше'}</p>
                <button type="button" className="ac-v3-builder__danger" onClick={onRemove}>Удалить связь</button>
            </section>
        </div>
    );
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
        }));
    }

    const buttons = findModule(block?.settings_payload, 'buttons');
    const buttonOutputs = flatButtons(buttons).map((button) => ({
        id: button.id,
        label: button.text || button.id,
    }));

    if (buttonOutputs.length > 0) {
        return buttonOutputs;
    }

    return [{ id: null, label: 'Дальше' }];
}

function outputAnchor(block, outputId) {
    const position = blockPosition(block);
    const outputs = blockOutputs(block);
    const index = Math.max(0, outputs.findIndex((output) => output.id === outputId));

    return {
        x: position.x + PORT_DOT_CENTER_X,
        y: position.y + portsTopOffset(block) + (index * (PORT_ROW_HEIGHT + PORT_ROW_GAP)) + (PORT_ROW_HEIGHT / 2),
    };
}

function inputAnchor(block) {
    const position = blockPosition(block);

    return {
        x: position.x - 2,
        y: position.y + NODE_HEADER_HEIGHT + 34,
    };
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
        return { minX: 0, minY: 0, width: 1800, height: 1100 };
    }

    const xs = blocks.map((block) => blockPosition(block).x);
    const ys = blocks.map((block) => blockPosition(block).y);
    const maxX = Math.max(...xs) + NODE_WIDTH + 420;
    const maxY = Math.max(...ys) + 520;

    return {
        minX: Math.min(...xs),
        minY: Math.min(...ys),
        width: Math.max(1800, maxX),
        height: Math.max(1100, maxY),
    };
}

function normalizeSettings(settingsPayload) {
    return {
        schema_version: 3,
        kind: 'state',
        ui: settingsPayload?.ui ?? { sheet_id: 'main', width: 320, collapsed: false },
        modules: sortModules(modulesFrom(settingsPayload)),
        outputs: Array.isArray(settingsPayload?.outputs) ? settingsPayload.outputs : [],
    };
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
                match: 'strict',
                variable: '',
                exclude: '',
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
                rows: [[{ id: 'btn_catalog', text: 'Получить каталог', fn: 'default', url: null, color: null }]],
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

function edgePayload(outputId, label) {
    return {
        schema_version: 3,
        from_output_id: outputId,
        label,
        match: {
            type: 'strict',
            variable: 'last_user_message',
            value: outputId ? label : '',
            ignore_strings: [],
        },
        delay: {
            value: 0,
            unit: 'sec',
            cancel_if_left: false,
            cancel_timed: true,
            no_cancel: false,
            send_time: null,
            send_date: null,
            send_if_date_passed: true,
        },
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

function moduleLabel(type) {
    return MODULE_META[type]?.label ?? type;
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

function snap(value) {
    return Math.round(value / 8) * 8;
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
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
