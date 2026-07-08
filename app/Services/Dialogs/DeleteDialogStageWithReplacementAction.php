<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;
use App\Models\DialogStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteDialogStageWithReplacementAction
{
    public function handle(DialogStage|int $stage, DialogStage|int $replacementStage): void
    {
        DB::transaction(function () use ($stage, $replacementStage): void {
            $stage = DialogStage::query()
                ->lockForUpdate()
                ->findOrFail($stage instanceof DialogStage ? $stage->getKey() : $stage);

            $replacementStage = DialogStage::query()
                ->lockForUpdate()
                ->findOrFail($replacementStage instanceof DialogStage ? $replacementStage->getKey() : $replacementStage);

            if ($stage->isSystemDerivedStage()) {
                throw ValidationException::withMessages([
                    'stage' => 'Автоматическую системную стадию нельзя удалить.',
                ]);
            }

            if ((int) $stage->getKey() === (int) $replacementStage->getKey()) {
                throw ValidationException::withMessages([
                    'replacement_stage_id' => 'Нужно выбрать другую стадию для переноса диалогов.',
                ]);
            }

            Dialog::query()
                ->where(function ($query) use ($stage): void {
                    $query
                        ->where('stage_id', $stage->getKey())
                        ->orWhere('stage', $stage->key);
                })
                ->update([
                    'stage' => $replacementStage->key,
                    'stage_id' => $replacementStage->getKey(),
                ]);

            $stage->delete();
        });
    }
}
