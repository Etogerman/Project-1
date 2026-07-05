import assert from 'node:assert/strict';
import test from 'node:test';

import { createServer } from 'vite';

let viteServer;

test.before(async () => {
    viteServer = await createServer({
        appType: 'custom',
        logLevel: 'error',
        optimizeDeps: {
            entries: [],
            noDiscovery: true,
        },
        server: {
            middlewareMode: true,
        },
    });
});

test.after(async () => {
    await viteServer?.close();
});

test('keeps legacy contact phone write action as legacy payload', async () => {
    const { normalizeActionItemForType } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const normalized = normalizeActionItemForType({
        type: 'write_contact_field',
        source_type: 'static_value',
        static_value: '+7 999 111-22-33',
        target_scope: 'contact',
        target_field: 'phone',
    });

    assert.equal(normalized.type, 'write_contact_field');
    assert.equal(normalized.source_type, 'static_value');
    assert.equal(normalized.static_value, '+7 999 111-22-33');
    assert.equal(normalized.target_scope, 'contact');
    assert.equal(normalized.target_field, 'phone');
    assert.equal(normalized.value_source, undefined);
    assert.equal(normalized.manual_value, undefined);
});

test('keeps legacy complete data collection action without field payload', async () => {
    const { normalizeActionItemForType } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const normalized = normalizeActionItemForType({
        type: 'complete_data_collection',
        target_field: 'first_name',
        operation: 'contact_sync',
    });

    assert.deepEqual(normalized, { type: 'complete_data_collection' });
});

test('auto reply table keeps start blocks sorted by priority and display id', async () => {
    const { autoReplyBlocksForBuilder, compareAutoReplyBlocks } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const startBlock = (clientKey, displayNumber, priority, enabled = true) => ({
        client_key: clientKey,
        settings_payload: {
            ui: { display_number: displayNumber },
            modules: [
                {
                    type: 'start_condition',
                    enabled,
                    payload: { priority },
                },
            ],
        },
    });
    const rows = autoReplyBlocksForBuilder([
        { client_key: 'message_only', settings_payload: { modules: [{ type: 'message', payload: { text: 'Нет старта' } }] } },
        startBlock('older_same_priority', '18', 10),
        startBlock('higher_priority', '3', 20),
        startBlock('newer_same_priority', '20', 10, false),
    ]).sort(compareAutoReplyBlocks);

    assert.deepEqual(rows.map((block) => block.client_key), [
        'higher_priority',
        'newer_same_priority',
        'older_same_priority',
    ]);
});

test('auto reply table filters by search status channel match sheet and continuation', async () => {
    const { autoReplyTableView } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const startBlock = ({
        clientKey,
        displayNumber,
        title,
        priority = 10,
        match = 'exact_keyword',
        command = '',
        text = '',
        sheetId = 'main',
        channelIds = [],
        enabled = true,
    }) => ({
        client_key: clientKey,
        title,
        settings_payload: {
            ui: { display_number: displayNumber, sheet_id: sheetId },
            modules: [
                {
                    type: 'start_condition',
                    enabled,
                    payload: { priority, match, command, channels: { ids: channelIds } },
                },
                {
                    type: 'message',
                    payload: { text },
                },
            ],
        },
    });
    const context = {
        sheets: [
            { id: 'main', name: 'Главный' },
            { id: 'sheet-2', name: 'Анкета' },
        ],
        channels: [
            { id: 1, name: 'Telegram' },
            { id: 2, name: 'MAX' },
        ],
        edges: [
            { source: { client_key: 'with_continuation' } },
        ],
    };
    const blocks = [
        startBlock({ clientKey: 'main', displayNumber: 1, title: 'Главный старт', command: '/start', text: 'Привет', channelIds: [1] }),
        startBlock({
            clientKey: 'book',
            displayNumber: 2,
            title: 'Книга',
            match: 'contains_text',
            command: 'книга',
            text: 'Материал для книги',
            sheetId: 'sheet-2',
            channelIds: [2],
            enabled: false,
        }),
        startBlock({
            clientKey: 'with_continuation',
            displayNumber: 3,
            title: 'Продолжение',
            match: 'contains_text',
            command: 'курс',
            text: 'Есть следующий шаг',
            sheetId: 'sheet-2',
            channelIds: [2],
        }),
    ];

    const filtered = autoReplyTableView(blocks, context, {
        query: 'книга',
        status: 'inactive',
        channelId: '2',
        match: 'contains_text',
        sheetId: 'sheet-2',
        continuation: 'no',
    });

    assert.deepEqual(filtered.rows.map((row) => row.block.client_key), ['book']);
    assert.equal(filtered.rangeLabel, '1-1 из 1, всего 3');

    const withContinuation = autoReplyTableView(blocks, context, {
        status: 'active',
        channelId: '2',
        sheetId: 'sheet-2',
        continuation: 'yes',
    });

    assert.deepEqual(withContinuation.rows.map((row) => row.block.client_key), ['with_continuation']);
});

test('publish issue links validation error to block across sheets', async () => {
    const { publishIssueFromError } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const issue = publishIssueFromError({
        status: 422,
        data: {
            errors: {
                'builder.telegram_account_gateway': [
                    'Telegram Account Gateway пока поддерживает только текстовые V3-сообщения без кнопок. Проверьте блок #24.',
                ],
            },
        },
    }, [
        {
            id: 155,
            client_key: 'block_155',
            title: 'Старт 1',
            settings_payload: { ui: { display_number: '1', sheet_id: 'sheet_1' } },
        },
        {
            id: 2469,
            client_key: 'block_2469',
            title: 'Старт: нет номера',
            settings_payload: { ui: { display_number: '24', sheet_id: 'main' } },
        },
    ], [
        { id: 'main', name: 'Главный' },
        { id: 'sheet_1', name: 'Лист 1' },
    ]);

    assert.equal(issue.blockKey, 'block_2469');
    assert.equal(issue.blockLabel, '#24');
    assert.equal(issue.blockTitle, 'Старт: нет номера');
    assert.equal(issue.sheetId, 'main');
    assert.equal(issue.sheetName, 'Главный');
});

test('auto reply table sorts paginates and normalizes visible columns', async () => {
    const { autoReplyTableView } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const startBlock = (clientKey, title, displayNumber) => ({
        client_key: clientKey,
        title,
        settings_payload: {
            ui: { display_number: displayNumber },
            modules: [
                {
                    type: 'start_condition',
                    payload: { priority: 10, match: 'exact_keyword', command: title },
                },
            ],
        },
    });
    const blocks = Array.from({ length: 26 }, (_, index) => {
        const number = index + 1;

        return startBlock(`block_${number}`, `Блок ${String(number).padStart(2, '0')}`, number);
    }).reverse();
    const view = autoReplyTableView(blocks, {}, {
        sortKey: 'block',
        sortDirection: 'asc',
        pageSize: 25,
        page: 2,
        visibleColumns: ['block', 'open', 'unknown'],
    });

    assert.equal(view.page, 2);
    assert.equal(view.pageCount, 2);
    assert.deepEqual(view.rows.map((row) => row.block.client_key), ['block_26']);
    assert.deepEqual(view.visibleColumnIds, ['block', 'open']);
    assert.equal(view.rangeLabel, '26-26 из 26');
});

test('auto reply table keeps custom column order', async () => {
    const { autoReplyTableView } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const view = autoReplyTableView([], {}, {
        columnOrder: ['text', 'block', 'channels', 'open'],
        visibleColumns: ['block', 'text', 'open'],
    });

    assert.deepEqual(view.visibleColumnIds, ['text', 'block', 'open']);
    assert.deepEqual(view.visibleColumns.map((column) => column.id), ['text', 'block', 'open']);
});

test('auto reply table counts active filters separately from search and columns', async () => {
    const { autoReplyTableActiveFilterCount } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');

    assert.equal(autoReplyTableActiveFilterCount({
        query: 'маркетолог',
        visibleColumns: ['block'],
    }), 0);

    assert.equal(autoReplyTableActiveFilterCount({
        status: 'active',
        channelId: '2',
        match: 'contains_text',
        sheetId: 'sheet-2',
        continuation: 'yes',
    }), 5);
});

test('auto reply table resets filters without resetting search or view settings', async () => {
    const { autoReplyTableSettingsWithoutActiveFilters } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const settings = {
        query: 'маркетолог',
        status: 'active',
        channelId: '2',
        match: 'contains_text',
        sheetId: 'sheet-2',
        continuation: 'yes',
        sortKey: 'block',
        sortDirection: 'asc',
        pageSize: 50,
        page: 3,
        columnOrder: ['text', 'block', 'channels', 'open'],
        visibleColumns: ['text', 'block', 'open'],
    };

    const filtersOnly = autoReplyTableSettingsWithoutActiveFilters(settings);

    assert.equal(filtersOnly.query, 'маркетолог');
    assert.equal(filtersOnly.status, 'all');
    assert.equal(filtersOnly.channelId, 'all');
    assert.equal(filtersOnly.match, 'all');
    assert.equal(filtersOnly.sheetId, 'all');
    assert.equal(filtersOnly.continuation, 'all');
    assert.equal(filtersOnly.sortKey, 'block');
    assert.equal(filtersOnly.sortDirection, 'asc');
    assert.equal(filtersOnly.pageSize, 50);
    assert.equal(filtersOnly.page, 1);
    assert.deepEqual(filtersOnly.visibleColumns, ['text', 'block', 'open']);

    const searchAndFilters = autoReplyTableSettingsWithoutActiveFilters(settings, { clearQuery: true });

    assert.equal(searchAndFilters.query, '');
    assert.deepEqual(searchAndFilters.visibleColumns, ['text', 'block', 'open']);
});

test('new builder block is placed near the visible canvas center on large sheets', async () => {
    const { newBlockPositionForVisibleCanvas } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const position = newBlockPositionForVisibleCanvas(
        { tx: -1200, ty: -800, zoom: 1 },
        { width: 800, height: 600 },
        { settings_payload: { modules: [] } },
        129,
    );

    assert.deepEqual(position, { x: 1488, y: 1008 });
});

test('stored builder workspace restores active sheet and sheet views only for existing sheets', async () => {
    const { builderWithStoredWorkspace } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const builder = {
        active_sheet_id: 'main',
        sheets: [
            { id: 'main', name: 'Главный', view: { tx: 0, ty: 0, zoom: 1 } },
            { id: 'sheet-2', name: 'Лист 2', view: { tx: 10, ty: 20, zoom: 1 } },
        ],
        blocks: [],
        edges: [],
    };
    const restored = builderWithStoredWorkspace(builder, {
        active_sheet_id: 'sheet-2',
        views: {
            main: { tx: -100.4, ty: 88.6, zoom: 0.2 },
            'sheet-2': { tx: -320.2, ty: -240.8, zoom: 1.35 },
            deleted: { tx: 999, ty: 999, zoom: 1 },
        },
    });

    assert.equal(restored.active_sheet_id, 'sheet-2');
    assert.deepEqual(restored.sheets.map((sheet) => [sheet.id, sheet.view]), [
        ['main', { tx: -100, ty: 89, zoom: 0.35 }],
        ['sheet-2', { tx: -320, ty: -241, zoom: 1.35 }],
    ]);
});

test('stored builder workspace restores selection only when it still exists on the active sheet', async () => {
    const { selectedBuilderItemFromStoredWorkspace } = await viteServer.ssrLoadModule('/resources/js/scenario-builder-v3/App.jsx');
    const builder = {
        active_sheet_id: 'sheet-2',
        sheets: [
            { id: 'main', name: 'Главный' },
            { id: 'sheet-2', name: 'Лист 2' },
        ],
        blocks: [
            { client_key: 'main-block', settings_payload: { ui: { sheet_id: 'main' } } },
            { client_key: 'sheet-block', settings_payload: { ui: { sheet_id: 'sheet-2' } } },
        ],
        edges: [],
    };

    assert.deepEqual(
        selectedBuilderItemFromStoredWorkspace(builder, {
            selection: { type: 'block', key: 'sheet-block', panel_collapsed: false },
        }),
        { blockKey: 'sheet-block', edgeKey: null, panelCollapsed: false },
    );
    assert.deepEqual(
        selectedBuilderItemFromStoredWorkspace(builder, {
            selection: { type: 'block', key: 'main-block', panel_collapsed: false },
        }),
        { blockKey: null, edgeKey: null, panelCollapsed: false },
    );
    assert.deepEqual(
        selectedBuilderItemFromStoredWorkspace(builder, {
            selection: { type: null, key: null, panel_collapsed: false },
        }),
        { blockKey: null, edgeKey: null, panelCollapsed: false },
    );
});
