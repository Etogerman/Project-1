<?php

namespace App\Services\Dialogs;

use App\Models\Message;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class MessageChronology
{
    public function resolveSortAt(Message $message): ?Carbon
    {
        return $message->received_at ?? $message->created_at;
    }

    public function resolveSortAtValue(mixed $receivedAt, mixed $createdAt): mixed
    {
        return $receivedAt ?? $createdAt;
    }

    public function sqlSortAt(string $table = 'messages'): string
    {
        return sprintf('coalesce(%s.received_at, %s.created_at)', $table, $table);
    }

    public function applyLatestOrder(Builder $query, string $table = 'messages'): Builder
    {
        return $query
            ->orderByRaw($this->sqlSortAt($table).' desc')
            ->orderByDesc(sprintf('%s.id', $table));
    }

    public function applyOldestOrder(Builder $query, string $table = 'messages'): Builder
    {
        return $query
            ->orderByRaw($this->sqlSortAt($table).' asc')
            ->orderBy(sprintf('%s.id', $table));
    }

    public function latestMessageIdSubquery(
        string $foreignColumn,
        string $parentReference,
        ?Closure $scope = null,
    ): Builder {
        $query = Message::query()
            ->select('id')
            ->whereColumn($foreignColumn, $parentReference);

        if ($scope instanceof Closure) {
            $scope($query);
        }

        return $this->applyLatestOrder($query)->limit(1);
    }

    public function latestMessageSortAtSubquery(
        string $foreignColumn,
        string $parentReference,
        ?Closure $scope = null,
    ): Builder {
        $query = Message::query()
            ->selectRaw($this->sqlSortAt('messages'))
            ->whereColumn($foreignColumn, $parentReference);

        if ($scope instanceof Closure) {
            $scope($query);
        }

        return $this->applyLatestOrder($query)->limit(1);
    }

    public function latestDialogMessageIdSql(
        ?string $messageKind = null,
        ?string $direction = null,
    ): string {
        return $this->buildLatestMessageIdSql('dialog_id', 'dialogs.id', $messageKind, $direction);
    }

    public function latestDialogMessageSortAtSql(
        ?string $messageKind = null,
        ?string $direction = null,
    ): string {
        return $this->buildLatestMessageSortAtSql('dialog_id', 'dialogs.id', $messageKind, $direction);
    }

    public function latestContactMessageIdSql(
        ?string $messageKind = null,
        ?string $direction = null,
    ): string {
        return $this->buildLatestMessageIdSql('contact_id', 'contacts.id', $messageKind, $direction);
    }

    public function latestContactMessageSortAtSql(
        ?string $messageKind = null,
        ?string $direction = null,
    ): string {
        return $this->buildLatestMessageSortAtSql('contact_id', 'contacts.id', $messageKind, $direction);
    }

    public function compareSortTuple(mixed $leftSortAt, mixed $leftId, mixed $rightSortAt, mixed $rightId): int
    {
        $leftTimestamp = $this->toTimestamp($leftSortAt);
        $rightTimestamp = $this->toTimestamp($rightSortAt);

        if ($leftTimestamp === null && $rightTimestamp !== null) {
            return -1;
        }

        if ($leftTimestamp !== null && $rightTimestamp === null) {
            return 1;
        }

        $comparison = ($leftTimestamp ?? 0) <=> ($rightTimestamp ?? 0);

        if ($comparison !== 0) {
            return $comparison;
        }

        return (int) $leftId <=> (int) $rightId;
    }

    public function isAfter(mixed $leftSortAt, mixed $leftId, mixed $rightSortAt, mixed $rightId): bool
    {
        return $this->compareSortTuple($leftSortAt, $leftId, $rightSortAt, $rightId) > 0;
    }

    public function timestampSortKey(mixed $value): string
    {
        $timestamp = $this->toTimestamp($value);

        return $timestamp === null
            ? ''
            : sprintf('%020d', $timestamp);
    }

    public function timestampAndIdSortKey(mixed $sortAt, int $id): string
    {
        return $this->timestampSortKey($sortAt)
            .'|'.str_pad((string) $id, 10, '0', STR_PAD_LEFT);
    }

    private function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);

            return $timestamp === false ? null : $timestamp;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buildSqlConditions(
        string $foreignColumn,
        string $parentReference,
        ?string $messageKind,
        ?string $direction,
    ): array {
        $conditions = [sprintf('messages.%s = %s', $foreignColumn, $parentReference)];

        if ($messageKind !== null) {
            $conditions[] = sprintf("messages.message_kind = '%s'", str_replace("'", "''", $messageKind));
        }

        if ($direction !== null) {
            $conditions[] = sprintf("messages.direction = '%s'", str_replace("'", "''", $direction));
        }

        return $conditions;
    }

    private function buildLatestMessageIdSql(
        string $foreignColumn,
        string $parentReference,
        ?string $messageKind,
        ?string $direction,
    ): string {
        $conditions = $this->buildSqlConditions($foreignColumn, $parentReference, $messageKind, $direction);

        return sprintf(
            '(select id from messages where %s order by %s desc, messages.id desc limit 1)',
            implode(' and ', $conditions),
            $this->sqlSortAt('messages'),
        );
    }

    private function buildLatestMessageSortAtSql(
        string $foreignColumn,
        string $parentReference,
        ?string $messageKind,
        ?string $direction,
    ): string {
        $conditions = $this->buildSqlConditions($foreignColumn, $parentReference, $messageKind, $direction);

        return sprintf(
            '(select %s from messages where %s order by %s desc, messages.id desc limit 1)',
            $this->sqlSortAt('messages'),
            implode(' and ', $conditions),
            $this->sqlSortAt('messages'),
        );
    }
}
