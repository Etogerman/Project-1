import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
    applyScenarioBuilderSheetImport,
    createScenarioBuilderAutoReplyImportTag,
    exportScenarioBuilderSheet,
    loadScenarioBuilderState,
    previewScenarioBuilderAutoReplyImport,
    previewScenarioBuilderSheetImport,
    publishScenarioBuilderState,
    saveScenarioBuilderState,
} from './api.js';

const MAIN_SHEET = {
    id: 'main',
    name: 'Главный',
    color: 'none',
    view: { tx: 120, ty: 88, zoom: 1 },
};
const SHEET_COLORS = [
    ['none', 'Без цвета'],
    ['blue', 'Синий'],
    ['green', 'Зелёный'],
    ['yellow', 'Жёлтый'],
    ['red', 'Красный'],
    ['purple', 'Фиолетовый'],
    ['teal', 'Бирюзовый'],
    ['gray', 'Серый'],
];
const AUTO_REPLY_IMPORT_PLACEMENTS = [
    ['single_sheet', 'Один новый лист'],
    ['current_sheet', 'Текущий лист'],
    ['by_category', 'По категориям'],
];
const AUTO_REPLY_IMPORT_DEFAULT_PLACEMENT = 'single_sheet';
const AUTO_REPLY_IMPORT_TYPE = 'auto_reply_rule_xlsx';
const MAX_VISIBLE_SHEET_TABS = 8;

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
const MODULE_TYPE_CALCULATOR = 'calculator';
const MODULE_ORDER = ['start_condition', 'message', 'buttons', 'ai', 'action'];
const MODULE_DISPLAY_ORDER = ['start_condition', 'message', 'buttons', 'ai', 'action', MODULE_TYPE_CALCULATOR];
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
    ['exact_parameter', 'Точный параметр'],
    ['exact_text_or_parameter', 'Текст или параметр'],
    ['exact_callback', 'Точный callback'],
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
const EDGE_FIELD_CONDITION_OPERATOR_OPTIONS = [
    ['filled', 'Заполнено'],
    ['empty', 'Не заполнено'],
    ['equals', 'Равно'],
    ['not_equals', 'Не равно'],
];
const EDGE_CONTACT_FIELD_OPTIONS = [
    ['phone', 'Телефон', 'phone'],
    ['first_name', 'Имя', 'any_text'],
    ['last_name', 'Фамилия', 'any_text'],
    ['country', 'Страна', 'any_text'],
    ['region', 'Регион', 'any_text'],
    ['city', 'Город', 'any_text'],
    ['gender', 'Пол', 'any_text'],
    ['age_years', 'Возраст', 'number'],
    ['age_range', 'Возрастной диапазон', 'any_text'],
];
const EDGE_CONTACT_CONDITION_FIELD_OPTIONS = [
    ...EDGE_CONTACT_FIELD_OPTIONS,
    ['first_name_source', 'Откуда знаем имя', 'any_text'],
];
const ACTION_TYPE_CHANGE_FIELD = 'change_field';
const ACTION_TYPE_WRITE_CONTACT_FIELD = 'write_contact_field';
const ACTION_TYPE_CHECK_DATA = 'check_data';
const ACTION_TYPE_EDIT_MESSAGE = 'edit_message';
const ACTION_TYPE_CALCULATE_DISTANCE_TO_MOSCOW = 'calculate_distance_to_moscow';
const ACTION_TYPE_RESOLVE_GEO_CITY = 'resolve_geo_city';
const ACTION_TYPE_VARIABLES = 'variables';
const ACTION_TYPE_SIMULATE_START_PARAMETER = 'simulate_start_parameter';
const ACTION_TYPE_TAG_EFFECTS = 'tag_effects';
const ACTION_TYPE_BITRIX24_SYNC = 'bitrix24_sync';
const BITRIX24_SYNC_OPERATION_OPTIONS = [
    ['contact_sync', 'Синхронизировать контакт'],
    ['deal_sync', 'Синхронизировать сделку'],
    ['history_export', 'Выгрузить историю переписки'],
    ['contact_sync_with_followups', 'Синхронизировать контакт и последующие Bitrix-задачи'],
];
const BITRIX24_SYNC_OPERATION_HELP = {
    contact_sync: [
        'Синхронизировать контакт',
        'Ставит в очередь обновление карточки контакта в Bitrix24: имя, телефон и другие собранные поля.',
        'Выбирайте после анкеты или изменения данных клиента.',
        'Не создаёт сделку и не выгружает переписку.',
    ].join('\n'),
    deal_sync: [
        'Синхронизировать сделку',
        'Ставит в очередь обновление или создание сделки по текущей Bitrix24-логике проекта.',
        'Выбирайте, когда клиент уже должен попасть в CRM-процесс по сделке.',
        'Не заменяет простую синхронизацию контакта.',
    ].join('\n'),
    history_export: [
        'Выгрузить историю переписки',
        'Ставит в очередь передачу сообщений диалога в Bitrix24.',
        'Выбирайте перед передачей менеджеру, чтобы в CRM был контекст общения.',
        'Не обновляет контакт и не запускает сделку.',
    ].join('\n'),
    contact_sync_with_followups: [
        'Синхронизировать контакт и последующие Bitrix-задачи',
        'Ставит в очередь синхронизацию контакта; последующие Bitrix-задачи запускает существующая логика проекта.',
        'Выбирайте после полного сбора данных, когда нужен стандартный Bitrix24-процесс.',
        'Не добавляет отдельные выходы и не ждёт результата в этом блоке.',
    ].join('\n'),
};
const ACTION_EDIT_MESSAGE_OPERATION_REMOVE_BUTTONS = 'remove_buttons';
const ACTION_EDIT_MESSAGE_OPERATION_DELETE_MESSAGE = 'delete_message';
const ACTION_EDIT_MESSAGE_TARGET_LAST_CURRENT_RUN_OUTBOUND_WITH_INLINE_BUTTONS = 'last_current_run_outbound_with_inline_buttons';
const ACTION_EDIT_MESSAGE_TARGET_LAST_CURRENT_RUN_OUTBOUND = 'last_current_run_outbound';
const ACTION_TARGET_SCOPE_OPTIONS = [
    ['contact', 'Контакт'],
    ['dialog', 'Диалог'],
];
const ACTION_VALUE_SOURCE_OPTIONS = [
    ['manual', 'Ввести вручную'],
    ['start_parameter', 'Параметр запуска'],
    ['ai_result', 'Результат ИИ'],
];
const LEGACY_WRITE_CONTACT_FIELD_SOURCE_OPTIONS = [
    ['ai_data', 'Результат ИИ'],
    ['static_value', 'Ввести вручную'],
];
const VARIABLE_SET_VALUE_SOURCE_OPTIONS = [
    ['static_value', 'Ввести вручную'],
    ['start_param', 'Параметр запуска'],
];
const VARIABLE_SET_LEGACY_VALUE_SOURCE_OPTIONS = [
    ['current_message', 'Текст сообщения'],
];
const MESSAGE_VARIABLE_TEXT_OPERATORS = [
    ['eq', '='],
    ['gt', '>'],
    ['gte', '>='],
    ['lt', '<'],
    ['lte', '<='],
];
const GEO_CITY_SOURCE_OPTIONS = [
    ['current_inbound_message', 'Последний ответ клиента'],
    ['ai_data', 'Данные из ИИ-анализа'],
];
const ACTION_FIELD_VALUE_OPTIONS = {
    contact: {
        gender: [
            ['male', 'Мужской'],
            ['female', 'Женский'],
            ['unknown', 'Непонятно'],
        ],
        age_range: [
            ['under_18', 'До 18 лет'],
            ['18_23', '18 - 23 года'],
            ['24_29', '24 - 29 лет'],
            ['30_39', '30 - 39 лет'],
            ['over_40', 'Больше 40 лет'],
        ],
    },
};
const FIELD_DICTIONARY_ENTITY_CONTACT = 'contact';
const FIELD_DICTIONARY_ENTITY_DIALOG = 'dialog';
const FIELD_DICTIONARY_MISSING_LABEL = 'нет в справочнике';
const CONTACT_RUNTIME_WRITABLE_FIELD_KEYS = new Set([
    'phone',
    'first_name',
    'last_name',
    'country',
    'region',
    'city',
    'gender',
    'age_years',
    'age_range',
]);
const ACTION_CONTACT_WRITABLE_FIELD_KEYS = new Set([
    'first_name',
    'last_name',
    'country',
    'region',
    'city',
    'gender',
    'age_years',
    'age_range',
]);
const MAX_TRANSITION_ACTIONS_PER_EDGE = 5;
const TRANSITION_ACTION_TYPE_WRITE_FIELD = 'write_field';
const TRANSITION_ACTION_VALUE_SOURCE_STATIC = 'static';
const TRANSITION_CONTACT_WRITABLE_FIELD_KEYS = new Set([
    'first_name',
    'last_name',
    'country',
    'region',
    'city',
    'gender',
    'gender_source',
    'age_years',
    'age_range',
    'first_name_source',
    'first_name_resolution_method',
]);
const CONTACT_RUNTIME_CONDITION_FIELD_KEYS = new Set([
    'phone',
    'first_name',
    'first_name_source',
    'last_name',
    'country',
    'region',
    'city',
    'gender',
    'age_years',
    'age_range',
    'region_status',
    'region_source',
    'distance_to_moscow_km',
    'distance_to_moscow_status',
    'distance_to_moscow_calculated_at',
]);
const START_EXPRESSION_CONTACT_FIELD_KEYS = new Set([
    'id',
    'phone',
    'phones',
    'first_name',
    'first_name_source',
    'first_name_resolution_method',
    'last_name',
    'country',
    'city',
    'region',
    'gender',
    'gender_source',
    'birth_date',
    'age_years',
    'age_range',
    'region_status',
    'region_source',
    'distance_to_moscow_km',
    'distance_to_moscow_status',
    'distance_to_moscow_calculated_at',
    'data_collection_status',
    'data_collection_current_field',
    'data_collection_last_prompted_field',
    'data_collection_started_at',
    'data_collection_current_field_started_at',
    'data_collection_completed_at',
    'data_collection_attempts_count',
    'is_auto_reply_enabled',
    'assigned_user_id',
    'bitrix24_contact_id',
    'bitrix24_sync_status',
    'bitrix24_last_synced_at',
    'bitrix24_deal_id',
    'bitrix24_deal_sync_status',
    'bitrix24_deal_last_synced_at',
    'bitrix24_history_sync_status',
    'bitrix24_history_last_synced_at',
    'created_at',
    'updated_at',
]);
const START_EXPRESSION_DIALOG_SYSTEM_FIELD_KEYS = new Set([
    'id',
    'contact_id',
    'channel_id',
    'stage',
    'phone',
    'bot_subscription_status',
    'bot_subscription_changed_at',
    'external_chat_id',
    'bitrix24_live_chat_id',
    'bitrix24_live_status',
    'bitrix24_live_last_exported_at',
    'bitrix24_live_last_imported_at',
    'phone_confirmed_at',
    'phone_confirmed_via',
    'last_message_at',
    'last_inbound_message_at',
    'last_outbound_message_at',
    'last_message_id',
    'last_inbound_message_id',
    'last_outbound_message_id',
    'created_at',
    'updated_at',
]);
const START_EXPRESSION_EXAMPLES = [
    {
        token: '{{contact.phone}} != ""',
        label: 'Телефон контакта заполнен',
    },
    {
        token: '{{contact.phone}} == ""',
        label: 'Телефон контакта не заполнен',
    },
    {
        token: '{{dialog.phone}} != ""',
        label: 'Телефон мессенджера заполнен',
    },
    {
        token: '{{dialog.phone}} == ""',
        label: 'Телефон мессенджера не заполнен',
    },
    {
        token: '{{dialog.start_param}} == "123321"',
        label: 'Параметр запуска равен 123321',
    },
];
const FIELD_TYPE_DATA_TYPE = {
    phone: 'phone',
    email: 'email',
    number: 'number',
    text: 'any_text',
    select: 'any_text',
    boolean: 'any_text',
    date: 'any_text',
};
const ACTION_CHECK_DATA_OUTPUTS = [
    { id: 'data_found', label: 'Найдено', source: 'action', action_result_id: 'data_found' },
    {
        id: 'data_manual_required',
        label: 'Требует уточнения',
        source: 'action',
        action_result_id: 'data_manual_required',
    },
    { id: 'data_not_found', label: 'Не найдено', source: 'action', action_result_id: 'data_not_found' },
];
const ACTION_CHECK_SOURCE_OPTIONS = [
    ['current_inbound_message', 'Последний ответ клиента'],
];
const ACTION_DICTIONARY_OPTIONS = [
    ['names', 'Имена'],
];
const ACTION_DISTANCE_TO_MOSCOW_OUTPUTS = [
    { id: 'distance_resolved', label: 'Рассчитано', source: 'action', action_result_id: 'distance_resolved' },
    { id: 'distance_pending', label: 'Ждёт данных', source: 'action', action_result_id: 'distance_pending' },
    { id: 'distance_out_of_scope', label: 'Не Россия', source: 'action', action_result_id: 'distance_out_of_scope' },
    { id: 'distance_unknown', label: 'Не удалось определить', source: 'action', action_result_id: 'distance_unknown' },
    { id: 'distance_failed', label: 'Ошибка расчёта', source: 'action', action_result_id: 'distance_failed' },
];
const ACTION_GEO_CITY_OUTPUTS = [
    { id: 'geo_found', label: 'Город найден', source: 'action', action_result_id: 'geo_found' },
    { id: 'geo_manual_required', label: 'Нужно уточнить', source: 'action', action_result_id: 'geo_manual_required' },
    { id: 'geo_not_found', label: 'Город не найден', source: 'action', action_result_id: 'geo_not_found' },
];
const ACTION_GEO_CITY_LEGACY_LIMIT_OUTPUT = {
    id: 'geo_limit_reached',
    label: 'Превышено попыток',
    source: 'action',
    action_result_id: 'geo_limit_reached',
    legacy: true,
};
const ACTION_GEO_CITY_OUTPUTS_WITH_LEGACY = [
    ...ACTION_GEO_CITY_OUTPUTS,
    ACTION_GEO_CITY_LEGACY_LIMIT_OUTPUT,
];
const ACTION_VARIABLE_LEGACY_OUTPUT_IDS = new Set(['variables_done', 'variables_failed']);
const ACTION_RESULT_OUTPUTS = [
    ...ACTION_CHECK_DATA_OUTPUTS,
    ...ACTION_DISTANCE_TO_MOSCOW_OUTPUTS,
    ...ACTION_GEO_CITY_OUTPUTS,
];
const ACTION_RESULT_OUTPUTS_WITH_LEGACY = [
    ...ACTION_CHECK_DATA_OUTPUTS,
    ...ACTION_DISTANCE_TO_MOSCOW_OUTPUTS,
    ...ACTION_GEO_CITY_OUTPUTS_WITH_LEGACY,
];
const FIRST_NAME_SOURCE_CONDITION_OPTIONS = [
    ['auto', 'Авто'],
    ['contact_confirmed', 'Клиент назвал'],
    ['manual', 'Оператор'],
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
const EDGE_LABEL_MODE_AUTO = 'auto';
const EDGE_LABEL_MODE_MANUAL = 'manual';
const EDGE_CANVAS_LABEL_LIMIT = 52;
const EDGE_TOOLTIP_LABEL_LIMIT = 300;
const EDGE_MAX_WAYPOINTS = 5;
const EDGE_WAYPOINT_ID_LIMIT = 40;
const PANEL_WIDTH_STORAGE_KEY = 'scenario-builder-v3-panel-width';
const PANEL_WIDTH_DEFAULT = 420;
const PANEL_WIDTH_MIN = 320;
const PANEL_WIDTH_MAX = 620;
const DIALOG_FIELD_KEY_PATTERN = /^(?!_)\p{L}[\p{L}\p{N}_]{0,63}$/u;
const RESERVED_DIALOG_FIELD_KEYS = new Set(['__proto__', 'constructor', 'prototype']);
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
    ['has_phone', 'Заполнен'],
    ['missing_phone', 'Не заполнен'],
];
const BUTTON_TYPE_TEXT = 'text';
const BUTTON_TYPE_REQUEST_PHONE = 'request_phone';
const BUTTON_TYPE_LINK = 'link';
const BUTTON_TYPE_OPTIONS = [
    [BUTTON_TYPE_TEXT, 'Текстовая'],
    [BUTTON_TYPE_REQUEST_PHONE, 'Запросить телефон'],
    [BUTTON_TYPE_LINK, 'Ссылка'],
];
const BUTTON_PLACEMENT_AUTO = 'auto';
const BUTTON_PLACEMENT_REPLY = 'reply_keyboard';
const BUTTON_PLACEMENT_INLINE = 'inline_message';
const BUTTON_PLACEMENT_OPTIONS = [
    [BUTTON_PLACEMENT_AUTO, 'Авто'],
    [BUTTON_PLACEMENT_REPLY, 'Клавиатура'],
    [BUTTON_PLACEMENT_INLINE, 'В сообщении'],
];
let activeFieldDictionary = null;
const REQUEST_PHONE_BUTTON_TEXT = 'Поделиться номером телефона';
const BUTTON_COLOR_OPTIONS = [
    [null, 'Без цвета', null],
    ['blue', 'Синий', '#2ea3db'],
    ['red', 'Красный', '#ef3d3d'],
    ['green', 'Зелёный', '#43a047'],
];
const DEFAULT_AI_PROMPT = 'Проанализируй данные:\n{{input.client_messages}}\nВыбери ID одного варианта результата.';
const DEFAULT_AI_RETRY_DELAY_SECONDS = 10;
const DEFAULT_AI_VARIANTS = [
    { id: '1', label: 'Имя найдено', delay_seconds: 0 },
    { id: '2', label: 'Имя не найдено', delay_seconds: DEFAULT_AI_RETRY_DELAY_SECONDS },
];
const AI_FAILED_OUTPUT = {
    id: 'ai_failed',
    label: 'Ошибка ИИ',
    source: 'ai',
    module_id: 'mod_ai',
    ai_variant_id: 'ai_failed',
    ai_choice_id: null,
    system: true,
};
const AI_EXTRACT_FIELD_TYPE_OPTIONS = [
    ['text', 'Текст'],
    ['number', 'Число'],
];
const DEFAULT_AI_EXTRACT_FIELDS = [
    {
        key: 'first_name',
        label: 'Имя клиента',
        type: 'text',
    },
];
const AI_PROMPT_VARIABLE_GROUPS = [
    {
        title: 'Входящие сообщения',
        items: [
            {
                token: '{{input.current_message}}',
                label: 'Последнее сообщение клиента',
                source: 'Сообщение, которое запустило блок ИИ.',
                type: 'Текст',
            },
            {
                token: '{{input.client_messages}}',
                label: 'Пакет сообщений клиента',
                source: 'Все сообщения клиента после предыдущего сообщения бота.',
                type: 'Текст, несколько строк',
            },
            {
                token: '{{input.start_param}}',
                label: 'Параметр запуска',
                source: 'Значение после команды /start.',
                type: 'Текст',
            },
        ],
    },
    {
        title: 'Карточка контакта',
        items: [
            {
                token: '{{contact.gender|unknown}}',
                label: 'Пол',
                source: 'Поле “Пол” в карточке контакта.',
                type: 'male / female / unknown',
            },
            {
                token: '{{contact.first_name}}',
                label: 'Имя',
                source: 'Поле “Имя” в карточке контакта.',
                type: 'Текст',
            },
            {
                token: '{{contact.first_name_source}}',
                label: 'Откуда знаем имя',
                source: 'Поле “Откуда знаем имя” в карточке контакта.',
                type: 'auto / contact_confirmed / manual',
            },
            {
                token: '{{contact.phone}}',
                label: 'Телефон',
                source: 'Основной телефон из карточки контакта.',
                type: 'Текст',
            },
            {
                token: '{{contact.last_name}}',
                label: 'Фамилия',
                source: 'Поле “Фамилия” в карточке контакта.',
                type: 'Текст',
            },
            {
                token: '{{contact.country}}',
                label: 'Страна',
                source: 'Поле “Страна” в карточке контакта.',
                type: 'Текст',
            },
            {
                token: '{{contact.city}}',
                label: 'Город',
                source: 'Поле “Город” в карточке контакта.',
                type: 'Текст',
            },
            {
                token: '{{contact.age_years}}',
                label: 'Возраст',
                source: 'Поле “Возраст” в карточке контакта.',
                type: 'Число',
            },
            {
                token: '{{contact.age_range}}',
                label: 'Возрастной диапазон',
                source: 'Поле “Возрастной диапазон” в карточке контакта.',
                type: 'under_18 / 18_23 / 24_29 / 30_39 / over_40',
            },
        ],
    },
    {
        title: 'Карточка диалога',
        items: [
            {
                token: '{{dialog.selected_gender}}',
                label: 'Поле диалога',
                source: 'Любое поле, которое было записано в карточку диалога действием.',
                type: 'Текст',
            },
        ],
    },
    {
        title: 'Переменные сценария',
        items: [
            {
                token: '{{variables.first_name}}',
                label: 'Переменная сценария',
                source: 'Данные, которые записал предыдущий блок проверки данных или ИИ.',
                type: 'Текст или число',
            },
        ],
    },
];

const MODULE_META = {
    start_condition: { label: 'Старт', short: 'ST', className: 'is-start' },
    message: { label: 'Сообщение', short: 'MSG', className: 'is-message' },
    buttons: { label: 'Кнопки', short: 'BTN', className: 'is-buttons' },
    ai: { label: 'ИИ-анализ', short: 'AI', className: 'is-ai' },
    action: { label: 'Действие', short: 'ACT', className: 'is-action' },
    calculator: { label: 'Калькулятор', short: 'CALC', className: 'is-calculator' },
};

const FUTURE_MODULE_META = [
    { type: 'attachment', label: 'Вложение' },
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

export default function App({
    stateUrl,
    saveUrl,
    publishUrl,
    sheetExportUrl,
    sheetImportPreviewUrl,
    sheetImportApplyUrl,
    autoReplyImportPreviewUrl,
    autoReplyImportTagStoreUrl,
    csrfToken,
}) {
    const canvasRef = useRef(null);
    const dragRef = useRef(null);
    const sheetImportFileRef = useRef(null);
    const autoReplyImportFileRef = useRef(null);
    const moreMenuRef = useRef(null);
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
    const [isExportingSheet, setIsExportingSheet] = useState(false);
    const [isImportingSheet, setIsImportingSheet] = useState(false);
    const [isApplyingSheetImport, setIsApplyingSheetImport] = useState(false);
    const [sheetImportJson, setSheetImportJson] = useState('');
    const [sheetImportPreview, setSheetImportPreview] = useState(null);
    const [sheetImportSelection, setSheetImportSelection] = useState({});
    const [sheetImportError, setSheetImportError] = useState(null);
    const [autoReplyImportFile, setAutoReplyImportFile] = useState(null);
    const [autoReplyImportPreview, setAutoReplyImportPreview] = useState(null);
    const [autoReplyImportMappings, setAutoReplyImportMappings] = useState({
        channels: {},
        tags: {},
        excludedRows: [],
        overwriteRows: [],
    });
    const [autoReplyImportPlacement, setAutoReplyImportPlacement] = useState(AUTO_REPLY_IMPORT_DEFAULT_PLACEMENT);
    const [autoReplyImportBatchId, setAutoReplyImportBatchId] = useState('');
    const [autoReplyImportError, setAutoReplyImportError] = useState(null);
    const [isImportingAutoReplies, setIsImportingAutoReplies] = useState(false);
    const [isApplyingAutoReplyImport, setIsApplyingAutoReplyImport] = useState(false);
    const [creatingAutoReplyTagName, setCreatingAutoReplyTagName] = useState('');
    const [sheetDialog, setSheetDialog] = useState(null);
    const [isSheetListOpen, setIsSheetListOpen] = useState(false);
    const [sheetListQuery, setSheetListQuery] = useState('');
    const [deleteImportDialog, setDeleteImportDialog] = useState(null);
    const [isMoreMenuOpen, setIsMoreMenuOpen] = useState(false);
    const [blockSearchQuery, setBlockSearchQuery] = useState('');
    const [blockSearchIndex, setBlockSearchIndex] = useState(0);
    const [selectedBlockKey, setSelectedBlockKey] = useState(null);
    const [selectedEdgeKey, setSelectedEdgeKey] = useState(null);
    const [selectedWaypoint, setSelectedWaypoint] = useState(null);
    const [waypointPreview, setWaypointPreview] = useState(null);
    const [isPanelCollapsed, setIsPanelCollapsed] = useState(false);
    const [pendingButtonFocus, setPendingButtonFocus] = useState(null);
    const [pendingConnection, setPendingConnection] = useState(null);
    const [pendingPublishWarning, setPendingPublishWarning] = useState(null);
    const [rewireTargetKey, setRewireTargetKey] = useState(null);
    const [anchors, setAnchors] = useState({ ports: {} });
    const [panelWidth, setPanelWidth] = useState(() => storedPanelWidth());

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
        if (
            status !== 'ready'
            || isSaving
            || isPublishing
            || isImportingSheet
            || isApplyingSheetImport
            || isImportingAutoReplies
            || isApplyingAutoReplyImport
        ) {
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
    }, [
        status,
        isSaving,
        isPublishing,
        isImportingSheet,
        isApplyingSheetImport,
        isImportingAutoReplies,
        isApplyingAutoReplyImport,
        refreshBuilderDiagnostics,
    ]);

    const builder = state?.builder ?? null;
    const allBlocks = builder?.blocks ?? [];
    const allEdges = builder?.edges ?? [];
    const channels = state?.catalogs?.channels ?? [];
    const tags = state?.catalogs?.tags ?? [];
    const fieldDictionary = useMemo(
        () => normalizeFieldDictionary(state?.catalogs?.field_dictionary),
        [state?.catalogs?.field_dictionary],
    );
    activeFieldDictionary = fieldDictionary;
    const scheduledTransitions = builder?.diagnostics?.scheduled_transitions ?? [];
    const sheets = sheetsFrom(builder);
    const activeSheet = activeSheetFrom(builder);
    const visibleSheetTabs = useMemo(() => visibleSheetsForTabs(sheets, activeSheet.id), [sheets, activeSheet.id]);
    const hiddenSheetCount = Math.max(0, sheets.length - visibleSheetTabs.length);
    const importBatches = useMemo(
        () => autoReplyImportBatches(builder),
        [builder?.blocks, builder?.sheets],
    );
    const view = activeSheet.view ?? MAIN_SHEET.view;
    const revision = builder?.revision ?? null;
    const serverClock = state?.server ?? null;
    const serverTimezone = serverClock?.timezone || '';
    const serverTimezoneLabel = serverClock?.timezone_abbr || serverClock?.utc_offset || '';
    const blocks = useMemo(() => blocksForSheet(allBlocks, activeSheet.id), [allBlocks, activeSheet.id]);
    const edges = useMemo(() => filterEdgesForBlocks(allEdges, blocks), [allEdges, blocks]);
    const selectedBlock = blocks.find((block) => block.client_key === selectedBlockKey) ?? null;
    const selectedEdge = edges.find((edge) => edge.client_key === selectedEdgeKey) ?? null;
    const dialogFieldKeys = useMemo(() => dialogFieldSuggestionsFromDictionary(fieldDictionary), [fieldDictionary]);
    const blockSearchMatches = useMemo(() => searchBlocks(blocks, blockSearchQuery), [blocks, blockSearchQuery]);
    const canSave = state?.permissions?.can_update === true
        && status === 'ready'
        && ! isSaving
        && ! isPublishing
        && ! isImportingSheet
        && ! isApplyingSheetImport
        && ! isImportingAutoReplies
        && ! isApplyingAutoReplyImport;
    const canPublish = state?.permissions?.can_publish === true
        && status === 'ready'
        && ! isSaving
        && ! isPublishing
        && ! isImportingSheet
        && ! isApplyingSheetImport
        && ! isImportingAutoReplies
        && ! isApplyingAutoReplyImport
        && Boolean(publishUrl);
    const canTransferSheet = state?.permissions?.can_update === true
        && status === 'ready'
        && ! isSaving
        && ! isPublishing
        && ! isExportingSheet
        && ! isImportingSheet
        && ! isApplyingSheetImport
        && Boolean(sheetExportUrl)
        && Boolean(sheetImportPreviewUrl)
        && Boolean(sheetImportApplyUrl);
    const canImportAutoReplies = state?.permissions?.can_update === true
        && status === 'ready'
        && ! isSaving
        && ! isPublishing
        && ! isImportingSheet
        && ! isApplyingSheetImport
        && ! isImportingAutoReplies
        && ! isApplyingAutoReplyImport
        && Boolean(autoReplyImportPreviewUrl);
    const canCreateAutoReplyTags = state?.permissions?.can_create_tags === true
        && Boolean(autoReplyImportTagStoreUrl);
    const canvasBounds = useMemo(() => graphBounds(blocks, edges), [blocks, edges]);
    const hasPanelSelection = Boolean(selectedBlock || selectedEdge);
    const isPanelOpen = mode === 'design' && hasPanelSelection && ! isPanelCollapsed;

    useEffect(() => {
        setBlockSearchIndex(0);
    }, [blockSearchQuery]);

    useEffect(() => {
        if (! isMoreMenuOpen) {
            return undefined;
        }

        const handlePointerDown = (event) => {
            if (moreMenuRef.current?.contains(event.target)) {
                return;
            }

            setIsMoreMenuOpen(false);
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                setIsMoreMenuOpen(false);
            }
        };

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [isMoreMenuOpen]);

    useEffect(() => {
        if (status !== 'ready') {
            return;
        }

        if (selectedBlockKey && blocks.some((block) => block.client_key === selectedBlockKey)) {
            return;
        }

        if (selectedEdgeKey && edges.some((edge) => edge.client_key === selectedEdgeKey)) {
            return;
        }

        setSelectedEdgeKey(null);
        setSelectedBlockKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setPendingConnection(null);
        setRewireTargetKey(null);
    }, [status, activeSheet.id, blocks, edges, selectedBlockKey, selectedEdgeKey]);

    useEffect(() => {
        if (! selectedWaypoint) {
            return;
        }

        const edge = edges.find((item) => item.client_key === selectedWaypoint.edgeKey);

        if (! edge || ! edgeWaypoints(edge).some((waypoint) => waypoint.id === selectedWaypoint.waypointId)) {
            setSelectedWaypoint(null);
        }
    }, [edges, selectedWaypoint]);

    useEffect(() => {
        if (! selectedWaypoint) {
            return undefined;
        }

        const handleKeyDown = (event) => {
            if (! ['Delete', 'Backspace'].includes(event.key) || isTextEditingTarget(event.target)) {
                return;
            }

            event.preventDefault();
            removeEdgeWaypoint(selectedWaypoint.edgeKey, selectedWaypoint.waypointId);
        };

        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [selectedWaypoint]);

    useEffect(() => {
        if (blockSearchMatches.length === 0 || blockSearchIndex < blockSearchMatches.length) {
            return;
        }

        setBlockSearchIndex(0);
    }, [blockSearchIndex, blockSearchMatches.length]);

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
        setState((current) => {
            const sheetId = activeSheetIdFrom(current?.builder);
            const currentAllBlocks = current?.builder?.blocks ?? [];
            const currentSheetBlocks = blocksForSheet(currentAllBlocks, sheetId);
            const resolvedSheetBlocks = typeof nextBlocks === 'function'
                ? nextBlocks(currentSheetBlocks)
                : nextBlocks;

            return {
                ...current,
                builder: {
                    ...current.builder,
                    blocks: [
                        ...currentAllBlocks.filter((block) => blockSheetId(block) !== sheetId),
                        ...resolvedSheetBlocks.map((block) => blockWithSheet(block, sheetId)),
                    ],
                },
            };
        });
    }

    function updateEdges(nextEdges) {
        setState((current) => {
            const sheetId = activeSheetIdFrom(current?.builder);
            const currentAllBlocks = current?.builder?.blocks ?? [];
            const currentAllEdges = current?.builder?.edges ?? [];
            const currentSheetEdges = filterEdgesForBlocks(currentAllEdges, blocksForSheet(currentAllBlocks, sheetId));
            const currentSheetEdgeKeys = new Set(currentSheetEdges.map(edgeIdentityKey));
            const resolvedSheetEdges = typeof nextEdges === 'function'
                ? nextEdges(currentSheetEdges)
                : nextEdges;
            const resolvedAllEdges = [
                ...currentAllEdges.filter((edge) => ! currentSheetEdgeKeys.has(edgeIdentityKey(edge))),
                ...resolvedSheetEdges,
            ];
            return {
                ...current,
                builder: {
                    ...current.builder,
                    blocks: currentAllBlocks,
                    edges: resolvedAllEdges,
                },
            };
        });
    }

    function updateView(nextView) {
        const sheets = (builder?.sheets?.length ? builder.sheets : [MAIN_SHEET]).map((sheet) => (
            sheet.id === activeSheet.id
                ? { ...sheet, view: typeof nextView === 'function' ? nextView(sheet.view ?? MAIN_SHEET.view) : nextView }
                : sheet
        ));

        updateBuilder({ sheets });
    }

    function switchSheet(sheetId) {
        if (! sheetId || sheetId === activeSheet.id) {
            return;
        }

        updateBuilder({ active_sheet_id: sheetId });
        setSelectedBlockKey(null);
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setPendingConnection(null);
        setRewireTargetKey(null);
        setNotice(null);
    }

    function addSheet() {
        if (! builder) {
            return;
        }

        if (sheets.length >= 20) {
            setNotice('Можно создать не больше 20 листов.');

            return;
        }

        const nextNumber = nextSheetNumberFromBuilder(builder, sheets);
        const sheetId = uniqueSheetIdForNumber(sheets, nextNumber);
        const sheetNumber = sheetNumberFromId(sheetId) ?? nextNumber;
        const nextSheet = {
            id: sheetId,
            name: `Лист ${sheetNumber}`,
            color: 'none',
            view: MAIN_SHEET.view,
        };

        updateBuilder({
            active_sheet_id: sheetId,
            sheets: [...sheets, nextSheet],
            meta: {
                ...(builder.meta ?? {}),
                next_sheet_number: sheetNumber + 1,
            },
        });
        setSelectedBlockKey(null);
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setPendingConnection(null);
        setRewireTargetKey(null);
        setNotice('Лист добавлен. Нажмите «Сохранить», чтобы записать изменение.');
    }

    function openRenameSheet(sheet) {
        if (! sheet) {
            return;
        }

        setSheetDialog({
            type: 'rename',
            sheetId: sheet.id,
            name: sheet.name || '',
            color: sheet.color || 'none',
        });
    }

    function renameSheet(sheetId, name, color = 'none') {
        const nextName = String(name ?? '').trim();
        const nextColor = SHEET_COLORS.some(([value]) => value === color) ? color : 'none';

        if (nextName === '') {
            setNotice('Название листа не может быть пустым.');

            return;
        }

        if (nextName.length > 40) {
            setNotice('Название листа должно быть до 40 символов.');

            return;
        }

        updateBuilder({
            sheets: sheets.map((sheet) => (
                sheet.id === sheetId ? { ...sheet, name: nextName, color: nextColor } : sheet
            )),
        });
        setSheetDialog(null);
        setNotice('Лист переименован. Нажмите «Сохранить», чтобы записать изменение.');
    }

    function openDeleteSheet(sheet) {
        if (! sheet || sheet.id === MAIN_SHEET.id) {
            setNotice('Главный лист удалить нельзя.');

            return;
        }

        setSheetDialog({
            type: 'delete',
            sheetId: sheet.id,
            name: sheet.name || sheet.id,
            blockCount: sheetBlockCount(allBlocks, sheet.id),
        });
    }

    function deleteSheet(sheetId) {
        if (! sheetId || sheetId === MAIN_SHEET.id) {
            return;
        }

        setState((current) => {
            const currentBuilder = current?.builder ?? {};
            const currentSheets = sheetsFrom(currentBuilder);
            const index = currentSheets.findIndex((sheet) => sheet.id === sheetId);

            if (index < 0) {
                return current;
            }

            const nextSheets = currentSheets.filter((sheet) => sheet.id !== sheetId);
            const fallbackSheet = nextSheets[Math.max(0, index - 1)] ?? nextSheets[index] ?? MAIN_SHEET;
            const currentBlocks = currentBuilder.blocks ?? [];
            const removedBlockKeys = new Set(
                currentBlocks
                    .filter((block) => blockSheetId(block) === sheetId)
                    .map((block) => block.client_key),
            );
            const nextBlocks = currentBlocks.filter((block) => ! removedBlockKeys.has(block.client_key));
            const nextEdges = (currentBuilder.edges ?? []).filter((edge) => (
                ! removedBlockKeys.has(edge.source?.client_key)
                && ! removedBlockKeys.has(edge.target?.client_key)
            ));

            return {
                ...current,
                builder: {
                    ...currentBuilder,
                    active_sheet_id: fallbackSheet.id,
                    sheets: nextSheets.length > 0 ? nextSheets : [MAIN_SHEET],
                    blocks: nextBlocks,
                    edges: nextEdges,
                },
            };
        });

        setSelectedBlockKey(null);
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setPendingConnection(null);
        setRewireTargetKey(null);
        setSheetDialog(null);
        setNotice('Лист удалён локально вместе с блоками и связями. Нажмите «Сохранить», чтобы записать изменение.');
    }

    function deleteAutoReplyImport(batchId) {
        const resolvedBatchId = String(batchId ?? '').trim();

        if (! resolvedBatchId) {
            return;
        }

        setState((current) => {
            const currentBuilder = current?.builder ?? {};
            const currentBlocks = Array.isArray(currentBuilder.blocks) ? currentBuilder.blocks : [];
            const removedBlocks = currentBlocks.filter((block) => blockImportCreatedBatchId(block) === resolvedBatchId);
            const removedBlockKeys = new Set(removedBlocks.map((block) => String(block.client_key ?? '')).filter(Boolean));
            const nextBlocks = currentBlocks.filter((block) => ! removedBlockKeys.has(String(block.client_key ?? '')));
            const nextEdges = (currentBuilder.edges ?? []).filter((edge) => (
                ! removedBlockKeys.has(String(edge?.source?.client_key ?? ''))
                && ! removedBlockKeys.has(String(edge?.target?.client_key ?? ''))
            ));
            const removableSheetIds = new Set(
                sheetsFrom(currentBuilder)
                    .filter((sheet) => sheet.id !== MAIN_SHEET.id && sheetImportCreatedBatchId(sheet) === resolvedBatchId)
                    .map((sheet) => String(sheet.id)),
            );
            const remainingBlocksBySheet = new Map();

            nextBlocks.forEach((block) => {
                const sheetId = blockSheetId(block);
                remainingBlocksBySheet.set(sheetId, (remainingBlocksBySheet.get(sheetId) ?? 0) + 1);
            });

            const nextSheets = sheetsFrom(currentBuilder).filter((sheet) => (
                ! removableSheetIds.has(String(sheet.id)) || (remainingBlocksBySheet.get(String(sheet.id)) ?? 0) > 0
            ));
            const activeSheetId = nextSheets.some((sheet) => sheet.id === currentBuilder.active_sheet_id)
                ? currentBuilder.active_sheet_id
                : MAIN_SHEET.id;

            return {
                ...current,
                builder: {
                    ...currentBuilder,
                    active_sheet_id: activeSheetId,
                    sheets: nextSheets.length > 0 ? nextSheets : [MAIN_SHEET],
                    blocks: nextBlocks,
                    edges: nextEdges,
                },
            };
        });

        setSelectedBlockKey(null);
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setPendingConnection(null);
        setRewireTargetKey(null);
        setDeleteImportDialog(null);
        setNotice('Импорт удалён локально. Нажмите «Сохранить», чтобы записать изменение.');
    }

    function selectBlock(clientKey) {
        setSelectedBlockKey(clientKey);
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setRewireTargetKey(null);
        cancelConnection();
    }

    function selectEdge(clientKey, { openPanel = false } = {}) {
        setSelectedEdgeKey(clientKey);
        setSelectedBlockKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(! openPanel);
        setPendingConnection(null);
        setRewireTargetKey(null);
    }

    function closePanelSelection() {
        setSelectedBlockKey(null);
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setRewireTargetKey(null);
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

        if (kind === 'ai') {
            block.title = `ИИ-анализ ${index}`;
            block.settings_payload = aiSettingsPayload();
        }

        updateBlocks([...blocks, blockWithSheet(block, activeSheet.id)]);
        selectBlock(clientKey);
    }

    function duplicateBlock(clientKey) {
        const sourceBlock = blocks.find((block) => block.client_key === clientKey);

        if (! sourceBlock) {
            return;
        }

        const index = blocks.length + 1;
        const duplicateKey = `tmp_block_${Date.now().toString(36)}_${index}`;
        const sourcePosition = blockPosition(sourceBlock);
        const settingsPayload = cloneBlockSettingsForCopy(sourceBlock.settings_payload);
        const duplicate = {
            ...sourceBlock,
            id: null,
            display_id: null,
            client_key: duplicateKey,
            title: `${sourceBlock.title || `Блок ${index}`} копия`,
            position: {
                x: snap(sourcePosition.x + 42),
                y: snap(sourcePosition.y + 42),
            },
            settings_payload: settingsPayload,
        };

        updateBlocks([...blocks, blockWithSheet(duplicate, activeSheet.id)]);
        selectBlock(duplicateKey);
        setNotice('Блок скопирован');
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
        setState((current) => {
            const currentBlocks = current.builder?.blocks ?? [];
            const currentEdges = current.builder?.edges ?? [];
            const nextBlocks = currentBlocks.filter((block) => block.client_key !== clientKey);

            return {
                ...current,
                builder: {
                    ...current.builder,
                    blocks: nextBlocks,
                    edges: filterEdgesForBlocks(currentEdges, nextBlocks),
                },
            };
        });
        setSelectedBlockKey((current) => (current === clientKey ? null : current));
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        cancelConnection();
        setNotice('Блок удалён');
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
        setSelectedWaypoint(null);
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
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setRewireTargetKey(null);

        window.addEventListener('pointermove', handleGlobalPointerMove);
        window.addEventListener('pointerup', stopGlobalDrag, { once: true });
    }

    function startEdgeRewire(event, edgeKey, endpoint) {
        event.preventDefault();
        event.stopPropagation();

        const edge = edges.find((item) => item.client_key === edgeKey);

        if (! edge) {
            return;
        }

        dragRef.current = {
            type: 'edge-rewire',
            edgeKey,
            endpoint,
        };

        selectEdge(edgeKey);
        setNotice(null);
        window.addEventListener('pointermove', handleGlobalPointerMove);
        window.addEventListener('pointerup', stopGlobalDrag, { once: true });
    }

    function addEdgeWaypoint(event, edgeKey, routePoints) {
        event.preventDefault();
        event.stopPropagation();

        const point = worldPointFromEvent(event);
        const edge = edges.find((item) => item.client_key === edgeKey);

        if (! point || ! edge) {
            return;
        }

        const waypoints = edgeWaypoints(edge);

        if (waypoints.length >= EDGE_MAX_WAYPOINTS) {
            setSelectedBlockKey(null);
            setSelectedEdgeKey(edgeKey);
            setSelectedWaypoint(null);
            setNotice(`На одной стрелке можно поставить до ${EDGE_MAX_WAYPOINTS} точек`);

            return;
        }

        const waypoint = {
            id: nextEdgeWaypointId(waypoints),
            x: roundWaypointCoordinate(point.x),
            y: roundWaypointCoordinate(point.y),
        };
        const insertAt = nearestRouteSegmentIndex(routePoints, point);
        const nextWaypoints = [...waypoints];

        nextWaypoints.splice(insertAt, 0, waypoint);
        updateEdges((currentEdges) => currentEdges.map((item) => (
            item.client_key === edgeKey ? edgeWithWaypoints(item, nextWaypoints) : item
        )));

        setSelectedBlockKey(null);
        setSelectedEdgeKey(edgeKey);
        setSelectedWaypoint({ edgeKey, waypointId: waypoint.id });
        setPendingConnection(null);
        setRewireTargetKey(null);
        setNotice(null);
    }

    function startEdgeWaypointDrag(event, edgeKey, waypointId) {
        event.preventDefault();
        event.stopPropagation();

        const edge = edges.find((item) => item.client_key === edgeKey);
        const waypoint = edgeWaypoints(edge).find((item) => item.id === waypointId);

        if (! waypoint) {
            return;
        }

        dragRef.current = {
            type: 'edge-waypoint',
            edgeKey,
            waypointId,
        };

        setSelectedBlockKey(null);
        setSelectedEdgeKey(edgeKey);
        setSelectedWaypoint({ edgeKey, waypointId });
        setPendingConnection(null);
        setRewireTargetKey(null);
        setNotice(null);
        setWaypointPreview({
            edgeKey,
            waypointId,
            point: { x: waypoint.x, y: waypoint.y },
        });

        window.addEventListener('pointermove', handleGlobalPointerMove);
        window.addEventListener('pointerup', stopGlobalDrag, { once: true });
    }

    function removeEdgeWaypoint(edgeKey, waypointId) {
        updateEdges((currentEdges) => currentEdges.map((edge) => {
            if (edge.client_key !== edgeKey) {
                return edge;
            }

            return edgeWithWaypoints(
                edge,
                edgeWaypoints(edge).filter((waypoint) => waypoint.id !== waypointId),
            );
        }));
        setSelectedWaypoint(null);
    }

    function resetEdgeWaypoints(edgeKey) {
        updateEdges((currentEdges) => currentEdges.map((edge) => (
            edge.client_key === edgeKey ? edgeWithWaypoints(edge, []) : edge
        )));
        setSelectedWaypoint(null);
    }

    function startPanelResize(event) {
        event.preventDefault();
        event.stopPropagation();

        const maxWidth = panelMaxWidth();

        dragRef.current = {
            type: 'panel-resize',
            start: { x: event.clientX, y: event.clientY },
            origin: {
                width: panelWidth,
                maxWidth,
            },
        };

        document.body.classList.add('ac-v3-builder-is-resizing-panel');
        window.addEventListener('pointermove', handleGlobalPointerMove);
        window.addEventListener('pointerup', stopGlobalDrag, { once: true });
    }

    function resetPanelWidth(event) {
        event.preventDefault();
        event.stopPropagation();
        setPanelWidth(PANEL_WIDTH_DEFAULT);
        savePanelWidth(PANEL_WIDTH_DEFAULT);
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

        if (drag.type === 'panel-resize') {
            const nextWidth = clamp(
                drag.origin.width - (event.clientX - drag.start.x),
                PANEL_WIDTH_MIN,
                drag.origin.maxWidth,
            );

            setPanelWidth(nextWidth);
            savePanelWidth(nextWidth);

            return;
        }

        if (drag.type === 'edge-rewire') {
            const target = drag.endpoint === 'source'
                ? sourceDropAtPointer(event)
                : blockAtPointer(event);

            setRewireTargetKey(target?.client_key ?? target?.block?.client_key ?? null);
            event.preventDefault();

            return;
        }

        if (drag.type === 'edge-waypoint') {
            const point = worldPointFromEvent(event);

            if (point) {
                setWaypointPreview({
                    edgeKey: drag.edgeKey,
                    waypointId: drag.waypointId,
                    point: {
                        x: roundWaypointCoordinate(point.x),
                        y: roundWaypointCoordinate(point.y),
                    },
                });
            }

            event.preventDefault();

            return;
        }

        updateView({
            ...drag.origin,
            tx: Math.round(drag.origin.tx + event.clientX - drag.start.x),
            ty: Math.round(drag.origin.ty + event.clientY - drag.start.y),
        });
    }

    function stopGlobalDrag(event) {
        const drag = dragRef.current;

        if (drag?.type === 'edge-rewire') {
            finishEdgeRewire(drag, event);
        }

        if (drag?.type === 'edge-waypoint') {
            finishEdgeWaypointDrag(drag, event);
        }

        dragRef.current = null;
        setWaypointPreview(null);
        setRewireTargetKey(null);
        window.removeEventListener('pointermove', handleGlobalPointerMove);
        document.body.classList.remove('ac-v3-builder-is-resizing-panel');
    }

    function worldPointFromEvent(event) {
        const rect = canvasRef.current?.getBoundingClientRect();

        if (! rect) {
            return null;
        }

        return {
            x: (event.clientX - rect.left - view.tx) / view.zoom,
            y: (event.clientY - rect.top - view.ty) / view.zoom,
        };
    }

    function blockAtPointer(event) {
        const point = worldPointFromEvent(event);

        if (! point) {
            return null;
        }

        const hit = blocks.find((block) => {
            const rect = anchors.nodes?.[block.client_key];

            if (rect) {
                return point.x >= rect.x
                    && point.x <= rect.x + rect.width
                    && point.y >= rect.y
                    && point.y <= rect.y + rect.height;
            }

            const position = blockPosition(block);

            return point.x >= position.x
                && point.x <= position.x + NODE_WIDTH
                && point.y >= position.y
                && point.y <= position.y + 220;
        });

        return hit ?? null;
    }

    function sourceDropAtPointer(event) {
        const point = worldPointFromEvent(event);

        if (! point) {
            return null;
        }

        const portHit = sourcePortAtPoint(point);

        if (portHit) {
            return portHit;
        }

        const block = blockAtPointer(event);

        if (! block) {
            return null;
        }

        return {
            block,
            output: outputAtPoint(block, point) ?? DEFAULT_OUTPUT,
        };
    }

    function sourcePortAtPoint(point) {
        const maxDistance = 22 / view.zoom;
        let hit = null;

        Object.entries(anchors.ports ?? {}).forEach(([key, anchor]) => {
            const parsed = parsePortAnchorKey(key);

            if (! parsed) {
                return;
            }

            const distance = Math.hypot(point.x - anchor.x, point.y - anchor.y);

            if (distance > maxDistance || (hit && distance >= hit.distance)) {
                return;
            }

            const block = blocks.find((item) => item.client_key === parsed.blockKey);

            if (! block) {
                return;
            }

            const output = blockOutputById(block, parsed.outputId);

            if (! output) {
                return;
            }

            hit = {
                block,
                output,
                distance,
            };
        });

        return hit ? { block: hit.block, output: hit.output } : null;
    }

    function outputAtPoint(block, point) {
        const rect = blockRect(block, anchors);

        if (
            point.x < rect.x - 28
            || point.x > rect.x + rect.width + 28
            || point.y < rect.y
            || point.y > rect.y + rect.height
        ) {
            return null;
        }

        const outputs = visibleBlockOutputs(block);

        if (outputs.length === 0) {
            return null;
        }

        const rowStep = PORT_ROW_HEIGHT + PORT_ROW_GAP;
        const top = rect.y + portsTopOffset(block);
        const index = Math.floor((point.y - top) / rowStep);
        const rowStart = top + (index * rowStep);

        if (
            index < 0
            || index >= outputs.length
            || point.y < rowStart
            || point.y > rowStart + PORT_ROW_HEIGHT
        ) {
            return null;
        }

        return outputs[index] ?? null;
    }

    function blockOutputById(block, outputId) {
        if (outputId === null) {
            return DEFAULT_OUTPUT;
        }

        return blockOutputs(block).find((output) => output.id === outputId) ?? null;
    }

    function finishEdgeRewire(drag, event) {
        const edge = edges.find((item) => item.client_key === drag.edgeKey);

        if (! edge) {
            return;
        }

        if (drag.endpoint === 'target') {
            const block = blockAtPointer(event);

            if (! block) {
                return;
            }

            if (block.client_key === edge.source?.client_key) {
                setNotice('Конец стрелки нельзя привязать к её начальному блоку.');

                return;
            }

            updateEdges((currentEdges) => currentEdges.map((item) => (
                item.client_key === edge.client_key
                    ? {
                        ...item,
                        target: {
                            block_id: block.id,
                            client_key: block.client_key,
                        },
                    }
                    : item
            )));
            selectEdge(edge.client_key);

            return;
        }

        const drop = sourceDropAtPointer(event);

        if (! drop) {
            return;
        }

        const { block, output } = drop;

        if (isReadonlyOutput(output)) {
            setNotice('Этот выход оставлен только для старых связей. Новые стрелки из него создавать нельзя.');

            return;
        }

        if (block.client_key === edge.target?.client_key) {
            setNotice('Начало стрелки нельзя привязать к её конечному блоку.');

            return;
        }

        const outputId = output.id ?? null;

        const source = {
            block_id: block.id,
            client_key: block.client_key,
            output_id: outputId,
        };

        updateEdges((currentEdges) => currentEdges
            .filter((item) => (
                item.client_key === edge.client_key
                || outputId === null
                || ! sameSource(item.source, source)
            ))
            .map((item) => (
                item.client_key === edge.client_key
                    ? {
                        ...item,
                        source,
                        condition_payload: rewiredEdgePayload(edge.condition_payload ?? {}, output),
                    }
                    : item
            )));
        selectEdge(edge.client_key);
    }

    function finishEdgeWaypointDrag(drag, event) {
        const point = worldPointFromEvent(event) ?? waypointPreview?.point;

        if (! point) {
            return;
        }

        updateEdges((currentEdges) => currentEdges.map((edge) => {
            if (edge.client_key !== drag.edgeKey) {
                return edge;
            }

            return edgeWithWaypoints(edge, edgeWaypoints(edge).map((waypoint) => (
                waypoint.id === drag.waypointId
                    ? {
                        ...waypoint,
                        x: roundWaypointCoordinate(point.x),
                        y: roundWaypointCoordinate(point.y),
                    }
                    : waypoint
            )));
        }));
        setSelectedWaypoint({ edgeKey: drag.edgeKey, waypointId: drag.waypointId });
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

    function focusBlock(block, message = null) {
        if (! block) {
            return;
        }

        const rect = blockRect(block, anchors);
        const canvasRect = canvasRef.current?.getBoundingClientRect();
        const zoom = clamp(Number(view.zoom) || 1, 0.35, 2.2);
        const viewportWidth = canvasRect?.width ?? 1100;
        const viewportHeight = canvasRect?.height ?? 720;

        setMode('design');
        setTool('select');
        setSelectedBlockKey(block.client_key);
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setPendingConnection(null);
        setRewireTargetKey(null);
        updateView({
            ...view,
            zoom,
            tx: Math.round((viewportWidth * 0.42) - (rect.x + (rect.width / 2)) * zoom),
            ty: Math.round((viewportHeight * 0.38) - (rect.y + (rect.height / 2)) * zoom),
        });

        if (message) {
            setNotice(message);
        }
    }

    function focusBlockSearchMatch(offset = 0) {
        if (String(blockSearchQuery ?? '').trim() === '') {
            setNotice('Введите номер или название блока.');

            return;
        }

        if (blockSearchMatches.length === 0) {
            setNotice('Блок не найден.');

            return;
        }

        const nextIndex = (blockSearchIndex + offset + blockSearchMatches.length) % blockSearchMatches.length;
        const block = blockSearchMatches[nextIndex];

        setBlockSearchIndex(nextIndex);
        focusBlock(
            block,
            `Найден блок ${nextIndex + 1} из ${blockSearchMatches.length}: ${shortBlockId(block)} · ${block.title || 'Без названия'}`,
        );
    }

    function beginConnection(block, output) {
        if (isReadonlyOutput(output)) {
            setNotice('Этот выход оставлен только для старых связей. Новые стрелки из него создавать нельзя.');

            return;
        }

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
        setSelectedWaypoint(null);
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
            condition_payload: edgePayload(connection.outputId, connection.label, connection.kind),
        };

        updateEdges((currentEdges) => [
            ...currentEdges.filter((item) => connection.outputId === null || ! sameSource(item.source, source)),
            edge,
        ]);
        setSelectedEdgeKey(edge.client_key);
        setSelectedBlockKey(null);
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        cancelConnection();
    }

    function cancelConnection() {
        setPendingConnection(null);
    }

    function removeEdge(edgeKey) {
        updateEdges(edges.filter((edge) => edge.client_key !== edgeKey));
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
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
        if (type === MODULE_TYPE_CALCULATOR) {
            updateBlockSettings(clientKey, (settings) => {
                const modules = modulesFrom(settings);
                const actionModule = findModule(settings, 'action');
                const regularItems = regularActionItems(actionModule);
                const calculatorItems = calculatorActionItems(actionModule);

                const nextItems = enabled
                    ? [
                        ...regularItems,
                        ...(calculatorItems.length > 0 ? calculatorItems : [normalizeActionItemForType({
                            type: ACTION_TYPE_VARIABLES,
                            operations: [defaultVariableOperation()],
                        })]),
                    ]
                    : regularItems;
                const nextModules = nextItems.length > 0
                    ? replaceActionModule(modules, actionModule, nextItems)
                    : modules.filter((module) => module.type !== 'action');

                return syncOutputs({
                    ...settings,
                    modules: sortModules(nextModules),
                });
            });

            if (! enabled) {
                updateEdges(edges.filter((edge) => (
                    edge.source?.client_key !== clientKey
                    || ! ACTION_VARIABLE_LEGACY_OUTPUT_IDS.has(edge.source?.output_id)
                )));
            }

            return;
        }

        updateBlockSettings(clientKey, (settings) => {
            let modules = modulesFrom(settings);

            if (type === 'action') {
                const actionModule = findModule(settings, 'action');
                const currentItems = existingActionItems(actionModule);
                const nextItems = enabled
                    ? (currentItems.length > 0 ? currentItems : [defaultActionItem()])
                    : [];

                modules = nextItems.length > 0
                    ? replaceActionModule(modules, actionModule, nextItems)
                    : modules.filter((module) => module.type !== 'action');
            } else if (enabled && ! modules.some((module) => module.type === type)) {
                modules = [...modules, moduleTemplate(type, channels, blocks, clientKey)];
            }

            if (! enabled && type !== 'action') {
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

            if (type === 'ai' || type === 'action') {
                next = syncOutputs(next);
            }

            return next;
        });

        if (type === 'buttons' && ! enabled) {
            updateEdges(edges.filter((edge) => ! edge.source?.output_id || edge.source?.client_key !== clientKey));
        }

        if (type === 'ai' && ! enabled) {
            const currentBlock = blocks.find((block) => block.client_key === clientKey);
            const currentAi = findModule(currentBlock?.settings_payload, 'ai');
            const aiOutputIds = new Set([
                ...aiVariantDefinitions(currentAi).map((output) => output.id),
                AI_FAILED_OUTPUT.id,
            ]);

            updateEdges(edges.filter((edge) => (
                edge.source?.client_key !== clientKey
                || ! aiOutputIds.has(edge.source?.output_id)
            )));
        }

        if (type === 'action' && ! enabled) {
            const actionOutputIds = new Set([
                ...ACTION_RESULT_OUTPUTS_WITH_LEGACY.map((output) => output.id),
                ...ACTION_VARIABLE_LEGACY_OUTPUT_IDS,
            ]);

            updateEdges(edges.filter((edge) => (
                edge.source?.client_key !== clientKey
                || ! actionOutputIds.has(edge.source?.output_id)
            )));
        }
    }

    function updateModulePayload(clientKey, type, patch) {
        updateBlockSettings(clientKey, (settings) => {
            const next = {
                ...settings,
                modules: sortModules(modulesFrom(settings).map((module) => (
                    module.type === type
                        ? { ...module, payload: { ...module.payload, ...patch } }
                        : module
                ))),
            };

            return (type === 'ai' || type === 'action') ? syncOutputs(next) : next;
        });

        if (type === 'ai' && Array.isArray(patch.variants)) {
            updateEdges(edges.map((edge) => {
                if (edge.source?.client_key !== clientKey || ! edge.source?.output_id) {
                    return edge;
                }

                const variant = patch.variants.find((item) => item.id === edge.source.output_id);

                if (! variant) {
                    return edge;
                }

                return {
                    ...edge,
                    condition_payload: {
                        ...edge.condition_payload,
                        label: variant.label,
                    },
                };
            }));
        }

        if (type === 'action' && Array.isArray(patch.actions)) {
            const hasCheckData = patch.actions.some((item) => item?.type === ACTION_TYPE_CHECK_DATA);
            const hasDistanceToMoscow = patch.actions.some((item) => item?.type === ACTION_TYPE_CALCULATE_DISTANCE_TO_MOSCOW);
            const hasGeoCity = patch.actions.some((item) => isGeoCityResultActionType(item?.type));
            const allActionOutputIds = new Set([
                ...ACTION_RESULT_OUTPUTS_WITH_LEGACY.map((output) => output.id),
                ...ACTION_VARIABLE_LEGACY_OUTPUT_IDS,
            ]);
            const nextActionOutputIds = new Set([
                ...(hasCheckData ? ACTION_CHECK_DATA_OUTPUTS : []),
                ...(hasDistanceToMoscow ? ACTION_DISTANCE_TO_MOSCOW_OUTPUTS : []),
                ...(hasGeoCity ? ACTION_GEO_CITY_OUTPUTS : []),
            ].map((output) => output.id));

            updateEdges(edges.filter((edge) => (
                edge.source?.client_key !== clientKey
                || ! allActionOutputIds.has(edge.source?.output_id)
                || nextActionOutputIds.has(edge.source?.output_id)
            )));
        }

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

        let nextEdges = edges;

        if (normalizedPatch.type === BUTTON_TYPE_LINK) {
            nextEdges = nextEdges.filter((edge) => edge.source?.client_key !== clientKey || edge.source?.output_id !== buttonId);
        }

        if (Object.prototype.hasOwnProperty.call(normalizedPatch, 'text')) {
            if (String(normalizedPatch.text ?? '').trim() !== '') {
                setValidationIssue((current) => (
                    current?.blockKey === clientKey && current?.buttonId === buttonId ? null : current
                ));
            }

            nextEdges = nextEdges.map((edge) => {
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
            });
        }

        if (nextEdges !== edges) {
            updateEdges(nextEdges);
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

        updateEdges((currentEdges) => currentEdges.filter((edge) => edge.source?.client_key !== clientKey || edge.source?.output_id !== buttonId));
    }

    function removeAiVariant(clientKey, variantId) {
        updateBlockSettings(clientKey, (settings) => {
            const ai = findModule(settings, 'ai');
            const variants = aiVariantDefinitions(ai);

            if (variants.length <= 1) {
                return settings;
            }

            return syncOutputs({
                ...settings,
                modules: sortModules(modulesFrom(settings).map((module) => (
                    module.type === 'ai'
                        ? {
                            ...module,
                            payload: {
                                ...module.payload,
                                variants: variants.filter((variant) => variant.id !== variantId),
                            },
                        }
                        : module
                ))),
            });
        });

        updateEdges((currentEdges) => currentEdges.filter((edge) => edge.source?.client_key !== clientKey || edge.source?.output_id !== variantId));
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
        const blocksForSave = allBlocks.map((block) => ({
            ...block,
            settings_payload: syncOutputs(normalizeSettings(block.settings_payload)),
        }));
        const edgesForSave = filterEdgesForBlocks(allEdges, blocksForSave);

        return {
            draft_version_id: state.scenario.draft_version_id,
            base_revision: state.builder.revision,
            builder: {
                schema_version: 3,
                active_sheet_id: state.builder.active_sheet_id || 'main',
                sheets,
                meta: state.builder.meta ?? {},
                blocks: blocksForSave,
                edges: edgesForSave,
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
            setSelectedWaypoint(null);
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

            await publishSavedState(savedState, blockBeforePublish, edgeBeforePublish);
        } catch (requestError) {
            if (
                requestError?.data?.code === 'scheduled_transitions_pending'
                || requestError?.data?.code === 'auto_reply_import_double_response_risk'
            ) {
                return;
            }

            handlePublishError(requestError, null, blockBeforePublish, edgeBeforePublish);
        } finally {
            setIsPublishing(false);
        }
    }

    async function publishSavedState(
        savedState,
        blockBeforePublish,
        edgeBeforePublish,
        scheduledTransitionPolicy = null,
        confirmAutoReplyImportRisk = false,
    ) {
        const payload = {
            draft_version_id: savedState.scenario.draft_version_id,
            base_revision: savedState.builder.revision,
        };

        if (scheduledTransitionPolicy) {
            payload.scheduled_transition_policy = scheduledTransitionPolicy;
        }

        if (confirmAutoReplyImportRisk) {
            payload.confirm_auto_reply_import_double_response_risk = true;
        }

        try {
            const response = await publishScenarioBuilderState(publishUrl, csrfToken, payload);
            const selection = resolvePublishedSelection(savedState, response, blockBeforePublish, edgeBeforePublish);
            const cancelledCount = Number(response.published?.cancelled_scheduled_transitions ?? 0);

            setState(response);
            setSelectedBlockKey(selection.blockKey);
            setSelectedEdgeKey(selection.edgeKey);
            setSelectedWaypoint(null);
            setPendingPublishWarning(null);
            cancelConnection();
            setStatus('ready');
            setNotice([
                `Опубликовано v${response.published?.version_number ?? ''}`.trim(),
                cancelledCount > 0 ? `отменено переходов: ${cancelledCount}` : null,
            ].filter(Boolean).join(' · '));
        } catch (requestError) {
            handlePublishError(requestError, savedState, blockBeforePublish, edgeBeforePublish);
            throw requestError;
        }
    }

    function handlePublishError(requestError, savedState, blockBeforePublish, edgeBeforePublish) {
        if (requestError.status === 409 && requestError.data?.code === 'scheduled_transitions_pending' && savedState) {
            setError(null);
            setPendingPublishWarning({
                type: 'scheduled_transitions',
                savedState,
                blockBeforePublish,
                edgeBeforePublish,
                warning: requestError.data.warning ?? {},
            });
            setStatus('ready');

            return;
        }

        if (requestError.status === 409 && requestError.data?.code === 'auto_reply_import_double_response_risk' && savedState) {
            setError(null);
            setPendingPublishWarning({
                type: 'auto_reply_import',
                savedState,
                blockBeforePublish,
                edgeBeforePublish,
                warning: requestError.data.warning ?? {},
            });
            setStatus('ready');

            return;
        }

        setError(errorText(requestError));

        if (requestError.status === 409) {
            setStatus('conflict');
        }
    }

    async function exportSheet() {
        if (! sheetExportUrl || isExportingSheet) {
            return;
        }

        setIsExportingSheet(true);
        setError(null);
        setNotice(null);

        try {
            const document = await exportScenarioBuilderSheet(sheetExportUrl);
            downloadJsonDocument(sheetExportFilename(document), document);
            setNotice('Активный лист экспортирован из сохранённого черновика.');
        } catch (requestError) {
            setError(errorText(requestError));
        } finally {
            setIsExportingSheet(false);
        }
    }

    function openSheetImportPicker() {
        if (! canTransferSheet) {
            return;
        }

        sheetImportFileRef.current?.click();
    }

    async function previewSheetImportFromFile(event) {
        const file = event.target.files?.[0] ?? null;

        event.target.value = '';

        if (! file || ! sheetImportPreviewUrl) {
            return;
        }

        setIsImportingSheet(true);
        setError(null);
        setNotice(null);
        setSheetImportError(null);

        try {
            const json = await file.text();
            const preview = await previewScenarioBuilderSheetImport(sheetImportPreviewUrl, csrfToken, { json });

            setSheetImportJson(json);
            setSheetImportPreview(preview);
            setSheetImportSelection(defaultSheetImportSelection(preview));
        } catch (requestError) {
            setError(errorText(requestError));
        } finally {
            setIsImportingSheet(false);
        }
    }

    function closeSheetImportPreview() {
        if (isApplyingSheetImport) {
            return;
        }

        setSheetImportJson('');
        setSheetImportPreview(null);
        setSheetImportSelection({});
        setSheetImportError(null);
    }

    function updateSheetImportChannels(blockExportKey, channelIds) {
        setSheetImportSelection((current) => ({
            ...current,
            [blockExportKey]: channelIds.map((id) => Number(id)).filter((id) => id > 0),
        }));
    }

    async function downloadSheetBackup() {
        if (! sheetExportUrl) {
            return;
        }

        try {
            const document = await exportScenarioBuilderSheet(sheetExportUrl);
            downloadJsonDocument(`backup-${sheetExportFilename(document)}`, document);
            setSheetImportError(null);
        } catch (requestError) {
            setSheetImportError(errorText(requestError));
        }
    }

    async function applySheetImport() {
        if (! sheetImportPreview || ! sheetImportApplyUrl || isApplyingSheetImport) {
            return;
        }

        setIsApplyingSheetImport(true);
        setSheetImportError(null);
        setError(null);
        setNotice(null);

        try {
            const response = await applyScenarioBuilderSheetImport(sheetImportApplyUrl, csrfToken, {
                json: sheetImportJson,
                draft_version_id: sheetImportPreview.draft_version_id,
                base_builder_revision: sheetImportPreview.base_builder_revision,
                selected_channels: sheetImportSelection,
            });
            const focusKey = resolveReturnedKey(response.import?.focus_block_client_key, response.id_map?.blocks, 'block');
            const focusedState = stateWithSheetImportFocus(response, focusKey);

            setState(focusedState);
            setSelectedBlockKey(focusKey);
            setSelectedEdgeKey(null);
            setSelectedWaypoint(null);
            cancelConnection();
            setStatus('ready');
            setNotice('Активный лист импортирован и сохранён в черновик.');
            setSheetImportJson('');
            setSheetImportPreview(null);
            setSheetImportSelection({});
            setSheetImportError(null);
        } catch (requestError) {
            if (requestError.status === 409) {
                setStatus('conflict');
            }

            setSheetImportError(errorText(requestError));
        } finally {
            setIsApplyingSheetImport(false);
        }
    }

    function openAutoReplyImportPicker() {
        if (! canImportAutoReplies) {
            return;
        }

        autoReplyImportFileRef.current?.click();
    }

    async function previewAutoReplyImportFromFile(event) {
        const file = event.target.files?.[0] ?? null;

        event.target.value = '';

        if (! file || ! autoReplyImportPreviewUrl) {
            return;
        }

        const nextMappings = {
            channels: {},
            tags: {},
            excludedRows: [],
            overwriteRows: [],
        };
        const nextPlacement = AUTO_REPLY_IMPORT_DEFAULT_PLACEMENT;
        const nextBatchId = createAutoReplyImportBatchId();

        setAutoReplyImportFile(file);
        setAutoReplyImportMappings(nextMappings);
        setAutoReplyImportPlacement(nextPlacement);
        setAutoReplyImportBatchId(nextBatchId);
        await refreshAutoReplyImportPreview({
            file,
            mappings: nextMappings,
            placement: nextPlacement,
            batchId: nextBatchId,
        });
    }

    function autoReplyImportPayload(
        mappings = autoReplyImportMappings,
        placement = autoReplyImportPlacement,
        batchId = autoReplyImportBatchId,
    ) {
        return {
            builder_state: { builder: state?.builder ?? {} },
            placement_mode: placement || AUTO_REPLY_IMPORT_DEFAULT_PLACEMENT,
            import_batch_id: batchId || createAutoReplyImportBatchId(),
            channel_mappings: Object.entries(mappings.channels ?? {})
                .map(([excelChannelKey, channelId]) => ({
                    excel_channel_key: excelChannelKey,
                    channel_id: Number(channelId),
                }))
                .filter((mapping) => mapping.excel_channel_key !== '' && mapping.channel_id > 0),
            tag_mappings: Object.entries(mappings.tags ?? {})
                .map(([excelTagName, tagId]) => ({
                    excel_tag_name: excelTagName,
                    tag_id: Number(tagId),
                }))
                .filter((mapping) => mapping.excel_tag_name !== '' && mapping.tag_id > 0),
            excluded_row_numbers: mappings.excludedRows ?? [],
            overwrite_conflict_row_numbers: mappings.overwriteRows ?? [],
        };
    }

    async function refreshAutoReplyImportPreview({
        file = autoReplyImportFile,
        mappings = autoReplyImportMappings,
        placement = autoReplyImportPlacement,
        batchId = autoReplyImportBatchId,
    } = {}) {
        if (! file || ! autoReplyImportPreviewUrl || isImportingAutoReplies) {
            return null;
        }

        setIsImportingAutoReplies(true);
        setError(null);
        setNotice(null);
        setAutoReplyImportError(null);

        try {
            const preview = await previewScenarioBuilderAutoReplyImport(
                autoReplyImportPreviewUrl,
                csrfToken,
                file,
                autoReplyImportPayload(mappings, placement, batchId),
            );

            setAutoReplyImportPreview(preview);

            return preview;
        } catch (requestError) {
            const message = errorText(requestError);

            setAutoReplyImportError(message);
            setError(message);

            return null;
        } finally {
            setIsImportingAutoReplies(false);
        }
    }

    async function updateAutoReplyImportPlacement(placement) {
        const nextPlacement = AUTO_REPLY_IMPORT_PLACEMENTS.some(([value]) => value === placement)
            ? placement
            : AUTO_REPLY_IMPORT_DEFAULT_PLACEMENT;

        setAutoReplyImportPlacement(nextPlacement);
        await refreshAutoReplyImportPreview({ placement: nextPlacement });
    }

    async function updateAutoReplyChannelMapping(excelChannelKey, channelId) {
        const nextChannels = { ...(autoReplyImportMappings.channels ?? {}) };
        const nextId = Number(channelId);

        if (nextId > 0) {
            nextChannels[excelChannelKey] = nextId;
        } else {
            delete nextChannels[excelChannelKey];
        }

        const nextMappings = {
            ...autoReplyImportMappings,
            channels: nextChannels,
        };

        setAutoReplyImportMappings(nextMappings);
        await refreshAutoReplyImportPreview({ mappings: nextMappings });
    }

    async function updateAutoReplyTagMapping(excelTagName, tagId) {
        const nextTags = { ...(autoReplyImportMappings.tags ?? {}) };
        const nextId = Number(tagId);

        if (nextId > 0) {
            nextTags[excelTagName] = nextId;
        } else {
            delete nextTags[excelTagName];
        }

        const nextMappings = {
            ...autoReplyImportMappings,
            tags: nextTags,
        };

        setAutoReplyImportMappings(nextMappings);
        await refreshAutoReplyImportPreview({ mappings: nextMappings });
    }

    async function createAutoReplyImportTag(excelTagName) {
        const name = String(excelTagName ?? '').trim();

        if (! name || ! autoReplyImportTagStoreUrl || creatingAutoReplyTagName) {
            return;
        }

        setCreatingAutoReplyTagName(name);
        setError(null);
        setNotice(null);
        setAutoReplyImportError(null);

        try {
            const response = await createScenarioBuilderAutoReplyImportTag(autoReplyImportTagStoreUrl, csrfToken, {
                name,
                color: 'gray',
            });
            const tag = response?.tag;
            const tagId = Number(tag?.id);

            if (! tag || tagId <= 0) {
                throw new Error('Тег создан, но сервер не вернул его ID.');
            }

            setState((current) => stateWithCatalogTag(current, tag));

            const nextMappings = {
                ...autoReplyImportMappings,
                tags: {
                    ...(autoReplyImportMappings.tags ?? {}),
                    [name]: tagId,
                },
            };

            setAutoReplyImportMappings(nextMappings);
            await refreshAutoReplyImportPreview({ mappings: nextMappings });
        } catch (requestError) {
            const message = errorText(requestError);

            setAutoReplyImportError(message);
            setError(message);
        } finally {
            setCreatingAutoReplyTagName('');
        }
    }

    async function toggleAutoReplyImportRow(listKey, rowNumber, checked) {
        const currentRows = new Set((autoReplyImportMappings[listKey] ?? []).map((value) => Number(value)));
        const row = Number(rowNumber);

        if (checked) {
            currentRows.add(row);
        } else {
            currentRows.delete(row);
        }

        const nextMappings = {
            ...autoReplyImportMappings,
            [listKey]: Array.from(currentRows).filter((value) => value > 0).sort((left, right) => left - right),
        };

        setAutoReplyImportMappings(nextMappings);
        await refreshAutoReplyImportPreview({ mappings: nextMappings });
    }

    function closeAutoReplyImportPreview() {
        if (isApplyingAutoReplyImport || isImportingAutoReplies) {
            return;
        }

        setAutoReplyImportFile(null);
        setAutoReplyImportPreview(null);
        setAutoReplyImportMappings({
            channels: {},
            tags: {},
            excludedRows: [],
            overwriteRows: [],
        });
        setAutoReplyImportPlacement(AUTO_REPLY_IMPORT_DEFAULT_PLACEMENT);
        setAutoReplyImportBatchId('');
        setAutoReplyImportError(null);
    }

    function applyAutoReplyImportPlan() {
        if (! autoReplyImportPreview?.can_apply || isApplyingAutoReplyImport) {
            return;
        }

        setIsApplyingAutoReplyImport(true);
        setError(null);
        setNotice(null);
        setAutoReplyImportError(null);

        try {
            const focusBlockKey = String(autoReplyImportPreview.plan?.focus_block_client_key ?? '');

            setState((current) => stateWithAutoReplyImportPlan(current, autoReplyImportPreview));
            setSelectedBlockKey(focusBlockKey || null);
            setSelectedEdgeKey(null);
            setSelectedWaypoint(null);
            cancelConnection();
            setStatus('ready');
            setNotice('Автоответы импортированы на холст. Нажмите «Сохранить», чтобы записать черновик.');
            setAutoReplyImportFile(null);
            setAutoReplyImportPreview(null);
            setAutoReplyImportMappings({
                channels: {},
                tags: {},
                excludedRows: [],
                overwriteRows: [],
            });
            setAutoReplyImportPlacement(AUTO_REPLY_IMPORT_DEFAULT_PLACEMENT);
            setAutoReplyImportBatchId('');
        } catch (applyError) {
            setAutoReplyImportError(applyError instanceof Error ? applyError.message : 'Не удалось применить импорт.');
        } finally {
            setIsApplyingAutoReplyImport(false);
        }
    }

    async function resolvePendingPublishWarning(policy) {
        if (! pendingPublishWarning || isPublishing) {
            return;
        }

        const warning = pendingPublishWarning;

        setIsPublishing(true);
        setPendingPublishWarning(null);
        setError(null);
        setNotice(null);

        try {
            if (warning.type === 'auto_reply_import') {
                await publishSavedState(
                    warning.savedState,
                    warning.blockBeforePublish,
                    warning.edgeBeforePublish,
                    null,
                    true,
                );
            } else {
                await publishSavedState(
                    warning.savedState,
                    warning.blockBeforePublish,
                    warning.edgeBeforePublish,
                    policy,
                );
            }
        } catch {
            // Error state is already rendered by publishSavedState.
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
        setSelectedWaypoint(null);
        setIsPanelCollapsed(false);
        setPendingConnection(null);
        setError(null);
        setNotice(`Открыта связь ${shortEdgeId(edge)}`);
    }

    function openValidationIssueBlock(issue) {
        setSelectedBlockKey(issue.blockKey);
        setSelectedEdgeKey(null);
        setSelectedWaypoint(null);
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
            setNotice(`Номер блока #${value} скопирован`);
        } catch {
            setNotice(null);
            setError('Не удалось скопировать номер. Скопируйте его вручную.');
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
        <section className="ac-v3-builder" data-active-sheet-color={activeSheet.color || 'none'}>
            <header className="ac-v3-builder__topbar">
                <div className="ac-v3-builder__crumb">
                    <strong>{state?.scenario?.name ?? 'Сценарий'}</strong>
                </div>

                <BlockSearchControl
                    query={blockSearchQuery}
                    matchCount={blockSearchMatches.length}
                    matchIndex={blockSearchIndex}
                    onQueryChange={setBlockSearchQuery}
                    onOpen={() => focusBlockSearchMatch(0)}
                    onPrevious={() => focusBlockSearchMatch(-1)}
                    onNext={() => focusBlockSearchMatch(1)}
                />

                <div className="ac-v3-builder__top-actions">
                    <button type="button" className="ac-v3-builder__run" onClick={() => setNotice('Симулятор будет подключен отдельным шагом.')}>
                        <PlayIcon />
                        Прогнать
                    </button>
                    <button type="button" className="ac-v3-builder__primary" disabled={! canSave} onClick={save}>
                        {isSaving ? 'Сохраняю...' : 'Сохранить'}
                    </button>
                    <button type="button" className="ac-v3-builder__publish" disabled={! canPublish} onClick={publish}>
                        {isPublishing ? 'Публикую...' : 'Опубликовать'}
                    </button>
                    <div className="ac-v3-builder__more" ref={moreMenuRef}>
                        <button
                            type="button"
                            className={`ac-v3-builder__more-button ${isMoreMenuOpen ? 'is-active' : ''}`}
                            aria-haspopup="menu"
                            aria-expanded={isMoreMenuOpen}
                            onClick={() => setIsMoreMenuOpen((isOpen) => ! isOpen)}
                        >
                            <MoreIcon />
                            Ещё
                        </button>

                        {isMoreMenuOpen ? (
                            <div className="ac-v3-builder__more-menu" role="menu">
                                <div className="ac-v3-builder__more-section">
                                    <span className="ac-v3-builder__more-label">Режим</span>
                                    <div className="ac-v3-builder__mode ac-v3-builder__mode--menu" role="tablist" aria-label="Режим конструктора">
                                        <button
                                            type="button"
                                            className={mode === 'design' ? 'is-active' : ''}
                                            onClick={() => {
                                                setMode('design');
                                                setIsMoreMenuOpen(false);
                                            }}
                                        >
                                            Сценарий
                                        </button>
                                        <button
                                            type="button"
                                            className={mode === 'logs' ? 'is-active' : ''}
                                            onClick={() => {
                                                setMode('logs');
                                                setIsMoreMenuOpen(false);
                                            }}
                                        >
                                            Логи
                                        </button>
                                    </div>
                                </div>

                                <div className="ac-v3-builder__more-section">
                                    <span className="ac-v3-builder__more-label">Лист</span>
                                    <button
                                        type="button"
                                        role="menuitem"
                                        disabled={! canTransferSheet}
                                        onClick={() => {
                                            setIsMoreMenuOpen(false);
                                            exportSheet();
                                        }}
                                    >
                                        {isExportingSheet ? 'Экспорт...' : 'Экспорт листа'}
                                    </button>
                                    <button
                                        type="button"
                                        role="menuitem"
                                        disabled={! canTransferSheet}
                                        onClick={() => {
                                            setIsMoreMenuOpen(false);
                                            openSheetImportPicker();
                                        }}
                                    >
                                        {isImportingSheet ? 'Проверяю...' : 'Импорт листа'}
                                    </button>
                                    <button
                                        type="button"
                                        role="menuitem"
                                        disabled={! canImportAutoReplies}
                                        onClick={() => {
                                            setIsMoreMenuOpen(false);
                                            openAutoReplyImportPicker();
                                        }}
                                    >
                                        {isImportingAutoReplies ? 'Проверяю...' : 'Импорт автоответов Excel'}
                                    </button>
                                </div>

                                <div className="ac-v3-builder__more-section">
                                    <span className="ac-v3-builder__more-label">Сервис</span>
                                    <button
                                        type="button"
                                        role="menuitem"
                                        onClick={() => {
                                            setIsMoreMenuOpen(false);
                                            setNotice('История версий не входит в текущий шаг.');
                                        }}
                                    >
                                        <HistoryIcon />
                                        История версий
                                    </button>
                                    {revision ? (
                                        <div className="ac-v3-builder__more-meta">
                                            <span>Версия</span>
                                            <strong>{revision}</strong>
                                        </div>
                                    ) : null}
                                    {serverClock?.time ? (
                                        <div className="ac-v3-builder__more-meta">
                                            <span>Время сервера</span>
                                            <strong>{formatDateTime(serverClock.time, serverTimezoneLabel, serverTimezone)}</strong>
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        ) : null}
                    </div>
                </div>
            </header>

            <input
                ref={sheetImportFileRef}
                type="file"
                accept="application/json,.json"
                className="ac-v3-builder__file-input"
                onChange={previewSheetImportFromFile}
            />
            <input
                ref={autoReplyImportFileRef}
                type="file"
                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                className="ac-v3-builder__file-input"
                onChange={previewAutoReplyImportFromFile}
            />

            <div className="ac-v3-builder__tabs">
                {visibleSheetTabs.map((sheet) => (
                    <button
                        key={sheet.id}
                        type="button"
                        className={sheet.id === activeSheet.id ? 'is-active' : ''}
                        data-sheet-color={sheet.color || 'none'}
                        onClick={(event) => {
                            if (event.target.closest('[data-sheet-rename]')) {
                                openRenameSheet(sheet);

                                return;
                            }

                            switchSheet(sheet.id);
                        }}
                    >
                        <span>{sheet.name}</span>
                        <b>{sheetBlockCount(allBlocks, sheet.id)}</b>
                        {sheet.id === activeSheet.id ? (
                            <span className="ac-v3-builder__sheet-tab-gear" title="Переименовать лист" data-sheet-rename>
                                <GearIcon />
                            </span>
                        ) : null}
                    </button>
                ))}
                <button type="button" className="ac-v3-builder__tab-add" title="Добавить лист" onClick={addSheet}>+</button>
                <div className="ac-v3-builder__sheet-actions" aria-label="Действия с активным листом">
                    <button
                        type="button"
                        className="ac-v3-builder__sheet-list-button"
                        title="Открыть список листов"
                        onClick={() => setIsSheetListOpen(true)}
                    >
                        <GearIcon />
                        {hiddenSheetCount > 0 ? (
                            <span>{hiddenSheetCount}</span>
                        ) : null}
                    </button>
                </div>
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

            {pendingPublishWarning ? (
                pendingPublishWarning.type === 'auto_reply_import' ? (
                    <AutoReplyImportPublishDialog
                        warning={pendingPublishWarning.warning}
                        isPublishing={isPublishing}
                        onConfirm={() => resolvePendingPublishWarning('confirm')}
                        onClose={() => setPendingPublishWarning(null)}
                    />
                ) : (
                    <ScheduledPublishDialog
                        warning={pendingPublishWarning.warning}
                        isPublishing={isPublishing}
                        onKeep={() => resolvePendingPublishWarning('keep')}
                        onCancelScheduled={() => resolvePendingPublishWarning('cancel')}
                        onClose={() => setPendingPublishWarning(null)}
                    />
                )
            ) : null}

            {sheetImportPreview ? (
                <SheetImportPreviewDialog
                    preview={sheetImportPreview}
                    selection={sheetImportSelection}
                    error={sheetImportError}
                    isApplying={isApplyingSheetImport}
                    onChangeChannels={updateSheetImportChannels}
                    onDownloadBackup={downloadSheetBackup}
                    onApply={applySheetImport}
                    onClose={closeSheetImportPreview}
                />
            ) : null}

            {autoReplyImportPreview ? (
                <AutoReplyImportPreviewDialog
                    preview={autoReplyImportPreview}
                    mappings={autoReplyImportMappings}
                    error={autoReplyImportError}
                    isApplying={isApplyingAutoReplyImport}
                    isRefreshing={isImportingAutoReplies}
                    canCreateTag={canCreateAutoReplyTags}
                    creatingTagName={creatingAutoReplyTagName}
                    placement={autoReplyImportPlacement}
                    onPlacementChange={updateAutoReplyImportPlacement}
                    onChannelMapping={updateAutoReplyChannelMapping}
                    onTagMapping={updateAutoReplyTagMapping}
                    onCreateTag={createAutoReplyImportTag}
                    onToggleExcluded={(rowNumber, checked) => toggleAutoReplyImportRow('excludedRows', rowNumber, checked)}
                    onToggleOverwrite={(rowNumber, checked) => toggleAutoReplyImportRow('overwriteRows', rowNumber, checked)}
                    onRefresh={() => refreshAutoReplyImportPreview()}
                    onApply={applyAutoReplyImportPlan}
                    onClose={closeAutoReplyImportPreview}
                />
            ) : null}

            {isSheetListOpen ? (
                <SheetListDialog
                    sheets={sheets}
                    blocks={allBlocks}
                    importBatches={importBatches}
                    activeSheetId={activeSheet.id}
                    query={sheetListQuery}
                    onQuery={setSheetListQuery}
                    onOpen={(sheetId) => {
                        switchSheet(sheetId);
                        setIsSheetListOpen(false);
                    }}
                    onRename={(sheet) => {
                        setIsSheetListOpen(false);
                        openRenameSheet(sheet);
                    }}
                    onDelete={(sheet) => {
                        setIsSheetListOpen(false);
                        openDeleteSheet(sheet);
                    }}
                    onDeleteImport={(batch) => {
                        setIsSheetListOpen(false);
                        setDeleteImportDialog({ batch });
                    }}
                    onClose={() => setIsSheetListOpen(false)}
                />
            ) : null}

            {deleteImportDialog ? (
                <DeleteImportDialog
                    batch={deleteImportDialog.batch}
                    onDelete={deleteAutoReplyImport}
                    onClose={() => setDeleteImportDialog(null)}
                />
            ) : null}

            {sheetDialog ? (
                <SheetManageDialog
                    dialog={sheetDialog}
                    onRename={renameSheet}
                    onDelete={deleteSheet}
                    onClose={() => setSheetDialog(null)}
                />
            ) : null}

            <div
                className={`ac-v3-builder__workbench ${isPanelOpen ? 'is-panel-open' : ''}`}
                style={{ '--ac-v3-panel-width': `${panelWidth}px` }}
            >
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
                                    <marker id="ac-v3-arrow-ai" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                        <path d="M 0 0 L 10 5 L 0 10 z" />
                                    </marker>
                                    <marker id="ac-v3-arrow-action" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
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
                                        selectedWaypointId={selectedWaypoint?.edgeKey === edge.client_key ? selectedWaypoint.waypointId : null}
                                        waypointPreview={waypointPreview?.edgeKey === edge.client_key ? waypointPreview : null}
                                        onSelect={() => selectEdge(edge.client_key)}
                                        onOpenSettings={() => selectEdge(edge.client_key, { openPanel: true })}
                                        onStartRewire={(event, endpoint) => startEdgeRewire(event, edge.client_key, endpoint)}
                                        onAddWaypoint={(event, routePoints) => addEdgeWaypoint(event, edge.client_key, routePoints)}
                                        onSelectWaypoint={(waypointId) => {
                                            setSelectedBlockKey(null);
                                            setSelectedEdgeKey(edge.client_key);
                                            setSelectedWaypoint({ edgeKey: edge.client_key, waypointId });
                                            setIsPanelCollapsed(false);
                                            setPendingConnection(null);
                                            setRewireTargetKey(null);
                                        }}
                                        onRemoveWaypoint={(waypointId) => removeEdgeWaypoint(edge.client_key, waypointId)}
                                        onStartWaypointDrag={(event, waypointId) => startEdgeWaypointDrag(event, edge.client_key, waypointId)}
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
                                    rewireTarget={rewireTargetKey === block.client_key}
                                    connectedOutputIds={connectedOutputIds(block, edges)}
                                    onSelect={() => completeConnection(block)}
                                    onDragStart={(event) => startBlockDrag(event, block.client_key)}
                                    onStartConnection={startConnection}
                                    onStartDefaultConnection={() => startPanelConnection(block, DEFAULT_OUTPUT)}
                                    onDuplicate={() => duplicateBlock(block.client_key)}
                                    onRemove={() => removeBlock(block.client_key)}
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
                        <button
                            type="button"
                            className="ac-v3-builder__panel-resizer"
                            title="Изменить ширину панели"
                            aria-label="Изменить ширину панели"
                            onPointerDown={startPanelResize}
                            onDoubleClick={resetPanelWidth}
                        />
                        {selectedEdge ? (
                            <EdgePanel
                                edge={selectedEdge}
                                blocks={blocks}
                                onCollapse={collapsePanel}
                                onClose={closePanelSelection}
                                onRemove={() => removeEdge(selectedEdge.client_key)}
                                onUpdateConditionPayload={(nextPayload) => updateEdgeConditionPayload(selectedEdge.client_key, nextPayload)}
                                onResetWaypoints={() => resetEdgeWaypoints(selectedEdge.client_key)}
                                onCopyEdgeId={copyEdgeId}
                                onRefreshDiagnostics={refreshBuilderDiagnostics}
                                timezone={serverTimezone}
                                timezoneLabel={serverTimezoneLabel}
                                dialogFieldKeys={dialogFieldKeys}
                            />
                        ) : (
                            <BlockPanel
                                block={selectedBlock}
                                channels={channels}
                                tags={tags}
                                blocks={blocks}
                                dialogFieldKeys={dialogFieldKeys}
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
                                onRemoveAiVariant={removeAiVariant}
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

function SheetManageDialog({ dialog, onRename, onDelete, onClose }) {
    const [name, setName] = useState(dialog.name ?? '');
    const [color, setColor] = useState(dialog.color ?? 'none');
    const isDelete = dialog.type === 'delete';

    function handleSubmit(event) {
        event.preventDefault();

        if (isDelete) {
            onDelete(dialog.sheetId);

            return;
        }

        onRename(dialog.sheetId, name, color);
    }

    return (
        <div className="ac-v3-builder__dialog-backdrop" role="presentation">
            <form className="ac-v3-builder__sheet-dialog" role="dialog" aria-modal="true" aria-labelledby="sheet-dialog-title" onSubmit={handleSubmit}>
                <div className="ac-v3-builder__publish-dialog-head">
                    <h2 id="sheet-dialog-title">{isDelete ? 'Удалить лист' : 'Переименовать лист'}</h2>
                    <button type="button" title="Закрыть" onClick={onClose}>×</button>
                </div>

                <div className="ac-v3-builder__publish-dialog-body">
                    {isDelete ? (
                        <p>
                            Лист <strong>{dialog.name}</strong> будет удалён вместе с блоками и связями на нём.
                            Блоков на листе: <strong>{dialog.blockCount ?? 0}</strong>.
                        </p>
                    ) : (
                        <div className="ac-v3-builder__sheet-dialog-field">
                            <label>
                                <span>Название листа</span>
                                <input
                                    type="text"
                                    value={name}
                                    maxLength={40}
                                    autoFocus
                                    onChange={(event) => setName(event.target.value)}
                                />
                            </label>
                            <span>Цвет листа</span>
                            <div className="ac-v3-builder__sheet-color-grid" role="radiogroup" aria-label="Цвет листа">
                                {SHEET_COLORS.map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        className={color === value ? 'is-active' : ''}
                                        data-sheet-color={value}
                                        aria-pressed={color === value ? 'true' : 'false'}
                                        onClick={() => setColor(value)}
                                    >
                                        <i aria-hidden="true" />
                                        <span>{label}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <div className="ac-v3-builder__publish-dialog-footer">
                    <button type="button" onClick={onClose}>Отмена</button>
                    <button type="submit" className={isDelete ? 'is-danger' : ''}>
                        {isDelete ? 'Удалить' : 'Сохранить'}
                    </button>
                </div>
            </form>
        </div>
    );
}

function SheetListDialog({
    sheets,
    blocks,
    importBatches,
    activeSheetId,
    query,
    onQuery,
    onOpen,
    onRename,
    onDelete,
    onDeleteImport,
    onClose,
}) {
    const search = normalizeSearchValue(query);
    const visibleSheets = (Array.isArray(sheets) ? sheets : [MAIN_SHEET]).filter((sheet) => {
        if (search === '') {
            return true;
        }

        return normalizeSearchValue(`${sheet.name ?? ''} ${sheet.id ?? ''}`).includes(search);
    });
    const batches = Array.isArray(importBatches) ? importBatches : [];

    return (
        <div className="ac-v3-builder__dialog-backdrop" role="presentation">
            <div className="ac-v3-builder__sheet-list-dialog" role="dialog" aria-modal="true" aria-labelledby="sheet-list-title">
                <div className="ac-v3-builder__publish-dialog-head">
                    <h2 id="sheet-list-title">Листы</h2>
                    <button type="button" title="Закрыть" onClick={onClose}>×</button>
                </div>

                <div className="ac-v3-builder__sheet-list-body">
                    <input
                        type="search"
                        value={query}
                        placeholder="Найти лист"
                        onChange={(event) => onQuery(event.target.value)}
                    />

                    {batches.length > 0 ? (
                        <section className="ac-v3-builder__sheet-list-imports">
                            <div className="ac-v3-builder__sheet-list-section-title">
                                <strong>Импорты Excel</strong>
                                <span>{batches.length}</span>
                            </div>
                            {batches.map((batch) => (
                                <article key={batch.created_batch_id} className="ac-v3-builder__sheet-list-import-row">
                                    <div>
                                        <strong>{batch.file_name || batch.created_batch_id}</strong>
                                        <small>
                                            создано блоков: {batch.created_blocks_count} · обновлено: {batch.last_updated_blocks_count} · листов: {batch.created_sheets_count}
                                        </small>
                                    </div>
                                    <button type="button" className="is-danger" onClick={() => onDeleteImport(batch)}>
                                        Удалить импорт
                                    </button>
                                </article>
                            ))}
                        </section>
                    ) : null}

                    <section className="ac-v3-builder__sheet-list">
                        {visibleSheets.map((sheet) => {
                            const isMain = sheet.id === MAIN_SHEET.id;
                            const isActive = sheet.id === activeSheetId;

                            return (
                                <article key={sheet.id} className={isActive ? 'is-active' : ''}>
                                    <div className="ac-v3-builder__sheet-list-main">
                                        <i data-sheet-color={sheet.color || 'none'} aria-hidden="true" />
                                        <div>
                                            <strong>{sheet.name || sheet.id}</strong>
                                            <small>
                                                {sheetBlockCount(blocks, sheet.id)} блоков · {sheetColorLabel(sheet.color)}
                                                {sheetImportCreatedBatchId(sheet) ? ' · Импорт Excel' : ''}
                                            </small>
                                        </div>
                                    </div>
                                    <div className="ac-v3-builder__sheet-list-actions">
                                        <button type="button" onClick={() => onOpen(sheet.id)}>
                                            Открыть
                                        </button>
                                        <button type="button" onClick={() => onRename(sheet)}>
                                            Настроить
                                        </button>
                                        <button type="button" className="is-danger" disabled={isMain} onClick={() => onDelete(sheet)}>
                                            Удалить
                                        </button>
                                    </div>
                                </article>
                            );
                        })}
                    </section>
                </div>
            </div>
        </div>
    );
}

function DeleteImportDialog({ batch, onDelete, onClose }) {
    if (! batch) {
        return null;
    }

    return (
        <div className="ac-v3-builder__dialog-backdrop" role="presentation">
            <div className="ac-v3-builder__publish-dialog" role="dialog" aria-modal="true" aria-labelledby="delete-import-title">
                <div className="ac-v3-builder__publish-dialog-head">
                    <h2 id="delete-import-title">Удалить импорт</h2>
                    <button type="button" title="Закрыть" onClick={onClose}>×</button>
                </div>

                <div className="ac-v3-builder__publish-dialog-body">
                    <p>
                        Будут удалены только блоки, созданные этим импортом, их связи и пустые листы этого импорта.
                        Изменение сохранится в базе только после кнопки «Сохранить».
                    </p>
                    <ul>
                        <li><span>Файл</span><strong>{batch.file_name || 'Не указан'}</strong></li>
                        <li><span>Создано блоков</span><strong>{batch.created_blocks_count}</strong></li>
                        <li><span>Листов импорта</span><strong>{batch.created_sheets_count}</strong></li>
                    </ul>
                </div>

                <div className="ac-v3-builder__publish-dialog-footer">
                    <button type="button" onClick={onClose}>Отмена</button>
                    <button type="button" className="is-danger" onClick={() => onDelete(batch.created_batch_id)}>
                        Удалить импорт
                    </button>
                </div>
            </div>
        </div>
    );
}

function ScheduledPublishDialog({ warning, isPublishing, onKeep, onCancelScheduled, onClose }) {
    const transitions = warning?.scheduled_transitions ?? {};
    const count = Number(transitions.count ?? 0);
    const items = Array.isArray(transitions.items) ? transitions.items : [];

    return (
        <div className="ac-v3-builder__dialog-backdrop" role="presentation">
            <div className="ac-v3-builder__publish-dialog" role="dialog" aria-modal="true" aria-labelledby="publish-warning-title">
                <div className="ac-v3-builder__publish-dialog-head">
                    <h2 id="publish-warning-title">Есть запланированные переходы</h2>
                    <button type="button" title="Закрыть" disabled={isPublishing} onClick={onClose}>×</button>
                </div>

                <div className="ac-v3-builder__publish-dialog-body">
                    <p>
                        В этом сценарии сейчас запланировано переходов: <strong>{count}</strong>.
                        При публикации новой версии можно сохранить их или отменить.
                    </p>

                    {items.length > 0 ? (
                        <ul>
                            {items.map((transition) => (
                                <li key={transition.id}>
                                    <span>#{transition.id}</span>
                                    <span>v{transition.published_version_id}</span>
                                    <span>диалог #{transition.dialog_id}</span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>

                <div className="ac-v3-builder__publish-dialog-footer">
                    <button type="button" disabled={isPublishing} onClick={onKeep}>
                        Сохранить переходы
                    </button>
                    <button type="button" className="is-danger" disabled={isPublishing} onClick={onCancelScheduled}>
                        Отменить переходы
                    </button>
                </div>
            </div>
        </div>
    );
}

function AutoReplyImportPublishDialog({ warning, isPublishing, onConfirm, onClose }) {
    const summary = warning?.auto_reply_import ?? {};
    const count = Number(summary.count ?? 0);

    return (
        <div className="ac-v3-builder__dialog-backdrop" role="presentation">
            <div className="ac-v3-builder__publish-dialog" role="dialog" aria-modal="true" aria-labelledby="auto-reply-import-warning-title">
                <div className="ac-v3-builder__publish-dialog-head">
                    <h2 id="auto-reply-import-warning-title">Есть импортированные автоответы</h2>
                    <button type="button" title="Закрыть" disabled={isPublishing} onClick={onClose}>×</button>
                </div>

                <div className="ac-v3-builder__publish-dialog-body">
                    <p>
                        В сценарии есть импортированные автоответы: <strong>{count}</strong>.
                        Старый модуль автоответов не отключается автоматически, поэтому перед публикацией проверьте, что не будет двойных ответов клиенту.
                    </p>
                </div>

                <div className="ac-v3-builder__publish-dialog-footer">
                    <button type="button" disabled={isPublishing} onClick={onClose}>
                        Отмена
                    </button>
                    <button type="button" className="is-danger" disabled={isPublishing} onClick={onConfirm}>
                        Опубликовать с подтверждением
                    </button>
                </div>
            </div>
        </div>
    );
}

function SheetImportPreviewDialog({
    preview,
    selection,
    error,
    isApplying,
    onChangeChannels,
    onDownloadBackup,
    onApply,
    onClose,
}) {
    const counts = preview?.counts ?? {};
    const startBlocks = Array.isArray(preview?.start_blocks) ? preview.start_blocks : [];
    const channels = Array.isArray(preview?.available_channels) ? preview.available_channels : [];
    const channelHints = new Map((preview?.channel_hints ?? []).map((hint) => [hint.export_key, hint]));

    return (
        <div className="ac-v3-builder__dialog-backdrop" role="presentation">
            <div className="ac-v3-builder__sheet-import-dialog" role="dialog" aria-modal="true" aria-labelledby="sheet-import-title">
                <div className="ac-v3-builder__publish-dialog-head">
                    <h2 id="sheet-import-title">Импорт листа</h2>
                    <button type="button" title="Закрыть" disabled={isApplying} onClick={onClose}>×</button>
                </div>

                <div className="ac-v3-builder__sheet-import-body">
                    <div className="ac-v3-builder__sheet-import-summary">
                        <span><strong>{counts.blocks ?? 0}</strong> блоков</span>
                        <span><strong>{counts.edges ?? 0}</strong> связей</span>
                        <span><strong>{counts.start_blocks ?? 0}</strong> стартовых</span>
                        <span><strong>{counts.channel_hints ?? 0}</strong> подсказок каналов</span>
                    </div>

                    <div className="ac-v3-builder__sheet-import-warnings">
                        {(preview?.warnings ?? []).map((warning) => (
                            <p key={warning}>{warning}</p>
                        ))}
                    </div>

                    {startBlocks.length > 0 ? (
                        <div className="ac-v3-builder__sheet-import-starts">
                            <h3>Каналы стартовых блоков</h3>
                            {startBlocks.map((startBlock) => {
                                const selected = selection[startBlock.block_export_key] ?? [];
                                const hints = (startBlock.channel_hint_keys ?? [])
                                    .map((key) => channelHints.get(key))
                                    .filter(Boolean);

                                return (
                                    <label key={startBlock.block_export_key} className="ac-v3-builder__sheet-import-start">
                                        <span>
                                            <strong>{startBlock.title || startBlock.block_export_key}</strong>
                                            {startBlock.start_condition_summary ? <small>{startBlock.start_condition_summary}</small> : null}
                                            {hints.length > 0 ? (
                                                <small>Подсказки: {hints.map((hint) => hint.name).join(', ')}</small>
                                            ) : null}
                                        </span>
                                        <select
                                            multiple
                                            value={selected.map(String)}
                                            disabled={isApplying}
                                            onChange={(event) => onChangeChannels(
                                                startBlock.block_export_key,
                                                Array.from(event.target.selectedOptions).map((option) => option.value),
                                            )}
                                        >
                                            {channels.map((channel) => (
                                                <option key={channel.id} value={channel.id}>
                                                    #{channel.id} {channel.name} ({channel.platform})
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                );
                            })}
                        </div>
                    ) : null}

                    {error ? <p className="ac-v3-builder__sheet-import-error">{error}</p> : null}
                </div>

                <div className="ac-v3-builder__publish-dialog-footer">
                    <button type="button" disabled={isApplying} onClick={onDownloadBackup}>
                        Скачать backup
                    </button>
                    <button type="button" disabled={isApplying} onClick={onClose}>
                        Отмена
                    </button>
                    <button type="button" className="is-danger" disabled={isApplying} onClick={onApply}>
                        {isApplying ? 'Импортирую...' : 'Импортировать'}
                    </button>
                </div>
            </div>
        </div>
    );
}

function AutoReplyImportPreviewDialog({
    preview,
    mappings,
    error,
    isApplying,
    isRefreshing,
    canCreateTag,
    creatingTagName,
    placement,
    onPlacementChange,
    onChannelMapping,
    onTagMapping,
    onCreateTag,
    onToggleExcluded,
    onToggleOverwrite,
    onRefresh,
    onApply,
    onClose,
}) {
    const summary = preview?.summary ?? {};
    const channels = Array.isArray(preview?.available_channels) ? preview.available_channels : [];
    const tags = Array.isArray(preview?.available_tags) ? preview.available_tags : [];
    const unresolvedChannels = Array.isArray(preview?.unresolved_channels) ? preview.unresolved_channels : [];
    const unresolvedTags = Array.isArray(preview?.unresolved_tags) ? preview.unresolved_tags : [];
    const rows = Array.isArray(preview?.rows) ? preview.rows : [];
    const excludedRows = new Set((mappings.excludedRows ?? []).map(Number));
    const overwriteRows = new Set((mappings.overwriteRows ?? []).map(Number));
    const planBlocksCount = Array.isArray(preview?.plan?.blocks) ? preview.plan.blocks.length : 0;
    const canApply = preview?.can_apply === true && planBlocksCount > 0 && ! isApplying && ! isRefreshing;

    return (
        <div className="ac-v3-builder__dialog-backdrop" role="presentation">
            <div className="ac-v3-builder__sheet-import-dialog ac-v3-builder__auto-reply-import-dialog" role="dialog" aria-modal="true" aria-labelledby="auto-reply-import-title">
                <div className="ac-v3-builder__publish-dialog-head">
                    <h2 id="auto-reply-import-title">Импорт автоответов Excel</h2>
                    <button type="button" title="Закрыть" disabled={isApplying || isRefreshing} onClick={onClose}>×</button>
                </div>

                <div className="ac-v3-builder__sheet-import-body">
                    <div className="ac-v3-builder__sheet-import-summary ac-v3-builder__auto-reply-import-summary">
                        <span><strong>{summary.rows_total ?? 0}</strong> строк</span>
                        <span><strong>{summary.created ?? 0}</strong> создать</span>
                        <span><strong>{summary.updated ?? 0}</strong> обновить</span>
                        <span><strong>{summary.blocked ?? 0}</strong> блокировано</span>
                        <span><strong>{summary.conflicts ?? 0}</strong> конфликтов</span>
                        <span><strong>{summary.excluded ?? 0}</strong> исключено</span>
                    </div>

                    <div className="ac-v3-builder__sheet-import-warnings">
                        {(preview?.warnings ?? []).map((warning) => (
                            <p key={warning}>{warning}</p>
                        ))}
                    </div>

                    <div className="ac-v3-builder__auto-reply-import-section">
                        <h3>Куда разместить новые блоки</h3>
                        <div className="ac-v3-builder__auto-reply-import-placement" role="group" aria-label="Размещение новых блоков">
                            {AUTO_REPLY_IMPORT_PLACEMENTS.map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    className={placement === value ? 'is-active' : ''}
                                    disabled={isApplying || isRefreshing}
                                    onClick={() => onPlacementChange(value)}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {unresolvedChannels.length > 0 ? (
                        <div className="ac-v3-builder__auto-reply-import-section">
                            <h3>Сопоставить каналы</h3>
                            {unresolvedChannels.map((channel) => {
                                const key = String(channel.excel_channel_key ?? '');

                                return (
                                    <label key={key} className="ac-v3-builder__auto-reply-import-mapping">
                                        <span>
                                            <strong>{channel.name || `Канал ${key}`}</strong>
                                            <small>{key}{channel.platform ? ` · ${channel.platform}` : ''}</small>
                                        </span>
                                        <select
                                            value={mappings.channels?.[key] ?? ''}
                                            disabled={isApplying || isRefreshing}
                                            onChange={(event) => onChannelMapping(key, event.target.value)}
                                        >
                                            <option value="">Выбрать канал</option>
                                            {channels.map((item) => (
                                                <option key={item.id} value={item.id}>
                                                    #{item.id} {item.name} ({item.platform})
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                );
                            })}
                        </div>
                    ) : null}

                    {unresolvedTags.length > 0 ? (
                        <div className="ac-v3-builder__auto-reply-import-section">
                            <h3>Сопоставить теги</h3>
                            {unresolvedTags.map((tag) => {
                                const key = String(tag.excel_tag_name ?? '');

                                return (
                                    <label key={`${key}-${tag.column ?? ''}`} className="ac-v3-builder__auto-reply-import-mapping">
                                        <span>
                                            <strong>{tag.name || key}</strong>
                                            <small>{autoReplyImportColumnLabel(tag.column)}</small>
                                        </span>
                                        <div className="ac-v3-builder__auto-reply-import-map-control">
                                            <select
                                                value={mappings.tags?.[key] ?? ''}
                                                disabled={isApplying || isRefreshing}
                                                onChange={(event) => onTagMapping(key, event.target.value)}
                                            >
                                                <option value="">Выбрать тег</option>
                                                {tags.map((item) => (
                                                    <option key={item.id} value={item.id}>
                                                        {item.name}
                                                    </option>
                                                ))}
                                            </select>
                                            {canCreateTag ? (
                                                <button
                                                    type="button"
                                                    className="ac-v3-builder__auto-reply-import-create"
                                                    disabled={isApplying || isRefreshing || creatingTagName === key}
                                                    onClick={() => onCreateTag(key)}
                                                >
                                                    {creatingTagName === key ? 'Создаю...' : 'Создать'}
                                                </button>
                                            ) : null}
                                        </div>
                                    </label>
                                );
                            })}
                        </div>
                    ) : null}

                    <div className="ac-v3-builder__auto-reply-import-section">
                        <h3>Строки файла</h3>
                        <div className="ac-v3-builder__auto-reply-import-rows">
                            {rows.map((row) => {
                                const rowNumber = Number(row.row_number ?? 0);
                                const reasons = Array.isArray(row.reasons) ? row.reasons : [];
                                const isConflict = row.status === 'conflict';

                                return (
                                    <div key={rowNumber || `${row.source_rule_id}-${row.status}`} className="ac-v3-builder__auto-reply-import-row" data-status={row.status}>
                                        <span>
                                            <strong>#{row.source_rule_id || rowNumber}</strong>
                                            <small>строка {rowNumber}</small>
                                        </span>
                                        <span>
                                            <strong>{row.name || 'Без названия'}</strong>
                                            <small>{autoReplyImportStatusLabel(row.status)}</small>
                                        </span>
                                        <span>
                                            {reasons.length > 0
                                                ? reasons.map(autoReplyImportReasonLabel).join(', ')
                                                : 'Готово'}
                                        </span>
                                        <label>
                                            <input
                                                type="checkbox"
                                                checked={excludedRows.has(rowNumber)}
                                                disabled={isApplying || isRefreshing}
                                                onChange={(event) => onToggleExcluded(rowNumber, event.target.checked)}
                                            />
                                            Исключить
                                        </label>
                                        <label>
                                            <input
                                                type="checkbox"
                                                checked={overwriteRows.has(rowNumber)}
                                                disabled={! isConflict || isApplying || isRefreshing}
                                                onChange={(event) => onToggleOverwrite(rowNumber, event.target.checked)}
                                            />
                                            Перезаписать
                                        </label>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {error ? <p className="ac-v3-builder__sheet-import-error">{error}</p> : null}
                </div>

                <div className="ac-v3-builder__publish-dialog-footer">
                    <button type="button" disabled={isApplying || isRefreshing} onClick={onRefresh}>
                        {isRefreshing ? 'Проверяем...' : 'Обновить проверку'}
                    </button>
                    <button type="button" disabled={isApplying || isRefreshing} onClick={onClose}>
                        Закрыть
                    </button>
                    <button type="button" className="is-success" disabled={! canApply} onClick={onApply}>
                        {isApplying ? 'Импортируем...' : 'Импортировать'}
                    </button>
                </div>
            </div>
        </div>
    );
}

function storedPanelWidth() {
    if (typeof window === 'undefined') {
        return PANEL_WIDTH_DEFAULT;
    }

    const width = Number(window.localStorage.getItem(PANEL_WIDTH_STORAGE_KEY));

    return Number.isFinite(width) ? clamp(width, PANEL_WIDTH_MIN, panelMaxWidth()) : PANEL_WIDTH_DEFAULT;
}

function savePanelWidth(width) {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(PANEL_WIDTH_STORAGE_KEY, String(Math.round(width)));
}

function panelMaxWidth() {
    if (typeof window === 'undefined') {
        return PANEL_WIDTH_MAX;
    }

    return Math.max(PANEL_WIDTH_MIN, Math.min(PANEL_WIDTH_MAX, Math.floor(window.innerWidth * 0.55)));
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
    const edgeId = transitionEdgeId(transition, edge);

    return (
        <article className={`ac-v3-builder__log-row is-${transition.status ?? 'unknown'}`}>
            <div className="ac-v3-builder__log-main">
                <strong>{transition.status_label ?? edgeTransitionStatusLabel(transition.status)}</strong>
                <span>Стрелка {edgeId} · переход #{transition.id} · v{transition.published_version_id} · run #{transition.scenario_run_id}</span>
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

function searchBlocks(blocks, query) {
    const needle = normalizeSearchValue(query);

    if (needle === '') {
        return [];
    }

    return (Array.isArray(blocks) ? blocks : []).filter((block) => blockSearchHaystack(block).includes(needle));
}

function blockSearchHaystack(block) {
    const modules = modulesFrom(block?.settings_payload);
    const moduleParts = modules.flatMap((module) => [
        moduleLabel(module.type),
        module?.payload?.text,
        module?.payload?.command,
        module?.payload?.template_key,
        module.type === 'action' ? actionSummary(module) : null,
        module.type === 'ai' ? aiSummary(module) : null,
        module.type === 'buttons' ? flatButtons(module).map((button) => button.text).join(' ') : null,
    ]);

    return normalizeSearchValue([
        copyableBlockId(block),
        shortBlockId(block),
        block?.id,
        block?.display_id,
        block?.client_key,
        block?.title,
        block?.type,
        nodeTypeLabel(Boolean(findModule(block?.settings_payload, 'start_condition')), block?.settings_payload?.kind),
        ...moduleParts,
    ].filter(Boolean).join(' '));
}

function normalizeSearchValue(value) {
    return String(value ?? '')
        .toLocaleLowerCase('ru-RU')
        .replace(/ё/g, 'е')
        .replace(/^#/, '')
        .trim();
}

function BlockSearchControl({
    query,
    matchCount,
    matchIndex,
    onQueryChange,
    onOpen,
    onPrevious,
    onNext,
}) {
    const hasQuery = String(query ?? '').trim() !== '';
    const hasMatches = matchCount > 0;

    return (
        <div className="ac-v3-builder__block-search">
            <input
                type="search"
                value={query}
                placeholder="Номер или название"
                aria-label="Найти блок по номеру или названию"
                onChange={(event) => onQueryChange(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        onOpen();
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        onQueryChange('');
                    }
                }}
            />
            <span className="ac-v3-builder__block-search-count">
                {hasQuery ? (hasMatches ? `${matchIndex + 1}/${matchCount}` : '0') : '№'}
            </span>
            <button type="button" title="Предыдущий найденный блок" disabled={! hasMatches} onClick={onPrevious}>‹</button>
            <button type="button" title="Следующий найденный блок" disabled={! hasMatches} onClick={onNext}>›</button>
            <button type="button" title="Открыть найденный блок" disabled={! hasQuery} onClick={onOpen}>Найти</button>
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
            <button type="button" title="Создать ИИ-анализ" onClick={() => onAddBlock('ai')}>
                <SparkleIcon />
            </button>
        </aside>
    );
}

function ScenarioNode({
    block,
    selected,
    pendingTarget,
    rewireTarget,
    connectedOutputIds,
    onSelect,
    onDragStart,
    onStartConnection,
    onStartDefaultConnection,
    onDuplicate,
    onRemove,
}) {
    const visibleOutputs = visibleBlockOutputs(block);
    const start = findModule(block.settings_payload, 'start_condition');
    const message = findModule(block.settings_payload, 'message');
    const buttons = findModule(block.settings_payload, 'buttons');
    const ai = findModule(block.settings_payload, 'ai');
    const action = findModule(block.settings_payload, 'action');
    const hasAction = hasRegularAction(action);
    const position = blockPosition(block);
    const modules = modulesFrom(block.settings_payload);
    const displayModuleTypes = MODULE_DISPLAY_ORDER.filter((type) => (
        type === 'action' ? hasAction : modules.some((module) => module.type === type)
    ));
    const blockKind = block.settings_payload?.kind === 'non_state' ? 'non_state' : 'state';

    return (
        <article
            data-node
            data-node-key={block.client_key}
            className={[
                'ac-v3-builder__node',
                selected ? 'is-selected' : '',
                pendingTarget ? 'is-targetable' : '',
                rewireTarget ? 'is-rewire-target' : '',
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
                    <button
                        type="button"
                        title="Копировать блок"
                        onClick={(event) => {
                            event.stopPropagation();
                            onDuplicate();
                        }}
                    >
                        <CopyIcon />
                        <span>Копировать</span>
                    </button>
                    <button
                        type="button"
                        className="is-danger"
                        title="Удалить блок"
                        onClick={(event) => {
                            event.stopPropagation();
                            onRemove();
                        }}
                    >
                        <TrashIcon />
                        <span>Удалить</span>
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
                {displayModuleTypes.map((type) => (
                    <b key={type} className={MODULE_META[type].className}>{MODULE_META[type].short}</b>
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
                {ai ? (
                    <ModulePreview type="ai" label="ИИ" value={aiSummary(ai)} />
                ) : null}
                {hasAction ? (
                    <ModulePreview type="action" label="Действие" value={actionSummary(action)} />
                ) : null}
            </div>

            {visibleOutputs.length > 0 ? (
                <div className="ac-v3-builder__ports">
                    {visibleOutputs.map((output) => (
                        <button
                            key={output.id ?? 'default'}
                            type="button"
                            className={[
                                connectedOutputIds.has(output.id ?? 'default') ? 'is-connected' : '',
                                output.kind === 'default'
                                    ? 'is-default-output'
                                    : (output.kind === 'action' ? 'is-action-output' : 'is-button-output'),
                                output.legacy ? 'is-legacy-output' : '',
                            ].filter(Boolean).join(' ')}
                            title={output.hint || (output.kind === 'default'
                                ? 'Связать автопереход с блоком'
                                : (output.legacy
                                    ? 'Старый выход: можно удалить существующую стрелку, новые стрелки не создаются'
                                    : (output.kind === 'ai'
                                        ? 'Связать результат ИИ с блоком'
                                        : (output.kind === 'action' ? 'Связать результат действия с блоком' : 'Связать кнопку с блоком'))))}
                            onPointerDown={(event) => onStartConnection(event, block, output)}
                            onClick={(event) => event.stopPropagation()}
                        >
                            <i className="is-left" data-port-key={portAnchorKey(block.client_key, output.id, 'left')} />
                            <span>
                                <strong>{output.label}</strong>
                                {output.caption ? <em>{output.caption}</em> : null}
                            </span>
                            <i className="is-right" data-port-key={portAnchorKey(block.client_key, output.id, 'right')} />
                        </button>
                    ))}
                </div>
            ) : null}
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

function EdgePath({
    edge,
    blocks,
    anchors,
    selected,
    selectedWaypointId,
    waypointPreview,
    onSelect,
    onOpenSettings,
    onStartRewire,
    onAddWaypoint,
    onSelectWaypoint,
    onRemoveWaypoint,
    onStartWaypointDrag,
}) {
    const sourceBlock = blocks.find((block) => block.client_key === edge.source?.client_key);
    const targetBlock = blocks.find((block) => block.client_key === edge.target?.client_key);

    if (! sourceBlock || ! targetBlock) {
        return null;
    }

    const waypoints = edgeWaypoints(edge, waypointPreview);
    const sourceReference = waypoints[0] ?? blockCenter(targetBlock, anchors);
    const source = edgeSourceAnchor(edge, sourceBlock, sourceReference, anchors);
    const targetReference = waypoints[waypoints.length - 1] ?? source;
    const target = edgeTargetAnchor(targetBlock, targetReference, anchors);
    const routePoints = edgeRoutePoints(source, target, waypoints);
    const d = edgeRoutePath(routePoints);
    const labelPoint = edgeRouteLabelPoint(routePoints, source, target);
    const isButton = isButtonEdge(edge, sourceBlock);
    const visualKind = edgeVisualKind(edge, sourceBlock);
    const edgeClassName = [
        selected ? 'is-selected' : '',
        `is-${visualKind}-edge`,
    ].filter(Boolean).join(' ');
    const markerId = selected ? 'ac-v3-arrow-selected' : `ac-v3-arrow-${visualKind}`;
    const label = edgeLabel(edge, isButton);
    const title = edgeVisualTitle(visualKind);
    const fullLabel = edgeFullLabel(edge);

    return (
        <g className={edgeClassName}>
            <title>{title}: {fullLabel}</title>
            <path
                data-edge-action
                d={d}
                className="ac-v3-builder__edge-hit"
                onClick={onSelect}
                onDoubleClick={(event) => onAddWaypoint(event, routePoints)}
            />
            {selected ? <path d={d} className="ac-v3-builder__edge-selection" /> : null}
            <path d={d} className="ac-v3-builder__edge" markerEnd={`url(#${markerId})`} />
            {label ? (
                <text
                    x={labelPoint.x}
                    y={labelPoint.y}
                    className="ac-v3-builder__edge-label"
                    textAnchor="middle"
                    dominantBaseline="central"
                    data-edge-action
                    onClick={(event) => {
                        event.stopPropagation();
                        onSelect();
                    }}
                    onDoubleClick={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                    }}
                >
                    {label}
                </text>
            ) : null}
            {selected ? (
                <>
                    <circle
                        data-edge-action
                        cx={source.x}
                        cy={source.y}
                        r="8"
                        className="ac-v3-builder__edge-endpoint is-source"
                        onPointerDown={(event) => onStartRewire(event, 'source')}
                    />
                    <circle
                        data-edge-action
                        cx={target.x}
                        cy={target.y}
                        r="8"
                        className="ac-v3-builder__edge-endpoint is-target"
                        onPointerDown={(event) => onStartRewire(event, 'target')}
                    />
                    <g
                        data-edge-action
                        className="ac-v3-builder__edge-gear"
                        transform={`translate(${labelPoint.x} ${labelPoint.y})`}
                        onClick={(event) => {
                            event.stopPropagation();
                            onOpenSettings();
                        }}
                    >
                        <circle r="14" />
                        <text textAnchor="middle" dominantBaseline="central">⚙</text>
                    </g>
                    {waypoints.map((waypoint) => (
                        <circle
                            key={waypoint.id}
                            data-edge-action
                            cx={waypoint.x}
                            cy={waypoint.y}
                            r={waypoint.id === selectedWaypointId ? 7 : 6}
                            className={[
                                'ac-v3-builder__edge-waypoint',
                                waypoint.id === selectedWaypointId ? 'is-selected' : '',
                            ].filter(Boolean).join(' ')}
                            onClick={(event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                onSelectWaypoint(waypoint.id);
                            }}
                            onDoubleClick={(event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                onRemoveWaypoint(waypoint.id);
                            }}
                            onPointerDown={(event) => onStartWaypointDrag(event, waypoint.id)}
                        >
                            <title>Перетащить. Двойной клик — удалить</title>
                        </circle>
                    ))}
                </>
            ) : ! isButton ? (
                <circle
                    cx={source.x}
                    cy={source.y}
                    r="4"
                    className="ac-v3-builder__edge-source-dot"
                />
            ) : null}
        </g>
    );
}

function edgeSourceAnchor(edge, sourceBlock, referencePoint, anchors) {
    const outputId = edge.source?.output_id ?? null;

    if (outputId === null) {
        return nearestBlockSideAnchor(sourceBlock, referencePoint, anchors);
    }

    const side = blockHorizontalSideToward(sourceBlock, referencePoint, anchors);
    const portAnchor = anchors.ports[portAnchorKey(sourceBlock.client_key, outputId, side)];

    if (portAnchor) {
        return { ...portAnchor, side };
    }

    return outputAnchor(sourceBlock, outputId, side);
}

function edgeTargetAnchor(targetBlock, referencePoint, anchors) {
    return shiftAnchorOutside(nearestBlockSideAnchor(targetBlock, referencePoint, anchors), EDGE_TARGET_CLEARANCE);
}

function edgeRoutePoints(source, target, waypoints) {
    return [source, ...waypoints, target];
}

function edgeRoutePath(points) {
    if (points.length < 2) {
        return '';
    }

    const segments = edgeRouteSegments(points);
    const [first] = points;
    let path = `M ${first.x} ${first.y}`;

    segments.forEach((segment) => {
        path += ` C ${segment.c1.x} ${segment.c1.y}, ${segment.c2.x} ${segment.c2.y}, ${segment.end.x} ${segment.end.y}`;
    });

    return path;
}

function edgeRouteLabelPoint(points, source, target) {
    if (points.length <= 2) {
        return edgeCurveLabelPoint(source, target);
    }

    return routeCurveMidpoint(edgeRouteSegments(points));
}

function edgeCurvePath(source, target) {
    const { c1, c2 } = edgeCurveControlPoints(source, target);

    return `M ${source.x} ${source.y} C ${c1.x} ${c1.y}, ${c2.x} ${c2.y}, ${target.x} ${target.y}`;
}

function edgeCurveLabelPoint(source, target) {
    const { c1, c2 } = edgeCurveControlPoints(source, target);

    return cubicBezierPoint(source, c1, c2, target, 0.5);
}

function edgeCurveControlPoints(source, target) {
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

    return { c1, c2 };
}

function edgeRouteControlPoints(source, target) {
    const routeSource = {
        ...source,
        side: source.side ?? sideFromDelta(target.x - source.x, target.y - source.y),
    };
    const routeTarget = {
        ...target,
        side: target.side ?? sideFromDelta(source.x - target.x, source.y - target.y),
    };

    return edgeCurveControlPoints(routeSource, routeTarget);
}

function edgeRouteSegments(points) {
    if (points.length < 2) {
        return [];
    }

    if (points.length === 2) {
        const [source, target] = points;
        const { c1, c2 } = edgeRouteControlPoints(source, target);

        return [{ start: source, c1, c2, end: target }];
    }

    const tangents = points.map((point, index) => edgeRouteTangent(points, index));

    return points.slice(0, -1).map((start, index) => {
        const end = points[index + 1];
        const length = Math.hypot(end.x - start.x, end.y - start.y);
        const handle = Math.min(length * 0.34, 150);
        const startTangent = tangents[index];
        const endTangent = tangents[index + 1];

        return {
            start,
            c1: {
                x: start.x + (startTangent.x * handle),
                y: start.y + (startTangent.y * handle),
            },
            c2: {
                x: end.x - (endTangent.x * handle),
                y: end.y - (endTangent.y * handle),
            },
            end,
        };
    });
}

function edgeRouteTangent(points, index) {
    const point = points[index];
    const previous = points[index - 1] ?? null;
    const next = points[index + 1] ?? null;

    if (index === 0 && point.side) {
        return sideVector(point.side);
    }

    if (index === points.length - 1 && point.side) {
        const vector = sideVector(point.side);

        return { x: -vector.x, y: -vector.y };
    }

    if (previous && next) {
        return normalizedVector(next.x - previous.x, next.y - previous.y)
            ?? normalizedVector(next.x - point.x, next.y - point.y)
            ?? normalizedVector(point.x - previous.x, point.y - previous.y)
            ?? { x: 1, y: 0 };
    }

    if (next) {
        return normalizedVector(next.x - point.x, next.y - point.y) ?? { x: 1, y: 0 };
    }

    if (previous) {
        return normalizedVector(point.x - previous.x, point.y - previous.y) ?? { x: 1, y: 0 };
    }

    return { x: 1, y: 0 };
}

function normalizedVector(dx, dy) {
    const length = Math.hypot(dx, dy);

    if (length <= 0) {
        return null;
    }

    return {
        x: dx / length,
        y: dy / length,
    };
}

function sideFromDelta(dx, dy) {
    if (Math.abs(dx) >= Math.abs(dy)) {
        return dx >= 0 ? 'right' : 'left';
    }

    return dy >= 0 ? 'bottom' : 'top';
}

function polylineMidpoint(points) {
    const segments = [];
    let total = 0;

    for (let index = 0; index < points.length - 1; index += 1) {
        const start = points[index];
        const end = points[index + 1];
        const length = Math.hypot(end.x - start.x, end.y - start.y);

        segments.push({ start, end, length });
        total += length;
    }

    if (total <= 0) {
        return points[0] ?? { x: 0, y: 0 };
    }

    let remaining = total / 2;

    for (const segment of segments) {
        if (remaining > segment.length) {
            remaining -= segment.length;

            continue;
        }

        const t = segment.length <= 0 ? 0 : remaining / segment.length;

        return {
            x: segment.start.x + ((segment.end.x - segment.start.x) * t),
            y: segment.start.y + ((segment.end.y - segment.start.y) * t),
        };
    }

    return points[points.length - 1] ?? { x: 0, y: 0 };
}

function routeCurveMidpoint(segments) {
    const samples = [];
    let total = 0;

    segments.forEach((segment) => {
        let previous = segment.start;

        for (let step = 1; step <= 18; step += 1) {
            const point = cubicBezierPoint(segment.start, segment.c1, segment.c2, segment.end, step / 18);
            const length = Math.hypot(point.x - previous.x, point.y - previous.y);

            total += length;
            samples.push({ start: previous, end: point, length });
            previous = point;
        }
    });

    if (total <= 0) {
        return segments[0]?.start ?? { x: 0, y: 0 };
    }

    let remaining = total / 2;

    for (const sample of samples) {
        if (remaining > sample.length) {
            remaining -= sample.length;

            continue;
        }

        const t = sample.length <= 0 ? 0 : remaining / sample.length;

        return {
            x: sample.start.x + ((sample.end.x - sample.start.x) * t),
            y: sample.start.y + ((sample.end.y - sample.start.y) * t),
        };
    }

    return segments[segments.length - 1]?.end ?? { x: 0, y: 0 };
}

function edgeWaypoints(edge, preview = null) {
    const rawWaypoints = edge?.condition_payload?.ui?.waypoints;

    if (! Array.isArray(rawWaypoints)) {
        return [];
    }

    return rawWaypoints
        .filter((waypoint) => (
            waypoint
            && typeof waypoint === 'object'
            && typeof waypoint.id === 'string'
            && waypoint.id.length > 0
            && waypoint.id.length <= EDGE_WAYPOINT_ID_LIMIT
            && Number.isFinite(Number(waypoint.x))
            && Number.isFinite(Number(waypoint.y))
        ))
        .slice(0, EDGE_MAX_WAYPOINTS)
        .map((waypoint) => {
            if (preview?.waypointId === waypoint.id && preview?.point) {
                return {
                    id: waypoint.id,
                    x: roundWaypointCoordinate(preview.point.x),
                    y: roundWaypointCoordinate(preview.point.y),
                };
            }

            return {
                id: waypoint.id,
                x: roundWaypointCoordinate(Number(waypoint.x)),
                y: roundWaypointCoordinate(Number(waypoint.y)),
            };
        });
}

function edgeWithWaypoints(edge, waypoints) {
    const ui = {
        ...(edge.condition_payload?.ui ?? {}),
        waypoints: waypoints.slice(0, EDGE_MAX_WAYPOINTS).map((waypoint) => ({
            id: waypoint.id,
            x: roundWaypointCoordinate(waypoint.x),
            y: roundWaypointCoordinate(waypoint.y),
        })),
    };

    return {
        ...edge,
        condition_payload: {
            ...(edge.condition_payload ?? {}),
            ui,
        },
    };
}

function nextEdgeWaypointId(waypoints) {
    const used = new Set(waypoints.map((waypoint) => waypoint.id));

    for (let attempt = 0; attempt < 20; attempt += 1) {
        const id = `wp_${Date.now().toString(36)}_${attempt.toString(36)}`;

        if (! used.has(id) && id.length <= EDGE_WAYPOINT_ID_LIMIT) {
            return id;
        }
    }

    return `wp_${Math.random().toString(36).slice(2, 12)}`;
}

function nearestRouteSegmentIndex(routePoints, point) {
    if (! Array.isArray(routePoints) || routePoints.length < 2) {
        return 0;
    }

    let bestIndex = 0;
    let bestDistance = Number.POSITIVE_INFINITY;

    for (let index = 0; index < routePoints.length - 1; index += 1) {
        const distance = distanceToSegment(point, routePoints[index], routePoints[index + 1]);

        if (distance < bestDistance) {
            bestDistance = distance;
            bestIndex = index;
        }
    }

    return bestIndex;
}

function distanceToSegment(point, start, end) {
    const dx = end.x - start.x;
    const dy = end.y - start.y;
    const lengthSquared = (dx * dx) + (dy * dy);

    if (lengthSquared <= 0) {
        return Math.hypot(point.x - start.x, point.y - start.y);
    }

    const t = clamp(
        (((point.x - start.x) * dx) + ((point.y - start.y) * dy)) / lengthSquared,
        0,
        1,
    );
    const projection = {
        x: start.x + (dx * t),
        y: start.y + (dy * t),
    };

    return Math.hypot(point.x - projection.x, point.y - projection.y);
}

function roundWaypointCoordinate(value) {
    return Math.round(Number(value) * 100) / 100;
}

function isTextEditingTarget(target) {
    if (!(target instanceof Element)) {
        return false;
    }

    return Boolean(target.closest('input, textarea, select, [contenteditable="true"]'));
}

function cubicBezierPoint(p0, p1, p2, p3, t) {
    const oneMinusT = 1 - t;
    const a = oneMinusT ** 3;
    const b = 3 * (oneMinusT ** 2) * t;
    const c = 3 * oneMinusT * (t ** 2);
    const d = t ** 3;

    return {
        x: (a * p0.x) + (b * p1.x) + (c * p2.x) + (d * p3.x),
        y: (a * p0.y) + (b * p1.y) + (c * p2.y) + (d * p3.y),
    };
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

function isButtonEdge(edge, sourceBlock = null) {
    return Boolean(edge?.source?.output_id ?? edge?.condition_payload?.from_output_id)
        && ! isAiEdge(edge)
        && ! isActionResultEdge(edge, sourceBlock);
}

function isAiEdge(edge) {
    return edge?.condition_payload?.mode === 'ai_analysis';
}

function isActionResultEdge(edge, sourceBlock = null) {
    if (edge?.condition_payload?.mode === 'action_result') {
        return true;
    }

    const outputId = edge?.source?.output_id ?? edge?.condition_payload?.from_output_id;

    if (! outputId || ! sourceBlock) {
        return false;
    }

    const output = blockOutputs(sourceBlock).find((candidate) => candidate.id === outputId) ?? null;

    return output?.kind === 'action';
}

function isDefaultEdge(edge, sourceBlock = null) {
    return ! isButtonEdge(edge, sourceBlock)
        && ! isAiEdge(edge)
        && ! isActionResultEdge(edge, sourceBlock);
}

function edgeVisualKind(edge, sourceBlock = null) {
    if (isAiEdge(edge)) {
        return 'ai';
    }

    if (isActionResultEdge(edge, sourceBlock)) {
        return 'action';
    }

    if (isButtonEdge(edge, sourceBlock)) {
        return 'button';
    }

    return edge?.condition_payload?.mode === 'automatic' ? 'auto' : 'wait';
}

function edgeVisualTitle(kind) {
    if (kind === 'button') {
        return 'Связь от кнопки';
    }

    if (kind === 'ai') {
        return 'Связь от ИИ-анализа';
    }

    if (kind === 'action') {
        return 'Связь от результата действия';
    }

    if (kind === 'auto') {
        return 'Автоматическая связь';
    }

    return 'Связь ждёт ответ клиента';
}

function defaultEdgeForBlock(block, edges) {
    return edges.find((edge) => edge.source?.client_key === block.client_key && isDefaultEdge(edge, block)) ?? null;
}

function edgeLabel(edge, isButton = isButtonEdge(edge)) {
    const label = edgeFullLabel(edge, isButton);

    if (edgeUsesOutputLabel(edge, isButton)) {
        return label;
    }

    return truncate(label, EDGE_CANVAS_LABEL_LIMIT);
}

function edgeFullLabel(edge, isButton = isButtonEdge(edge)) {
    const label = String(edge?.condition_payload?.label ?? '').trim();

    if (edgeUsesOutputLabel(edge, isButton)) {
        return truncate(label, EDGE_TOOLTIP_LABEL_LIMIT);
    }

    if (edgeLabelMode(edge) === EDGE_LABEL_MODE_MANUAL) {
        return truncate(label || DEFAULT_OUTPUT.label, EDGE_TOOLTIP_LABEL_LIMIT);
    }

    return truncate(edgeAutoLabel(edge) || DEFAULT_OUTPUT.label, EDGE_TOOLTIP_LABEL_LIMIT);
}

function edgeUsesOutputLabel(edge, isButton = isButtonEdge(edge)) {
    return isButton
        || isAiEdge(edge)
        || isActionResultEdge(edge)
        || Boolean(edge?.source?.output_id ?? edge?.condition_payload?.from_output_id);
}

function edgeLabelMode(edge) {
    const mode = edge?.condition_payload?.ui?.label_mode;

    if ([EDGE_LABEL_MODE_AUTO, EDGE_LABEL_MODE_MANUAL].includes(mode)) {
        return mode;
    }

    const label = String(edge?.condition_payload?.label ?? '').trim();

    return label && label !== DEFAULT_OUTPUT.label ? EDGE_LABEL_MODE_MANUAL : EDGE_LABEL_MODE_AUTO;
}

function edgeAutoLabel(edge) {
    const payload = edge?.condition_payload ?? {};
    const parts = [];
    const expression = String(payload.expression ?? '').trim();

    if (expression) {
        parts.push(`Условие: ${expression}`);
    }

    const fieldConditionLabel = edgeFieldConditionLabel(payload.field_condition ?? {});

    if (fieldConditionLabel) {
        parts.push(fieldConditionLabel);
    }

    if (payload.contact_phone_condition) {
        parts.push(`Телефон контакта: ${optionLabel(PHONE_CONDITION_OPTIONS, payload.contact_phone_condition)}`);
    }

    if (payload.dialog_phone_condition) {
        parts.push(`Телефон мессенджера: ${optionLabel(PHONE_CONDITION_OPTIONS, payload.dialog_phone_condition)}`);
    }

    const mode = payload.mode === 'automatic' ? 'automatic' : 'wait_reply';

    if (mode === 'wait_reply') {
        const matchLabel = edgeMatchLabel(payload.match ?? {});

        if (matchLabel) {
            parts.push(matchLabel);
        }

        const captureLabel = edgeCaptureLabel(payload.input_capture ?? {});

        if (captureLabel) {
            parts.push(captureLabel);
        }
    }

    if (mode === 'automatic') {
        const delayLabel = edgeDelayLabel(payload.delay);

        if (delayLabel) {
            parts.push(delayLabel);
        }
    }

    const transitionLimit = Math.max(0, Number(payload.transition_limit) || 0);

    if (transitionLimit > 0) {
        parts.push(`Лимит: ${transitionLimit}`);
    }

    return parts.join(' · ');
}

function edgeFieldConditionLabel(fieldCondition) {
    if (fieldCondition?.enabled !== true) {
        return '';
    }

    const scope = fieldCondition.field_scope === 'contact' ? 'contact' : 'dialog';
    const field = scope === 'contact'
        ? contactConditionField(fieldCondition.field_key)
        : [fieldCondition.field_key, dictionaryFieldLabel(FIELD_DICTIONARY_ENTITY_DIALOG, fieldCondition.field_key, `dialog.${fieldCondition.field_key}`)];
    const fieldLabel = field[1] ?? field[0] ?? 'Поле';
    const operator = optionLabel(EDGE_FIELD_CONDITION_OPERATOR_OPTIONS, fieldCondition.operator || 'filled');
    const hasValue = ['equals', 'not_equals'].includes(fieldCondition.operator);
    const value = hasValue ? edgeFieldConditionValueLabel(fieldCondition, scope, field[0]) : '';

    return `${fieldLabel}: ${operator}${value ? ` ${value}` : ''}`;
}

function edgeFieldConditionValueLabel(fieldCondition, scope, fieldKey) {
    const value = String(fieldCondition.value ?? '').trim();

    if (! value) {
        return '""';
    }

    return dictionaryFieldValueLabel(scope, fieldKey, value, value);
}

function edgeMatchLabel(match) {
    const type = EDGE_MATCH_OPTIONS.some(([value]) => value === match.type) ? match.type : 'any_inbound';

    if (type === 'any_inbound') {
        return 'Любое входящее';
    }

    const value = String(match.text ?? '').trim();

    if (type === 'contains_text') {
        return `Содержит: ${value || '—'}`;
    }

    return `${optionLabel(EDGE_MATCH_OPTIONS, type)}: ${value || '—'}`;
}

function edgeCaptureLabel(capture) {
    if (capture?.enabled !== true) {
        return '';
    }

    const scope = capture.field_scope === 'contact' ? 'contact' : 'dialog';
    const field = scope === 'contact'
        ? contactCaptureField(capture.field_key)
        : [capture.field_key, dictionaryFieldLabel(FIELD_DICTIONARY_ENTITY_DIALOG, capture.field_key, `dialog.${capture.field_key}`)];
    const fieldLabel = field[1] ?? field[0] ?? 'поле';

    return `Записать: ${fieldLabel}`;
}

function edgeDelayLabel(delay) {
    const normalized = normalizedEdgeDelay(delay);

    if (normalized.type === 'relative') {
        return `Через ${normalized.value} ${edgeDelayUnitLabel(normalized.value, normalized.unit)}`;
    }

    if (normalized.type === 'scheduled') {
        return 'В дату и время';
    }

    return '';
}

function edgeDelayUnitLabel(value, unit) {
    const number = Math.abs(Number(value) || 0);
    const lastTwo = number % 100;
    const last = number % 10;

    if (unit === 'min') {
        if (lastTwo >= 11 && lastTwo <= 14) {
            return 'минут';
        }

        if (last === 1) {
            return 'минута';
        }

        return [2, 3, 4].includes(last) ? 'минуты' : 'минут';
    }

    if (lastTwo >= 11 && lastTwo <= 14) {
        return 'секунд';
    }

    if (last === 1) {
        return 'секунда';
    }

    return [2, 3, 4].includes(last) ? 'секунды' : 'секунд';
}

function optionLabel(options, value) {
    return options.find(([optionValue]) => optionValue === value)?.[1] ?? String(value ?? '');
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
    const displayId = numericEdgeId(edge?.condition_payload?.ui?.edge_id);

    if (displayId) {
        return displayId;
    }

    if (edge?.id) {
        return String(edge.id);
    }

    return String(edge?.client_key ?? '').replace(/^tmp_edge_/, '').replace(/^edge_/, '').slice(0, 8);
}

function numericEdgeId(value) {
    const displayId = String(value ?? '').trim();

    return /^\d+$/.test(displayId) ? displayId : '';
}

function transitionEdgeId(transition, edge) {
    if (edge) {
        return shortEdgeId(edge);
    }

    const edgeId = numericEdgeId(transition?.edge_id);

    return edgeId ? `#${edgeId}` : '#draft';
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
    return String(
        block?.display_id
        ?? block?.settings_payload?.ui?.display_number
        ?? block?.settings_payload?.ui?.card_id
        ?? '',
    ).trim();
}

function cloneBlockSettingsForCopy(settingsPayload) {
    const payload = clonePlainObject(settingsPayload ?? {});
    const ui = payload.ui && typeof payload.ui === 'object' ? payload.ui : {};

    payload.ui = {
        ...ui,
        card_id: '',
        display_number: '',
    };

    return payload;
}

function clonePlainObject(value) {
    if (typeof structuredClone === 'function') {
        return structuredClone(value);
    }

    return JSON.parse(JSON.stringify(value));
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
    tags,
    blocks,
    dialogFieldKeys,
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
    onRemoveAiVariant,
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
    const ai = findModule(block.settings_payload, 'ai');
    const action = findModule(block.settings_payload, 'action');
    const hasAction = hasRegularAction(action);
    const startChannels = start?.payload?.channels?.ids ?? [];
    const buttonsSummary = buttons ? buttonSummary(buttons) : '';
    const blockKind = block.settings_payload?.kind === 'non_state' ? 'non_state' : 'state';
    const activeModuleTypes = new Set();

    modulesFrom(block.settings_payload).forEach((module) => {
        if (module.type === 'action') {
            if (hasAction) {
                activeModuleTypes.add('action');
            }

            return;
        }

        activeModuleTypes.add(module.type);
    });

    const activeModules = activeModuleTypes.size;

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
                        title="Скопировать номер блока"
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
                    <ModuleSwitch
                        type="ai"
                        checked={Boolean(ai)}
                        onChange={(checked) => onToggleModule(block.client_key, 'ai', checked)}
                    />
                    <ModuleSwitch
                        type="action"
                        checked={hasAction}
                        onChange={(checked) => onToggleModule(block.client_key, 'action', checked)}
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
                        {MODULE_DISPLAY_ORDER.filter((type) => activeModuleTypes.has(type)).map((type) => (
                            <ModuleConfigCard
                                key={type}
                                type={type}
                                summary={type === 'buttons' ? buttonsSummary : null}
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
                                    <MessageFields
                                        message={message}
                                        blockKey={block.client_key}
                                        onUpdateModulePayload={onUpdateModulePayload}
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
                                {type === 'ai' ? (
                                    <AiFields
                                        ai={ai}
                                        blockKey={block.client_key}
                                        onUpdateModulePayload={onUpdateModulePayload}
                                        onRemoveAiVariant={onRemoveAiVariant}
                                    />
                                ) : null}
                                {type === 'action' ? (
                                    <ActionFields
                                        action={action}
                                        blocks={blocks}
                                        tags={tags}
                                        blockKey={block.client_key}
                                        onUpdateModulePayload={onUpdateModulePayload}
                                        dialogFieldKeys={dialogFieldKeys}
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
    const onCloseRef = useRef(onClose);
    const [text, setText] = useState(button.text ?? '');
    const [type, setType] = useState(BUTTON_TYPE_OPTIONS.some(([value]) => value === button.type) ? button.type : BUTTON_TYPE_TEXT);
    const [url, setUrl] = useState(button.url ?? '');
    const [color, setColor] = useState(button.color ?? null);

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    useEffect(() => {
        function focusInput() {
            if (! inputRef.current) {
                return;
            }

            const input = inputRef.current;
            const cursorPosition = input.value.length;

            input.focus();
            input.setSelectionRange(cursorPosition, cursorPosition);
        }

        focusInput();
        const focusTimer = window.setTimeout(focusInput, 0);

        function handleKeyDown(event) {
            if (event.key === 'Escape') {
                onCloseRef.current();
            }
        }

        document.addEventListener('keydown', handleKeyDown);

        return () => {
            window.clearTimeout(focusTimer);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, []);

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

                {type === BUTTON_TYPE_LINK ? (
                    <label className="ac-v3-builder__button-dialog-field">
                        <span>Ссылка</span>
                        <div className="ac-v3-builder__button-dialog-input-wrap">
                            <input
                                value={url}
                                maxLength={2000}
                                placeholder="https://example.com"
                                onChange={(event) => setUrl(event.target.value)}
                            />
                        </div>
                    </label>
                ) : null}

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
                        onClick={() => onSave({ text, type, url: type === BUTTON_TYPE_LINK ? url : null, color })}
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

function AiFields({ ai, blockKey, onUpdateModulePayload, onRemoveAiVariant }) {
    const prompt = typeof ai?.payload?.prompt === 'string' ? ai.payload.prompt : DEFAULT_AI_PROMPT;
    const variants = aiVariantDefinitions(ai);
    const extractFields = aiExtractFieldDefinitions(ai);
    const [isVariableHelpOpen, setIsVariableHelpOpen] = useState(false);
    const variableGroups = aiPromptVariableGroups();

    function updateVariant(variantId, patch) {
        onUpdateModulePayload(blockKey, 'ai', {
            variants: variants.map((variant) => (
                variant.id === variantId ? { ...variant, ...patch } : variant
            )),
        });
    }

    function addVariant() {
        onUpdateModulePayload(blockKey, 'ai', {
            variants: [
                ...variants,
                { id: nextAiVariantId(variants), label: 'Новый вариант' },
            ],
        });
    }

    function removeVariant(variantId) {
        if (variants.length <= 1) {
            return;
        }

        onRemoveAiVariant(blockKey, variantId);
    }

    function updateExtractField(fieldKey, patch) {
        onUpdateModulePayload(blockKey, 'ai', {
            extract_fields: extractFields.map((field) => (
                field.key === fieldKey ? { ...field, ...patch } : field
            )),
        });
    }

    function addExtractField() {
        onUpdateModulePayload(blockKey, 'ai', {
            extract_fields: [
                ...extractFields,
                { key: nextAiExtractFieldKey(extractFields), label: 'Новые данные', type: 'text' },
            ],
        });
    }

    function removeExtractField(fieldKey) {
        onUpdateModulePayload(blockKey, 'ai', {
            extract_fields: extractFields.filter((field) => field.key !== fieldKey),
        });
    }

    return (
        <>
            <div className="ac-v3-builder__field">
                <span className="ac-v3-builder__label-row">
                    <span>Промт</span>
                    <button
                        type="button"
                        className="ac-v3-builder__inline-help-button"
                        onClick={() => setIsVariableHelpOpen((value) => ! value)}
                    >
                        Переменные
                    </button>
                </span>
                <AutoGrowTextarea
                    value={prompt}
                    maxHeight={220}
                    onChange={(event) => onUpdateModulePayload(blockKey, 'ai', { prompt: event.target.value })}
                />
                <p className="ac-v3-builder__field-hint">
                    Можно подставлять переменные: {'{{contact.gender|unknown}}'}, {'{{input.client_messages}}'}.
                </p>
                {isVariableHelpOpen ? (
                    <div className="ac-v3-builder__ai-variable-popover" aria-label="Переменные промпта ИИ">
                        <div className="ac-v3-builder__ai-variable-popover-head">
                            <strong>Переменные промпта</strong>
                            <button
                                type="button"
                                aria-label="Закрыть переменные"
                                onClick={() => setIsVariableHelpOpen(false)}
                            >
                                ×
                            </button>
                        </div>
                        <div className="ac-v3-builder__ai-variable-popover-body">
                            {variableGroups.map((group) => (
                                <div key={group.title} className="ac-v3-builder__ai-variable-group">
                                    <strong>{group.title}</strong>
                                    {group.items.map((item) => (
                                        <div key={item.token} className="ac-v3-builder__ai-variable-row">
                                            <code>{item.token}</code>
                                            <span>{item.label}</span>
                                            <p>{item.source}</p>
                                            <small>Тип: {item.type}</small>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                        <p className="ac-v3-builder__field-hint">
                            Значение после вертикальной черты используется, если поле пустое: {'{{contact.gender|unknown}}'}.
                        </p>
                    </div>
                ) : null}
            </div>
            <div className="ac-v3-builder__ai-outputs">
                <div className="ac-v3-builder__ai-outputs-head">
                    <span>Варианты результата</span>
                    <button type="button" onClick={addVariant}>Добавить</button>
                </div>
                {variants.map((variant, index) => (
                    <div key={variant.id} className="ac-v3-builder__ai-output-row">
                        <span className="ac-v3-builder__ai-output-id">ID {index + 1}</span>
                        <input
                            value={variant.label}
                            onChange={(event) => updateVariant(variant.id, { label: event.target.value })}
                        />
                        <input
                            type="number"
                            min="0"
                            max="300"
                            title="Сколько секунд ждать перед применением этого результата"
                            value={Math.max(0, Math.min(300, Math.floor(Number(variant.delay_seconds) || 0)))}
                            onChange={(event) => updateVariant(variant.id, {
                                delay_seconds: Math.max(0, Math.min(300, Math.floor(Number(event.target.value) || 0))),
                            })}
                        />
                        <button
                            type="button"
                            title="Удалить вариант"
                            disabled={variants.length <= 1}
                            onClick={() => removeVariant(variant.id)}
                        >
                            ×
                        </button>
                    </div>
                ))}
            </div>
            <div className="ac-v3-builder__ai-extract-fields">
                <div className="ac-v3-builder__ai-outputs-head">
                    <span>Переменные результата</span>
                    <button type="button" onClick={addExtractField}>Добавить</button>
                </div>
                {extractFields.map((field) => (
                    <div key={field.key} className="ac-v3-builder__ai-extract-row">
                        <input
                            value={field.label}
                            placeholder="Название"
                            onChange={(event) => updateExtractField(field.key, { label: event.target.value })}
                        />
                        <input
                            value={field.key}
                            placeholder="variable_name"
                            onChange={(event) => updateExtractField(field.key, { key: normalizeAiExtractFieldKey(event.target.value) })}
                        />
                        <select
                            value={field.type}
                            onChange={(event) => updateExtractField(field.key, { type: event.target.value })}
                        >
                            {AI_EXTRACT_FIELD_TYPE_OPTIONS.map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                        <button
                            type="button"
                            title="Удалить данные"
                            onClick={() => removeExtractField(field.key)}
                        >
                            ×
                        </button>
                    </div>
                ))}
            </div>
        </>
    );
}

function VariableHelpPopover({ title, ariaLabel, onClose, onInsert = null }) {
    const variableGroups = aiPromptVariableGroups();

    return (
        <div className="ac-v3-builder__ai-variable-popover" aria-label={ariaLabel}>
            <div className="ac-v3-builder__ai-variable-popover-head">
                <strong>{title}</strong>
                <button
                    type="button"
                    aria-label="Закрыть переменные"
                    onClick={onClose}
                >
                    ×
                </button>
            </div>
            <div className="ac-v3-builder__ai-variable-popover-body">
                {variableGroups.map((group) => (
                    <div key={group.title} className="ac-v3-builder__ai-variable-group">
                        <strong>{group.title}</strong>
                        {group.items.map((item) => (
                            <div key={item.token} className="ac-v3-builder__ai-variable-row">
                                <code>{item.token}</code>
                                <span>{item.label}</span>
                                <p>{item.source}</p>
                                <small>Тип: {item.type}</small>
                                {onInsert ? (
                                    <button
                                        type="button"
                                        className="ac-v3-builder__variable-insert-button"
                                        onClick={() => onInsert(item.token)}
                                    >
                                        Вставить
                                    </button>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ))}
            </div>
            <p className="ac-v3-builder__field-hint">
                Значение после вертикальной черты используется, если поле пустое: {'{{contact.gender|unknown}}'}.
            </p>
        </div>
    );
}

function StartExpressionVariablePopover({
    anchorRef,
    onClose,
    onInsert,
    title = 'Переменные условий запуска',
    ariaLabel = 'Переменные для условий запуска',
}) {
    const [position, setPosition] = useState({ top: 96, left: 24 });
    const variableGroups = startExpressionVariableGroups();

    useLayoutEffect(() => {
        const anchor = anchorRef.current;

        if (! anchor) {
            return undefined;
        }

        function updatePosition() {
            const rect = anchor.getBoundingClientRect();
            const width = Math.min(430, window.innerWidth - 24);
            const left = Math.max(12, Math.min(rect.left - width + rect.width, window.innerWidth - width - 12));
            const top = Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - 560));

            setPosition({ top, left });
        }

        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);

        return () => {
            window.removeEventListener('resize', updatePosition);
            window.removeEventListener('scroll', updatePosition, true);
        };
    }, [anchorRef]);

    if (typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <div
            className="ac-v3-builder__start-expression-popover"
            style={{ top: position.top, left: position.left }}
            role="dialog"
            aria-label={ariaLabel}
        >
            <div className="ac-v3-builder__start-expression-popover-head">
                <strong>{title}</strong>
                <button type="button" aria-label="Закрыть переменные" onClick={onClose}>×</button>
            </div>
            <p className="ac-v3-builder__field-hint">
                Вставьте переменную или пример и допишите сравнение: ==, !=, &gt;, &gt;=, &lt;, &lt;=. Можно использовать and/or и скобки.
            </p>
            <div className="ac-v3-builder__start-expression-popover-body">
                <div className="ac-v3-builder__start-expression-group">
                    <strong>Готовые примеры</strong>
                    {START_EXPRESSION_EXAMPLES.map((item) => (
                        <div
                            key={item.token}
                            className="ac-v3-builder__start-expression-variable"
                        >
                            <div className="ac-v3-builder__start-expression-variable-text">
                                <code>{item.token}</code>
                                <span>{item.label}</span>
                            </div>
                            <button
                                type="button"
                                className="ac-v3-builder__start-expression-insert"
                                onClick={() => onInsert(item.token)}
                            >
                                Вставить
                            </button>
                        </div>
                    ))}
                </div>
                {variableGroups.map((group) => (
                    <div key={group.title} className="ac-v3-builder__start-expression-group">
                        <strong>{group.title}</strong>
                        {group.items.map((item) => (
                            <div
                                key={item.token}
                                className="ac-v3-builder__start-expression-variable"
                            >
                                <div className="ac-v3-builder__start-expression-variable-text">
                                    <code>{item.token}</code>
                                    <span>{item.label}</span>
                                </div>
                                <button
                                    type="button"
                                    className="ac-v3-builder__start-expression-insert"
                                    onClick={() => onInsert(item.token)}
                                >
                                    Вставить
                                </button>
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </div>,
        document.body,
    );
}

function MessageFields({ message, blockKey, onUpdateModulePayload }) {
    const [isVariableHelpOpen, setIsVariableHelpOpen] = useState(false);
    const textareaRef = useRef(null);
    const payload = message?.payload ?? {};
    const text = payload.text ?? '';
    const textMode = payload.text_mode === 'by_dialog_variable' ? 'by_dialog_variable' : 'static';
    const variableTextVariants = normalizeMessageVariableTextVariants(payload.variable_text_variants);

    function updateText(value) {
        onUpdateModulePayload(blockKey, 'message', { text: value });
    }

    function updateVariant(index, patch) {
        onUpdateModulePayload(blockKey, 'message', {
            variable_text_variants: variableTextVariants.map((variant, variantIndex) => (
                variantIndex === index ? { ...variant, ...patch } : variant
            )),
        });
    }

    function addVariant() {
        onUpdateModulePayload(blockKey, 'message', {
            variable_text_variants: [
                ...variableTextVariants,
                { operator: 'eq', value: String(variableTextVariants.length + 1), text: '' },
            ],
        });
    }

    function removeVariant(index) {
        if (variableTextVariants.length <= 1) {
            return;
        }

        onUpdateModulePayload(blockKey, 'message', {
            variable_text_variants: variableTextVariants.filter((_, variantIndex) => variantIndex !== index),
        });
    }

    function insertToken(token) {
        const input = textareaRef.current;
        const start = input?.selectionStart ?? text.length;
        const end = input?.selectionEnd ?? text.length;
        const nextText = `${text.slice(0, start)}${token}${text.slice(end)}`;

        updateText(nextText);

        requestAnimationFrame(() => {
            const nextInput = textareaRef.current;
            const cursorPosition = start + token.length;

            if (! nextInput) {
                return;
            }

            nextInput.focus();
            nextInput.setSelectionRange(cursorPosition, cursorPosition);
        });
    }

    return (
        <div className="ac-v3-builder__field">
            <span className="ac-v3-builder__label-row">
                <span>Текст сообщения</span>
                <button
                    type="button"
                    className="ac-v3-builder__inline-help-button"
                    onClick={() => setIsVariableHelpOpen((value) => ! value)}
                >
                    Переменные
                </button>
            </span>
            <AutoGrowTextarea
                textareaRef={textareaRef}
                value={text}
                onChange={(event) => updateText(event.target.value)}
            />
            {isVariableHelpOpen ? (
                <VariableHelpPopover
                    title="Переменные сообщения"
                    ariaLabel="Подстановки сообщения"
                    onClose={() => setIsVariableHelpOpen(false)}
                    onInsert={insertToken}
                />
            ) : null}
            <label className="ac-v3-builder__compact-label">
                <span>Режим текста</span>
                <select
                    value={textMode}
                    onChange={(event) => onUpdateModulePayload(blockKey, 'message', {
                        text_mode: event.target.value,
                        variable_key: event.target.value === 'by_dialog_variable'
                            ? (payload.variable_key || 'счетчик')
                            : '',
                        variable_text_variants: event.target.value === 'by_dialog_variable'
                            ? variableTextVariants
                            : [],
                        fallback_text: event.target.value === 'by_dialog_variable'
                            ? (payload.fallback_text ?? text)
                            : '',
                    })}
                >
                    <option value="static">Один текст</option>
                    <option value="by_dialog_variable">Текст по полю диалога</option>
                </select>
            </label>
            {textMode === 'by_dialog_variable' ? (
                <div className="ac-v3-builder__action-list">
                    <label>
                        <span>Поле диалога</span>
                        <input
                            value={payload.variable_key ?? ''}
                            placeholder="счетчик"
                            onChange={(event) => onUpdateModulePayload(blockKey, 'message', {
                                variable_key: normalizeDialogFieldKey(event.target.value),
                            })}
                        />
                    </label>
                    <label>
                        <span>Текст по умолчанию</span>
                        <AutoGrowTextarea
                            value={payload.fallback_text ?? ''}
                            maxHeight={120}
                            onChange={(event) => onUpdateModulePayload(blockKey, 'message', {
                                fallback_text: event.target.value,
                            })}
                        />
                    </label>
                    <div className="ac-v3-builder__ai-outputs-head">
                        <span>Варианты</span>
                        <button type="button" onClick={addVariant}>Добавить</button>
                    </div>
                    {variableTextVariants.map((variant, index) => (
                        <div key={index} className="ac-v3-builder__message-variant-row">
                            <div className="ac-v3-builder__message-variant-value-row">
                                <label>
                                    <span>Значение</span>
                                    <div className="ac-v3-builder__message-variant-condition">
                                        <select
                                            value={variant.operator}
                                            onChange={(event) => updateVariant(index, { operator: normalizeMessageVariableTextOperator(event.target.value) })}
                                        >
                                            {MESSAGE_VARIABLE_TEXT_OPERATORS.map(([operator, label]) => (
                                                <option key={operator} value={operator}>{label}</option>
                                            ))}
                                        </select>
                                        <input
                                            value={variant.value}
                                            placeholder="1"
                                            onChange={(event) => updateVariant(index, { value: event.target.value })}
                                        />
                                    </div>
                                </label>
                                <button
                                    type="button"
                                    title="Удалить вариант"
                                    disabled={variableTextVariants.length <= 1}
                                    onClick={() => removeVariant(index)}
                                >
                                    ×
                                </button>
                            </div>
                            <label>
                                <span>Текст</span>
                                <AutoGrowTextarea
                                    value={variant.text}
                                    maxHeight={160}
                                    placeholder="Как тебя зовут?"
                                    onChange={(event) => updateVariant(index, { text: event.target.value })}
                                />
                            </label>
                        </div>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

function normalizeMessageVariableTextVariants(variants) {
    const normalized = Array.isArray(variants)
        ? variants
            .filter((variant) => variant && typeof variant === 'object')
            .map((variant) => ({
                operator: normalizeMessageVariableTextOperator(variant.operator),
                value: String(variant.value ?? ''),
                text: String(variant.text ?? ''),
            }))
            .filter((variant) => variant.value !== '')
            .slice(0, 20)
        : [];

    return normalized.length > 0 ? normalized : [{ operator: 'eq', value: '1', text: '' }];
}

function normalizeMessageVariableTextOperator(operator) {
    return MESSAGE_VARIABLE_TEXT_OPERATORS.some(([value]) => value === operator) ? operator : 'eq';
}

function ActionFields({ action, blocks = [], tags = [], blockKey, onUpdateModulePayload, dialogFieldKeys = [] }) {
    const items = existingActionItems(action);
    const aiSourceBlocks = aiBlocksForGeoSource(blocks, blockKey);

    function updateItems(nextItems) {
        onUpdateModulePayload(blockKey, 'action', {
            actions: nextItems,
        });
    }

    function updateItem(index, patch) {
        updateItems(items.map((item, itemIndex) => (
            itemIndex === index ? { ...item, ...patch } : item
        )));
    }

    function updateChangeDataItem(index, patch) {
        updateItem(index, normalizeActionItemForType({
            ...items[index],
            ...patch,
        }));
    }

    function addItem() {
        updateItems([...items, defaultActionItem()]);
    }

    function removeItem(index) {
        if (items.length <= 1) {
            return;
        }

        updateItems(items.filter((_, itemIndex) => itemIndex !== index));
    }

    return (
        <div className="ac-v3-builder__action-list">
            <div className="ac-v3-builder__ai-outputs-head">
                <span>Что сделать</span>
                <button type="button" onClick={addItem}>Добавить</button>
            </div>
            {items.map((item, index) => (
                <div key={index} className="ac-v3-builder__action-row">
                    <div className="ac-v3-builder__action-row-head">
                        <label>
                            <span>Действие</span>
                            <select
                                value={item.type}
                                onChange={(event) => updateItem(index, normalizeActionItemForType({ ...item, type: event.target.value }))}
                            >
                                {item.type === ACTION_TYPE_VARIABLES ? (
                                    <option value="variables">Изменить поле</option>
                                ) : item.type === ACTION_TYPE_WRITE_CONTACT_FIELD ? (
                                    <option value="write_contact_field">Изменить поле</option>
                                ) : (
                                    <option value="change_field">Изменить поле</option>
                                )}
                                <option value="check_data">Проверить данные</option>
                                <option value="edit_message">Изменить сообщение</option>
                                <option value="calculate_distance_to_moscow">Рассчитать расстояние до Москвы</option>
                                <option value="resolve_geo_city">Распознать географию</option>
                                <option value="simulate_start_parameter">Имитировать старт с параметром</option>
                                <option value="tag_effects">Изменить теги</option>
                                <option value="bitrix24_sync">Bitrix24</option>
                            </select>
                        </label>
                        <button
                            type="button"
                            className="ac-v3-builder__action-remove-btn"
                            title="Удалить действие"
                            disabled={items.length <= 1}
                            onClick={() => removeItem(index)}
                        >
                            ×
                        </button>
                    </div>
                    {item.type === ACTION_TYPE_CALCULATE_DISTANCE_TO_MOSCOW ? (
                        <label>
                            <span>Что рассчитать</span>
                            <input value="Расстояние от города контакта до Москвы" readOnly />
                        </label>
                    ) : isGeoCityResultActionType(item.type) ? (
                        <GeoCityActionFields
                            item={item}
                            aiSourceBlocks={aiSourceBlocks}
                            onChange={(patch) => updateItem(index, normalizeActionItemForType({
                                ...item,
                                ...patch,
                            }))}
                        />
                    ) : item.type === ACTION_TYPE_VARIABLES ? (
                        <VariablesActionFields
                            item={item}
                            dialogFieldKeys={dialogFieldKeys}
                            onChange={(patch) => updateItem(index, normalizeActionItemForType({
                                ...item,
                                ...patch,
                            }))}
                        />
                    ) : item.type === ACTION_TYPE_SIMULATE_START_PARAMETER ? (
                        <SimulateStartParameterActionFields
                            item={item}
                            dialogFieldKeys={dialogFieldKeys}
                            onChange={(patch) => updateItem(index, normalizeActionItemForType({
                                ...item,
                                ...patch,
                            }))}
                        />
                    ) : item.type === ACTION_TYPE_TAG_EFFECTS ? (
                        <TagEffectsActionFields
                            item={item}
                            tags={tags}
                            onChange={(patch) => updateItem(index, normalizeActionItemForType({
                                ...item,
                                ...patch,
                            }))}
                        />
                    ) : item.type === ACTION_TYPE_BITRIX24_SYNC ? (
                        <Bitrix24SyncActionFields
                            item={item}
                            onChange={(patch) => updateItem(index, normalizeActionItemForType({
                                ...item,
                                ...patch,
                            }))}
                        />
                    ) : item.type === ACTION_TYPE_EDIT_MESSAGE ? (
                        <>
                            <label>
                                <span>Что изменить</span>
                                <select
                                    value={item.target}
                                    onChange={(event) => updateItem(index, normalizeActionItemForType({
                                        ...item,
                                        target: event.target.value,
                                    }))}
                                >
                                    {item.operation === ACTION_EDIT_MESSAGE_OPERATION_DELETE_MESSAGE ? (
                                        <option value={ACTION_EDIT_MESSAGE_TARGET_LAST_CURRENT_RUN_OUTBOUND}>
                                            Последнее наше сообщение
                                        </option>
                                    ) : (
                                        <option value={ACTION_EDIT_MESSAGE_TARGET_LAST_CURRENT_RUN_OUTBOUND_WITH_INLINE_BUTTONS}>
                                            Последнее сообщение сценария с кнопками
                                        </option>
                                    )}
                                </select>
                            </label>
                            <label>
                                <span>Как изменить</span>
                                <select
                                    value={item.operation}
                                    onChange={(event) => updateItem(index, normalizeActionItemForType({
                                        ...item,
                                        operation: event.target.value,
                                        target: event.target.value === ACTION_EDIT_MESSAGE_OPERATION_DELETE_MESSAGE
                                            ? ACTION_EDIT_MESSAGE_TARGET_LAST_CURRENT_RUN_OUTBOUND
                                            : ACTION_EDIT_MESSAGE_TARGET_LAST_CURRENT_RUN_OUTBOUND_WITH_INLINE_BUTTONS,
                                    }))}
                                >
                                    <option value={ACTION_EDIT_MESSAGE_OPERATION_REMOVE_BUTTONS}>Убрать кнопки</option>
                                    <option value={ACTION_EDIT_MESSAGE_OPERATION_DELETE_MESSAGE}>Удалить сообщение полностью</option>
                                </select>
                            </label>
                        </>
                    ) : item.type === ACTION_TYPE_CHECK_DATA ? (
                        <>
                            <label>
                                <span>Что проверяем</span>
                                <select
                                    value={item.check_source}
                                    onChange={(event) => updateItem(index, { check_source: event.target.value })}
                                >
                                    {ACTION_CHECK_SOURCE_OPTIONS.map(([value, label]) => (
                                        <option key={value} value={value}>{label}</option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                <span>Где проверяем</span>
                                <select
                                    value={item.dictionary_key}
                                    onChange={(event) => updateItem(index, { dictionary_key: event.target.value })}
                                >
                                    {ACTION_DICTIONARY_OPTIONS.map(([value, label]) => (
                                        <option key={value} value={value}>Справочник: {label}</option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                <span>Ищем в поле</span>
                                <input value="Вариант от клиента" readOnly />
                            </label>
                            <label>
                                <span>Если нашли, взять поле</span>
                                <input value="Полное имя" readOnly />
                            </label>
                            <label>
                                <span>Записать в переменную</span>
                                <input
                                    value={item.target_variable_key}
                                    placeholder="first_name"
                                    onChange={(event) => updateItem(index, {
                                        target_variable_key: normalizeAiExtractFieldKey(event.target.value),
                                    })}
                                />
                            </label>
                        </>
                    ) : item.type === ACTION_TYPE_WRITE_CONTACT_FIELD ? (
                        <LegacyWriteContactFieldActionFields
                            item={item}
                            aiSourceBlocks={aiSourceBlocks}
                            dialogFieldKeys={dialogFieldKeys}
                            onChange={(patch) => updateItem(index, normalizeActionItemForType({
                                ...item,
                                ...patch,
                            }))}
                        />
                    ) : (
                        <ChangeFieldActionFields
                            item={item}
                            aiSourceBlocks={aiSourceBlocks}
                            dialogFieldKeys={dialogFieldKeys}
                            onChange={(patch) => updateChangeDataItem(index, patch)}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function CalculatorFields({ action, blockKey, onUpdateModulePayload }) {
    const item = calculatorActionItem(action);
    const regularItems = regularActionItems(action);

    return (
        <VariablesActionFields
            item={item}
            dialogFieldKeys={[]}
            onChange={(patch) => onUpdateModulePayload(blockKey, 'action', {
                actions: [
                    ...regularItems,
                    normalizeActionItemForType({ ...item, ...patch, type: ACTION_TYPE_VARIABLES }),
                ],
            })}
        />
    );
}

function SimulateStartParameterActionFields({ item, dialogFieldKeys = [], onChange }) {
    return (
        <>
            <label>
                <span>Откуда взять параметр</span>
                <input value="Диалог" readOnly />
            </label>
            <label>
                <span>Поле диалога</span>
                <DialogFieldKeyInput
                    value={item.source_field_key}
                    placeholder="start_param"
                    onChange={(fieldKey) => onChange({
                        source_scope: 'dialog',
                        source_field_key: normalizeDialogFieldKey(fieldKey),
                    })}
                    suggestions={dialogFieldKeys}
                    purpose="action"
                />
            </label>
            <label className="ac-v3-builder__check ac-v3-builder__simulate-start-clear-check">
                <input
                    type="checkbox"
                    checked={Boolean(item.clear_source_field_after_reroute)}
                    onChange={(event) => onChange({
                        clear_source_field_after_reroute: event.target.checked,
                    })}
                />
                <span>Очистить поле после успешного перехода</span>
            </label>
            <p className="ac-v3-builder__field-hint">
                Поле очистится только если стартовый блок найден и переход выполнен.
            </p>
        </>
    );
}

function TagEffectsActionFields({ item, tags = [], onChange }) {
    const assignTagIds = normalizeIntegerList(item.assign_tag_ids);
    const removeTagIds = normalizeIntegerList(item.remove_tag_ids);

    function selectedIdsFromEvent(event) {
        return Array.from(event.target.selectedOptions)
            .map((option) => Number(option.value))
            .filter((id) => id > 0);
    }

    return (
        <>
            <label>
                <span>Назначить теги</span>
                <select
                    multiple
                    value={assignTagIds.map(String)}
                    className="ac-v3-builder__multi-select"
                    onChange={(event) => onChange({ assign_tag_ids: selectedIdsFromEvent(event) })}
                >
                    {tags.map((tag) => (
                        <option key={tag.id} value={tag.id}>
                            {tag.name}
                        </option>
                    ))}
                </select>
            </label>
            <label>
                <span>Снять теги</span>
                <select
                    multiple
                    value={removeTagIds.map(String)}
                    className="ac-v3-builder__multi-select"
                    onChange={(event) => onChange({ remove_tag_ids: selectedIdsFromEvent(event) })}
                >
                    {tags.map((tag) => (
                        <option key={tag.id} value={tag.id}>
                            {tag.name}
                        </option>
                    ))}
                </select>
            </label>
        </>
    );
}

function Bitrix24SyncActionFields({ item, onChange }) {
    const operation = normalizeBitrix24SyncOperation(item.operation);
    const operationHelp = BITRIX24_SYNC_OPERATION_HELP[operation] ?? BITRIX24_SYNC_OPERATION_HELP.contact_sync;

    return (
        <>
            <label>
                <span>Операция</span>
                <select
                    value={operation}
                    onChange={(event) => onChange({ operation: event.target.value })}
                >
                    {BITRIX24_SYNC_OPERATION_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
            <div className="ac-v3-builder__bitrix-help">
                <button
                    type="button"
                    className="ac-v3-builder__info-tooltip"
                    aria-label="Что делает выбранная операция Bitrix24"
                    data-tooltip={operationHelp}
                >
                    i
                </button>
            </div>
        </>
    );
}

function LegacyWriteContactFieldActionFields({ item, aiSourceBlocks, dialogFieldKeys, onChange }) {
    const selectedBlock = aiSourceBlocks.find((block) => block.client_key === item.source_block_client_key) ?? null;
    const selectedAiModule = selectedBlock ? findModule(selectedBlock.settings_payload, 'ai') : null;
    const fieldOptions = selectedAiModule ? aiExtractFieldDefinitions(selectedAiModule) : [];

    function updateTargetScope(targetScope) {
        onChange({
            target_scope: targetScope,
            target_field: targetScope === 'contact' ? defaultRuntimeWritableContactFieldKey() : 'field',
        });
    }

    function updateSourceType(sourceType) {
        onChange({
            source_type: sourceType,
            source_block_client_key: sourceType === 'ai_data' ? item.source_block_client_key : '',
            source_block_id: '',
            source_field_key: sourceType === 'ai_data' ? item.source_field_key : '',
            static_value: sourceType === 'static_value' ? item.static_value : '',
        });
    }

    function updateSourceBlock(sourceBlockClientKey) {
        const block = aiSourceBlocks.find((candidate) => candidate.client_key === sourceBlockClientKey) ?? null;
        const aiModule = block ? findModule(block.settings_payload, 'ai') : null;
        const firstField = aiModule ? aiExtractFieldDefinitions(aiModule)[0]?.key ?? '' : '';

        onChange({
            source_block_client_key: sourceBlockClientKey,
            source_block_id: '',
            source_field_key: firstField,
        });
    }

    return (
        <>
            <label>
                <span>Где изменить</span>
                <select value={item.target_scope} onChange={(event) => updateTargetScope(event.target.value)}>
                    {ACTION_TARGET_SCOPE_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
            {item.target_scope === 'contact' ? (
                <label>
                    <span>Поле контакта</span>
                    <select value={item.target_field} onChange={(event) => onChange({ target_field: event.target.value })}>
                        {contactLegacyWriteFieldOptions(item.target_field).map((option) => (
                            <option key={option.key} value={option.key} disabled={option.disabled}>
                                {option.label}{option.disabled ? ' · недоступно' : ''}
                            </option>
                        ))}
                    </select>
                </label>
            ) : (
                <label>
                    <span>Поле диалога</span>
                    <DialogFieldKeyInput
                        value={item.target_field}
                        placeholder="start_param"
                        onChange={(fieldKey) => onChange({ target_field: normalizeDialogFieldKey(fieldKey) })}
                        suggestions={dialogFieldKeys}
                        purpose="action"
                    />
                </label>
            )}
            <label>
                <span>Откуда взять значение</span>
                <select value={item.source_type} onChange={(event) => updateSourceType(event.target.value)}>
                    {LEGACY_WRITE_CONTACT_FIELD_SOURCE_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
            {item.source_type === 'static_value' ? (
                <ActionStaticValueField
                    item={item}
                    onChange={(value) => onChange({ static_value: value })}
                />
            ) : (
                <>
                    <label>
                        <span>ИИ-блок</span>
                        <select
                            value={item.source_block_client_key}
                            onChange={(event) => updateSourceBlock(event.target.value)}
                        >
                            <option value="">Выберите блок</option>
                            {aiSourceBlocks.map((block) => (
                                <option key={block.client_key} value={block.client_key}>
                                    {block.title || 'ИИ-анализ'}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span>Поле результата</span>
                        <select
                            value={item.source_field_key}
                            onChange={(event) => onChange({ source_field_key: event.target.value })}
                            disabled={fieldOptions.length === 0}
                        >
                            {fieldOptions.length === 0 ? (
                                <option value={item.source_field_key}>Нет полей ИИ</option>
                            ) : fieldOptions.map((field) => (
                                <option key={field.key} value={field.key}>
                                    {field.label || field.key}
                                </option>
                            ))}
                        </select>
                    </label>
                </>
            )}
        </>
    );
}

function ChangeFieldActionFields({ item, aiSourceBlocks, dialogFieldKeys, onChange }) {
    const selectedBlock = aiSourceBlocks.find((block) => block.client_key === item.source_block_client_key) ?? null;
    const selectedAiModule = selectedBlock ? findModule(selectedBlock.settings_payload, 'ai') : null;
    const fieldOptions = selectedAiModule ? aiExtractFieldDefinitions(selectedAiModule) : [];

    function updateTargetScope(targetScope) {
        onChange({
            target_scope: targetScope,
            target_field: targetScope === 'contact' ? defaultWritableContactFieldKey() : 'field',
        });
    }

    function updateValueSource(valueSource) {
        onChange({
            value_source: valueSource,
            source_block_client_key: valueSource === 'ai_result' ? item.source_block_client_key : '',
            source_block_id: '',
            source_field_key: valueSource === 'ai_result' ? item.source_field_key : '',
        });
    }

    function updateSourceBlock(sourceBlockClientKey) {
        const block = aiSourceBlocks.find((candidate) => candidate.client_key === sourceBlockClientKey) ?? null;
        const aiModule = block ? findModule(block.settings_payload, 'ai') : null;
        const firstField = aiModule ? aiExtractFieldDefinitions(aiModule)[0]?.key ?? '' : '';

        onChange({
            source_block_client_key: sourceBlockClientKey,
            source_block_id: '',
            source_field_key: firstField,
        });
    }

    return (
        <>
            <label>
                <span>Где изменить</span>
                <select value={item.target_scope} onChange={(event) => updateTargetScope(event.target.value)}>
                    {ACTION_TARGET_SCOPE_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
            {item.target_scope === 'contact' ? (
                <label>
                    <span>Поле контакта</span>
                    <select value={item.target_field} onChange={(event) => onChange({ target_field: event.target.value })}>
                        {contactActionFieldOptions(item.target_field).map((option) => (
                            <option key={option.key} value={option.key} disabled={option.disabled}>
                                {option.label}{option.disabled ? ' · недоступно' : ''}
                            </option>
                        ))}
                    </select>
                </label>
            ) : (
                <label>
                    <span>Поле диалога</span>
                    <DialogFieldKeyInput
                        value={item.target_field}
                        placeholder="start_param"
                        onChange={(fieldKey) => onChange({ target_field: normalizeDialogFieldKey(fieldKey) })}
                        suggestions={dialogFieldKeys}
                        purpose="action"
                    />
                </label>
            )}
            <label>
                <span>Как изменить</span>
                <select value={item.value_source} onChange={(event) => updateValueSource(event.target.value)}>
                    {ACTION_VALUE_SOURCE_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
            {item.value_source === 'manual' ? (
                <ActionManualValueField
                    item={item}
                    onChange={(value) => onChange({ manual_value: value })}
                />
            ) : null}
            {item.value_source === 'ai_result' ? (
                <>
                    <label>
                        <span>ИИ-блок</span>
                        <select
                            value={item.source_block_client_key}
                            onChange={(event) => updateSourceBlock(event.target.value)}
                        >
                            <option value="">Выберите блок</option>
                            {aiSourceBlocks.map((block) => (
                                <option key={block.client_key} value={block.client_key}>
                                    {block.title || 'ИИ-анализ'}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span>Поле результата</span>
                        <select
                            value={item.source_field_key}
                            onChange={(event) => onChange({ source_field_key: event.target.value })}
                            disabled={fieldOptions.length === 0}
                        >
                            {fieldOptions.length === 0 ? (
                                <option value={item.source_field_key}>Нет полей ИИ</option>
                            ) : fieldOptions.map((field) => (
                                <option key={field.key} value={field.key}>
                                    {field.label || field.key}
                                </option>
                            ))}
                        </select>
                    </label>
                </>
            ) : null}
            {item.value_source === 'start_parameter' ? (
                <p className="ac-v3-builder__field-hint">
                    Будет использован параметр запуска текущего сценария.
                </p>
            ) : null}
        </>
    );
}

function GeoCityActionFields({ item, aiSourceBlocks, onChange }) {
    const selectedBlock = aiSourceBlocks.find((block) => block.client_key === item.source_block_client_key) ?? null;
    const selectedAiModule = selectedBlock ? findModule(selectedBlock.settings_payload, 'ai') : null;
    const fieldOptions = selectedAiModule ? aiExtractFieldDefinitions(selectedAiModule) : [];

    function updateSource(source) {
        if (source === 'ai_data') {
            onChange({
                source: 'ai_data',
                source_block_client_key: item.source_block_client_key || '',
                city_field_key: item.city_field_key || 'geo_city',
                region_field_key: item.region_field_key || 'geo_region',
                country_field_key: item.country_field_key || 'geo_country',
            });

            return;
        }

        onChange({ source: 'current_inbound_message' });
    }

    return (
        <>
            <label>
                <span>Откуда взять город</span>
                <select value={item.source} onChange={(event) => updateSource(event.target.value)}>
                    {GEO_CITY_SOURCE_OPTIONS.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
            {item.source === 'ai_data' ? (
                <>
                    <label>
                        <span>ИИ-блок</span>
                        <select
                            value={item.source_block_client_key}
                            onChange={(event) => onChange({ source_block_client_key: event.target.value })}
                        >
                            <option value="">Выберите блок</option>
                            {aiSourceBlocks.map((block) => (
                                <option key={block.client_key} value={block.client_key}>
                                    {block.title || 'ИИ-анализ'}
                                </option>
                            ))}
                        </select>
                    </label>
                    <GeoCityAiFieldSelect
                        label="Поле города"
                        value={item.city_field_key}
                        fieldOptions={fieldOptions}
                        onChange={(value) => onChange({ city_field_key: value })}
                    />
                    <GeoCityAiFieldSelect
                        label="Поле региона"
                        value={item.region_field_key}
                        fieldOptions={fieldOptions}
                        onChange={(value) => onChange({ region_field_key: value })}
                    />
                    <GeoCityAiFieldSelect
                        label="Поле страны"
                        value={item.country_field_key}
                        fieldOptions={fieldOptions}
                        onChange={(value) => onChange({ country_field_key: value })}
                    />
                </>
            ) : (
                <label>
                    <span>Что распознать</span>
                    <input value="Город из сообщения клиента" readOnly />
                </label>
            )}
        </>
    );
}

function GeoCityAiFieldSelect({ label, value, fieldOptions, onChange }) {
    return (
        <label>
            <span>{label}</span>
            <select value={value} onChange={(event) => onChange(event.target.value)} disabled={fieldOptions.length === 0}>
                {fieldOptions.length === 0 ? (
                    <option value={value}>Нет полей ИИ</option>
                ) : fieldOptions.map((field) => (
                    <option key={field.key} value={field.key}>
                        {field.label || field.key}
                    </option>
                ))}
            </select>
        </label>
    );
}

function VariablesActionFields({ item, dialogFieldKeys = [], onChange }) {
    const operations = normalizeVariableOperations(item.operations);

    function updateOperation(index, patch) {
        onChange({
            operations: operations.map((operation, operationIndex) => (
                operationIndex === index ? normalizeVariableOperation({ ...operation, ...patch }) : operation
            )),
        });
    }

    function addOperation() {
        onChange({
            operations: [...operations, defaultVariableOperation()],
        });
    }

    function removeOperation(index) {
        if (operations.length <= 1) {
            return;
        }

        onChange({
            operations: operations.filter((_, operationIndex) => operationIndex !== index),
        });
    }

    return (
        <div className="ac-v3-builder__action-list">
            <div className="ac-v3-builder__ai-outputs-head">
                <span>Изменение полей диалога</span>
                <button type="button" onClick={addOperation}>Добавить</button>
            </div>
            {operations.map((operation, index) => (
                <div key={index} className="ac-v3-builder__action-row">
                    <label>
                        <span>Поле диалога</span>
                        <DialogFieldKeyInput
                            value={operation.field_key}
                            placeholder="счетчик"
                            onChange={(fieldKey) => updateOperation(index, {
                                field_key: normalizeDialogFieldKey(fieldKey),
                            })}
                            suggestions={dialogFieldKeys}
                            purpose="action"
                        />
                        <span className="ac-v3-builder__field-hint">
                            Это сохранённое поле диалога. В тексте и условиях используйте его как {'{{dialog.счетчик}}'}.
                        </span>
                    </label>
                    <label>
                        <span>Что сделать</span>
                        <select
                            value={operation.operation}
                            onChange={(event) => updateOperation(index, { operation: event.target.value })}
                        >
                            <option value="set">Изменить значение</option>
                            <option value="increment">Увеличить число</option>
                            <option value="clear">Очистить поле</option>
                        </select>
                    </label>
                    {operation.operation === 'increment' ? (
                        <label>
                            <span>На сколько</span>
                            <input
                                type="number"
                                min="1"
                                max="100"
                                step="1"
                                value={operation.amount}
                                onChange={(event) => updateOperation(index, { amount: event.target.value })}
                            />
                            <span className="ac-v3-builder__field-hint">
                                Для счётчика выберите нужное поле диалога и оставьте значение 1.
                            </span>
                        </label>
                    ) : operation.operation === 'set' ? (
                        <>
                            <label>
                                <span>Откуда взять значение</span>
                                <select
                                    value={operation.value_source}
                                    onChange={(event) => updateOperation(index, { value_source: event.target.value })}
                                >
                                    {variableSetValueSourceOptions(operation.value_source).map(([value, label]) => (
                                        <option key={value} value={value}>{label}</option>
                                    ))}
                                </select>
                            </label>
                            {operation.value_source === 'static_value' ? (
                                <label>
                                    <span>Значение</span>
                                    <input
                                        value={operation.value}
                                        placeholder="1"
                                        onChange={(event) => updateOperation(index, { value: event.target.value })}
                                    />
                                </label>
                            ) : null}
                        </>
                    ) : null}
                    <button
                        type="button"
                        title="Удалить операцию"
                        disabled={operations.length <= 1}
                        onClick={() => removeOperation(index)}
                    >
                        ×
                    </button>
                </div>
            ))}
        </div>
    );
}

function ActionStaticValueField({ item, onChange }) {
    const options = actionStaticValueOptions(item);

    if (options.length > 0) {
        return (
            <label>
                <span>Значение</span>
                <select value={item.static_value} onChange={(event) => onChange(event.target.value)}>
                    {options.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
        );
    }

    return (
        <label>
            <span>Значение</span>
            <input
                value={item.static_value}
                placeholder="Текст"
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    );
}

function ActionManualValueField({ item, onChange }) {
    const options = actionManualValueOptions(item);

    if (options.length > 0) {
        return (
            <label>
                <span>Новое значение</span>
                <select value={item.manual_value} onChange={(event) => onChange(event.target.value)}>
                    <option value="">Пусто</option>
                    {options.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
        );
    }

    return (
        <label>
            <span>Новое значение</span>
            <input
                value={item.manual_value}
                placeholder="Оставьте пустым, чтобы очистить"
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    );
}

function TransitionActionValueField({ item, onChange }) {
    const options = transitionActionValueOptions(item);

    if (options.length > 0) {
        return (
            <label>
                <span>Значение</span>
                <select value={item.value} onChange={(event) => onChange(event.target.value)}>
                    {options.map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </label>
        );
    }

    return (
        <label>
            <span>Значение</span>
            <input
                value={item.value}
                placeholder="Текст"
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    );
}

function startPhoneConditionExpression(scope, condition) {
    const variable = scope === 'contact' ? '{{contact.phone}}' : '{{dialog.phone}}';

    if (condition === 'has_phone') {
        return `${variable} != ""`;
    }

    if (condition === 'missing_phone') {
        return `${variable} == ""`;
    }

    return '';
}

function mergeStartExpressionConditions(expression, additions) {
    const parts = [
        String(expression ?? '').trim(),
        ...additions.map((item) => String(item ?? '').trim()).filter(Boolean),
    ].filter(Boolean);

    if (parts.length === 0) {
        return '';
    }

    const uniqueParts = [];

    parts.forEach((part) => {
        if (! uniqueParts.includes(part)) {
            uniqueParts.push(part);
        }
    });

    return uniqueParts
        .map((part) => (part.startsWith('(') && part.endsWith(')') ? part : `(${part})`))
        .join(' and ');
}

function migrateStartPhoneConditions(payload = {}) {
    const additions = [
        startPhoneConditionExpression('contact', payload.contact_phone_condition),
        startPhoneConditionExpression('dialog', payload.dialog_phone_condition),
    ].filter(Boolean);

    if (additions.length === 0) {
        return null;
    }

    const expression = mergeStartExpressionConditions(payload.expression, additions);

    return {
        expression,
        contact_phone_condition: '',
        dialog_phone_condition: '',
    };
}

function insertTextAtSelection(input, currentValue, text) {
    const value = String(currentValue ?? '');

    if (! input) {
        return value ? `${value} ${text}` : text;
    }

    const start = input.selectionStart ?? value.length;
    const end = input.selectionEnd ?? value.length;
    const before = value.slice(0, start);
    const after = value.slice(end);
    const prefix = before && ! /\s$/.test(before) ? ' ' : '';
    const suffix = after && ! /^\s/.test(after) ? ' ' : '';

    return `${before}${prefix}${text}${suffix}${after}`;
}

function StartConditionFields({
    start,
    channels,
    startChannels,
    blockKey,
    onUpdateModulePayload,
    onUpdateStartChannels,
}) {
    const [isExpressionHelpOpen, setIsExpressionHelpOpen] = useState(false);
    const expressionHelpButtonRef = useRef(null);
    const expressionTextareaRef = useRef(null);
    const selectedMatch = startMatchForUi(start?.payload?.match);
    const usesCommandValue = selectedMatch !== 'any_inbound';
    const expression = start?.payload?.expression ?? '';

    useEffect(() => {
        const migration = migrateStartPhoneConditions(start?.payload ?? {});

        if (migration) {
            onUpdateModulePayload(blockKey, 'start_condition', migration);
        }
    }, [
        blockKey,
        onUpdateModulePayload,
        start?.payload?.contact_phone_condition,
        start?.payload?.dialog_phone_condition,
        start?.payload?.expression,
    ]);

    function insertStartExpressionToken(token) {
        const nextExpression = insertTextAtSelection(expressionTextareaRef.current, expression, token);

        onUpdateModulePayload(blockKey, 'start_condition', { expression: nextExpression });
        window.requestAnimationFrame(() => {
            expressionTextareaRef.current?.focus();
        });
    }

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
            <div className="ac-v3-builder__field">
                <div className="ac-v3-builder__label-row">
                    <span className="ac-v3-builder__field-label">Условия запуска</span>
                    <button
                        ref={expressionHelpButtonRef}
                        type="button"
                        className="ac-v3-builder__inline-help-button ac-v3-builder__inline-help-button--icon"
                        aria-label="Показать переменные для условий запуска"
                        aria-expanded={isExpressionHelpOpen}
                        onClick={(event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            setIsExpressionHelpOpen((value) => ! value);
                        }}
                    >
                        i
                    </button>
                </div>
                <textarea
                    ref={expressionTextareaRef}
                    className="ac-v3-builder__textarea-auto"
                    rows={3}
                    value={expression}
                    placeholder={'{{contact.phone}} != "" and {{dialog.start_param}} == "123321"'}
                    onChange={(event) => onUpdateModulePayload(blockKey, 'start_condition', { expression: event.target.value })}
                />
            </div>
            {isExpressionHelpOpen ? (
                <StartExpressionVariablePopover
                    anchorRef={expressionHelpButtonRef}
                    onClose={() => setIsExpressionHelpOpen(false)}
                    onInsert={insertStartExpressionToken}
                />
            ) : null}
            <label className="ac-v3-builder__field-row">
                <span>Приоритет</span>
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
                    <label key={channel.id} className="ac-v3-builder__check" title={channel.name}>
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

function ModuleConfigCard({ type, children, onRemove, summary = null }) {
    const [collapsed, setCollapsed] = useState(false);
    const meta = MODULE_META[type];

    return (
        <section className={`ac-v3-builder__module-card ${meta.className} ${collapsed ? 'is-collapsed' : ''}`}>
            <div className="ac-v3-builder__module-card-head">
                <span className="ac-v3-builder__module-card-icon">
                    <ModuleIcon type={type} />
                </span>
                <span className="ac-v3-builder__module-card-title">{meta.label}</span>
                {summary ? (
                    <span className="ac-v3-builder__module-card-summary">{summary}</span>
                ) : null}
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

function AutoGrowTextarea({ value, maxHeight = 180, className = '', textareaRef = null, ...props }) {
    const ref = useRef(null);
    const setRef = useCallback((element) => {
        ref.current = element;

        if (typeof textareaRef === 'function') {
            textareaRef(element);
        } else if (textareaRef) {
            textareaRef.current = element;
        }
    }, [textareaRef]);

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
            ref={setRef}
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

    if (type === 'action') {
        return <BotIcon />;
    }

    if (type === MODULE_TYPE_CALCULATOR) {
        return <CalculatorIcon />;
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

function edgeMatchInputLabel(matchType) {
    if (matchType === 'exact_parameter') {
        return 'Параметр';
    }

    if (matchType === 'exact_text_or_parameter') {
        return 'Текст или параметр';
    }

    if (matchType === 'exact_callback') {
        return 'Callback';
    }

    return 'Текст условия';
}

function normalizeDialogFieldKey(value) {
    return String(value ?? '').trim();
}

function isValidDialogFieldKey(value) {
    const key = normalizeDialogFieldKey(value);

    return DIALOG_FIELD_KEY_PATTERN.test(key) && ! RESERVED_DIALOG_FIELD_KEYS.has(key);
}

function currentFieldDictionary() {
    if (! activeFieldDictionary) {
        activeFieldDictionary = normalizeFieldDictionary(null);
    }

    return activeFieldDictionary;
}

function normalizeFieldDictionary(catalog) {
    const byEntity = {
        [FIELD_DICTIONARY_ENTITY_CONTACT]: new Map(),
        [FIELD_DICTIONARY_ENTITY_DIALOG]: new Map(),
    };
    const rowsByEntity = catalog && typeof catalog === 'object' ? catalog : null;
    const hasServerCatalog = rowsByEntity !== null
        && (
            Array.isArray(rowsByEntity[FIELD_DICTIONARY_ENTITY_CONTACT])
            || Array.isArray(rowsByEntity[FIELD_DICTIONARY_ENTITY_DIALOG])
        );

    if (! hasServerCatalog) {
        EDGE_CONTACT_CONDITION_FIELD_OPTIONS.forEach(([key, label, dataType]) => {
            byEntity[FIELD_DICTIONARY_ENTITY_CONTACT].set(key, {
                key,
                label,
                type: dataType === 'number' ? 'number' : (dataType === 'phone' ? 'phone' : 'text'),
                dataType,
                options: fallbackFieldOptions(FIELD_DICTIONARY_ENTITY_CONTACT, key),
                sourceFieldKey: null,
                isMultiple: false,
                isSystem: true,
                isFallback: true,
                sortOrder: 1000,
            });
        });
    }

    [FIELD_DICTIONARY_ENTITY_CONTACT, FIELD_DICTIONARY_ENTITY_DIALOG].forEach((entity) => {
        const rows = Array.isArray(rowsByEntity?.[entity]) ? rowsByEntity[entity] : [];

        rows.forEach((row) => {
            const key = normalizeDictionaryFieldKey(row?.key ?? row?.field_key);

            if (! key) {
                return;
            }

            const type = String(row?.type ?? 'text').trim() || 'text';
            const label = String(row?.label ?? row?.name ?? key).trim() || key;
            const options = Array.isArray(row?.options)
                ? row.options
                    .map((option) => ({
                        value: String(option?.value ?? '').trim(),
                        label: String(option?.label ?? '').trim(),
                        isSystem: option?.is_system === true,
                    }))
                    .filter((option) => option.value !== '' && option.label !== '')
                : [];

            byEntity[entity].set(key, {
                key,
                label,
                type,
                dataType: fieldTypeDataType(type),
                options,
                sourceFieldKey: normalizeDictionaryFieldKey(row?.source_field_key),
                isMultiple: row?.is_multiple === true,
                isSystem: row?.is_system === true,
                isFallback: false,
                sortOrder: Number(row?.sort_order) || 1000,
            });
        });
    });

    const contact = Array.from(byEntity[FIELD_DICTIONARY_ENTITY_CONTACT].values())
        .sort(dictionaryFieldSort);
    const dialog = Array.from(byEntity[FIELD_DICTIONARY_ENTITY_DIALOG].values())
        .sort(dictionaryFieldSort);

    return {
        contact,
        dialog,
        contactByKey: byEntity[FIELD_DICTIONARY_ENTITY_CONTACT],
        dialogByKey: byEntity[FIELD_DICTIONARY_ENTITY_DIALOG],
    };
}

function normalizeDictionaryFieldKey(value) {
    return String(value ?? '').trim();
}

function dictionaryFieldSort(left, right) {
    if ((left.sortOrder ?? 1000) !== (right.sortOrder ?? 1000)) {
        return (left.sortOrder ?? 1000) - (right.sortOrder ?? 1000);
    }

    return String(left.label ?? left.key).localeCompare(String(right.label ?? right.key), 'ru');
}

function fieldTypeDataType(type) {
    return FIELD_TYPE_DATA_TYPE[String(type ?? '').trim()] ?? 'any_text';
}

function fallbackFieldOptions(entity, fieldKey) {
    if (entity === FIELD_DICTIONARY_ENTITY_CONTACT && fieldKey === 'first_name_source') {
        return FIRST_NAME_SOURCE_CONDITION_OPTIONS.map(([value, label]) => ({ value, label, isSystem: true }));
    }

    return (ACTION_FIELD_VALUE_OPTIONS[entity]?.[fieldKey] ?? [])
        .map(([value, label]) => ({ value, label, isSystem: true }));
}

function dictionaryField(entity, fieldKey) {
    const dictionary = currentFieldDictionary();
    const key = normalizeDictionaryFieldKey(fieldKey);

    return entity === FIELD_DICTIONARY_ENTITY_CONTACT
        ? dictionary.contactByKey.get(key)
        : dictionary.dialogByKey.get(key);
}

function dictionaryFieldLabel(entity, fieldKey, fallback = null) {
    const key = normalizeDictionaryFieldKey(fieldKey);
    const field = dictionaryField(entity, key);
    const label = String(field?.label ?? '').trim();

    if (label) {
        return label;
    }

    const base = String(fallback ?? key).trim() || 'Поле';

    return `${base} · ${FIELD_DICTIONARY_MISSING_LABEL}`;
}

function dictionaryFieldValueOptions(entity, fieldKey, currentValue = null) {
    const field = dictionaryField(entity, fieldKey);
    const options = Array.isArray(field?.options) ? [...field.options] : fallbackFieldOptions(entity, fieldKey);
    const value = String(currentValue ?? '').trim();

    if (value && options.length > 0 && ! options.some((option) => option.value === value)) {
        options.push({
            value,
            label: `${value} · ${FIELD_DICTIONARY_MISSING_LABEL}`,
            isSystem: false,
        });
    }

    return options;
}

function dictionaryFieldValueLabel(entity, fieldKey, value, fallback = null) {
    const normalizedValue = String(value ?? '').trim();

    if (! normalizedValue) {
        return fallback ?? '""';
    }

    const option = dictionaryFieldValueOptions(entity, fieldKey, normalizedValue)
        .find((candidate) => candidate.value === normalizedValue);

    return option?.label ?? String(fallback ?? normalizedValue);
}

function contactCaptureFields() {
    return currentFieldDictionary().contact
        .filter((field) => CONTACT_RUNTIME_WRITABLE_FIELD_KEYS.has(field.key))
        .map((field) => [field.key, field.label, field.dataType]);
}

function contactConditionFields() {
    return currentFieldDictionary().contact
        .filter((field) => CONTACT_RUNTIME_CONDITION_FIELD_KEYS.has(field.key))
        .map((field) => [field.key, field.label, field.dataType]);
}

function contactActionFieldOptions(currentFieldKey = '') {
    const currentKey = normalizeDictionaryFieldKey(currentFieldKey);
    const options = currentFieldDictionary().contact.map((field) => ({
        key: field.key,
        label: field.label,
        disabled: ! ACTION_CONTACT_WRITABLE_FIELD_KEYS.has(field.key),
    }));

    if (currentKey && ! options.some((option) => option.key === currentKey)) {
        options.unshift({
            key: currentKey,
            label: `${currentKey} · ${FIELD_DICTIONARY_MISSING_LABEL}`,
            disabled: true,
        });
    }

    return options;
}

function contactLegacyWriteFieldOptions(currentFieldKey = '') {
    const currentKey = normalizeDictionaryFieldKey(currentFieldKey);
    const options = currentFieldDictionary().contact.map((field) => ({
        key: field.key,
        label: field.label,
        disabled: ! CONTACT_RUNTIME_WRITABLE_FIELD_KEYS.has(field.key),
    }));

    if (currentKey && ! options.some((option) => option.key === currentKey)) {
        options.unshift({
            key: currentKey,
            label: `${currentKey} · ${FIELD_DICTIONARY_MISSING_LABEL}`,
            disabled: true,
        });
    }

    return options;
}

function contactTransitionActionFieldOptions(currentFieldKey = '') {
    const currentKey = normalizeDictionaryFieldKey(currentFieldKey);
    const options = currentFieldDictionary().contact.map((field) => ({
        key: field.key,
        label: field.label,
        disabled: ! TRANSITION_CONTACT_WRITABLE_FIELD_KEYS.has(field.key),
    }));

    if (currentKey && ! options.some((option) => option.key === currentKey)) {
        options.unshift({
            key: currentKey,
            label: `${currentKey} · ${FIELD_DICTIONARY_MISSING_LABEL}`,
            disabled: true,
        });
    }

    return options;
}

function defaultTransitionContactFieldKey() {
    return currentFieldDictionary().contact
        .find((field) => TRANSITION_CONTACT_WRITABLE_FIELD_KEYS.has(field.key))
        ?.key ?? 'first_name';
}

function defaultWritableContactFieldKey() {
    return currentFieldDictionary().contact
        .find((field) => ACTION_CONTACT_WRITABLE_FIELD_KEYS.has(field.key))
        ?.key ?? 'first_name';
}

function defaultRuntimeWritableContactFieldKey() {
    return currentFieldDictionary().contact
        .find((field) => CONTACT_RUNTIME_WRITABLE_FIELD_KEYS.has(field.key))
        ?.key ?? 'first_name';
}

function aiPromptVariableGroups() {
    const dictionary = currentFieldDictionary();
    const contactItems = dictionary.contact
        .filter((field) => ! ['id', 'created_at', 'updated_at'].includes(field.key))
        .map((field) => ({
            token: `{{contact.${field.key}}}`,
            label: field.label,
            source: `Поле “${field.label}” в карточке контакта.`,
            type: variableTypeLabel(field),
        }));
    const dialogItems = dictionary.dialog
        .filter((field) => ! ['id', 'created_at', 'updated_at'].includes(field.key))
        .map((field) => ({
            token: `{{dialog.${field.key}}}`,
            label: field.label,
            source: `Поле “${field.label}” в карточке диалога.`,
            type: variableTypeLabel(field),
        }));

    return AI_PROMPT_VARIABLE_GROUPS.map((group) => {
        if (group.title === 'Карточка контакта') {
            return { ...group, items: contactItems.length > 0 ? contactItems : group.items };
        }

        if (group.title === 'Карточка диалога') {
            return { ...group, items: dialogItems.length > 0 ? dialogItems : group.items };
        }

        return group;
    });
}

function startExpressionVariableGroups() {
    const dictionary = currentFieldDictionary();
    const contactItems = dictionary.contact
        .filter((field) => START_EXPRESSION_CONTACT_FIELD_KEYS.has(field.key))
        .map((field) => ({
            token: `{{contact.${field.key}}}`,
            label: field.label,
        }));
    const dialogItems = dictionary.dialog
        .filter((field) => ! field.isSystem || START_EXPRESSION_DIALOG_SYSTEM_FIELD_KEYS.has(field.key))
        .map((field) => ({
            token: `{{dialog.${field.key}}}`,
            label: field.label,
        }));

    return [
        {
            title: 'Контакт',
            items: contactItems.length > 0 ? contactItems : [
                { token: '{{contact.phone}}', label: 'Телефон' },
                { token: '{{contact.first_name}}', label: 'Имя' },
            ],
        },
        {
            title: 'Диалог',
            items: dialogItems.length > 0 ? dialogItems : [
                { token: '{{dialog.phone}}', label: 'Телефон мессенджера' },
                { token: '{{dialog.start_param}}', label: 'Параметр запуска' },
            ],
        },
    ];
}

function variableTypeLabel(field) {
    if (Array.isArray(field.options) && field.options.length > 0) {
        return field.options.map((option) => option.value).join(' / ');
    }

    if (field.isMultiple) {
        return `${fieldTypeLabel(field.type)}, несколько значений`;
    }

    return fieldTypeLabel(field.type);
}

function fieldTypeLabel(type) {
    const normalized = String(type ?? '').trim();

    if (normalized === 'number') {
        return 'Число';
    }

    if (normalized === 'phone') {
        return 'Телефон';
    }

    if (normalized === 'email') {
        return 'Email';
    }

    if (normalized === 'date') {
        return 'Дата';
    }

    if (normalized === 'boolean') {
        return 'Да/нет';
    }

    if (normalized === 'select') {
        return 'Список';
    }

    return 'Текст';
}

function dialogFieldSuggestionsFromDictionary(fieldDictionary = currentFieldDictionary()) {
    return fieldDictionary.dialog
        .filter((field) => isValidDialogFieldKey(field.key))
        .map((field) => ({
            key: field.key,
            label: field.label || field.key,
            group: field.group || '',
        }));
}

function DialogFieldKeyInput({ value, onChange, placeholder, suggestions = [], purpose }) {
    const wrapperRef = useRef(null);
    const suggestionsRef = useRef(null);
    const [isSuggestionsOpen, setIsSuggestionsOpen] = useState(false);
    const [suggestionsStyle, setSuggestionsStyle] = useState(null);
    const fieldKey = String(value ?? '');
    const normalizedFieldKey = normalizeDialogFieldKey(fieldKey);
    const isInvalid = normalizedFieldKey !== '' && ! isValidDialogFieldKey(normalizedFieldKey);
    const visibleSuggestions = suggestions
        .map((suggestion) => {
            if (suggestion && typeof suggestion === 'object') {
                const key = normalizeDialogFieldKey(suggestion.key);

                return {
                    key,
                    label: String(suggestion.label ?? key),
                    group: String(suggestion.group ?? ''),
                };
            }

            const key = normalizeDialogFieldKey(suggestion);

            return { key, label: key, group: '' };
        })
        .filter((suggestion) => suggestion.key !== '');

    const updateSuggestionsPosition = useCallback(() => {
        if (! wrapperRef.current || typeof window === 'undefined') {
            return;
        }

        const rect = wrapperRef.current.getBoundingClientRect();
        const viewportWidth = window.innerWidth || 0;
        const viewportHeight = window.innerHeight || 0;
        const preferredWidth = Math.max(260, rect.width);
        const width = Math.min(preferredWidth, Math.max(220, viewportWidth - 24));
        const left = Math.min(Math.max(12, rect.right - width), Math.max(12, viewportWidth - width - 12));
        const belowSpace = viewportHeight - rect.bottom - 12;
        const aboveSpace = rect.top - 12;
        const openBelow = belowSpace >= 180 || belowSpace >= aboveSpace;
        const availableHeight = openBelow ? belowSpace : aboveSpace;
        const maxHeight = Math.max(140, Math.min(320, availableHeight));
        const top = openBelow
            ? rect.bottom + 6
            : Math.max(12, rect.top - maxHeight - 6);

        setSuggestionsStyle({
            left: `${left}px`,
            top: `${top}px`,
            width: `${width}px`,
            maxHeight: `${maxHeight}px`,
        });
    }, []);

    useEffect(() => {
        if (! isSuggestionsOpen) {
            return undefined;
        }

        function handlePointerDown(event) {
            const target = event.target;
            const isInsideField = wrapperRef.current?.contains(target);
            const isInsideSuggestions = suggestionsRef.current?.contains(target);

            if (! isInsideField && ! isInsideSuggestions) {
                setIsSuggestionsOpen(false);
            }
        }

        function handleKeyDown(event) {
            if (event.key === 'Escape') {
                setIsSuggestionsOpen(false);
            }
        }

        function handleViewportChange() {
            updateSuggestionsPosition();
        }

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);
        window.addEventListener('resize', handleViewportChange);
        window.addEventListener('scroll', handleViewportChange, true);

        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
            window.removeEventListener('resize', handleViewportChange);
            window.removeEventListener('scroll', handleViewportChange, true);
        };
    }, [isSuggestionsOpen, updateSuggestionsPosition]);

    useLayoutEffect(() => {
        if (isSuggestionsOpen) {
            updateSuggestionsPosition();
        }
    }, [isSuggestionsOpen, visibleSuggestions.length, updateSuggestionsPosition]);

    const suggestionsList = isSuggestionsOpen && visibleSuggestions.length > 0 && typeof document !== 'undefined' ? createPortal(
        <div
            ref={suggestionsRef}
            className="ac-v3-builder__dialog-field-suggestions"
            data-role="scenario-edge-dialog-field-key-suggestions"
            data-field-key-purpose={purpose}
            style={suggestionsStyle ?? undefined}
        >
            {visibleSuggestions.map((suggestion) => (
                <button
                    key={suggestion.key}
                    type="button"
                    data-role="scenario-edge-dialog-field-key-option"
                    data-field-key={suggestion.key}
                    title={`${suggestion.key} — ${suggestion.label}`}
                    onClick={() => {
                        onChange(suggestion.key);
                        setIsSuggestionsOpen(false);
                    }}
                >
                    <strong>{suggestion.key}</strong>
                    <span>{suggestion.label}</span>
                </button>
            ))}
        </div>,
        document.body,
    ) : null;

    return (
        <div className="ac-v3-builder__dialog-field-key" ref={wrapperRef}>
            <div className="ac-v3-builder__dialog-field-key-control">
                <input
                    data-role="scenario-edge-dialog-field-key-input"
                    data-field-key-purpose={purpose}
                    aria-invalid={isInvalid ? 'true' : 'false'}
                    value={fieldKey}
                    placeholder={placeholder}
                    onChange={(event) => onChange(event.target.value)}
                />
                {visibleSuggestions.length > 0 ? (
                    <button
                        type="button"
                        className="ac-v3-builder__dialog-field-suggestions-toggle"
                        aria-expanded={isSuggestionsOpen ? 'true' : 'false'}
                        onClick={() => setIsSuggestionsOpen((value) => ! value)}
                    >
                        Поля
                    </button>
                ) : null}
                {suggestionsList}
            </div>
            {isInvalid ? (
                <p
                    className="ac-v3-builder__field-error"
                    data-role="scenario-edge-dialog-field-key-error"
                    data-validation-status="invalid"
                >
                    Буквы, цифры и _, начинается с буквы.
                </p>
            ) : (
                <p className="ac-v3-builder__field-hint">
                    Буквы, цифры и _, начинается с буквы. Например: сколько_раз_спросили_имя
                </p>
            )}
        </div>
    );
}

function contactCaptureField(fieldKey) {
    const fields = contactCaptureFields();

    return fields.find(([value]) => value === fieldKey) ?? fields[0] ?? EDGE_CONTACT_FIELD_OPTIONS[0];
}

function contactConditionField(fieldKey) {
    const fields = contactConditionFields();

    return fields.find(([value]) => value === fieldKey) ?? fields[0] ?? EDGE_CONTACT_CONDITION_FIELD_OPTIONS[0];
}

function EdgePanel({ edge, blocks, onCollapse, onClose, onRemove, onUpdateConditionPayload, onResetWaypoints, onCopyEdgeId, onRefreshDiagnostics, timezone, timezoneLabel, dialogFieldKeys }) {
    const [isExpressionHelpOpen, setIsExpressionHelpOpen] = useState(false);
    const expressionHelpButtonRef = useRef(null);
    const expressionTextareaRef = useRef(null);
    const source = blocks.find((block) => block.client_key === edge.source?.client_key);
    const target = blocks.find((block) => block.client_key === edge.target?.client_key);
    const isAi = isAiEdge(edge);
    const isActionResult = isActionResultEdge(edge, source);
    const isButton = isButtonEdge(edge, source);
    const payload = edge.condition_payload ?? {};
    const edgeMode = isAi ? 'ai_analysis' : (isActionResult ? 'action_result' : (isButton ? 'button' : (payload.mode === 'automatic' ? 'automatic' : 'wait_reply')));
    const match = payload.match ?? {};
    const capture = payload.input_capture ?? {};
    const fieldCondition = payload.field_condition ?? {};
    const matchType = match.type ?? 'any_inbound';
    const captureEnabled = capture.enabled === true;
    const captureScope = capture.field_scope === 'contact' ? 'contact' : 'dialog';
    const selectedContactField = contactCaptureField(capture.field_key);
    const fieldConditionEnabled = fieldCondition.enabled === true;
    const fieldConditionScope = fieldCondition.field_scope === 'contact' ? 'contact' : 'dialog';
    const selectedFieldConditionContactField = contactConditionField(fieldCondition.field_key);
    const fieldConditionOperator = EDGE_FIELD_CONDITION_OPERATOR_OPTIONS.some(([value]) => value === fieldCondition.operator)
        ? fieldCondition.operator
        : 'filled';
    const fieldConditionEntity = fieldConditionScope === 'contact'
        ? FIELD_DICTIONARY_ENTITY_CONTACT
        : FIELD_DICTIONARY_ENTITY_DIALOG;
    const fieldConditionKey = fieldConditionScope === 'contact'
        ? selectedFieldConditionContactField[0]
        : normalizeDialogFieldKey(fieldCondition.field_key ?? '');
    const fieldConditionValueOptions = ['equals', 'not_equals'].includes(fieldConditionOperator)
        ? dictionaryFieldValueOptions(fieldConditionEntity, fieldConditionKey, fieldCondition.value)
        : [];
    const delay = normalizedEdgeDelay(payload.delay);
    const scheduledTransitions = edgeScheduledTransitions(edge);
    const showsAutoLabelControls = ! edgeUsesOutputLabel(edge, isButton);
    const labelMode = edgeLabelMode(edge);
    const transitionActions = transitionActionItems(payload.transition_actions);
    const waypointCount = edgeWaypoints(edge).length;
    const edgeTitlePlaceholder = edgeAutoLabel(edge) || DEFAULT_OUTPUT.label;
    const edgeTitle = edgeFullLabel(edge, isButton) || DEFAULT_OUTPUT.label;

    useEffect(() => {
        const migration = migrateStartPhoneConditions(payload);

        if (! migration) {
            return;
        }

        onUpdateConditionPayload((current) => ({
            ...current,
            ...migration,
        }));
    }, [
        onUpdateConditionPayload,
        payload.contact_phone_condition,
        payload.dialog_phone_condition,
        payload.expression,
    ]);

    function updatePayload(patch) {
        onUpdateConditionPayload((current) => ({
            ...current,
            ...patch,
        }));
    }

    function insertEdgeExpressionToken(token) {
        const nextExpression = insertTextAtSelection(expressionTextareaRef.current, payload.expression ?? '', token);

        updatePayload({ expression: nextExpression });
        window.requestAnimationFrame(() => {
            expressionTextareaRef.current?.focus();
        });
    }

    function updateEdgeLabel(value) {
        onUpdateConditionPayload((current) => ({
            ...current,
            label: value,
            ui: {
                ...(current.ui ?? {}),
                label_mode: EDGE_LABEL_MODE_MANUAL,
            },
        }));
    }

    function updateEdgeLabelMode(mode) {
        onUpdateConditionPayload((current) => ({
            ...current,
            ui: {
                ...(current.ui ?? {}),
                label_mode: mode,
            },
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

    function updateFieldCondition(patch) {
        onUpdateConditionPayload((current) => ({
            ...current,
            field_condition: {
                enabled: false,
                field_scope: 'dialog',
                field_key: '',
                operator: 'filled',
                value: '',
                ...(current.field_condition ?? {}),
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

    function updateTransitionActions(nextActions) {
        onUpdateConditionPayload((current) => ({
            ...current,
            transition_actions: nextActions.map((item) => normalizeTransitionActionItem(item)),
        }));
    }

    function updateTransitionAction(index, patch) {
        const next = transitionActions.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            return normalizeTransitionActionItem({
                ...item,
                ...patch,
            });
        });

        updateTransitionActions(next);
    }

    function addTransitionAction() {
        if (transitionActions.length >= MAX_TRANSITION_ACTIONS_PER_EDGE) {
            return;
        }

        updateTransitionActions([...transitionActions, defaultTransitionActionItem()]);
    }

    function removeTransitionAction(index) {
        updateTransitionActions(transitionActions.filter((_, itemIndex) => itemIndex !== index));
    }

    return (
        <div className="ac-v3-builder__inspector">
            <div className="ac-v3-builder__panel-head">
                <div className="ac-v3-builder__panel-title-row">
                    <span className="ac-v3-builder__panel-type-icon" title="Связь">
                        <LinkIcon />
                    </span>
                    <div className="ac-v3-builder__panel-title-field">
                        {showsAutoLabelControls ? (
                            <input
                                className="ac-v3-builder__panel-title-input ac-v3-builder__edge-title-input"
                                value={payload.label ?? ''}
                                placeholder={edgeTitlePlaceholder}
                                title={payload.label || edgeTitlePlaceholder}
                                aria-label="Название стрелки"
                                onChange={(event) => updateEdgeLabel(event.target.value)}
                            />
                        ) : (
                            <strong className="ac-v3-builder__panel-title-static" title={edgeTitle}>
                                {edgeTitle}
                            </strong>
                        )}
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
                <div className="ac-v3-builder__edge-mode-row">
                    <span>Режим</span>
                    {isAi ? (
                        <p className="ac-v3-builder__readonly">Переход по результату ИИ-анализа</p>
                    ) : isButton ? (
                        <p className="ac-v3-builder__readonly">Переход по кнопке</p>
                    ) : (
                        <div className="ac-v3-builder__edge-mode ac-v3-builder__edge-mode--inline" role="group" aria-label="Режим связи">
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
                </div>
                {showsAutoLabelControls ? (
                    <div className="ac-v3-builder__edge-mode-row">
                        <span>Подпись</span>
                        <div className="ac-v3-builder__edge-mode ac-v3-builder__edge-mode--inline" role="group" aria-label="Подпись на холсте">
                            <button
                                type="button"
                                className={labelMode === EDGE_LABEL_MODE_AUTO ? 'is-active' : ''}
                                onClick={() => updateEdgeLabelMode(EDGE_LABEL_MODE_AUTO)}
                            >
                                Авто
                            </button>
                            <button
                                type="button"
                                className={labelMode === EDGE_LABEL_MODE_MANUAL ? 'is-active' : ''}
                                onClick={() => updateEdgeLabelMode(EDGE_LABEL_MODE_MANUAL)}
                            >
                                Вручную
                            </button>
                        </div>
                    </div>
                ) : null}
                <div className="ac-v3-builder__field">
                    <div className="ac-v3-builder__label-row">
                        <span className="ac-v3-builder__field-label">Условие</span>
                        <button
                            ref={expressionHelpButtonRef}
                            type="button"
                            className="ac-v3-builder__inline-help-button ac-v3-builder__inline-help-button--icon"
                            aria-label="Показать переменные для условий перехода"
                            aria-expanded={isExpressionHelpOpen}
                            onClick={(event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                setIsExpressionHelpOpen((value) => ! value);
                            }}
                        >
                            i
                        </button>
                    </div>
                    <textarea
                        ref={expressionTextareaRef}
                        className="ac-v3-builder__textarea-auto ac-v3-builder__edge-expression-textarea"
                        rows={1}
                        value={payload.expression ?? ''}
                        placeholder={'{{contact.phone}} != "" and {{dialog.phone}} != ""'}
                        onChange={(event) => updatePayload({ expression: event.target.value })}
                    />
                </div>
                {isExpressionHelpOpen ? (
                    <StartExpressionVariablePopover
                        anchorRef={expressionHelpButtonRef}
                        title="Переменные условий перехода"
                        ariaLabel="Переменные для условий перехода"
                        onClose={() => setIsExpressionHelpOpen(false)}
                        onInsert={insertEdgeExpressionToken}
                    />
                ) : null}
                <label className="ac-v3-builder__check">
                    <input
                        type="checkbox"
                        checked={fieldConditionEnabled}
                        onChange={(event) => updateFieldCondition({ enabled: event.target.checked })}
                    />
                    <span>Условие по полю</span>
                </label>
                {fieldConditionEnabled ? (
                    <>
                        <span>Где проверять</span>
                        <div className="ac-v3-builder__edge-mode" role="group" aria-label="Где проверять поле">
                            {EDGE_CAPTURE_SCOPE_OPTIONS.map(([value, label]) => (
                                <button
                                    type="button"
                                    key={value}
                                    className={fieldConditionScope === value ? 'is-active' : ''}
                                    onClick={() => {
                                        if (value === 'contact') {
                                            const [fieldKey] = selectedFieldConditionContactField;

                                            updateFieldCondition({ field_scope: 'contact', field_key: fieldKey });

                                            return;
                                        }

                                        updateFieldCondition({ field_scope: 'dialog', field_key: '' });
                                    }}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        {fieldConditionScope === 'contact' ? (
                            <label>
                                <span>Поле контакта</span>
                                <select
                                    value={selectedFieldConditionContactField[0]}
                                    onChange={(event) => {
                                        const fieldKey = event.target.value;
                                        const patch = { field_scope: 'contact', field_key: fieldKey };
                                        const valueOptions = dictionaryFieldValueOptions(FIELD_DICTIONARY_ENTITY_CONTACT, fieldKey, fieldCondition.value);

                                        if (
                                            ['equals', 'not_equals'].includes(fieldConditionOperator)
                                            && valueOptions.length > 0
                                            && ! valueOptions.some((option) => option.value === fieldCondition.value)
                                        ) {
                                            patch.value = valueOptions[0].value;
                                        }

                                        updateFieldCondition(patch);
                                    }}
                                >
                                    {contactConditionFields().map(([value, label]) => (
                                        <option key={value} value={value}>{label}</option>
                                    ))}
                                </select>
                            </label>
                        ) : (
                            <label>
                                <span>Поле диалога</span>
                                <DialogFieldKeyInput
                                    value={fieldCondition.field_key ?? ''}
                                    onChange={(fieldKey) => updateFieldCondition({ field_scope: 'dialog', field_key: fieldKey })}
                                    placeholder="lead_status"
                                    suggestions={dialogFieldKeys}
                                    purpose="condition"
                                />
                            </label>
                        )}
                        <label>
                            <span>Проверка</span>
                            <select
                                value={fieldConditionOperator}
                                onChange={(event) => {
                                    const operator = event.target.value;
                                    const patch = { operator };
                                    const nextValueOptions = dictionaryFieldValueOptions(fieldConditionEntity, fieldConditionKey, fieldCondition.value);

                                    if (
                                        ['equals', 'not_equals'].includes(operator)
                                        && nextValueOptions.length > 0
                                        && ! nextValueOptions.some((option) => option.value === fieldCondition.value)
                                    ) {
                                        patch.value = nextValueOptions[0].value;
                                    }

                                    updateFieldCondition(patch);
                                }}
                            >
                                {EDGE_FIELD_CONDITION_OPERATOR_OPTIONS.map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </label>
                        {['equals', 'not_equals'].includes(fieldConditionOperator) && fieldConditionValueOptions.length > 0 ? (
                            <label>
                                <span>Значение</span>
                                <select
                                    value={fieldConditionValueOptions.some((option) => option.value === fieldCondition.value)
                                        ? fieldCondition.value
                                        : fieldConditionValueOptions[0].value}
                                    onChange={(event) => updateFieldCondition({ value: event.target.value })}
                                >
                                    {fieldConditionValueOptions.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </label>
                        ) : ['equals', 'not_equals'].includes(fieldConditionOperator) ? (
                            <label>
                                <span>Значение</span>
                                <input
                                    value={fieldCondition.value ?? ''}
                                    onChange={(event) => updateFieldCondition({ value: event.target.value })}
                                />
                            </label>
                        ) : null}
                    </>
                ) : null}
                {isAi ? (
                    <>
                        <span>Результат ИИ</span>
                        <p className="ac-v3-builder__readonly">{edgeLabel(edge, false)}</p>
                        <label className="ac-v3-builder__field-row">
                            <span>Приоритет</span>
                            <input
                                type="number"
                                value={payload.priority ?? 10}
                                onChange={(event) => updatePayload({ priority: Number(event.target.value) })}
                            />
                        </label>
                    </>
                ) : isButton ? (
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
                                <span>{edgeMatchInputLabel(matchType)}</span>
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
                                onChange={(event) => {
                                    const enabled = event.target.checked;

                                    onUpdateConditionPayload((current) => ({
                                        ...current,
                                        match: enabled
                                            ? {
                                                ...(current.match ?? {}),
                                                type: 'any_inbound',
                                            }
                                            : current.match,
                                        input_capture: {
                                            ...(current.input_capture ?? {}),
                                            enabled,
                                        },
                                    }));
                                }}
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
                                                {contactCaptureFields().map(([value, label]) => (
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
                                            <DialogFieldKeyInput
                                                value={capture.field_key ?? ''}
                                                placeholder="client_phone"
                                                onChange={(fieldKey) => updateCapture({ field_scope: 'dialog', field_key: fieldKey })}
                                                suggestions={dialogFieldKeys}
                                                purpose="capture"
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
                                                    <span>Стрелка {shortEdgeId(edge)} · переход #{transition.id} · v{transition.published_version_id} · диалог #{transition.dialog_id}</span>
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
                <div className="ac-v3-builder__ai-outputs">
                    <div className="ac-v3-builder__ai-outputs-head">
                        <span>Действия перехода</span>
                        <button
                            type="button"
                            disabled={transitionActions.length >= MAX_TRANSITION_ACTIONS_PER_EDGE}
                            onClick={addTransitionAction}
                        >
                            Добавить
                        </button>
                    </div>
                    {transitionActions.length === 0 ? (
                        <p className="ac-v3-builder__edge-diagnostics-empty">
                            Можно записать значения в поля контакта или диалога до перехода в следующий блок.
                        </p>
                    ) : (
                        <div className="ac-v3-builder__action-list">
                            {transitionActions.map((item, index) => {
                                const contactOptions = contactTransitionActionFieldOptions(item.target_field);

                                return (
                                    <div className="ac-v3-builder__action-row" key={`${index}-${item.target_scope}-${item.target_field}`}>
                                        <label>
                                            <span>Действие</span>
                                            <input readOnly value="Изменить поле" />
                                        </label>
                                        <label>
                                            <span>Сущность</span>
                                            <select
                                                value={item.target_scope}
                                                onChange={(event) => {
                                                    const targetScope = event.target.value;

                                                    updateTransitionAction(index, {
                                                        target_scope: targetScope,
                                                        target_field: targetScope === 'contact' ? defaultTransitionContactFieldKey() : 'field',
                                                        value: '',
                                                    });
                                                }}
                                            >
                                                {ACTION_TARGET_SCOPE_OPTIONS.map(([value, label]) => (
                                                    <option key={value} value={value}>{label}</option>
                                                ))}
                                            </select>
                                        </label>
                                        {item.target_scope === 'contact' ? (
                                            <label>
                                                <span>Поле</span>
                                                <select
                                                    value={item.target_field}
                                                    onChange={(event) => updateTransitionAction(index, {
                                                        target_field: event.target.value,
                                                        value: '',
                                                    })}
                                                >
                                                    {contactOptions.map((option) => (
                                                        <option key={option.key} value={option.key} disabled={option.disabled}>
                                                            {option.disabled ? `${option.label} · только отображение` : option.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                        ) : (
                                            <label>
                                                <span>Поле</span>
                                                <DialogFieldKeyInput
                                                    value={item.target_field}
                                                    placeholder="questionnaire_step"
                                                    onChange={(fieldKey) => updateTransitionAction(index, { target_field: fieldKey })}
                                                    suggestions={dialogFieldKeys}
                                                    purpose="transition_action"
                                                />
                                            </label>
                                        )}
                                        <TransitionActionValueField
                                            item={item}
                                            onChange={(value) => updateTransitionAction(index, { value })}
                                        />
                                        <button
                                            type="button"
                                            title="Удалить действие"
                                            onClick={() => removeTransitionAction(index)}
                                        >
                                            ×
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
                {waypointCount > 0 ? (
                    <div className="ac-v3-builder__edge-shape ac-v3-builder__edge-shape--compact">
                        <button type="button" onClick={onResetWaypoints}>
                            Сбросить форму стрелки
                        </button>
                    </div>
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

function sheetsFrom(builder) {
    return builder?.sheets?.length ? builder.sheets : [MAIN_SHEET];
}

function visibleSheetsForTabs(sheets, activeSheetId) {
    const source = Array.isArray(sheets) && sheets.length > 0 ? sheets : [MAIN_SHEET];

    return source.slice(0, MAX_VISIBLE_SHEET_TABS);
}

function activeSheetIdFrom(builder) {
    return activeSheetFrom(builder).id ?? MAIN_SHEET.id;
}

function activeSheetFrom(builder) {
    const sheets = sheetsFrom(builder);

    return sheets.find((sheet) => sheet.id === builder?.active_sheet_id) ?? sheets[0] ?? MAIN_SHEET;
}

function blockSheetId(block) {
    return String(block?.settings_payload?.ui?.sheet_id || MAIN_SHEET.id);
}

function blocksForSheet(blocks, sheetId) {
    const resolvedSheetId = String(sheetId || MAIN_SHEET.id);

    return (Array.isArray(blocks) ? blocks : []).filter((block) => blockSheetId(block) === resolvedSheetId);
}

function sheetBlockCount(blocks, sheetId) {
    return blocksForSheet(blocks, sheetId).length;
}

function blockImportCreatedBatchId(block) {
    const source = block?.settings_payload?.ui?.import_source ?? {};

    return source?.type === AUTO_REPLY_IMPORT_TYPE ? String(source.created_batch_id ?? '') : '';
}

function blockImportLastBatchId(block) {
    const source = block?.settings_payload?.ui?.import_source ?? {};

    return source?.type === AUTO_REPLY_IMPORT_TYPE ? String(source.last_import_batch_id ?? '') : '';
}

function sheetImportCreatedBatchId(sheet) {
    const source = sheet?.import_source ?? {};

    return source?.type === AUTO_REPLY_IMPORT_TYPE ? String(source.created_batch_id ?? '') : '';
}

function autoReplyImportBatches(builder) {
    const batches = new Map();
    const ensure = (batchId) => {
        const key = String(batchId ?? '').trim();

        if (! key) {
            return null;
        }

        if (! batches.has(key)) {
            batches.set(key, {
                created_batch_id: key,
                file_names: new Set(),
                created_blocks_count: 0,
                last_updated_blocks_count: 0,
                created_sheets_count: 0,
            });
        }

        return batches.get(key);
    };

    (builder?.blocks ?? []).forEach((block) => {
        const source = block?.settings_payload?.ui?.import_source ?? {};

        if (source?.type !== AUTO_REPLY_IMPORT_TYPE) {
            return;
        }

        const createdBatch = ensure(source.created_batch_id);

        if (createdBatch) {
            createdBatch.created_blocks_count += 1;

            if (source.source_file_name) {
                createdBatch.file_names.add(String(source.source_file_name));
            }
        }

        const lastBatch = ensure(source.last_import_batch_id);

        if (lastBatch) {
            lastBatch.last_updated_blocks_count += 1;

            if (source.source_file_name) {
                lastBatch.file_names.add(String(source.source_file_name));
            }
        }
    });

    (builder?.sheets ?? []).forEach((sheet) => {
        const batch = ensure(sheetImportCreatedBatchId(sheet));

        if (batch) {
            batch.created_sheets_count += 1;
        }
    });

    return Array.from(batches.values()).map((batch) => {
        const fileNames = Array.from(batch.file_names);

        return {
            ...batch,
            file_name: fileNames.length > 1 ? 'Несколько файлов' : (fileNames[0] ?? ''),
        };
    });
}

function sheetColorLabel(color) {
    return SHEET_COLORS.find(([value]) => value === color)?.[1] ?? 'Без цвета';
}

function createAutoReplyImportBatchId() {
    const now = new Date();
    const timestamp = now.toISOString().replace(/[^0-9T]/g, '').slice(0, 15);
    const random = Math.random().toString(16).slice(2, 8);

    return `auto_reply_xlsx_${timestamp}_${random}`;
}

function nextSheetNumberFromBuilder(builder, sheets) {
    const stored = Number(builder?.meta?.next_sheet_number);
    const nextFromSheets = (Array.isArray(sheets) ? sheets : [])
        .map((sheet) => sheetNumberFromId(sheet?.id) ?? 0)
        .reduce((max, number) => Math.max(max, number), 0) + 1;

    return Math.max(Number.isFinite(stored) && stored > 0 ? Math.floor(stored) : 1, nextFromSheets);
}

function sheetNumberFromId(sheetId) {
    const match = String(sheetId ?? '').match(/^sheet_(\d+)$/);

    return match ? Math.max(1, Number(match[1]) || 1) : null;
}

function uniqueSheetIdForNumber(sheets, number) {
    const used = new Set((Array.isArray(sheets) ? sheets : []).map((sheet) => String(sheet?.id ?? '')));
    let next = Math.max(1, Math.floor(Number(number) || 1));

    while (used.has(`sheet_${next}`)) {
        next += 1;
    }

    return `sheet_${next}`;
}

function edgeIdentityKey(edge) {
    return edge?.client_key || (edge?.id ? `id:${edge.id}` : JSON.stringify(edge));
}

function blockWithSheet(block, sheetId) {
    return {
        ...block,
        settings_payload: settingsPayloadWithSheet(block?.settings_payload, sheetId),
    };
}

function settingsPayloadWithSheet(settingsPayload, sheetId) {
    const settings = normalizeSettings(settingsPayload);

    return {
        ...settings,
        ui: {
            ...settings.ui,
            sheet_id: String(sheetId || MAIN_SHEET.id),
        },
    };
}

function defaultSheetImportSelection(preview) {
    const channelsById = new Set((preview?.available_channels ?? []).map((channel) => Number(channel.id)));
    const hintsByKey = new Map((preview?.channel_hints ?? []).map((hint) => [hint.export_key, hint]));
    const selection = {};

    (preview?.start_blocks ?? []).forEach((startBlock) => {
        const ids = (startBlock.channel_hint_keys ?? [])
            .map((key) => Number(hintsByKey.get(key)?.source_channel_id ?? 0))
            .filter((id) => id > 0 && channelsById.has(id));

        selection[startBlock.block_export_key] = [...new Set(ids)];
    });

    return selection;
}

function autoReplyImportStatusLabel(status) {
    return {
        create: 'Будет создано',
        update: 'Будет обновлено',
        unchanged: 'Без изменений',
        blocked: 'Нужна правка',
        conflict: 'Конфликт',
        excluded: 'Исключено',
    }[status] ?? String(status || 'Неизвестно');
}

function autoReplyImportReasonLabel(reason) {
    return {
        row_excluded: 'строка исключена',
        rule_id_required: 'нет ID правила',
        inactive_rule: 'правило выключено',
        tag_conditions_not_supported: 'условия по тегам не поддержаны в MVP',
        channel_required: 'канал обязателен',
        channel_not_mapped: 'канал нужно сопоставить',
        tag_not_mapped: 'тег нужно сопоставить',
        button_link_required: 'у кнопки нет ссылки',
        button_url_invalid: 'некорректная ссылка кнопки',
        request_phone_channel_unsupported: 'запрос телефона не поддержан каналом',
        manual_edit_conflict: 'блок меняли вручную',
    }[reason] ?? String(reason || 'неизвестная причина');
}

function autoReplyImportColumnLabel(column) {
    return {
        assign_tag_names: 'назначить тег',
        remove_tag_names: 'снять тег',
    }[column] ?? String(column || 'тег');
}

function downloadJsonDocument(filename, document) {
    const blob = new Blob([JSON.stringify(document, null, 2)], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = documentForDownload();

    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

function documentForDownload() {
    const link = document.createElement('a');

    link.style.display = 'none';
    document.body.appendChild(link);
    window.setTimeout(() => link.remove(), 0);

    return link;
}

function sheetExportFilename(document) {
    const sheetId = String(document?.sheet?.source_sheet_id || 'main').replace(/[^A-Za-z0-9_-]+/g, '-');
    const timestamp = String(document?.exported_at || new Date().toISOString()).replace(/[^0-9T]+/g, '').slice(0, 15);

    return `scenario-sheet-${sheetId || 'main'}-${timestamp}.json`;
}

function stateWithSheetImportFocus(state, focusBlockKey) {
    const sheetId = state?.import?.sheet_id || state?.builder?.active_sheet_id || MAIN_SHEET.id;
    const focusBlock = focusBlockKey
        ? (state?.builder?.blocks ?? []).find((block) => block.client_key === focusBlockKey)
        : null;
    const nextView = focusBlock
        ? { tx: 132 - blockPosition(focusBlock).x, ty: 100 - blockPosition(focusBlock).y, zoom: 1 }
        : MAIN_SHEET.view;
    const sheets = (state?.builder?.sheets?.length ? state.builder.sheets : [MAIN_SHEET]).map((sheet) => (
        sheet.id === sheetId ? { ...sheet, view: nextView } : sheet
    ));

    return {
        ...state,
        builder: {
            ...state.builder,
            active_sheet_id: sheetId,
            sheets,
        },
    };
}

function stateWithAutoReplyImportPlan(state, preview) {
    if (! state?.builder || ! preview?.plan) {
        return state;
    }

    const plan = preview.plan;
    const currentBuilder = state.builder;
    const currentBlocks = Array.isArray(currentBuilder.blocks) ? currentBuilder.blocks : [];
    const plannedBlocks = (Array.isArray(plan.blocks) ? plan.blocks : [])
        .map((item) => item?.block)
        .filter((block) => block && typeof block === 'object' && String(block.client_key ?? '') !== '');
    const plannedBlocksByKey = new Map(plannedBlocks.map((block) => [String(block.client_key), normalizeImportedBlock(block)]));
    const currentBlockKeys = new Set(currentBlocks.map((block) => String(block.client_key ?? '')));
    const blocks = [
        ...currentBlocks.map((block) => plannedBlocksByKey.get(String(block.client_key ?? '')) ?? block),
        ...plannedBlocks
            .filter((block) => ! currentBlockKeys.has(String(block.client_key ?? '')))
            .map((block) => plannedBlocksByKey.get(String(block.client_key)) ?? block),
    ];
    const currentSheets = sheetsFrom(currentBuilder);
    const plannedSheets = (Array.isArray(plan.sheets) ? plan.sheets : [])
        .map((item) => item?.sheet ?? item)
        .filter((sheet) => sheet && typeof sheet === 'object' && String(sheet.id ?? '') !== '');
    const plannedSheetsById = new Map(plannedSheets.map((sheet) => [String(sheet.id), sheet]));
    const currentSheetIds = new Set(currentSheets.map((sheet) => String(sheet.id)));
    const sheets = [
        ...currentSheets.map((sheet) => plannedSheetsById.has(String(sheet.id))
            ? { ...sheet, ...plannedSheetsById.get(String(sheet.id)) }
            : sheet),
        ...plannedSheets.filter((sheet) => ! currentSheetIds.has(String(sheet.id))),
    ];
    const focusSheetId = String(plan.focus_sheet_id || activeSheetIdFrom(currentBuilder));
    const focusBlock = blocks.find((block) => block.client_key === plan.focus_block_client_key) ?? null;
    const nextView = focusBlock
        ? { tx: 132 - blockPosition(focusBlock).x, ty: 100 - blockPosition(focusBlock).y, zoom: 1 }
        : undefined;

    return {
        ...state,
        builder: {
            ...currentBuilder,
            active_sheet_id: focusSheetId,
            sheets: nextView
                ? sheets.map((sheet) => (sheet.id === focusSheetId ? { ...sheet, view: nextView } : sheet))
                : sheets,
            blocks,
        },
    };
}

function stateWithCatalogTag(state, tag) {
    if (! state?.catalogs || ! tag) {
        return state;
    }

    const tagId = Number(tag.id);

    if (tagId <= 0) {
        return state;
    }

    const nextTag = {
        id: tagId,
        name: String(tag.name ?? ''),
        color: String(tag.color ?? 'gray'),
    };
    const tags = [
        ...(state.catalogs.tags ?? []).filter((item) => Number(item.id) !== tagId),
        nextTag,
    ].sort((left, right) => String(left.name).localeCompare(String(right.name), 'ru'));

    return {
        ...state,
        catalogs: {
            ...state.catalogs,
            tags,
        },
    };
}

function normalizeImportedBlock(block) {
    return {
        ...block,
        settings_payload: syncOutputs(normalizeSettings(block.settings_payload)),
    };
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
            caption: output.id === AI_FAILED_OUTPUT.id
                ? 'Система · резервная ветка'
                : (output.system ? 'Система' : (output.source === 'ai' ? 'ИИ' : (output.source === 'action' ? 'Действие' : null))),
            hint: output.id === AI_FAILED_OUTPUT.id
                ? 'Рекомендуется провести резервную стрелку: она сработает, если ИИ не дал корректный результат после повторных попыток.'
                : null,
            legacy: Boolean(output.legacy) || output.id === ACTION_GEO_CITY_LEGACY_LIMIT_OUTPUT.id,
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

function visibleBlockOutputs(block) {
    return blockOutputs(block).filter((output) => output.id !== null && output.kind !== 'default');
}

function isReadonlyOutput(output) {
    return Boolean(output?.legacy);
}

function blockHasGeoCityAction(block) {
    return actionItems(findModule(block?.settings_payload, 'action'))
        .some((item) => isGeoCityResultActionType(item.type));
}

function outputAnchor(block, outputId, side = 'right') {
    if (outputId === null) {
        return blockSideAnchor(block, 'right');
    }

    const position = blockPosition(block);
    const outputs = visibleBlockOutputs(block);
    const index = Math.max(0, outputs.findIndex((output) => output.id === outputId));

    return {
        x: side === 'left' ? position.x - 2 : position.x + PORT_DOT_CENTER_X,
        y: position.y + portsTopOffset(block) + (index * (PORT_ROW_HEIGHT + PORT_ROW_GAP)) + (PORT_ROW_HEIGHT / 2),
        side,
    };
}

function nearestBlockSideAnchor(block, targetPoint, anchors = {}) {
    const center = blockCenter(block, anchors);
    const dx = targetPoint.x - center.x;
    const dy = targetPoint.y - center.y;

    if (Math.abs(dx) > Math.abs(dy)) {
        return blockSideAnchor(block, dx >= 0 ? 'right' : 'left', anchors);
    }

    return blockSideAnchor(block, dy >= 0 ? 'bottom' : 'top', anchors);
}

function blockHorizontalSideToward(block, targetPoint, anchors = {}) {
    return targetPoint.x < blockCenter(block, anchors).x ? 'left' : 'right';
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
    const outputs = visibleBlockOutputs(block);
    const portsHeight = (outputs.length * PORT_ROW_HEIGHT)
        + (Math.max(0, outputs.length - 1) * PORT_ROW_GAP)
        + (outputs.length > 0 ? 12 : 0);

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

function graphBounds(blocks, edges = []) {
    const waypointPositions = edges.flatMap((edge) => edgeWaypoints(edge).map((waypoint) => ({ x: waypoint.x, y: waypoint.y })));

    if (blocks.length === 0 && waypointPositions.length === 0) {
        return { minX: 0, minY: 0, width: CANVAS_MIN_WIDTH, height: CANVAS_MIN_HEIGHT };
    }

    const xs = [
        ...blocks.map((block) => blockPosition(block).x),
        ...waypointPositions.map((point) => point.x),
    ];
    const ys = [
        ...blocks.map((block) => blockPosition(block).y),
        ...waypointPositions.map((point) => point.y),
    ];
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
            display_number: settingsPayload?.ui?.display_number ?? '',
            import_source: settingsPayload?.ui?.import_source ?? null,
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

function existingActionItems(actionModule) {
    if (! actionModule || actionModule.type !== 'action') {
        return [];
    }

    const rawItems = Array.isArray(actionModule?.payload?.actions) ? actionModule.payload.actions : [];

    if (rawItems.length === 0) {
        return [];
    }

    return actionItems(actionModule);
}

function regularActionItems(actionModule) {
    return existingActionItems(actionModule);
}

function calculatorActionItems(actionModule) {
    return existingActionItems(actionModule).filter((item) => item.type === ACTION_TYPE_VARIABLES);
}

function hasRegularAction(actionModule) {
    return regularActionItems(actionModule).length > 0;
}

function hasCalculatorAction(actionModule) {
    return calculatorActionItems(actionModule).length > 0;
}

function isCalculatorActionModule(actionModule) {
    if (! actionModule || actionModule.type !== 'action') {
        return false;
    }

    return hasCalculatorAction(actionModule) && ! hasRegularAction(actionModule);
}

function replaceActionModule(modules, actionModule, items) {
    const nextActionModule = {
        ...(actionModule ?? {}),
        id: actionModule?.id ?? 'mod_action',
        type: 'action',
        enabled: true,
        payload: {
            ...(actionModule?.payload ?? {}),
            actions: items,
        },
    };

    return [
        ...modules.filter((module) => module.type !== 'action'),
        nextActionModule,
    ];
}

function calculatorActionModulePayload() {
    return {
        actions: [
            {
                type: ACTION_TYPE_VARIABLES,
                operations: [defaultVariableOperation()],
            },
        ],
    };
}

function calculatorModuleTemplate() {
    return {
        id: 'mod_action',
        type: 'action',
        enabled: true,
        payload: calculatorActionModulePayload(),
    };
}

function moduleTemplate(type, channels, blocks = [], currentBlockKey = null) {
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
                expression: '',
                contact_phone_condition: '',
                dialog_phone_condition: '',
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

    if (type === 'ai') {
        return {
            id: 'mod_ai',
            type,
            enabled: true,
            payload: {
                prompt: DEFAULT_AI_PROMPT,
                source: 'current_inbound_message',
                variants: DEFAULT_AI_VARIANTS,
                extract_fields: DEFAULT_AI_EXTRACT_FIELDS,
            },
        };
    }

    if (type === 'action') {
        return {
            id: 'mod_action',
            type,
            enabled: true,
            payload: {
                actions: [defaultActionItem()],
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

function aiSettingsPayload() {
    return syncOutputs({
        ...messageSettingsPayload(''),
        modules: [moduleTemplate('ai', [])],
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

function buttonSummary(buttonsModule) {
    const rows = buttonRows(buttonsModule);
    const total = rows.reduce((sum, row) => sum + row.length, 0);

    return total > 0 ? `(${total} / 100 · ${rows.length} ${pluralRows(rows.length)})` : '(0 / 100)';
}

function actionSummary(actionModule) {
    return actionItemsSummary(existingActionItems(actionModule));
}

function regularActionSummary(actionModule) {
    return actionItemsSummary(regularActionItems(actionModule));
}

function calculatorSummary(actionModule) {
    return actionItemSummary(calculatorActionItem(actionModule)) || 'Нет действий';
}

function actionItemsSummary(items) {
    const summaries = items
        .map(actionItemSummary)
        .filter((summary) => summary !== '');

    if (summaries.length === 0) {
        return 'Нет действий';
    }

    if (summaries.length === 1) {
        return summaries[0];
    }

    const visibleSummaries = summaries.slice(0, 2).join('; ');
    const suffix = summaries.length > 2 ? '; ...' : '';

    return `${summaries.length} ${pluralActions(summaries.length)}: ${visibleSummaries}${suffix}`;
}

function actionItemSummary(item) {
    if (item.type === ACTION_TYPE_EDIT_MESSAGE) {
        if (item.operation === ACTION_EDIT_MESSAGE_OPERATION_DELETE_MESSAGE) {
            return 'Сообщение → удалить полностью';
        }

        return 'Сообщение → убрать кнопки';
    }

    if (item.type === ACTION_TYPE_CALCULATE_DISTANCE_TO_MOSCOW) {
        return 'Контакт → расстояние до Москвы';
    }

    if (item.type === ACTION_TYPE_CHECK_DATA) {
        const dictionary = ACTION_DICTIONARY_OPTIONS.find(([value]) => value === item.dictionary_key)?.[1] ?? 'справочник';

        return `${dictionary} → ${item.target_variable_key || 'переменная'}`;
    }

    if (item.type === ACTION_TYPE_RESOLVE_GEO_CITY) {
        return item.source === 'ai_data'
            ? 'География → данные ИИ'
            : 'География → ответ клиента';
    }

    if (item.type === ACTION_TYPE_VARIABLES) {
        const operations = normalizeVariableOperations(item.operations);
        const count = operations.length;

        if (count === 1) {
            const operation = operations[0];

            if (operation.operation === 'increment') {
                return `Диалог → ${operation.field_key || 'поле'} +${operation.amount || 1}`;
            }

            if (operation.operation === 'clear') {
                return `Диалог → ${operation.field_key || 'поле'} очистить`;
            }

            return `Диалог → ${operation.field_key || 'поле'}`;
        }

        return `Диалог → ${count} изменений`;
    }

    if (item.type === ACTION_TYPE_SIMULATE_START_PARAMETER) {
        return `Старт → {{dialog.${item.source_field_key || 'start_param'}}}`;
    }

    if (item.type === ACTION_TYPE_TAG_EFFECTS) {
        const count = normalizeIntegerList(item.assign_tag_ids).length + normalizeIntegerList(item.remove_tag_ids).length;

        return `Теги → ${count} ${pluralActions(count)}`;
    }

    if (item.type === ACTION_TYPE_BITRIX24_SYNC) {
        const label = BITRIX24_SYNC_OPERATION_OPTIONS
            .find(([value]) => value === normalizeBitrix24SyncOperation(item.operation))?.[1] ?? 'Синхронизация';

        return `Bitrix24 → ${label}`;
    }

    if (item.type === ACTION_TYPE_WRITE_CONTACT_FIELD) {
        const scope = ACTION_TARGET_SCOPE_OPTIONS.find(([value]) => value === item.target_scope)?.[1] ?? 'Данные';
        const field = item.target_scope === 'contact'
            ? dictionaryFieldLabel(FIELD_DICTIONARY_ENTITY_CONTACT, item.target_field, item.target_field)
            : dictionaryFieldLabel(FIELD_DICTIONARY_ENTITY_DIALOG, item.target_field, item.target_field);

        return `${scope} → ${field}`;
    }

    if (item.type === ACTION_TYPE_CHANGE_FIELD) {
        const scope = ACTION_TARGET_SCOPE_OPTIONS.find(([value]) => value === item.target_scope)?.[1] ?? 'Данные';
        const field = item.target_scope === 'contact'
            ? dictionaryFieldLabel(FIELD_DICTIONARY_ENTITY_CONTACT, item.target_field, item.target_field)
            : dictionaryFieldLabel(FIELD_DICTIONARY_ENTITY_DIALOG, item.target_field, item.target_field);

        return `${scope} → ${field}`;
    }

    return '';
}

function pluralActions(count) {
    const mod10 = count % 10;
    const mod100 = count % 100;

    if (mod10 === 1 && mod100 !== 11) {
        return 'действие';
    }

    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
        return 'действия';
    }

    return 'действий';
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

function aiSummary(aiModule) {
    const variantsCount = aiVariantDefinitions(aiModule).length;

    return `${variantsCount} ${pluralVariants(variantsCount)}`;
}

function aiVariantDefinitions(aiModule) {
    if (! aiModule) {
        return [];
    }

    const variants = Array.isArray(aiModule?.payload?.variants) ? aiModule.payload.variants : [];
    const normalizedVariants = variants
        .filter((variant) => variant && typeof variant === 'object')
        .map((variant) => ({
            id: String(variant.id ?? '').trim(),
            label: String(variant.label ?? ''),
            delay_seconds: Math.max(0, Math.min(300, Math.floor(Number(variant.delay_seconds) || 0))),
        }))
        .filter((variant) => variant.id !== '');

    return normalizedVariants.length > 0 ? normalizedVariants : DEFAULT_AI_VARIANTS;
}

function aiExtractFieldDefinitions(aiModule) {
    if (! aiModule) {
        return [];
    }

    const fields = Array.isArray(aiModule?.payload?.extract_fields) ? aiModule.payload.extract_fields : [];
    const normalizedFields = fields
        .filter((field) => field && typeof field === 'object')
        .map((field) => {
            const type = AI_EXTRACT_FIELD_TYPE_OPTIONS.some(([value]) => value === field.type)
                ? field.type
                : 'text';

            return {
                key: normalizeAiExtractFieldKey(field.key),
                label: String(field.label ?? ''),
                type,
            };
        })
        .filter((field) => field.key !== '');

    return normalizedFields.length > 0 ? normalizedFields : DEFAULT_AI_EXTRACT_FIELDS;
}

function aiBlocksForGeoSource(blocks, currentBlockKey) {
    return (Array.isArray(blocks) ? blocks : [])
        .filter((block) => block && typeof block === 'object')
        .filter((block) => block.client_key !== currentBlockKey)
        .filter((block) => findModule(block.settings_payload, 'ai'))
        .map((block) => ({
            client_key: block.client_key,
            title: block.title,
            settings_payload: block.settings_payload,
        }));
}

function isGeoCityResultActionType(type) {
    return type === ACTION_TYPE_RESOLVE_GEO_CITY;
}

function normalizeVariableOperations(operations) {
    const normalized = Array.isArray(operations)
        ? operations
            .filter((operation) => operation && typeof operation === 'object')
            .map((operation) => normalizeVariableOperation(operation))
            .filter((operation) => operation.field_key !== '')
            .slice(0, 10)
        : [];

    return normalized.length > 0 ? normalized : [defaultVariableOperation()];
}

function normalizeIntegerList(values) {
    return Array.from(new Set((Array.isArray(values) ? values : [])
        .map((value) => Number(value))
        .filter((value) => Number.isInteger(value) && value > 0)));
}

function normalizeBitrix24SyncOperation(operation) {
    return BITRIX24_SYNC_OPERATION_OPTIONS.some(([value]) => value === operation)
        ? operation
        : 'contact_sync';
}

function variableSetValueSourceOptions(valueSource) {
    return valueSource === 'current_message'
        ? [...VARIABLE_SET_VALUE_SOURCE_OPTIONS, ...VARIABLE_SET_LEGACY_VALUE_SOURCE_OPTIONS]
        : VARIABLE_SET_VALUE_SOURCE_OPTIONS;
}

function isVariableSetValueSource(valueSource) {
    return variableSetValueSourceOptions(valueSource).some(([value]) => value === valueSource);
}

function normalizeVariableOperation(operation) {
    const type = ['set', 'increment', 'clear'].includes(operation.operation)
        ? operation.operation
        : 'set';
    const fieldKey = normalizeDialogFieldKey(operation.field_key || 'счетчик');

    if (type === 'increment') {
        const amount = Math.max(1, Math.min(100, Math.floor(Number(operation.amount) || 1)));

        return {
            operation: 'increment',
            field_key: fieldKey,
            amount,
        };
    }

    if (type === 'clear') {
        return {
            operation: 'clear',
            field_key: fieldKey,
        };
    }

    return {
        operation: 'set',
        field_key: fieldKey,
        value_source: isVariableSetValueSource(operation.value_source)
            ? operation.value_source
            : 'static_value',
        value: String(operation.value ?? ''),
    };
}

function defaultVariableOperation() {
    return {
        operation: 'increment',
        field_key: 'счетчик',
        amount: 1,
    };
}

function calculatorActionItem(actionModule) {
    const operations = actionItems(actionModule)
        .filter((item) => item.type === ACTION_TYPE_VARIABLES)
        .flatMap((item) => normalizeVariableOperations(item.operations));

    return {
        type: ACTION_TYPE_VARIABLES,
        operations: operations.length > 0 ? operations : [defaultVariableOperation()],
    };
}

function actionItems(actionModule) {
    const rawItems = Array.isArray(actionModule?.payload?.actions) ? actionModule.payload.actions : [];
    const normalizedItems = rawItems
        .filter((item) => item && typeof item === 'object')
        .map((item) => normalizeActionItemForType(item))
        .filter((item) => (
            item.type === ACTION_TYPE_CHECK_DATA
            || item.type === ACTION_TYPE_EDIT_MESSAGE
            || item.type === ACTION_TYPE_CALCULATE_DISTANCE_TO_MOSCOW
            || isGeoCityResultActionType(item.type)
            || item.type === ACTION_TYPE_VARIABLES
            || item.type === ACTION_TYPE_SIMULATE_START_PARAMETER
            || item.type === ACTION_TYPE_TAG_EFFECTS
            || item.type === ACTION_TYPE_BITRIX24_SYNC
            || item.type === ACTION_TYPE_CHANGE_FIELD
            || item.target_field !== ''
        ));

    return normalizedItems.length > 0 ? normalizedItems : [defaultActionItem()];
}

export function normalizeActionItemForType(item) {
    const knownType = [
        ACTION_TYPE_CHECK_DATA,
        ACTION_TYPE_EDIT_MESSAGE,
        ACTION_TYPE_CALCULATE_DISTANCE_TO_MOSCOW,
        ACTION_TYPE_VARIABLES,
        ACTION_TYPE_SIMULATE_START_PARAMETER,
        ACTION_TYPE_TAG_EFFECTS,
        ACTION_TYPE_BITRIX24_SYNC,
        ACTION_TYPE_WRITE_CONTACT_FIELD,
        ACTION_TYPE_CHANGE_FIELD,
    ].includes(item.type) || isGeoCityResultActionType(item.type);
    const type = knownType ? item.type : ACTION_TYPE_CHANGE_FIELD;

    if (type === ACTION_TYPE_EDIT_MESSAGE) {
        const operation = item.operation === ACTION_EDIT_MESSAGE_OPERATION_DELETE_MESSAGE
            ? ACTION_EDIT_MESSAGE_OPERATION_DELETE_MESSAGE
            : ACTION_EDIT_MESSAGE_OPERATION_REMOVE_BUTTONS;
        const defaultTarget = operation === ACTION_EDIT_MESSAGE_OPERATION_DELETE_MESSAGE
            ? ACTION_EDIT_MESSAGE_TARGET_LAST_CURRENT_RUN_OUTBOUND
            : ACTION_EDIT_MESSAGE_TARGET_LAST_CURRENT_RUN_OUTBOUND_WITH_INLINE_BUTTONS;
        const target = item.target === defaultTarget
            ? item.target
            : defaultTarget;

        return {
            type,
            operation,
            target,
        };
    }

    if (type === ACTION_TYPE_CHECK_DATA) {
        const dictionaryKey = ACTION_DICTIONARY_OPTIONS.some(([value]) => value === item.dictionary_key)
            ? item.dictionary_key
            : 'names';
        const checkSource = ACTION_CHECK_SOURCE_OPTIONS.some(([value]) => value === item.check_source)
            ? item.check_source
            : 'current_inbound_message';

        return {
            type,
            source_type: 'inbound_message',
            check_source: checkSource,
            dictionary_key: dictionaryKey,
            lookup_field: 'lookup_value',
            result_field: 'result_value',
            target_variable_key: normalizeAiExtractFieldKey(item.target_variable_key || item.source_field_key || 'first_name'),
        };
    }

    if (type === ACTION_TYPE_CALCULATE_DISTANCE_TO_MOSCOW) {
        return { type };
    }

    if (type === ACTION_TYPE_VARIABLES) {
        return {
            type,
            operations: normalizeVariableOperations(item.operations),
        };
    }

    if (type === ACTION_TYPE_SIMULATE_START_PARAMETER) {
        return {
            type,
            source_scope: 'dialog',
            source_field_key: normalizeDialogFieldKey(item.source_field_key || 'start_param'),
            clear_source_field_after_reroute: Boolean(item.clear_source_field_after_reroute),
        };
    }

    if (type === ACTION_TYPE_TAG_EFFECTS) {
        return {
            type,
            assign_tag_ids: normalizeIntegerList(item.assign_tag_ids),
            remove_tag_ids: normalizeIntegerList(item.remove_tag_ids),
        };
    }

    if (type === ACTION_TYPE_BITRIX24_SYNC) {
        return {
            type,
            operation: normalizeBitrix24SyncOperation(item.operation),
        };
    }

    if (isGeoCityResultActionType(type)) {
        const source = item.source === 'ai_data' ? 'ai_data' : 'current_inbound_message';

        if (source === 'ai_data') {
            return {
                type,
                source,
                source_block_client_key: String(item.source_block_client_key ?? ''),
                source_block_id: String(item.source_block_id ?? ''),
                city_field_key: normalizeAiExtractFieldKey(item.city_field_key || item.source_field_key || 'geo_city') || 'geo_city',
                region_field_key: normalizeAiExtractFieldKey(item.region_field_key || 'geo_region') || 'geo_region',
                country_field_key: normalizeAiExtractFieldKey(item.country_field_key || 'geo_country') || 'geo_country',
            };
        }

        return { type, source };
    }

    if (type === ACTION_TYPE_WRITE_CONTACT_FIELD) {
        const targetScope = ACTION_TARGET_SCOPE_OPTIONS.some(([value]) => value === item.target_scope)
            ? item.target_scope
            : 'contact';
        const requestedTargetField = normalizeDictionaryFieldKey(item.target_field);
        const targetField = targetScope === 'contact'
            ? (CONTACT_RUNTIME_WRITABLE_FIELD_KEYS.has(requestedTargetField) ? requestedTargetField : defaultRuntimeWritableContactFieldKey())
            : normalizeDialogFieldKey(item.target_field || 'field');
        const sourceType = item.source_type === 'static_value' ? 'static_value' : 'ai_data';

        return {
            type,
            source_type: sourceType,
            source_block_client_key: sourceType === 'ai_data' ? String(item.source_block_client_key ?? '') : '',
            source_block_id: sourceType === 'ai_data' ? String(item.source_block_id ?? '') : '',
            source_field_key: sourceType === 'ai_data'
                ? normalizeAiExtractFieldKey(item.source_field_key ?? item.target_variable_key ?? '')
                : '',
            static_value: sourceType === 'static_value' ? String(item.static_value ?? item.manual_value ?? '') : '',
            target_scope: targetScope,
            target_field: targetField,
        };
    }

    const targetScope = ACTION_TARGET_SCOPE_OPTIONS.some(([value]) => value === item.target_scope)
        ? item.target_scope
        : 'contact';
    const requestedTargetField = normalizeDictionaryFieldKey(item.target_field);
    const targetField = targetScope === 'contact'
        ? (ACTION_CONTACT_WRITABLE_FIELD_KEYS.has(requestedTargetField) ? requestedTargetField : defaultWritableContactFieldKey())
        : normalizeDialogFieldKey(item.target_field || 'field');
    const valueSource = ACTION_VALUE_SOURCE_OPTIONS.some(([value]) => value === item.value_source)
        ? item.value_source
        : (item.source_type === 'ai_data' ? 'ai_result' : 'manual');
    const normalized = {
        type,
        target_scope: targetScope,
        target_field: targetField,
        value_source: valueSource,
        source_block_client_key: String(item.source_block_client_key ?? ''),
        source_block_id: String(item.source_block_id ?? ''),
        source_field_key: normalizeAiExtractFieldKey(item.source_field_key ?? item.target_variable_key ?? ''),
        manual_value: String(item.manual_value ?? item.static_value ?? ''),
    };

    if (valueSource === 'manual') {
        const options = actionManualValueOptions(normalized);

        if (options.length > 0 && normalized.manual_value !== '' && ! options.some(([value]) => value === normalized.manual_value)) {
            normalized.manual_value = options[0][0];
        }
    }

    return normalized;
}

function defaultActionItem() {
    return {
        type: ACTION_TYPE_CHANGE_FIELD,
        target_scope: 'contact',
        target_field: 'first_name',
        value_source: 'manual',
        source_block_client_key: '',
        source_block_id: '',
        source_field_key: '',
        manual_value: '',
    };
}

function transitionActionItems(actions) {
    if (! Array.isArray(actions)) {
        return [];
    }

    return actions
        .slice(0, MAX_TRANSITION_ACTIONS_PER_EDGE)
        .map((item) => normalizeTransitionActionItem(item));
}

function normalizeTransitionActionItem(item = {}) {
    const targetScope = ACTION_TARGET_SCOPE_OPTIONS.some(([value]) => value === item.target_scope)
        ? item.target_scope
        : 'contact';
    const targetField = targetScope === 'contact'
        ? (normalizeDictionaryFieldKey(item.target_field) || defaultTransitionContactFieldKey())
        : normalizeDialogFieldKey(item.target_field || 'field');
    const normalized = {
        type: TRANSITION_ACTION_TYPE_WRITE_FIELD,
        target_scope: targetScope,
        target_field: targetField,
        value_source: TRANSITION_ACTION_VALUE_SOURCE_STATIC,
        value: String(item.value ?? item.static_value ?? ''),
    };
    const options = transitionActionValueOptions(normalized);

    if (options.length > 0 && ! options.some(([value]) => value === normalized.value)) {
        normalized.value = options[0][0];
    }

    return normalized;
}

function defaultTransitionActionItem() {
    return normalizeTransitionActionItem({
        type: TRANSITION_ACTION_TYPE_WRITE_FIELD,
        target_scope: 'contact',
        target_field: defaultTransitionContactFieldKey(),
        value_source: TRANSITION_ACTION_VALUE_SOURCE_STATIC,
        value: '',
    });
}

function transitionActionValueOptions(item) {
    const entity = item.target_scope === 'dialog'
        ? FIELD_DICTIONARY_ENTITY_DIALOG
        : FIELD_DICTIONARY_ENTITY_CONTACT;

    return dictionaryFieldValueOptions(entity, item.target_field, item.value)
        .map((option) => [option.value, option.label]);
}

function actionStaticValueOptions(item) {
    const entity = item.target_scope === 'dialog'
        ? FIELD_DICTIONARY_ENTITY_DIALOG
        : FIELD_DICTIONARY_ENTITY_CONTACT;

    return dictionaryFieldValueOptions(entity, item.target_field, item.static_value)
        .map((option) => [option.value, option.label]);
}

function actionManualValueOptions(item) {
    const entity = item.target_scope === 'dialog'
        ? FIELD_DICTIONARY_ENTITY_DIALOG
        : FIELD_DICTIONARY_ENTITY_CONTACT;

    return dictionaryFieldValueOptions(entity, item.target_field, item.manual_value)
        .map((option) => [option.value, option.label]);
}

function nextAiVariantId(variants) {
    const ids = new Set(variants.map((variant) => variant.id));
    let index = variants.length + 1;
    let id = String(index);

    while (ids.has(id)) {
        index += 1;
        id = String(index);
    }

    return id;
}

function normalizeAiExtractFieldKey(value) {
    const key = String(value ?? '')
        .trim()
        .replace(/[^A-Za-z0-9_]/g, '_')
        .replace(/^[^A-Za-z]+/, '')
        .slice(0, 64);

    return key;
}

function nextAiExtractFieldKey(fields) {
    const keys = new Set(fields.map((field) => field.key));
    let index = fields.length + 1;
    let key = `field_${index}`;

    while (keys.has(key)) {
        index += 1;
        key = `field_${index}`;
    }

    return key;
}

function pluralVariants(count) {
    if (count === 1) {
        return 'вариант';
    }

    if (count >= 2 && count <= 4) {
        return 'варианта';
    }

    return 'вариантов';
}

function syncOutputs(settingsPayload) {
    const buttons = findModule(settingsPayload, 'buttons');
    const buttonOutputs = buttons
        ? flatButtons(buttons)
            .filter((button) => button.type !== BUTTON_TYPE_LINK)
            .map((button) => ({
                id: button.id,
                label: button.text,
                source: 'button',
                module_id: buttons.id,
                button_id: button.id,
                button_type: button.type === BUTTON_TYPE_REQUEST_PHONE ? BUTTON_TYPE_REQUEST_PHONE : BUTTON_TYPE_TEXT,
            }))
        : [];
    const ai = findModule(settingsPayload, 'ai');
    const aiOutputs = aiVariantDefinitions(ai)
        .map((output, index) => ({
            id: output.id,
            label: output.label,
            source: 'ai',
            module_id: ai?.id ?? 'mod_ai',
            ai_variant_id: output.id,
            ai_choice_id: String(index + 1),
        }));
    const aiSystemOutputs = ai ? [{
        ...AI_FAILED_OUTPUT,
        module_id: ai?.id ?? AI_FAILED_OUTPUT.module_id,
    }] : [];
    const action = findModule(settingsPayload, 'action');
    const actionDefinitions = actionItems(action);
    const actionOutputs = [
        ...(actionDefinitions.some((item) => item.type === ACTION_TYPE_CHECK_DATA) ? ACTION_CHECK_DATA_OUTPUTS : []),
        ...(actionDefinitions.some((item) => item.type === ACTION_TYPE_CALCULATE_DISTANCE_TO_MOSCOW) ? ACTION_DISTANCE_TO_MOSCOW_OUTPUTS : []),
        ...(actionDefinitions.some((item) => isGeoCityResultActionType(item.type)) ? ACTION_GEO_CITY_OUTPUTS : []),
    ].map((output) => ({
        ...output,
        module_id: action?.id ?? 'mod_action',
    }));

    return {
        ...settingsPayload,
        outputs: [...buttonOutputs, ...aiOutputs, ...aiSystemOutputs, ...actionOutputs],
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

function edgePayload(outputId, label, kind = null) {
    const isButton = outputId !== null;
    const isAi = kind === 'ai';
    const isAction = kind === 'action';

    return {
        schema_version: 3,
        edge_schema_version: 3,
        edge_key: null,
        from_output_id: outputId,
        label,
        mode: isAi ? 'ai_analysis' : (isAction ? 'action_result' : (isButton ? 'button' : 'wait_reply')),
        priority: 10,
        transition_limit: 0,
        contact_phone_condition: '',
        dialog_phone_condition: '',
        expression: '',
        field_condition: {
            enabled: false,
            field_scope: 'dialog',
            field_key: '',
            operator: 'filled',
            value: '',
        },
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
            label_mode: isButton ? EDGE_LABEL_MODE_MANUAL : EDGE_LABEL_MODE_AUTO,
            from_anchor: null,
            to_anchor: { side: 'left', t: 0.5 },
            waypoints: [],
            style: 'curve',
        },
    };
}

function rewiredEdgePayload(payload, output) {
    const outputId = output.id ?? null;
    const kind = output.kind ?? (outputId === null ? 'default' : 'button');
    const base = edgePayload(outputId, output.label, kind);
    const mode = outputId === null && ['automatic', 'wait_reply'].includes(payload.mode)
        ? payload.mode
        : base.mode;
    const next = {
        ...base,
        ...payload,
        from_output_id: outputId,
        label: output.label,
        mode,
    };

    if (outputId !== null || mode === 'automatic') {
        next.match = base.match;
    }

    return next;
}

function sameSource(left, right) {
    return left?.client_key === right?.client_key && (left?.output_id ?? null) === (right?.output_id ?? null);
}

function filterEdgesForBlocks(edges, blocks) {
    const blockKeys = new Set(blocks.map((block) => block.client_key));

    return edges.filter((edge) => (
        blockKeys.has(edge?.source?.client_key)
        && blockKeys.has(edge?.target?.client_key)
    ));
}

function portAnchorKey(blockKey, outputId, side = 'right') {
    return `${blockKey}:${outputId ?? 'default'}:${side}`;
}

function parsePortAnchorKey(key) {
    const parts = String(key ?? '').split(':');

    if (parts.length !== 3) {
        return null;
    }

    return {
        blockKey: parts[0],
        outputId: parts[1] === 'default' ? null : parts[1],
        side: parts[2],
    };
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

function TrashIcon() {
    return (
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M3.2 4.5h9.6M6.2 4.5V3.3c0-.5.4-.9.9-.9h1.8c.5 0 .9.4.9.9v1.2M5 6.2l.4 6.1c0 .7.6 1.2 1.3 1.2h2.6c.7 0 1.3-.5 1.3-1.2l.4-6.1" stroke="currentColor" strokeWidth="1.35" strokeLinecap="round" strokeLinejoin="round" />
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

function CalculatorIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
            <rect x="3" y="2.5" width="10" height="11" rx="1.8" stroke="currentColor" strokeWidth="1.35" />
            <path d="M5.4 5.5h5.2M5.5 8.1h1.1M8 8.1h1.1M10.5 8.1h.1M5.5 10.5h1.1M8 10.5h1.1M10.5 10.5h.1" stroke="currentColor" strokeWidth="1.35" strokeLinecap="round" />
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
