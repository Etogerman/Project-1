import React, { useEffect, useMemo, useState } from 'react';
import { loadScenarioBuilderState, saveScenarioBuilderState } from './api.js';

const MAIN_SHEET = {
    id: 'main',
    name: 'Главный',
    color: 'none',
    view: { tx: 0, ty: 0, zoom: 1 },
};

export default function App({ stateUrl, saveUrl, csrfToken }) {
    const [state, setState] = useState(null);
    const [status, setStatus] = useState('loading');
    const [error, setError] = useState(null);
    const [isSaving, setIsSaving] = useState(false);

    useEffect(() => {
        let isMounted = true;

        setStatus('loading');
        setError(null);

        loadScenarioBuilderState(stateUrl)
            .then((data) => {
                if (! isMounted) {
                    return;
                }

                setState(data);
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

    const blocks = state?.builder?.blocks ?? [];
    const edges = state?.builder?.edges ?? [];
    const revision = state?.builder?.revision ?? null;
    const canSave = state?.permissions?.can_update === true && status === 'ready' && ! isSaving;

    const sortedBlocks = useMemo(
        () => [...blocks].sort((left, right) => blockPosition(left).x - blockPosition(right).x),
        [blocks],
    );

    function updateBuilder(nextBuilder) {
        setState((current) => ({
            ...current,
            builder: {
                ...current.builder,
                ...nextBuilder,
            },
        }));
    }

    function addMessageBlock() {
        const index = blocks.length + 1;
        const clientKey = `tmp_block_${Date.now()}`;

        updateBuilder({
            blocks: [
                ...blocks,
                {
                    id: null,
                    client_key: clientKey,
                    type: 'state',
                    title: `Сообщение ${index}`,
                    position: { x: 120 + index * 32, y: 120 + index * 24 },
                    settings_payload: messageSettingsPayload('Новый текст сообщения'),
                },
            ],
        });
    }

    function updateBlockTitle(clientKey, title) {
        updateBuilder({
            blocks: blocks.map((block) => (
                block.client_key === clientKey ? { ...block, title } : block
            )),
        });
    }

    function updateBlockMessage(clientKey, text) {
        updateBuilder({
            blocks: blocks.map((block) => (
                block.client_key === clientKey
                    ? { ...block, settings_payload: replaceMessageText(block.settings_payload, text) }
                    : block
            )),
        });
    }

    function removeBlock(clientKey) {
        updateBuilder({
            blocks: blocks.filter((block) => block.client_key !== clientKey),
            edges: edges.filter((edge) => edge.source?.client_key !== clientKey && edge.target?.client_key !== clientKey),
        });
    }

    async function save() {
        if (! state) {
            return;
        }

        setIsSaving(true);
        setError(null);

        try {
            const response = await saveScenarioBuilderState(saveUrl, csrfToken, {
                draft_version_id: state.scenario.draft_version_id,
                base_revision: state.builder.revision,
                builder: {
                    schema_version: 3,
                    active_sheet_id: state.builder.active_sheet_id || 'main',
                    sheets: state.builder.sheets?.length ? state.builder.sheets : [MAIN_SHEET],
                    blocks,
                    edges,
                    visible_scope: state.builder.visible_scope || { block_ids: [], edge_ids: [] },
                },
            });

            setState(response);
            setStatus('ready');
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
                <div className="ac-v3-builder__state">Загрузка конструктора...</div>
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
            <header className="ac-v3-builder__header">
                <div>
                    <span>V3-конструктор</span>
                    <strong>{state?.scenario?.name ?? 'Конструктор'}</strong>
                    <small>revision: {revision}</small>
                </div>
                <div className="ac-v3-builder__actions">
                    <button type="button" className="ac-v3-builder__button" onClick={addMessageBlock}>
                        Добавить сообщение
                    </button>
                    <button
                        type="button"
                        className="ac-v3-builder__button ac-v3-builder__button--primary"
                        disabled={! canSave}
                        onClick={save}
                    >
                        {isSaving ? 'Сохранение...' : 'Сохранить V3'}
                    </button>
                </div>
            </header>

            {error ? (
                <div className="ac-v3-builder__notice" data-kind={status === 'conflict' ? 'conflict' : 'error'}>
                    {error}
                </div>
            ) : null}

            <div className="ac-v3-builder__workspace">
                <div className="ac-v3-builder__canvas">
                    {sortedBlocks.length === 0 ? (
                        <div className="ac-v3-builder__empty">На листе пока нет блоков.</div>
                    ) : sortedBlocks.map((block) => (
                        <article
                            className="ac-v3-builder__node"
                            key={block.client_key}
                            style={{
                                left: `${blockPosition(block).x}px`,
                                top: `${blockPosition(block).y}px`,
                            }}
                        >
                            <strong>{block.title}</strong>
                            <span>{moduleLabels(block).join(', ') || 'state'}</span>
                        </article>
                    ))}
                </div>

                <aside className="ac-v3-builder__panel">
                    <div className="ac-v3-builder__metrics">
                        <span>Блоки: {blocks.length}</span>
                        <span>Связи: {edges.length}</span>
                    </div>

                    <div className="ac-v3-builder__block-list">
                        {blocks.map((block) => (
                            <div className="ac-v3-builder__block-editor" key={block.client_key}>
                                <label>
                                    <span>Название</span>
                                    <input
                                        value={block.title}
                                        onChange={(event) => updateBlockTitle(block.client_key, event.target.value)}
                                    />
                                </label>
                                <label>
                                    <span>Сообщение</span>
                                    <textarea
                                        value={messageText(block.settings_payload)}
                                        onChange={(event) => updateBlockMessage(block.client_key, event.target.value)}
                                    />
                                </label>
                                <button type="button" onClick={() => removeBlock(block.client_key)}>
                                    Удалить
                                </button>
                            </div>
                        ))}
                    </div>
                </aside>
            </div>
        </section>
    );
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

function moduleLabels(block) {
    return (block?.settings_payload?.modules ?? []).map((module) => module.type);
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

function messageText(settingsPayload) {
    return settingsPayload?.modules?.find((module) => module.type === 'message')?.payload?.text ?? '';
}

function replaceMessageText(settingsPayload, text) {
    const modules = settingsPayload?.modules ?? [];
    const hasMessageModule = modules.some((module) => module.type === 'message');
    const nextModules = hasMessageModule
        ? modules.map((module) => (
            module.type === 'message'
                ? { ...module, payload: { ...module.payload, text } }
                : module
        ))
        : [
            ...modules,
            {
                id: 'mod_message',
                type: 'message',
                enabled: true,
                payload: { text, text_format: 'plain_text' },
            },
        ];

    return {
        schema_version: 3,
        kind: 'state',
        ui: settingsPayload?.ui ?? { sheet_id: 'main', width: 320, collapsed: false },
        modules: nextModules,
        outputs: settingsPayload?.outputs ?? [],
    };
}
