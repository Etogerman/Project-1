<?php

namespace App\Services\Analytics;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Dialogs\MessageChronology;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildAnalyticsOverviewAction
{
    public function __construct(
        private readonly MessageChronology $messageChronology,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Carbon $periodStart, Carbon $periodEnd, ?Carbon $now = null): array
    {
        $now ??= now();
        $slaCutoff = $now->copy()->subHour();

        return [
            'periodMetrics' => $this->buildPeriodMetrics($periodStart, $periodEnd),
            'snapshotMetrics' => $this->buildSnapshotMetrics($slaCutoff),
            'stageRows' => $this->buildStageRows(),
            'channelRows' => $this->buildChannelRows($periodStart, $periodEnd),
            'tagRows' => $this->buildTagRows($periodStart, $periodEnd),
            'problemDialogs' => $this->buildProblemDialogs($slaCutoff),
        ];
    }

    /**
     * @return list<array{key:string,label:string,value:int,caption:string,tone:string}>
     */
    private function buildPeriodMetrics(Carbon $periodStart, Carbon $periodEnd): array
    {
        return [
            $this->metric(
                'new_clients',
                'Новые клиенты',
                $this->rootContactsQuery()
                    ->whereBetween('contacts.created_at', [$periodStart, $periodEnd])
                    ->count(),
                'contacts.created_at',
                'blue',
            ),
            $this->metric(
                'new_dialogs',
                'Новые диалоги',
                $this->rootDialogsQuery()
                    ->whereBetween('dialogs.created_at', [$periodStart, $periodEnd])
                    ->count(),
                'dialogs.created_at',
                'cyan',
            ),
            $this->metric(
                'bot_blocks',
                'Блокировки бота',
                $this->botBlockMessagesQuery($periodStart, $periodEnd)->count(),
                'messages.received_at',
                'red',
            ),
            $this->metric(
                'phones_received',
                'Телефон получен',
                $this->rootDialogsQuery()
                    ->whereBetween('dialogs.phone_confirmed_at', [$periodStart, $periodEnd])
                    ->count(),
                'dialogs.phone_confirmed_at',
                'emerald',
            ),
            $this->metric(
                'data_collected',
                'Данные собраны',
                $this->rootContactsQuery()
                    ->whereBetween('contacts.data_collection_completed_at', [$periodStart, $periodEnd])
                    ->count(),
                'contacts.data_collection_completed_at',
                'green',
            ),
        ];
    }

    /**
     * @return list<array{key:string,label:string,value:int,caption:string,tone:string}>
     */
    private function buildSnapshotMetrics(Carbon $slaCutoff): array
    {
        $requiresReplyQuery = $this->rootDialogsQuery();
        $this->applyRequiresReply($requiresReplyQuery);

        $overdueQuery = $this->rootDialogsQuery();
        $this->applyRequiresReply($overdueQuery);
        $this->applyLatestInboundUserBefore($overdueQuery, $slaCutoff);

        return [
            $this->metric(
                'requires_reply',
                'Требуют ответа',
                $requiresReplyQuery->count(),
                'сейчас',
                'amber',
            ),
            $this->metric(
                'requires_reply_overdue',
                'Больше 1 часа',
                $overdueQuery->count(),
                'последний inbound_user',
                'red',
            ),
            $this->metric(
                'unassigned',
                'Без ответственного',
                $this->rootDialogsQuery()
                    ->whereHas('contact', fn (Builder $query): Builder => $query->whereNull('assigned_user_id'))
                    ->count(),
                'сейчас',
                'slate',
            ),
            $this->metric(
                'blocked_now',
                'Сейчас заблокированы',
                $this->rootDialogsQuery()
                    ->where('dialogs.bot_subscription_status', Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER)
                    ->count(),
                'сейчас',
                'red',
            ),
        ];
    }

    /**
     * @return list<array{stage:string,label:string,count:int,tone:string,share:int}>
     */
    private function buildStageRows(): array
    {
        $total = max(1, $this->rootDialogsQuery()->count());

        return collect(Dialog::workingStages())
            ->map(function (string $stage) use ($total): array {
                $query = $this->rootDialogsQuery();
                $this->applyEffectiveStageFilter($query, $stage);
                $count = $query->count();

                return [
                    'stage' => $stage,
                    'label' => Dialog::stageLabel($stage),
                    'count' => $count,
                    'tone' => Dialog::stageTone($stage),
                    'share' => (int) round(($count / $total) * 100),
                ];
            })
            ->all();
    }

    /**
     * @return list<array{channel_id:int,label:string,new_dialogs:int,phones_received:int,bot_blocks:int}>
     */
    private function buildChannelRows(Carbon $periodStart, Carbon $periodEnd): array
    {
        $newDialogs = $this->groupDialogCountsByChannel('dialogs.created_at', $periodStart, $periodEnd);
        $phonesReceived = $this->groupDialogCountsByChannel('dialogs.phone_confirmed_at', $periodStart, $periodEnd);
        $botBlocks = $this->botBlockMessagesQuery($periodStart, $periodEnd)
            ->select('messages.channel_id', DB::raw('count(*) as aggregate_count'))
            ->groupBy('messages.channel_id')
            ->pluck('aggregate_count', 'channel_id')
            ->mapWithKeys(fn (mixed $count, mixed $channelId): array => [(int) $channelId => (int) $count]);

        $channelIds = collect([
            ...$newDialogs->keys()->all(),
            ...$phonesReceived->keys()->all(),
            ...$botBlocks->keys()->all(),
        ])->unique()->values();

        if ($channelIds->isEmpty()) {
            return [];
        }

        return Channel::query()
            ->whereIn('id', $channelIds->all())
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel): array => [
                'channel_id' => (int) $channel->id,
                'label' => $this->formatChannelLabel($channel),
                'new_dialogs' => $newDialogs->get((int) $channel->id, 0),
                'phones_received' => $phonesReceived->get((int) $channel->id, 0),
                'bot_blocks' => $botBlocks->get((int) $channel->id, 0),
            ])
            ->all();
    }

    /**
     * @return list<array{tag_id:int,label:string,count:int}>
     */
    private function buildTagRows(Carbon $periodStart, Carbon $periodEnd): array
    {
        return DB::table('contact_tag')
            ->join('tags', 'tags.id', '=', 'contact_tag.tag_id')
            ->join('contacts', 'contacts.id', '=', 'contact_tag.contact_id')
            ->whereNull('contacts.merged_into_contact_id')
            ->whereBetween('contact_tag.assigned_at', [$periodStart, $periodEnd])
            ->groupBy('tags.id', 'tags.name')
            ->orderByDesc(DB::raw('count(*)'))
            ->orderBy('tags.name')
            ->limit(8)
            ->get([
                'tags.id as tag_id',
                'tags.name as label',
                DB::raw('count(*) as aggregate_count'),
            ])
            ->map(fn (object $row): array => [
                'tag_id' => (int) $row->tag_id,
                'label' => (string) $row->label,
                'count' => (int) $row->aggregate_count,
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,contact:string,channel:string,last_activity:?string,reasons:list<string>,url:string}>
     */
    private function buildProblemDialogs(Carbon $slaCutoff): array
    {
        $query = $this->rootDialogsQuery()
            ->addSelect($this->inboxProjection())
            ->with(['channel', 'contact.assignedUser', 'contact.primaryIdentity'])
            ->where(function (Builder $query) use ($slaCutoff): void {
                $query
                    ->whereHas('contact', fn (Builder $query): Builder => $query->whereNull('assigned_user_id'))
                    ->orWhere('dialogs.bot_subscription_status', Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER)
                    ->orWhere(function (Builder $query) use ($slaCutoff): void {
                        $this->applyRequiresReply($query);
                        $this->applyLatestInboundUserBefore($query, $slaCutoff);
                    });
            })
            ->orderByDesc('dialogs.last_message_at')
            ->orderByDesc('dialogs.id')
            ->limit(10);

        /** @var Collection<int, Dialog> $dialogs */
        $dialogs = $query->get();

        return $dialogs
            ->map(fn (Dialog $dialog): array => [
                'id' => (int) $dialog->id,
                'contact' => app(ResolveContactDisplayNameAction::class)->handle($dialog->contact),
                'channel' => $dialog->channel instanceof Channel
                    ? $this->formatChannelLabel($dialog->channel)
                    : 'Канал не задан',
                'last_activity' => $dialog->last_message_at?->format('d.m.Y H:i'),
                'reasons' => $this->problemReasons($dialog, $slaCutoff),
                'url' => DialogResource::getUrl('view', ['record' => $dialog]),
            ])
            ->all();
    }

    /**
     * @return array{key:string,label:string,value:int,caption:string,tone:string}
     */
    private function metric(string $key, string $label, int $value, string $caption, string $tone): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'caption' => $caption,
            'tone' => $tone,
        ];
    }

    private function rootContactsQuery(): Builder
    {
        return Contact::query()->whereNull('contacts.merged_into_contact_id');
    }

    private function rootDialogsQuery(): Builder
    {
        return Dialog::query()
            ->whereHas('contact', fn (Builder $query): Builder => $query->whereNull('merged_into_contact_id'));
    }

    private function botBlockMessagesQuery(Carbon $periodStart, Carbon $periodEnd): Builder
    {
        return Message::query()
            ->where('messages.system_event_code', Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER)
            ->whereBetween('messages.received_at', [$periodStart, $periodEnd])
            ->whereHas('contact', fn (Builder $query): Builder => $query->whereNull('merged_into_contact_id'));
    }

    /**
     * @return Collection<int, int>
     */
    private function groupDialogCountsByChannel(string $timestampColumn, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        return $this->rootDialogsQuery()
            ->whereBetween($timestampColumn, [$periodStart, $periodEnd])
            ->select('dialogs.channel_id', DB::raw('count(*) as aggregate_count'))
            ->groupBy('dialogs.channel_id')
            ->pluck('aggregate_count', 'channel_id')
            ->mapWithKeys(fn (mixed $count, mixed $channelId): array => [(int) $channelId => (int) $count]);
    }

    private function applyEffectiveStageFilter(Builder $query, string $stage): Builder
    {
        if (Dialog::isManualStage($stage)) {
            return $query->where('dialogs.stage', $stage);
        }

        $this->applyNotManualStage($query);

        return match ($stage) {
            Dialog::STAGE_QUESTIONNAIRE_COMPLETED => $query
                ->whereHas('contact', fn (Builder $query): Builder => $this->applyCompletedContactScope($query)),
            Dialog::STAGE_PHONE_RECEIVED => $query
                ->whereNotNull('dialogs.phone_confirmed_at')
                ->whereHas('contact', fn (Builder $query): Builder => $this->applyNotCompletedContactScope($query)),
            default => $query
                ->whereNull('dialogs.phone_confirmed_at')
                ->whereHas('contact', fn (Builder $query): Builder => $this->applyNotCompletedContactScope($query)),
        };
    }

    private function applyNotManualStage(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('dialogs.stage')
                ->orWhereNotIn('dialogs.stage', Dialog::manualStages());
        });
    }

    private function applyCompletedContactScope(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('data_collection_status', Contact::DATA_COLLECTION_STATUS_COMPLETED)
                ->orWhereNotNull('data_collection_completed_at');
        });
    }

    private function applyNotCompletedContactScope(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->where('data_collection_status', '!=', Contact::DATA_COLLECTION_STATUS_COMPLETED)
                    ->orWhereNull('data_collection_status');
            })
            ->whereNull('data_collection_completed_at');
    }

    private function applyRequiresReply(Builder $query): Builder
    {
        [
            'latestInboundUserMessageId' => $latestInboundUserMessageId,
            'latestOutboundManualReplyMessageId' => $latestOutboundManualReplyMessageId,
            'latestInboundAfterOutboundManualReply' => $latestInboundAfterOutboundManualReply,
        ] = $this->buildInboxStatusFilterFragments();

        return $query
            ->whereRaw(
                $latestInboundUserMessageId['sql'].' is not null',
                $latestInboundUserMessageId['bindings'],
            )
            ->where(function (Builder $query) use (
                $latestOutboundManualReplyMessageId,
                $latestInboundAfterOutboundManualReply,
            ): Builder {
                return $query
                    ->whereRaw(
                        $latestOutboundManualReplyMessageId['sql'].' is null',
                        $latestOutboundManualReplyMessageId['bindings'],
                    )
                    ->orWhereRaw(
                        $latestInboundAfterOutboundManualReply['sql'],
                        $latestInboundAfterOutboundManualReply['bindings'],
                    );
            })
            ->where(function (Builder $query) use ($latestInboundUserMessageId): Builder {
                return $query
                    ->whereNull('dialogs.manual_reply_dismissed_source_message_id')
                    ->orWhereRaw(
                        $latestInboundUserMessageId['sql'].' <> dialogs.manual_reply_dismissed_source_message_id',
                        $latestInboundUserMessageId['bindings'],
                    );
            });
    }

    private function applyLatestInboundUserBefore(Builder $query, Carbon $cutoff): Builder
    {
        $latestInboundUserMessageSortAt = $this->messageChronology->latestDialogMessageSortAtFragment(
            Message::KIND_INBOUND_USER,
        );

        return $query->whereRaw(
            $latestInboundUserMessageSortAt['sql'].' < ?',
            [
                ...$latestInboundUserMessageSortAt['bindings'],
                $cutoff,
            ],
        );
    }

    /**
     * @return array<string, array{sql: string, bindings: list<mixed>}>
     */
    private function buildInboxStatusFilterFragments(): array
    {
        $latestInboundUserMessageId = $this->messageChronology->latestDialogMessageIdFragment(
            Message::KIND_INBOUND_USER,
        );
        $latestInboundUserMessageSortAt = $this->messageChronology->latestDialogMessageSortAtFragment(
            Message::KIND_INBOUND_USER,
        );
        $latestOutboundManualReplyMessageId = $this->messageChronology->latestDialogMessageIdFragment(
            Message::KIND_OUTBOUND_MANUAL_REPLY,
        );
        $latestOutboundManualReplyMessageSortAt = $this->messageChronology->latestDialogMessageSortAtFragment(
            Message::KIND_OUTBOUND_MANUAL_REPLY,
        );

        return [
            'latestInboundUserMessageId' => $latestInboundUserMessageId,
            'latestInboundUserMessageSortAt' => $latestInboundUserMessageSortAt,
            'latestOutboundManualReplyMessageId' => $latestOutboundManualReplyMessageId,
            'latestOutboundManualReplyMessageSortAt' => $latestOutboundManualReplyMessageSortAt,
            'latestInboundAfterOutboundManualReply' => $this->messageChronology->buildIsAfterCondition(
                $latestInboundUserMessageSortAt,
                $latestInboundUserMessageId,
                $latestOutboundManualReplyMessageSortAt,
                $latestOutboundManualReplyMessageId,
            ),
        ];
    }

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Builder>
     */
    private function inboxProjection(): array
    {
        return [
            'latest_inbound_user_message_id' => $this->messageChronology->latestMessageIdSubquery(
                'dialog_id',
                'dialogs.id',
                fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_INBOUND_USER),
            ),
            'latest_inbound_user_message_sort_at' => $this->messageChronology->latestMessageSortAtSubquery(
                'dialog_id',
                'dialogs.id',
                fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_INBOUND_USER),
            ),
            'latest_outbound_manual_reply_message_id' => $this->messageChronology->latestMessageIdSubquery(
                'dialog_id',
                'dialogs.id',
                fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_OUTBOUND_MANUAL_REPLY),
            ),
            'latest_outbound_manual_reply_message_sort_at' => $this->messageChronology->latestMessageSortAtSubquery(
                'dialog_id',
                'dialogs.id',
                fn (Builder $query): Builder => $query->where('message_kind', Message::KIND_OUTBOUND_MANUAL_REPLY),
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function problemReasons(Dialog $dialog, Carbon $slaCutoff): array
    {
        $reasons = [];

        if ($this->isRequiresReplyOverdue($dialog, $slaCutoff)) {
            $reasons[] = 'Требует ответа больше 1 часа';
        }

        if (! filled($dialog->contact?->assigned_user_id)) {
            $reasons[] = 'Без ответственного';
        }

        if ($dialog->isBotBlockedByUser()) {
            $reasons[] = 'Бот заблокирован';
        }

        return $reasons;
    }

    private function isRequiresReplyOverdue(Dialog $dialog, Carbon $slaCutoff): bool
    {
        $latestInboundUserMessageId = $dialog->getAttribute('latest_inbound_user_message_id');
        $latestInboundUserMessageSortAt = $dialog->getAttribute('latest_inbound_user_message_sort_at');
        $latestOutboundManualReplyMessageId = $dialog->getAttribute('latest_outbound_manual_reply_message_id');
        $latestOutboundManualReplyMessageSortAt = $dialog->getAttribute('latest_outbound_manual_reply_message_sort_at');

        if (! filled($latestInboundUserMessageId) || ! filled($latestInboundUserMessageSortAt)) {
            return false;
        }

        if ((int) $dialog->manual_reply_dismissed_source_message_id === (int) $latestInboundUserMessageId) {
            return false;
        }

        if (
            filled($latestOutboundManualReplyMessageId)
            && ! $this->messageChronology->isAfter(
                $latestInboundUserMessageSortAt,
                $latestInboundUserMessageId,
                $latestOutboundManualReplyMessageSortAt,
                $latestOutboundManualReplyMessageId,
            )
        ) {
            return false;
        }

        return Carbon::parse($latestInboundUserMessageSortAt)->lt($slaCutoff);
    }

    private function formatChannelLabel(Channel $channel): string
    {
        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: 'Канал без названия';
    }
}
