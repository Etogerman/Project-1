<?php

namespace App\Services\AutoReplyRules;

use App\Data\AutoReplyRules\AutoReplyRuleWorkbookPreviewData;
use App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowData;
use App\Data\AutoReplyRules\AutoReplyRuleWorkbookRowErrorData;
use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Models\AutoReplyRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyAutoReplyRulesWorkbookImportAction
{
    public function handle(AutoReplyRuleWorkbookPreviewData $preview): void
    {
        if ($preview->hasErrors()) {
            throw ValidationException::withMessages([
                'workbook' => 'Нельзя применить импорт, пока в предпросмотре есть ошибки.',
            ]);
        }

        $rows = [...$preview->createRows, ...$preview->updateRows];

        $uniquenessErrors = app(ValidateAutoReplyRulesWorkbookUniquenessAction::class)->handle($rows);

        if ($uniquenessErrors !== []) {
            throw ValidationException::withMessages($this->buildUniquenessValidationMessages($uniquenessErrors));
        }

        usort($rows, fn (AutoReplyRuleWorkbookRowData $left, AutoReplyRuleWorkbookRowData $right): int => $left->rowNumber <=> $right->rowNumber);

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $record = $row->id !== null
                    ? AutoReplyRule::query()->find($row->id)
                    : null;

                if ($row->id !== null && ! $record instanceof AutoReplyRule) {
                    throw ValidationException::withMessages([
                        'id' => sprintf('Правило из строки %d больше не найдено.', $row->rowNumber),
                        ]);
                    }

                try {
                    AutoReplyRuleResource::saveAutoReplyRule($this->buildPayload($row), $record);
                } catch (QueryException $exception) {
                    $this->throwValidationExceptionForUniqueConstraint($exception, $row);

                    throw $exception;
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(AutoReplyRuleWorkbookRowData $row): array
    {
        return [
            'name' => $row->name,
            'auto_reply_category_id' => $row->autoReplyCategoryId,
            'channel_ids' => $row->channelIds,
            'match_scope' => $row->matchScope,
            'keyword' => $row->keyword,
            'contact_phone_condition' => $row->contactPhoneCondition,
            'reply_text' => $row->replyText,
            'button_kind' => $row->buttonKind,
            'button_text' => $row->buttonText,
            'button_url' => $row->buttonUrl,
            'required_tag_ids' => $row->requiredTagIds,
            'excluded_tag_ids' => $row->excludedTagIds,
            'assign_tag_ids' => $row->assignTagIds,
            'remove_tag_ids' => $row->removeTagIds,
            'is_active' => $row->isActive,
            'priority' => $row->priority,
        ];
    }

    /**
     * @param  list<AutoReplyRuleWorkbookRowErrorData>  $errors
     * @return array<string, string>
     */
    protected function buildUniquenessValidationMessages(array $errors): array
    {
        $messages = [];

        foreach ($errors as $index => $error) {
            $messages['workbook_'.$index] = sprintf('Строка %d: %s', $error->rowNumber, $error->message);
        }

        return $messages;
    }

    protected function throwValidationExceptionForUniqueConstraint(QueryException $exception, AutoReplyRuleWorkbookRowData $row): void
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        if ($sqlState !== '23505') {
            return;
        }

        throw ValidationException::withMessages([
            'workbook' => sprintf(
                'Строка %d конфликтует с существующим правилом по ключу (channel_id + match_scope + keyword). Обновите предпросмотр и повторите импорт.',
                $row->rowNumber,
            ),
        ]);
    }
}
