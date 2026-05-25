<?php

namespace App\Filament\Resources\QuestionnaireTemplates\Pages;

use App\Filament\Resources\Pages\ManageRecords;
use App\Filament\Resources\QuestionnaireTemplates\QuestionnaireTemplateResource;
use App\Models\QuestionnaireTemplate;
use App\Services\Questionnaires\SaveQuestionnaireTemplateDraftAction;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageQuestionnaireTemplates extends ManageRecords
{
    protected static string $resource = QuestionnaireTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить анкету')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->form([
                    Section::make('Общие настройки')
                        ->schema([
                            TextInput::make('key')
                                ->label('Ключ')
                                ->helperText('Технический ключ, например profile.')
                                ->required()
                                ->maxLength(80)
                                ->regex('/^[a-z0-9_]+$/')
                                ->unique(QuestionnaireTemplate::class, 'key'),
                            TextInput::make('name')
                                ->label('Название')
                                ->required()
                                ->maxLength(255),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                    Section::make('Вопросы, варианты ответов и правила')
                        ->description('Новая анкета создаётся с черновиком. Чтобы бот начал её использовать, черновик нужно опубликовать.')
                        ->schema([
                            Textarea::make('fields_payload_json')
                                ->label('fields_payload')
                                ->helperText('JSON-массив шагов анкеты. Можно заменить пример своими вопросами.')
                                ->default(QuestionnaireTemplateResource::encodeFieldsPayload([
                                    [
                                        'field_key' => 'question',
                                        'label' => 'Вопрос',
                                        'type' => 'text',
                                        'required' => true,
                                        'allow_skip' => false,
                                        'max_attempts' => 3,
                                        'prompts' => [
                                            'Напиши ответ',
                                            'Уточни, пожалуйста, ответ',
                                            'Ответь одним сообщением, чтобы мы продолжили',
                                        ],
                                    ],
                                ]))
                                ->required()
                                ->rows(18)
                                ->columnSpanFull(),
                        ])
                        ->columnSpanFull(),
                ])
                ->using(function (array $data): QuestionnaireTemplate {
                    $template = QuestionnaireTemplate::query()->create([
                        'key' => QuestionnaireTemplate::normalizeKey($data['key'] ?? ''),
                        'name' => (string) ($data['name'] ?? ''),
                        'status' => QuestionnaireTemplate::STATUS_DRAFT,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                    ]);

                    app(SaveQuestionnaireTemplateDraftAction::class)->handle(
                        $template,
                        QuestionnaireTemplateResource::decodeFieldsPayloadJson((string) ($data['fields_payload_json'] ?? '')),
                        auth()->user(),
                        'fields_payload_json',
                    );

                    return $template;
                })
                ->createAnother(false),
        ];
    }
}
