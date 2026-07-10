-- Безопасный набор KPI-запросов AB Connector для production.
--
-- Назначение:
--   Получить только агрегированные KPI по production-данным без вывода
--   идентификаторов, текстов сообщений, телефонов, имён, raw payload,
--   сообщений об ошибках и строк отдельных диалогов или контактов.
--
-- Запуск через psql:
--   PGSERVICE=ab_connector_production_readonly \
--   PGPASSFILE="$HOME/.pgpass-ab-connector" \
--   psql \
--     --no-psqlrc \
--     --set ON_ERROR_STOP=1 \
--     -v period_start="2026-07-01 00:00:00" \
--     -v period_end="2026-07-08 00:00:00" \
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
--   :period_end    Исключительная верхняя граница периода.
--   snapshot_at    Вычисляется автоматически в начале транзакции;
--                  SLA-граница равна ему минус один час.
--
-- Примечания:
--   - Файл создаёт временные views, таблицу и индекс в текущей DB-сессии.
--   - В подсчётах используются только корневые контакты.
--   - Диалоговые метрики исключают стадии с behavior_policy = blacklist.
--   - Событийная метрика bot_blocks не зависит от текущей стадии диалога.

\set ON_ERROR_STOP on

set timezone = 'Europe/Moscow';
set statement_timeout = '30s';

begin transaction isolation level repeatable read;

drop view if exists pg_temp.ab_prod_kpi_contact_eligibility cascade;
drop table if exists pg_temp.ab_prod_kpi_reply_state cascade;
drop view if exists pg_temp.ab_prod_kpi_eligible_dialogs cascade;
drop view if exists pg_temp.ab_prod_kpi_root_contacts cascade;
drop view if exists pg_temp.ab_prod_kpi_params cascade;

create temporary view ab_prod_kpi_params as
select
    :'period_start'::timestamp as period_start,
    :'period_end'::timestamp as period_end,
    transaction_timestamp()::timestamp as snapshot_at,
    transaction_timestamp()::timestamp - interval '1 hour' as sla_cutoff;

select
    case when params.period_start < params.period_end then 'true' else 'false' end as period_order_valid,
    case when params.period_end <= params.snapshot_at then 'true' else 'false' end as period_end_valid
from ab_prod_kpi_params params
\gset ab_prod_kpi_

\if :ab_prod_kpi_period_order_valid
\else
do $validation$
begin
    raise exception 'period_start должен быть раньше period_end.';
end
$validation$;
\endif

\if :ab_prod_kpi_period_end_valid
\else
do $validation$
begin
    raise exception 'period_end не может быть позже snapshot_at.';
end
$validation$;
\endif

create temporary view ab_prod_kpi_root_contacts as
select
    contacts.id,
    contacts.assigned_user_id,
    contacts.data_collection_status,
    contacts.data_collection_completed_at,
    contacts.bitrix24_sync_status,
    contacts.bitrix24_deal_sync_status,
    contacts.bitrix24_history_sync_status
from contacts
where contacts.merged_into_contact_id is null;

create temporary view ab_prod_kpi_eligible_dialogs as
select
    dialogs.id,
    dialogs.contact_id,
    dialogs.channel_id,
    dialogs.created_at,
    dialogs.phone_confirmed_at,
    dialogs.manual_reply_dismissed_source_message_id,
    dialogs.bot_subscription_status,
    dialogs.bitrix24_live_status,
    dialogs.bitrix24_open_line_binding_verified_at,
    dialogs.bitrix24_open_line_route_id
from dialogs
join ab_prod_kpi_root_contacts contacts on contacts.id = dialogs.contact_id
left join dialog_stages stage_by_id on stage_by_id.id = dialogs.stage_id
left join dialog_stages stage_by_key on stage_by_key.key = dialogs.stage
where coalesce(stage_by_id.behavior_policy, 'standard') <> 'blacklist'
  and coalesce(stage_by_key.behavior_policy, 'standard') <> 'blacklist';

create temporary table ab_prod_kpi_reply_state as
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

create unique index ab_prod_kpi_reply_state_dialog_id_idx
    on ab_prod_kpi_reply_state (dialog_id);

analyze ab_prod_kpi_reply_state;

-- data_collection_completed_at — текущая изменяемая отметка, а не журнал
-- первого завершения сбора данных; точная семантика зафиксирована в runbook.
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
\echo '=== 0. Контекст запуска и область данных ==='
select
    'run_context' as section,
    params.period_start,
    params.period_end,
    params.snapshot_at,
    params.sla_cutoff,
    current_setting('transaction_isolation') as transaction_isolation,
    (select count(*) from contacts) as all_contacts,
    (select count(*) from ab_prod_kpi_root_contacts) as root_contacts,
    (select count(*) from dialogs) as all_dialogs,
    (select count(*) from ab_prod_kpi_eligible_dialogs) as eligible_dialogs,
    (select count(*) from dialogs) - (select count(*) from ab_prod_kpi_eligible_dialogs) as excluded_or_non_root_dialogs
from ab_prod_kpi_params params;

-- 1. Сводка основных KPI.
\echo '=== 1. Сводка основных KPI ==='
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
where dialogs.created_at >= params.period_start
  and dialogs.created_at < params.period_end

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
where eligibility.eligible_at >= params.period_start
  and eligibility.eligible_at < params.period_end;

-- 2. Агрегированная воронка.
\echo '=== 2. Агрегированная воронка ==='
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
where dialogs.created_at >= params.period_start
  and dialogs.created_at < params.period_end;

-- 3. Воронка и события блокировки по платформе и типу подключения.
\echo '=== 3. Воронка по платформе и типу подключения ==='
with dialog_funnel as (
    select
        coalesce(channels.platform, 'unknown') as platform,
        coalesce(channels.connection_type, 'unknown') as connection_type,
        count(*) as new_dialogs,
        count(*) filter (where dialogs.phone_confirmed_at is not null) as dialogs_with_phone,
        count(distinct contacts.id) filter (
            where contacts.data_collection_status = 'completed'
               or contacts.data_collection_completed_at is not null
        ) as contacts_with_completed_data
    from ab_prod_kpi_eligible_dialogs dialogs
    join channels on channels.id = dialogs.channel_id
    join ab_prod_kpi_root_contacts contacts on contacts.id = dialogs.contact_id
    cross join ab_prod_kpi_params params
    where dialogs.created_at >= params.period_start
      and dialogs.created_at < params.period_end
    group by
        coalesce(channels.platform, 'unknown'),
        coalesce(channels.connection_type, 'unknown')
),
bot_block_events as (
    select
        coalesce(channels.platform, 'unknown') as platform,
        coalesce(channels.connection_type, 'unknown') as connection_type,
        count(*) as bot_blocks
    from messages
    join channels on channels.id = messages.channel_id
    join ab_prod_kpi_root_contacts contacts on contacts.id = messages.contact_id
    cross join ab_prod_kpi_params params
    where messages.system_event_code = 'bot_blocked_by_user'
      and messages.received_at >= params.period_start
      and messages.received_at < params.period_end
    group by
        coalesce(channels.platform, 'unknown'),
        coalesce(channels.connection_type, 'unknown')
)
select
    coalesce(dialog_funnel.platform, bot_block_events.platform) as platform,
    coalesce(dialog_funnel.connection_type, bot_block_events.connection_type) as connection_type,
    coalesce(dialog_funnel.new_dialogs, 0) as new_dialogs,
    coalesce(dialog_funnel.dialogs_with_phone, 0) as dialogs_with_phone,
    coalesce(dialog_funnel.contacts_with_completed_data, 0) as contacts_with_completed_data,
    coalesce(bot_block_events.bot_blocks, 0) as bot_blocks,
    round(
        100.0 * coalesce(dialog_funnel.dialogs_with_phone, 0)
            / nullif(dialog_funnel.new_dialogs, 0),
        2
    ) as phone_capture_rate_pct
from dialog_funnel
full outer join bot_block_events
    on bot_block_events.platform = dialog_funnel.platform
   and bot_block_events.connection_type = dialog_funnel.connection_type
order by new_dialogs desc, platform, connection_type;

-- 4. Агрегированная очередь оператора.
\echo '=== 4. Агрегированная очередь оператора ==='
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
\echo '=== 5. Очередь оператора по возрастным группам ==='
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
\echo '=== 6. Распределение статусов Bitrix24 ==='
select
    contacts.bitrix24_sync_status,
    contacts.bitrix24_deal_sync_status,
    contacts.bitrix24_history_sync_status,
    count(*) as contacts_count
from ab_prod_kpi_contact_eligibility eligibility
join ab_prod_kpi_root_contacts contacts on contacts.id = eligibility.contact_id
cross join ab_prod_kpi_params params
where eligibility.eligible_at >= params.period_start
  and eligibility.eligible_at < params.period_end
group by
    contacts.bitrix24_sync_status,
    contacts.bitrix24_deal_sync_status,
    contacts.bitrix24_history_sync_status
order by contacts_count desc;

-- 7. Ошибки Bitrix24 по операции и коду ошибки.
\echo '=== 7. Ошибки Bitrix24 по операции и коду ==='
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
  and logs.created_at >= params.period_start
  and logs.created_at < params.period_end
group by logs.operation, logs.entity_type, coalesce(logs.error_code, 'no_error_code'), logs.http_status
order by failure_count desc, last_seen_at desc;

-- 8. Статусы привязки и live-режима Открытых линий.
\echo '=== 8. Привязка и live-режим Открытых линий ==='
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
  and dialogs.created_at >= params.period_start
  and dialogs.created_at < params.period_end
group by channels.platform, routes.status
order by dialogs_with_route desc, channels.platform, routes.status;

-- 9. Агрегированное состояние runtime каналов.
\echo '=== 9. Состояние runtime каналов ==='
with channel_health_source as (
    select
        channels.platform,
        channels.connection_type,
        -- Для account-каналов ok означает готовность gateway к исходящим ответам.
        case
            when channels.is_active is not true then 'inactive'
            when channels.connection_type = 'account' and channels.platform is distinct from 'telegram'
                then 'runtime_account_platform_unsupported'
            when channels.connection_type = 'account' and runtime.channel_id is null
                then 'runtime_state_missing'
            when channels.connection_type = 'account' and runtime.auth_status <> 'authorized'
                then 'runtime_auth_not_authorized'
            when channels.connection_type = 'account' and runtime.authorization_state <> 'ready'
                then 'runtime_authorization_not_ready'
            when channels.connection_type = 'account' and runtime.sync_status <> 'live'
                then 'runtime_not_live'
            when channels.connection_type = 'account' and (
                runtime.last_gateway_heartbeat_at is null
                or runtime.last_gateway_heartbeat_at < params.snapshot_at - interval '2 minutes'
            ) then 'runtime_heartbeat_stale'
            when channels.connection_type = 'account'
                and runtime.runtime_payload #> '{gateway_capabilities,outgoing_replies}' is distinct from 'true'::jsonb
                then 'runtime_outgoing_replies_unconfirmed'
            when channels.connection_type = 'account' then 'ok'
            when channels.connection_status is distinct from 'connected' then 'connection_not_connected'
            when coalesce(channels.webhook_status, '') not in ('installed', 'unsupported') then 'webhook_not_installed'
            else 'ok'
        end as health_flag,
        runtime.last_gateway_heartbeat_at,
        channels.connection_checked_at,
        runtime.last_error_at,
        params.snapshot_at
    from channels
    left join channel_runtime_states runtime on runtime.channel_id = channels.id
    cross join ab_prod_kpi_params params
),
channel_health as (
    select
        platform,
        connection_type,
        health_flag,
        count(*) as channels_count,
        count(*) filter (
            where last_gateway_heartbeat_at is not null
              and last_gateway_heartbeat_at >= snapshot_at - interval '2 minutes'
        ) as fresh_gateway_heartbeat_count,
        max(connection_checked_at) as latest_connection_checked_at,
        max(last_error_at) as latest_runtime_error_at
    from channel_health_source
    group by platform, connection_type, health_flag
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
\echo '=== 10. Контрольные показатели ИИ и сборщика ==='
with ai_requests_in_period as (
    select
        ai_requests.task_key,
        ai_requests.provider,
        ai_requests.model,
        ai_requests.status,
        ai_requests.latency_ms,
        ai_requests.total_tokens,
        ai_requests.estimated_cost,
        ai_requests.cost_status
    from ai_requests
    cross join ab_prod_kpi_params params
    where ai_requests.started_at >= params.period_start
      and ai_requests.started_at < params.period_end

    union all

    select
        ai_requests.task_key,
        ai_requests.provider,
        ai_requests.model,
        ai_requests.status,
        ai_requests.latency_ms,
        ai_requests.total_tokens,
        ai_requests.estimated_cost,
        ai_requests.cost_status
    from ai_requests
    cross join ab_prod_kpi_params params
    where ai_requests.started_at is null
      and ai_requests.created_at >= params.period_start
      and ai_requests.created_at < params.period_end
)
select
    request_rows.task_key,
    coalesce(request_rows.provider, 'unknown') as provider,
    coalesce(request_rows.model, 'unknown') as model,
    count(*) as requests,
    count(*) filter (where request_rows.status = 'success') as success_count,
    count(*) filter (where request_rows.status = 'error') as error_count,
    round(
        100.0 * count(*) filter (where request_rows.status = 'success') / nullif(count(*), 0),
        2
    ) as success_rate_pct,
    round(avg(request_rows.latency_ms) filter (where request_rows.latency_ms is not null), 2) as avg_latency_ms,
    round((
        percentile_cont(0.95) within group (order by request_rows.latency_ms)
            filter (where request_rows.latency_ms is not null)
    )::numeric, 2) as p95_latency_ms,
    sum(request_rows.total_tokens) as total_tokens,
    sum(request_rows.estimated_cost) as estimated_cost,
    count(*) filter (where request_rows.cost_status is distinct from 'calculated') as cost_not_calculated_count
from ai_requests_in_period request_rows
group by request_rows.task_key, coalesce(request_rows.provider, 'unknown'), coalesce(request_rows.model, 'unknown')
order by requests desc, task_key, provider, model;

rollback;
