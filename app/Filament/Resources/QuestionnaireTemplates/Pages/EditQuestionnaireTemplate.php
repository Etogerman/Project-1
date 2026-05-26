<?php

namespace App\Filament\Resources\QuestionnaireTemplates\Pages;

use App\Filament\Resources\QuestionnaireTemplates\QuestionnaireTemplateResource;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Services\Questionnaires\PublishQuestionnaireTemplateVersionAction;
use App\Services\Questionnaires\SaveQuestionnaireTemplateDraftAction;
use App\Services\Questionnaires\ValidateQuestionnaireFieldsPayloadAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditQuestionnaireTemplate extends EditRecord
{
    protected static string $resource = QuestionnaireTemplateResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return QuestionnaireTemplateResource::buildEditorOverview($this->getRecord());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(QuestionnaireTemplateResource::editorFormSchema(includeIdentitySection: false))
            ->columns(1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return [
            ...$data,
            ...QuestionnaireTemplateResource::fieldsPayloadEditorFormData(
                $record->draftVersion?->fields_payload
                ?? $record->publishedVersion?->fields_payload
                ?? [],
            ),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Предпросмотр')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->disabled(),
            Action::make('history')
                ->label('История')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->disabled(),
            Action::make('cancel')
                ->label('Отмена')
                ->color('gray')
                ->url(QuestionnaireTemplateResource::getUrl('index')),
            Action::make('saveDraft')
                ->label('Сохранить черновик')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->action('save')
                ->keyBindings(['mod+s']),
            Action::make('publishDraft')
                ->label('Опубликовать')
                ->icon(Heroicon::OutlinedBolt)
                ->color('success')
                ->action('publishDraft'),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof QuestionnaireTemplate, 404);

        $fieldsPayload = QuestionnaireTemplateResource::normalizeTableEditorPayload($data);
        app(ValidateQuestionnaireFieldsPayloadAction::class)->handle($fieldsPayload, 'fields_payload');

        $record = QuestionnaireTemplateResource::saveTemplate($data, $record);

        app(SaveQuestionnaireTemplateDraftAction::class)->handle(
            $record,
            $fieldsPayload,
            auth()->user(),
            'fields_payload',
        );

        $this->record = $record->fresh(['draftVersion', 'publishedVersion', 'updater']) ?? $record;

        return $this->record;
    }

    public function publishDraft(): void
    {
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        $record = $this->getRecord()->fresh(['draftVersion', 'publishedVersion']);

        if (! $record instanceof QuestionnaireTemplate) {
            return;
        }

        $draftVersion = $record->draftVersion()->first();

        if (! $draftVersion instanceof QuestionnaireTemplateVersion) {
            throw ValidationException::withMessages([
                'fields_payload' => 'У анкеты нет черновика для публикации.',
            ]);
        }

        app(PublishQuestionnaireTemplateVersionAction::class)->handle($draftVersion, auth()->user());

        $this->record = $record->fresh(['draftVersion', 'publishedVersion', 'updater']) ?? $record;
        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Черновик анкеты опубликован')
            ->body('Runtime будет использовать новую опубликованную версию.')
            ->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Черновик анкеты сохранён';
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'qe-questionnaire-editor',
            'density-compact',
        ];
    }
}
