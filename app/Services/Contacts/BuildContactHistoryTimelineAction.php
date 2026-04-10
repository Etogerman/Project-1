<?php

namespace App\Services\Contacts;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactTimelineEvent;
use App\Models\Dialog;
use Illuminate\Support\Collection;

class BuildContactHistoryTimelineAction
{
    private const EVENT_CONTACT_CREATED = 'contact_created';

    private const EVENT_DIALOG_CREATED = 'dialog_created';

    private const EVENT_DATA_COLLECTION_STARTED = 'data_collection_started';

    private const EVENT_DATA_COLLECTION_COMPLETED = 'data_collection_completed';

    private const EVENT_CONTACT_MERGED = 'contact_merged';

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

        foreach ($contact->timelineEvents as $timelineEvent) {
            if (! $timelineEvent instanceof ContactTimelineEvent || $timelineEvent->occurred_at === null) {
                continue;
            }

            if ($timelineEvent->event_type !== ContactTimelineEvent::EVENT_OPERATOR_COMMENT) {
                continue;
            }

            $items->push($this->makeItem(
                type: ContactTimelineEvent::EVENT_OPERATOR_COMMENT,
                title: 'Комментарий оператора',
                description: null,
                body: $timelineEvent->body ?: null,
                actorName: $this->formatCommentActorName($timelineEvent),
                timestamp: $timelineEvent->occurred_at,
                sortPriority: 90,
                sortId: (int) $timelineEvent->id,
            ));
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
