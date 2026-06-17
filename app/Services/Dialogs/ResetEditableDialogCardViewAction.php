<?php

namespace App\Services\Dialogs;

use App\Models\CardView;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetEditableDialogCardViewAction
{
    public function handle(): CardView
    {
        return DB::transaction(function (): CardView {
            app(SyncSystemDialogCardViewAction::class)->handle();

            CardView::query()
                ->where('entity', CardView::ENTITY_DIALOG)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', SyncSystemDialogCardViewAction::EDITABLE_VIEW_KEY)
                ->delete();

            CardView::query()
                ->where('entity', CardView::ENTITY_DIALOG)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $systemView = CardView::query()
                ->where('entity', CardView::ENTITY_DIALOG)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', SyncSystemDialogCardViewAction::VIEW_KEY)
                ->first();

            if (! $systemView instanceof CardView) {
                throw new RuntimeException('Системный вид карточки диалога не найден.');
            }

            $systemView->forceFill(['is_default' => true])->save();

            return $systemView->refresh();
        });
    }
}
