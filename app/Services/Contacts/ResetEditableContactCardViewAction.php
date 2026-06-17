<?php

namespace App\Services\Contacts;

use App\Models\CardView;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetEditableContactCardViewAction
{
    public function handle(): CardView
    {
        return DB::transaction(function (): CardView {
            app(SyncSystemContactCardViewAction::class)->handle();

            CardView::query()
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY)
                ->delete();

            CardView::query()
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $systemView = CardView::query()
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', SyncSystemContactCardViewAction::VIEW_KEY)
                ->first();

            if (! $systemView instanceof CardView) {
                throw new RuntimeException('Системный вид карточки контакта не найден.');
            }

            $systemView->forceFill(['is_default' => true])->save();

            return $systemView->refresh();
        });
    }
}
