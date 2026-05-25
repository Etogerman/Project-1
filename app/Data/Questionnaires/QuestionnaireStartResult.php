<?php

namespace App\Data\Questionnaires;

final readonly class QuestionnaireStartResult
{
    public const OUTCOME_WAITING = 'waiting';

    public const OUTCOME_COMPLETED = 'completed';

    public const OUTCOME_ALREADY_COMPLETED = 'already_completed';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const OUTCOME_OPERATOR_REQUESTED = 'operator_requested';

    /**
     * @param  list<array{value:string,label:string}>  $options
     */
    public function __construct(
        public string $outcome,
        public ?int $runId = null,
        public ?string $currentFieldKey = null,
        public ?string $promptText = null,
        public array $options = [],
        public ?string $error = null,
    ) {}

    public function shouldStopCurrentExecution(): bool
    {
        return $this->outcome === self::OUTCOME_WAITING;
    }
}
