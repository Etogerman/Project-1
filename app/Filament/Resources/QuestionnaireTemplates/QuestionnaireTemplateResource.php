<?php

namespace App\Filament\Resources\QuestionnaireTemplates;

use App\Filament\Resources\QuestionnaireTemplates\Pages\ManageQuestionnaireTemplates;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Services\Questionnaires\PublishQuestionnaireTemplateVersionAction;
use App\Services\Questionnaires\SaveQuestionnaireTemplateDraftAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Validation\ValidationException;
use JsonException;
use UnitEnum;

class QuestionnaireTemplateResource extends Resource
{
    protected static ?string $model = QuestionnaireTemplate::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Анкета';

    protected static ?string $pluralModelLabel = 'Анкеты';

    protected static ?string $navigationLabel = 'Анкеты';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 22;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('questionnaire_templates')
            && auth()->user()?->canManageSystem() === true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['publishedVersion', 'draftVersion'])
            ->withCount(['versions', 'runs']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Анкета')
                ->description('Поля анкеты редактируются отдельным JSON-черновиком. Runtime использует только опубликованную версию.')
                ->schema([
                    TextInput::make('key')
                        ->label('Ключ')
                        ->helperText('Технический ключ, например profile.')
                        ->required()
                        ->maxLength(80)
                        ->regex('/^[a-z0-9_]+$/')
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?QuestionnaireTemplate $record): bool => $record instanceof QuestionnaireTemplate)
                        ->dehydrated(fn (?QuestionnaireTemplate $record): bool => ! $record instanceof QuestionnaireTemplate),
                    TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('key')
                    ->label('Ключ')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => QuestionnaireTemplate::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        QuestionnaireTemplate::STATUS_PUBLISHED => 'success',
                        QuestionnaireTemplate::STATUS_DISABLED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('published_version')
                    ->label('Опубликована')
                    ->state(fn (QuestionnaireTemplate $record): string => static::formatVersionLabel($record->publishedVersion))
                    ->badge()
                    ->color(fn (QuestionnaireTemplate $record): string => $record->publishedVersion instanceof QuestionnaireTemplateVersion ? 'success' : 'gray'),
                TextColumn::make('draft_version')
                    ->label('Черновик')
                    ->state(fn (QuestionnaireTemplate $record): string => static::formatVersionLabel($record->draftVersion))
                    ->badge()
                    ->color(fn (QuestionnaireTemplate $record): string => $record->draftVersion instanceof QuestionnaireTemplateVersion ? 'warning' : 'gray'),
                TextColumn::make('fields_count')
                    ->label('Полей')
                    ->state(fn (QuestionnaireTemplate $record): int => count($record->publishedVersion?->fields_payload ?? []))
                    ->sortable(false),
                TextColumn::make('versions_count')
                    ->label('Версий')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                Action::make('editDraft')
                    ->label('JSON')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->iconButton()
                    ->tooltip('Редактировать JSON черновика')
                    ->modalHeading(fn (QuestionnaireTemplate $record): string => 'JSON анкеты: '.$record->name)
                    ->modalSubmitActionLabel('Сохранить черновик')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->fillForm(fn (QuestionnaireTemplate $record): array => [
                        'fields_payload_json' => static::encodeFieldsPayload(
                            $record->draftVersion?->fields_payload
                                ?? $record->publishedVersion?->fields_payload
                                ?? [],
                        ),
                    ])
                    ->form([
                        Textarea::make('fields_payload_json')
                            ->label('fields_payload')
                            ->helperText('JSON-массив шагов анкеты. Сохранение создаёт или обновляет черновик, но не публикует его.')
                            ->required()
                            ->rows(24)
                            ->columnSpanFull(),
                    ])
                    ->action(function (QuestionnaireTemplate $record, array $data): void {
                        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

                        app(SaveQuestionnaireTemplateDraftAction::class)->handle(
                            $record,
                            static::decodeFieldsPayloadJson((string) ($data['fields_payload_json'] ?? '')),
                            auth()->user(),
                            'fields_payload_json',
                        );

                        Notification::make()
                            ->success()
                            ->title('Черновик анкеты сохранён')
                            ->body('Опубликованная версия не изменилась.')
                            ->send();
                    }),
                Action::make('publishDraft')
                    ->icon(Heroicon::OutlinedBolt)
                    ->iconButton()
                    ->tooltip('Опубликовать черновик')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (QuestionnaireTemplate $record): bool => $record->draftVersion instanceof QuestionnaireTemplateVersion
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (QuestionnaireTemplate $record): void {
                        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

                        $draftVersion = $record->draftVersion()->first();

                        if (! $draftVersion instanceof QuestionnaireTemplateVersion) {
                            throw ValidationException::withMessages([
                                'version' => 'У анкеты нет черновика для публикации.',
                            ]);
                        }

                        app(PublishQuestionnaireTemplateVersionAction::class)->handle($draftVersion, auth()->user());

                        Notification::make()
                            ->success()
                            ->title('Черновик анкеты опубликован')
                            ->body('Runtime будет использовать новую опубликованную версию.')
                            ->send();
                    }),
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить')
                    ->modalWidth(Width::Large)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->using(fn (array $data, QuestionnaireTemplate $record): QuestionnaireTemplate => static::saveTemplate($data, $record)),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->tooltip('Удалить')
                    ->hidden(fn (QuestionnaireTemplate $record): bool => $record->runs_count > 0 || $record->versions_count > 0),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageQuestionnaireTemplates::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveTemplate(array $data, QuestionnaireTemplate $record): QuestionnaireTemplate
    {
        $record->fill([
            'name' => (string) ($data['name'] ?? $record->name),
            'updated_by' => auth()->id(),
        ])->save();

        return $record;
    }

    protected static function formatVersionLabel(?QuestionnaireTemplateVersion $version): string
    {
        return $version instanceof QuestionnaireTemplateVersion ? 'v'.$version->version : 'Нет';
    }

    /**
     * @param  array<mixed, mixed>  $fieldsPayload
     */
    protected static function encodeFieldsPayload(array $fieldsPayload): string
    {
        if ($fieldsPayload === []) {
            return '[]';
        }

        $encoded = json_encode(
            $fieldsPayload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function decodeFieldsPayloadJson(string $fieldsPayloadJson): array
    {
        $trimmedPayload = trim($fieldsPayloadJson);

        if ($trimmedPayload === '') {
            throw ValidationException::withMessages([
                'fields_payload_json' => 'Нужно указать JSON полей анкеты.',
            ]);
        }

        try {
            $decodedPayload = json_decode($trimmedPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'fields_payload_json' => 'Поля анкеты должны быть валидным JSON.',
            ]);
        }

        if (! is_array($decodedPayload) || ! array_is_list($decodedPayload)) {
            throw ValidationException::withMessages([
                'fields_payload_json' => 'Поля анкеты должны быть JSON-массивом.',
            ]);
        }

        return $decodedPayload;
    }
}
