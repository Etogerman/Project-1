<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;
use Illuminate\Database\Eloquent\Builder;

class DialogInboxStatusPolicy
{
    public function __construct(
        private readonly DialogStageCatalog $dialogStageCatalog,
    ) {}

    public function suppressesReplyRequirement(Dialog $dialog): bool
    {
        if ($dialog->isBotBlockedByUser()) {
            return true;
        }

        return $this->dialogStageCatalog->isBlacklistDialog($dialog);
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
