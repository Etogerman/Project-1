<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;
use Illuminate\Database\Eloquent\Builder;

class DialogInboxStatusPolicy
{
    public const BLOCKED_BY_USER_MESSAGE = 'Клиент заблокировал бота. Статус «Требует ответа» станет доступен после разблокировки.';

    public const BLACKLIST_STAGE_MESSAGE = 'Диалог находится в ЧС-стадии. Статус «Требует ответа» станет доступен после выхода из ЧС.';

    public function __construct(
        private readonly DialogStageCatalog $dialogStageCatalog,
    ) {}

    public function suppressesReplyRequirement(Dialog $dialog): bool
    {
        return $this->replyRequirementSuppressionReason($dialog) !== null;
    }

    public function replyRequirementSuppressionReason(Dialog $dialog): ?string
    {
        if ($dialog->isBotBlockedByUser()) {
            return self::BLOCKED_BY_USER_MESSAGE;
        }

        if ($this->dialogStageCatalog->isBlacklistDialog($dialog)) {
            return self::BLACKLIST_STAGE_MESSAGE;
        }

        return null;
    }

    public function applyReplyEligibleFilter(Builder $query): Builder
    {
        $this->dialogStageCatalog->applyNotBlacklistStageFilter($query);

        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('dialogs.bot_subscription_status')
                ->orWhere(
                    'dialogs.bot_subscription_status',
                    '!=',
                    Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
                );
        });
    }

    public function applyReplySuppressedFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $this->dialogStageCatalog->applyBlacklistStageFilter($query);
                })
                ->orWhere(
                    'dialogs.bot_subscription_status',
                    Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
                );
        });
    }
}
