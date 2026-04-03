<?php

namespace App\Services\Dialogs;

use App\Models\Message;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class MessageChronology
{
    /**
     * @param  array{sql: string, bindings: list<mixed>}  $leftSortAt
     * @param  array{sql: string, bindings: list<mixed>}  $leftId
     * @param  array{sql: string, bindings: list<mixed>}  $rightSortAt
     * @param  array{sql: string, bindings: list<mixed>}  $rightId
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function buildIsAfterCondition(
        array $leftSortAt,
        array $leftId,
        array $rightSortAt,
        array $rightId,
    ): array {
        return [
            'sql' => sprintf(
                '(%1$s > %2$s) or ((%1$s = %2$s) and (%3$s > %4$s))',
                $leftSortAt['sql'],
                $rightSortAt['sql'],
                $leftId['sql'],
                $rightId['sql'],
            ),
            'bindings' => [
                ...$leftSortAt['bindings'],
                ...$rightSortAt['bindings'],
                ...$leftSortAt['bindings'],
                ...$rightSortAt['bindings'],
                ...$leftId['bindings'],
                ...$rightId['bindings'],
            ],
        ];
    }

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

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function latestDialogMessageIdFragment(
        ?string $messageKind = null,
        ?string $direction = null,
    ): array {
        return $this->buildLatestMessageIdFragment('dialog_id', 'dialogs.id', $messageKind, $direction);
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function latestDialogMessageSortAtFragment(
        ?string $messageKind = null,
        ?string $direction = null,
    ): array {
        return $this->buildLatestMessageSortAtFragment('dialog_id', 'dialogs.id', $messageKind, $direction);
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function latestContactMessageIdFragment(
        ?string $messageKind = null,
        ?string $direction = null,
    ): array {
        return $this->buildLatestMessageIdFragment('contact_id', 'contacts.id', $messageKind, $direction);
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function latestContactMessageSortAtFragment(
        ?string $messageKind = null,
        ?string $direction = null,
    ): array {
        return $this->buildLatestMessageSortAtFragment('contact_id', 'contacts.id', $messageKind, $direction);
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
     * @return array{conditions: list<string>, bindings: list<mixed>}
     */
    private function buildSqlConditions(
        string $foreignColumn,
        string $parentReference,
        ?string $messageKind,
        ?string $direction,
    ): array {
        $conditions = [sprintf('messages.%s = %s', $foreignColumn, $parentReference)];
        $bindings = [];

        if ($messageKind !== null) {
            $conditions[] = 'messages.message_kind = ?';
            $bindings[] = $messageKind;
        }

        if ($direction !== null) {
            $conditions[] = 'messages.direction = ?';
            $bindings[] = $direction;
        }

        return [
            'conditions' => $conditions,
            'bindings' => $bindings,
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function buildLatestMessageIdFragment(
        string $foreignColumn,
        string $parentReference,
        ?string $messageKind,
        ?string $direction,
    ): array {
        $conditions = $this->buildSqlConditions($foreignColumn, $parentReference, $messageKind, $direction);

        return [
            'sql' => sprintf(
                '(select id from messages where %s order by %s desc, messages.id desc limit 1)',
                implode(' and ', $conditions['conditions']),
                $this->sqlSortAt('messages'),
            ),
            'bindings' => $conditions['bindings'],
        ];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function buildLatestMessageSortAtFragment(
        string $foreignColumn,
        string $parentReference,
        ?string $messageKind,
        ?string $direction,
    ): array {
        $conditions = $this->buildSqlConditions($foreignColumn, $parentReference, $messageKind, $direction);

        return [
            'sql' => sprintf(
                '(select %s from messages where %s order by %s desc, messages.id desc limit 1)',
                $this->sqlSortAt('messages'),
                implode(' and ', $conditions['conditions']),
                $this->sqlSortAt('messages'),
            ),
            'bindings' => $conditions['bindings'],
        ];
    }
}
