<?php

namespace App\Services\Contacts;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactTimelineEvent;
use App\Models\Dialog;
use App\Models\ScenarioRun;
use Illuminate\Support\Collection;

class BuildContactHistoryTimelineAction
{
    private const EVENT_CONTACT_CREATED = 'contact_created';

    private const EVENT_DIALOG_CREATED = 'dialog_created';

    private const EVENT_DATA_COLLECTION_STARTED = 'data_collection_started';

    private const EVENT_DATA_COLLECTION_COMPLETED = 'data_collection_completed';

    private const EVENT_CONTACT_MERGED = 'contact_merged';

    private const EVENT_VIP_IBIZA_COMPLETED = 'scenario_vip_ibiza_completed';

    private const VIP_IBIZA_SCENARIO_CODE = 'vip_ibiza';

    /**
     * @var array<string, string>
     */
    private const VIP_IBIZA_SUMMARY_FIELDS = [
        'run.first_name' => 'Имя',
        'run.dates_response' => 'Готовность по датам',
        'run.primary_goal' => 'Цель',
        'run.commitment' => 'Формат включения',
        'run.budget_tier' => 'Бюджет',
        'run.departure_city' => 'Город вылета',
        'run.call_readiness' => 'Готовность к созвону',
        'run.instagram_handle' => 'Instagram',
    ];

    /**
     * @return Collection<int, array{
     *     type:string,
     *     title:string,
     *     description:?string,
     *     body:?string,
     *     actorName:?string,
     *     timestampLabel:string
     * }>
     */
    public function handle(Contact $contact): Collection
    {
        $contact->loadMissing(['dialogs.channel', 'mergedInto', 'timelineEvents.actorUser']);

        /** @var Collection<int, array{
         *     type:string,
         *     title:string,
         *     description:?string,
         *     body:?string,
         *     actorName:?string,
         *     timestampLabel:string,
         *     sortTimestamp:int,
         *     sortPriority:int,
         *     sortId:int
         * }> $items
         */
        $items = collect();

        if ($contact->created_at !== null) {
            $items->push($this->makeItem(
                type: self::EVENT_CONTACT_CREATED,
                title: 'Контакт создан',
                description: 'Контакт появился в системе.',
                timestamp: $contact->created_at,
                sortPriority: 10,
                sortId: (int) $contact->id,
            ));
        }

        foreach ($contact->dialogs as $dialog) {
            if (! $dialog instanceof Dialog || $dialog->created_at === null) {
                continue;
            }

            $items->push($this->makeItem(
                type: self::EVENT_DIALOG_CREATED,
                title: 'Появился диалог',
                description: sprintf('Создан диалог в канале «%s».', $this->formatDialogChannelLabel($dialog)),
                timestamp: $dialog->created_at,
                sortPriority: 20,
                sortId: (int) $dialog->id,
            ));
        }

        if ($contact->data_collection_started_at !== null) {
            $items->push($this->makeItem(
                type: self::EVENT_DATA_COLLECTION_STARTED,
                title: 'Анкета начата',
                description: 'Запущен сбор данных по анкете контакта.',
                timestamp: $contact->data_collection_started_at,
                sortPriority: 30,
                sortId: (int) $contact->id,
            ));
        }

        if ($contact->data_collection_completed_at !== null) {
            $items->push($this->makeItem(
                type: self::EVENT_DATA_COLLECTION_COMPLETED,
                title: 'Анкета завершена',
                description: 'Сбор данных по анкете завершён.',
                timestamp: $contact->data_collection_completed_at,
                sortPriority: 40,
                sortId: (int) $contact->id,
            ));
        }

        if ($contact->merged_at !== null) {
            $items->push($this->makeItem(
                type: self::EVENT_CONTACT_MERGED,
                title: 'Контакт объединён',
                description: $this->formatMergedDescription($contact),
                timestamp: $contact->merged_at,
                sortPriority: 50,
                sortId: (int) $contact->id,
            ));
        }

        foreach ($this->buildVipIbizaRunItems($contact) as $scenarioItem) {
            $items->push($scenarioItem);
        }

        foreach ($contact->timelineEvents as $timelineEvent) {
            if (! $timelineEvent instanceof ContactTimelineEvent || $timelineEvent->occurred_at === null) {
                continue;
            }

            $item = match ($timelineEvent->event_type) {
                ContactTimelineEvent::EVENT_OPERATOR_COMMENT => $this->buildOperatorCommentItem($timelineEvent),
                ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED => $this->buildFirstNameChangedItem($timelineEvent),
                ContactTimelineEvent::EVENT_MERGE_NAME_CONFLICT => $this->buildMergeNameConflictItem($timelineEvent),
                default => null,
            };

            if ($item !== null) {
                $items->push($item);
            }
        }

        return $items
            ->sort(static function (array $left, array $right): int {
                $timestampComparison = $right['sortTimestamp'] <=> $left['sortTimestamp'];

                if ($timestampComparison !== 0) {
                    return $timestampComparison;
                }

                $priorityComparison = $right['sortPriority'] <=> $left['sortPriority'];

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return $right['sortId'] <=> $left['sortId'];
            })
            ->values()
            ->map(static function (array $item): array {
                unset($item['sortTimestamp'], $item['sortPriority'], $item['sortId']);

                return $item;
            });
    }

    /**
     * @return array{
     *     type:string,
     *     title:string,
     *     description:?string,
     *     body:?string,
     *     actorName:?string,
     *     timestampLabel:string,
     *     sortTimestamp:int,
     *     sortPriority:int,
     *     sortId:int
     * }
     */
    private function makeItem(
        string $type,
        string $title,
        ?string $description,
        \DateTimeInterface $timestamp,
        int $sortPriority,
        int $sortId,
        ?string $body = null,
        ?string $actorName = null,
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'body' => $body,
            'actorName' => $actorName,
            'timestampLabel' => $timestamp->format('d.m.Y H:i:s'),
            'sortTimestamp' => $timestamp->getTimestamp(),
            'sortPriority' => $sortPriority,
            'sortId' => $sortId,
        ];
    }

    private function formatDialogChannelLabel(Dialog $dialog): string
    {
        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            return 'неизвестный канал';
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: 'неизвестный канал';
    }

    /**
     * @return Collection<int, array{
     *     type:string,
     *     title:string,
     *     description:?string,
     *     body:?string,
     *     actorName:?string,
     *     timestampLabel:string,
     *     sortTimestamp:int,
     *     sortPriority:int,
     *     sortId:int
     * }>
     */
    private function buildVipIbizaRunItems(Contact $contact): Collection
    {
        $dialogs = $contact->dialogs
            ->filter(static fn (mixed $dialog): bool => $dialog instanceof Dialog)
            ->keyBy(static fn (Dialog $dialog): int => (int) $dialog->id);

        if ($dialogs->isEmpty()) {
            return collect();
        }

        return ScenarioRun::query()
            ->where('scenario_code', self::VIP_IBIZA_SCENARIO_CODE)
            ->where('status', ScenarioRun::STATUS_COMPLETED)
            ->whereIn('dialog_id', $dialogs->keys()->all())
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ScenarioRun $run) use ($dialogs): ?array {
                if ($run->finished_at === null) {
                    return null;
                }

                /** @var Dialog|null $dialog */
                $dialog = $dialogs->get((int) $run->dialog_id);

                return $this->makeItem(
                    type: self::EVENT_VIP_IBIZA_COMPLETED,
                    title: 'Пройден сценарий VIP Ibiza',
                    description: $this->formatVipIbizaDescription($dialog),
                    body: $this->formatVipIbizaSummary($run),
                    timestamp: $run->finished_at,
                    sortPriority: 85,
                    sortId: (int) $run->id,
                );
            })
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->values();
    }

    private function formatVipIbizaDescription(?Dialog $dialog): string
    {
        if ($dialog instanceof Dialog) {
            return sprintf('Сценарий завершён в канале «%s».', $this->formatDialogChannelLabel($dialog));
        }

        return 'Сценарий завершён.';
    }

    private function formatVipIbizaSummary(ScenarioRun $run): ?string
    {
        $statePayload = is_array($run->state_payload) ? $run->state_payload : [];
        $lines = [];

        foreach (self::VIP_IBIZA_SUMMARY_FIELDS as $path => $label) {
            $value = $this->normalizeTimelineValue(data_get($statePayload, $path));

            if ($value === null) {
                continue;
            }

            $lines[] = sprintf('%s: %s', $label, $value);
        }

        if ($lines === []) {
            return null;
        }

        return implode(PHP_EOL, $lines);
    }

    private function formatMergedDescription(Contact $contact): string
    {
        if ($contact->mergedInto instanceof Contact) {
            return sprintf(
                'Контакт объединён с основным контактом #%d %s.',
                $contact->mergedInto->id,
                $contact->mergedInto->display_name,
            );
        }

        return 'Контакт объединён с основным контактом.';
    }

    /**
     * @return array{
     *     type:string,
     *     title:string,
     *     description:?string,
     *     body:?string,
     *     actorName:?string,
     *     timestampLabel:string,
     *     sortTimestamp:int,
     *     sortPriority:int,
     *     sortId:int
     * }
     */
    private function buildOperatorCommentItem(ContactTimelineEvent $timelineEvent): array
    {
        return $this->makeItem(
            type: ContactTimelineEvent::EVENT_OPERATOR_COMMENT,
            title: 'Комментарий оператора',
            description: null,
            body: $timelineEvent->body ?: null,
            actorName: $this->formatCommentActorName($timelineEvent),
            timestamp: $timelineEvent->occurred_at,
            sortPriority: 90,
            sortId: (int) $timelineEvent->id,
        );
    }

    /**
     * @return array{
     *     type:string,
     *     title:string,
     *     description:?string,
     *     body:?string,
     *     actorName:?string,
     *     timestampLabel:string,
     *     sortTimestamp:int,
     *     sortPriority:int,
     *     sortId:int
     * }
     */
    private function buildFirstNameChangedItem(ContactTimelineEvent $timelineEvent): array
    {
        $payload = is_array($timelineEvent->payload) ? $timelineEvent->payload : [];
        $previousValue = $this->normalizeTimelineValue($payload['previous_value'] ?? null) ?? '—';
        $newValue = $this->normalizeTimelineValue($payload['new_value'] ?? null);

        if ($newValue !== null) {
            return $this->makeItem(
                type: ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
                title: 'Имя изменено',
                description: sprintf('«%s» → «%s»', $previousValue, $newValue),
                body: 'Источник: '.$this->formatTimelineSourceLabel($payload['new_source'] ?? null),
                timestamp: $timelineEvent->occurred_at,
                sortPriority: 80,
                sortId: (int) $timelineEvent->id,
            );
        }

        return $this->makeItem(
            type: ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
            title: 'Имя очищено',
            description: sprintf('Было: «%s»', $previousValue),
            body: 'Источник: '.$this->formatTimelineSourceLabel($payload['previous_source'] ?? null),
            timestamp: $timelineEvent->occurred_at,
            sortPriority: 80,
            sortId: (int) $timelineEvent->id,
        );
    }

    /**
     * @return array{
     *     type:string,
     *     title:string,
     *     description:?string,
     *     body:?string,
     *     actorName:?string,
     *     timestampLabel:string,
     *     sortTimestamp:int,
     *     sortPriority:int,
     *     sortId:int
     * }
     */
    private function buildMergeNameConflictItem(ContactTimelineEvent $timelineEvent): array
    {
        $payload = is_array($timelineEvent->payload) ? $timelineEvent->payload : [];
        $mergedContactId = (int) ($payload['merged_contact_id'] ?? 0);
        $mergedFirstName = $this->normalizeTimelineValue($payload['merged_first_name'] ?? null) ?? '—';

        return $this->makeItem(
            type: ContactTimelineEvent::EVENT_MERGE_NAME_CONFLICT,
            title: 'Конфликт имени при объединении',
            description: $mergedContactId > 0
                ? sprintf('При объединении с контактом #%d найдено другое имя: «%s»', $mergedContactId, $mergedFirstName)
                : sprintf('При объединении найдено другое имя: «%s»', $mergedFirstName),
            body: 'Источник: '.$this->formatTimelineSourceLabel($payload['merged_first_name_source'] ?? null),
            timestamp: $timelineEvent->occurred_at,
            sortPriority: 70,
            sortId: (int) $timelineEvent->id,
        );
    }

    private function formatTimelineSourceLabel(mixed $source): string
    {
        return Contact::formatFirstNameSourceTimelineLabel(is_string($source) ? $source : null) ?? '—';
    }

    private function normalizeTimelineValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        if (! is_string($normalized)) {
            return null;
        }

        return $normalized === '' ? null : $normalized;
    }

    private function formatCommentActorName(ContactTimelineEvent $timelineEvent): string
    {
        $actor = $timelineEvent->actorUser;

        if (filled($actor?->name)) {
            return (string) $actor->name;
        }

        if ($timelineEvent->actor_user_id !== null) {
            return 'Сотрудник #'.$timelineEvent->actor_user_id;
        }

        return 'Неизвестный сотрудник';
    }
}
