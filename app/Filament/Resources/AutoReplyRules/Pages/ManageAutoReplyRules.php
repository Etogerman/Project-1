<?php

namespace App\Filament\Resources\AutoReplyRules\Pages;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Models\AutoReplyRule;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageAutoReplyRules extends ManageRecords
{
    protected static string $resource = AutoReplyRuleResource::class;

    public function mount(): void
    {
        parent::mount();

        $tagId = request()->integer('tag');

        if ($tagId <= 0) {
            return;
        }

        $this->tableFilters ??= [];
        $this->tableFilters['tag'] = [
            'value' => (string) $tagId,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить правило')
                ->modalWidth(Width::FiveExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->extraModalWindowAttributes([
                    'class' => 'ac-auto-reply-form-modal',
                    'style' => 'width: 90vw; max-width: 90vw;',
                ])
                ->using(function (array $data): AutoReplyRule {
                    try {
                        return AutoReplyRuleResource::saveAutoReplyRule($data);
                    } catch (\Illuminate\Validation\ValidationException $exception) {
                        AutoReplyRuleResource::notifyValidationFailure($exception);

                        throw $exception;
                    }
                })
                ->createAnother(false),
        ];
    }
}
