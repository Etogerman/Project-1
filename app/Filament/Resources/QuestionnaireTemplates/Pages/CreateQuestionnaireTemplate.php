<?php

namespace App\Filament\Resources\QuestionnaireTemplates\Pages;

use App\Filament\Resources\QuestionnaireTemplates\QuestionnaireTemplateResource;
use App\Models\QuestionnaireTemplate;
use App\Services\Questionnaires\SaveQuestionnaireTemplateDraftAction;
use App\Services\Questionnaires\ValidateQuestionnaireFieldsPayloadAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class CreateQuestionnaireTemplate extends CreateRecord
{
    protected static string $resource = QuestionnaireTemplateResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string|Htmlable
    {
        return 'Новая анкета';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(QuestionnaireTemplateResource::editorFormSchema(
                QuestionnaireTemplateResource::defaultFieldsPayload(),
            ))
            ->columns(1);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Отмена')
                ->color('gray')
                ->url(QuestionnaireTemplateResource::getUrl('index')),
            Action::make('saveDraft')
                ->label('Сохранить черновик')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->action('create')
                ->keyBindings(['mod+s']),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $fieldsPayload = QuestionnaireTemplateResource::normalizeTableEditorPayload($data);
        app(ValidateQuestionnaireFieldsPayloadAction::class)->handle($fieldsPayload, 'fields_payload');

        $template = QuestionnaireTemplate::query()->create([
            'key' => QuestionnaireTemplate::normalizeKey($data['key'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'status' => QuestionnaireTemplate::STATUS_DRAFT,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        app(SaveQuestionnaireTemplateDraftAction::class)->handle(
            $template,
            $fieldsPayload,
            auth()->user(),
            'fields_payload',
        );

        return $template->fresh(['draftVersion', 'publishedVersion']) ?? $template;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Черновик анкеты создан';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('edit', [
            'record' => $this->getRecord(),
        ]);
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
