-- Безопасный набор KPI-запросов AB Connector для production.
--
-- Назначение:
--   Получить только агрегированные KPI по production-данным без вывода
--   идентификаторов, текстов сообщений, телефонов, имён, raw payload,
--   сообщений об ошибках и строк отдельных диалогов или контактов.
--
-- Запуск через psql:
--   psql "$DATABASE_URL" \
--     -v period_start="'2026-07-01 00:00:00'" \
--     -v period_end="'2026-07-07 23:59:59'" \
--     -v snapshot_at="'2026-07-07 23:59:59'" \
--     -f docs/reference/analytics-kpi-production-safe.sql
--
-- Ограничения для production:
--   - Использовать отдельную роль только с правами SELECT и TEMP.
--   - Начинать с короткого периода, например 7 или 30 дней.
--   - Не передавать полные psql-логи с параметрами подключения.
--   - Передавать только результирующие таблицы этого файла.
--
-- Параметры в часовом поясе Europe/Moscow:
--   :period_start  Включительная нижняя граница периода.
--   :period_end    Включительная верхняя граница периода.
--   :snapshot_at   Фактический момент запуска; SLA-граница равна ему минус один час.
--
-- Примечания:
--   - Файл создаёт только временные views в текущей DB-сессии.
--   - В подсчётах используются только корневые контакты.
--   - Диалоговые метрики исключают стадии с behavior_policy = blacklist.

set timezone = 'Europe/Moscow';
set statement_timeout = '30s';

drop view if exists pg_temp.ab_prod_kpi_contact_eligibility cascade;
drop view if exists pg_temp.ab_prod_kpi_reply_state cascade;
drop view if exists pg_temp.ab_prod_kpi_latest_operator_reply cascade;
drop view if exists pg_temp.ab_prod_kpi_latest_inbound cascade;
drop view if exists pg_temp.ab_prod_kpi_eligible_dialogs cascade;
drop view if exists pg_temp.ab_prod_kpi_root_contacts cascade;
drop view if exists pg_temp.ab_prod_kpi_params cascade;

create temporary view ab_prod_kpi_params as
select
    :period_start::timestamp as period_start,
    :period_end::timestamp as period_end,
    :snapshot_at::timestamp as snapshot_at,
    :snapshot_at::timestamp - interval '1 hour' as sla_cutoff;

create temporary view ab_prod_kpi_root_contacts as
select contacts.*
from contacts
where contacts.merged_into_contact_id is null;

create temporary view ab_prod_kpi_eligible_dialogs as
select dialogs.*
from dialogs
join ab_prod_kpi_root_contacts contacts on contacts.id = dialogs.contact_id
left join dialog_stages stage_by_id on stage_by_id.id = dialogs.stage_id
left join dialog_stages stage_by_key on stage_by_key.key = dialogs.stage
where coalesce(stage_by_id.behavior_policy, 'standard') <> 'blacklist'
  and coalesce(stage_by_key.behavior_policy, 'standard') <> 'blacklist';

create temporary view ab_prod_kpi_reply_state as
select
    dialogs.id as dialog_id,
    dialogs.contact_id,
    dialogs.channel_id,
    latest_inbound.sort_at as latest_inbound_at,
    (
        latest_inbound.message_id is not null
        and coalesce(dialogs.manual_reply_dismissed_source_message_id, -1) <> latest_inbound.message_id
        and (
            latest_reply.message_id is null
            or (latest_inbound.sort_at, latest_inbound.message_id) > (latest_reply.sort_at, latest_reply.message_id)
        )
    ) as requires_reply
from ab_prod_kpi_eligible_dialogs dialogs
left join lateral (
    select
        messages.id as message_id,
        coalesce(messages.received_at, messages.created_at) as sort_at
    from messages
    where messages.dialog_id = dialogs.id
      and messages.message_kind = 'inbound_user'
    order by
        coalesce(messages.received_at, messages.created_at) desc,
        messages.id desc
    limit 1
) latest_inbound on true
left join lateral (
    select
        messages.id as message_id,
        coalesce(messages.received_at, messages.created_at) as sort_at
    from messages
    where messages.dialog_id = dialogs.id
      and messages.message_kind in ('outbound_manual_reply', 'outbound_external_account_message')
    order by
        coalesce(messages.received_at, messages.created_at) desc,
        messages.id desc
    limit 1
) latest_reply on true;

create temporary view ab_prod_kpi_contact_eligibility as
select
    source.contact_id,
    min(source.eligible_at) as eligible_at
from (
    select
        dialogs.contact_id,
        dialogs.phone_confirmed_at as eligible_at
    from ab_prod_kpi_eligible_dialogs dialogs
    where dialogs.phone_confirmed_at is not null

    union all

    select
        contacts.id as contact_id,
        contacts.data_collection_completed_at as eligible_at
    from ab_prod_kpi_root_contacts contacts
    where contacts.data_collection_completed_at is not null
) source
group by source.contact_id;

-- 0. Контекст запуска и размеры агрегированной области данных.
select
    'run_context' as section,
    params.period_start,
    params.period_end,
    params.snapshot_at,
    params.sla_cutoff,
    (select count(*) from contacts) as all_contacts,
    (select count(*) from ab_prod_kpi_root_contacts) as root_contacts,
    (select count(*) from dialogs) as all_dialogs,
    (select count(*) from ab_prod_kpi_eligible_dialogs) as eligible_dialogs,
    (select count(*) from dialogs) - (select count(*) from ab_prod_kpi_eligible_dialogs) as excluded_or_non_root_dialogs
from ab_prod_kpi_params params;

-- 1. Сводка основных KPI.
select
    'dialog_to_usable_contact_rate' as metric,
    count(*) filter (
        where dialogs.phone_confirmed_at is not null
           or contacts.data_collection_status = 'completed'
           or contacts.data_collection_completed_at is not null
    ) as numerator,
    count(*) as denominator,
    round(
        100.0 * count(*) filter (
            where dialogs.phone_confirmed_at is not null
               or contacts.data_collection_status = 'completed'
               or contacts.data_collection_completed_at is not null
        ) / nullif(count(*), 0),
        2
    ) as pct
from ab_prod_kpi_eligible_dialogs dialogs
join ab_prod_kpi_root_contacts contacts on contacts.id = dialogs.contact_id
cross join ab_prod_kpi_params params
where dialogs.created_at between params.period_start and params.period_end

union all

select
    'operator_manageability_score' as metric,
    count(*) filter (where reply_state.requires_reply and reply_state.latest_inbound_at >= params.sla_cutoff) as numerator,
    count(*) filter (where reply_state.requires_reply) as denominator,
    case
        when count(*) filter (where reply_state.requires_reply) = 0 then 100.00
        else round(
            100.0 * count(*) filter (
                where reply_state.requires_reply
                  and reply_state.latest_inbound_at >= params.sla_cutoff
            ) / count(*) filter (where reply_state.requires_reply),
            2
        )
    end as pct
from ab_prod_kpi_reply_state reply_state
cross join ab_prod_kpi_params params

union all

select
    'bitrix24_crm_happy_path_rate' as metric,
    count(*) filter (
        where contacts.bitrix24_sync_status = 'synced'
          and contacts.bitrix24_deal_sync_status = 'synced'
          and contacts.bitrix24_history_sync_status = 'synced'
    ) as numerator,
    count(*) as denominator,
    round(
        100.0 * count(*) filter (
            where contacts.bitrix24_sync_status = 'synced'
              and contacts.bitrix24_deal_sync_status = 'synced'
              and contacts.bitrix24_history_sync_status = 'synced'
        ) / nullif(count(*), 0),
        2
    ) as pct
from ab_prod_kpi_contact_eligibility eligibility
join ab_prod_kpi_root_contacts contacts on contacts.id = eligibility.contact_id
cross join ab_prod_kpi_params params
where eligibility.eligible_at between params.period_start and params.period_end;

-- 2. Агрегированная воронка.
select
    count(*) as new_dialogs,
    count(*) filter (where dialogs.phone_confirmed_at is not null) as dialogs_with_phone,
    count(*) filter (
        where contacts.data_collection_status = 'completed'
           or contacts.data_collection_completed_at is not null
    ) as dialogs_with_completed_data,
    round(
        100.0 * count(*) filter (where dialogs.phone_confirmed_at is not null) / nullif(count(*), 0),
        2
    ) as phone_capture_rate_pct,
    round(
        100.0 * count(*) filter (
            where contacts.data_collection_status = 'completed'
               or contacts.data_collection_completed_at is not null
        ) / nullif(count(*), 0),
        2
    ) as data_completion_rate_pct
from ab_prod_kpi_eligible_dialogs dialogs
join ab_prod_kpi_root_contacts contacts on contacts.id = dialogs.contact_id
cross join ab_prod_kpi_params params
where dialogs.created_at between params.period_start and params.period_end;

-- 3. Воронка только по платформе и типу подключения.
select
    channels.platform,
    channels.connection_type,
    count(distinct dialogs.id) as new_dialogs,
    count(distinct dialogs.id) filter (where dialogs.phone_confirmed_at is not null) as dialogs_with_phone,
    count(distinct contacts.id) filter (
        where contacts.data_collection_status = 'completed'
           or contacts.data_collection_completed_at is not null
    ) as contacts_with_completed_data,
    count(messages.id) filter (where messages.system_event_code = 'bot_blocked_by_user') as bot_blocks,
    round(
        100.0 * count(distinct dialogs.id) filter (where dialogs.phone_confirmed_at is not null)
            / nullif(count(distinct dialogs.id), 0),
        2
    ) as phone_capture_rate_pct
from ab_prod_kpi_eligible_dialogs dialogs
join channels on channels.id = dialogs.channel_id
join ab_prod_kpi_root_contacts contacts on contacts.id = dialogs.contact_id
cross join ab_prod_kpi_params params
left join messages
    on messages.dialog_id = dialogs.id
   and messages.system_event_code = 'bot_blocked_by_user'
   and messages.received_at between params.period_start and params.period_end
where dialogs.created_at between params.period_start and params.period_end
group by channels.platform, channels.connection_type
order by new_dialogs desc, channels.platform, channels.connection_type;

-- 4. Агрегированная очередь оператора.
select
    count(*) filter (where reply_state.requires_reply) as requires_reply,
    count(*) filter (
        where reply_state.requires_reply
          and reply_state.latest_inbound_at < params.sla_cutoff
    ) as overdue_requires_reply,
    count(*) filter (where contacts.assigned_user_id is null) as unassigned_dialogs,
    count(*) filter (where dialogs.bot_subscription_status = 'blocked_by_user') as blocked_now,
    round((
        percentile_cont(0.5) within group (
            order by extract(epoch from (params.snapshot_at - reply_state.latest_inbound_at)) / 60.0
        ) filter (where reply_state.requires_reply)
    )::numeric, 2) as median_requires_reply_age_minutes
from ab_prod_kpi_reply_state reply_state
join ab_prod_kpi_eligible_dialogs dialogs on dialogs.id = reply_state.dialog_id
join ab_prod_kpi_root_contacts contacts on contacts.id = reply_state.contact_id
cross join ab_prod_kpi_params params;

-- 5. Агрегированная очередь оператора по возрастным группам.
select
    case
        when reply_state.latest_inbound_at >= params.snapshot_at - interval '15 minutes' then '00_0_15m'
        when reply_state.latest_inbound_at >= params.snapshot_at - interval '1 hour' then '01_15_60m'
        when reply_state.latest_inbound_at >= params.snapshot_at - interval '4 hours' then '02_1_4h'
        when reply_state.latest_inbound_at >= params.snapshot_at - interval '24 hours' then '03_4_24h'
        else '04_over_24h'
    end as reply_age_bucket,
    count(*) as dialogs_count,
    count(*) filter (where contacts.assigned_user_id is null) as unassigned_count,
    count(*) filter (where dialogs.bot_subscription_status = 'blocked_by_user') as bot_blocked_count
from ab_prod_kpi_reply_state reply_state
join ab_prod_kpi_eligible_dialogs dialogs on dialogs.id = reply_state.dialog_id
join ab_prod_kpi_root_contacts contacts on contacts.id = reply_state.contact_id
cross join ab_prod_kpi_params params
where reply_state.requires_reply
group by reply_age_bucket
order by reply_age_bucket;

-- 6. Распределение статусов Bitrix24 для подходящих контактов.
select
    contacts.bitrix24_sync_status,
    contacts.bitrix24_deal_sync_status,
    contacts.bitrix24_history_sync_status,
    count(*) as contacts_count
from ab_prod_kpi_contact_eligibility eligibility
join ab_prod_kpi_root_contacts contacts on contacts.id = eligibility.contact_id
cross join ab_prod_kpi_params params
where eligibility.eligible_at between params.period_start and params.period_end
group by
    contacts.bitrix24_sync_status,
    contacts.bitrix24_deal_sync_status,
    contacts.bitrix24_history_sync_status
order by contacts_count desc;

-- 7. Ошибки Bitrix24 по операции и коду ошибки.
select
    logs.operation,
    logs.entity_type,
    coalesce(logs.error_code, 'no_error_code') as error_code,
    logs.http_status,
    count(*) as failure_count,
    max(logs.created_at) as last_seen_at
from bitrix24_sync_logs logs
cross join ab_prod_kpi_params params
where logs.status = 'failed'
  and logs.created_at between params.period_start and params.period_end
group by logs.operation, logs.entity_type, coalesce(logs.error_code, 'no_error_code'), logs.http_status
order by failure_count desc, last_seen_at desc;

-- 8. Статусы привязки и live-режима Открытых линий.
select
    channels.platform,
    routes.status as route_status,
    count(dialogs.id) as dialogs_with_route,
    count(dialogs.id) filter (where dialogs.bitrix24_live_status = 'active') as live_active,
    count(dialogs.id) filter (where dialogs.bitrix24_open_line_binding_verified_at is not null) as binding_verified,
    round(
        100.0 * count(dialogs.id) filter (where dialogs.bitrix24_live_status = 'active') / nullif(count(dialogs.id), 0),
        2
    ) as live_active_rate_pct
from ab_prod_kpi_eligible_dialogs dialogs
join channels on channels.id = dialogs.channel_id
left join bitrix24_open_line_routes routes on routes.id = dialogs.bitrix24_open_line_route_id
cross join ab_prod_kpi_params params
where dialogs.bitrix24_open_line_route_id is not null
  and dialogs.created_at between params.period_start and params.period_end
group by channels.platform, routes.status
order by dialogs_with_route desc, channels.platform, routes.status;

-- 9. Агрегированное состояние runtime каналов.
with channel_health as (
    select
        channels.platform,
        channels.connection_type,
        case
            when channels.is_active is not true then 'inactive'
            when channels.connection_status is distinct from 'connected' then 'connection_not_connected'
            when coalesce(channels.webhook_status, '') not in ('installed', 'unsupported') then 'webhook_not_installed'
            when runtime.auth_status is not null and runtime.auth_status <> 'authorized' then 'runtime_auth_not_authorized'
            when runtime.sync_status is not null and runtime.sync_status <> 'live' then 'runtime_not_live'
            else 'ok'
        end as health_flag,
        count(*) as channels_count,
        count(*) filter (
            where runtime.last_gateway_heartbeat_at is not null
              and runtime.last_gateway_heartbeat_at >= params.snapshot_at - interval '2 minutes'
        ) as fresh_gateway_heartbeat_count,
        max(channels.connection_checked_at) as latest_connection_checked_at,
        max(runtime.last_error_at) as latest_runtime_error_at
    from channels
    left join channel_runtime_states runtime on runtime.channel_id = channels.id
    cross join ab_prod_kpi_params params
    group by
        channels.platform,
        channels.connection_type,
        params.snapshot_at,
        case
            when channels.is_active is not true then 'inactive'
            when channels.connection_status is distinct from 'connected' then 'connection_not_connected'
            when coalesce(channels.webhook_status, '') not in ('installed', 'unsupported') then 'webhook_not_installed'
            when runtime.auth_status is not null and runtime.auth_status <> 'authorized' then 'runtime_auth_not_authorized'
            when runtime.sync_status is not null and runtime.sync_status <> 'live' then 'runtime_not_live'
            else 'ok'
        end
)
select
    platform,
    connection_type,
    health_flag,
    channels_count,
    fresh_gateway_heartbeat_count,
    latest_connection_checked_at,
    latest_runtime_error_at
from channel_health
order by
    case health_flag
        when 'ok' then 5
        when 'inactive' then 4
        else 1
    end,
    platform,
    connection_type,
    health_flag;

-- 10. Контрольные показатели ИИ и сборщика по задаче и модели.
select
    ai_requests.task_key,
    coalesce(ai_requests.provider, 'unknown') as provider,
    coalesce(ai_requests.model, 'unknown') as model,
    count(*) as requests,
    count(*) filter (where ai_requests.status = 'success') as success_count,
    count(*) filter (where ai_requests.status = 'error') as error_count,
    round(
        100.0 * count(*) filter (where ai_requests.status = 'success') / nullif(count(*), 0),
        2
    ) as success_rate_pct,
    round(avg(ai_requests.latency_ms) filter (where ai_requests.latency_ms is not null), 2) as avg_latency_ms,
    round((
        percentile_cont(0.95) within group (order by ai_requests.latency_ms)
            filter (where ai_requests.latency_ms is not null)
    )::numeric, 2) as p95_latency_ms,
    sum(ai_requests.total_tokens) as total_tokens,
    sum(ai_requests.estimated_cost) as estimated_cost,
    count(*) filter (where ai_requests.cost_status is distinct from 'calculated') as cost_not_calculated_count
from ai_requests
cross join ab_prod_kpi_params params
where coalesce(ai_requests.started_at, ai_requests.created_at) between params.period_start and params.period_end
group by ai_requests.task_key, coalesce(ai_requests.provider, 'unknown'), coalesce(ai_requests.model, 'unknown')
order by requests desc, task_key, provider, model;
