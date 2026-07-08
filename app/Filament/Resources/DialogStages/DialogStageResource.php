<?php

namespace App\Filament\Resources\DialogStages;

use App\Filament\Forms\Components\ColorPicker;
use App\Filament\Resources\DialogStages\Pages\ManageDialogStages;
use App\Filament\Tables\Columns\ColorBadgeColumn;
use App\Models\DialogStage;
use App\Services\Dialogs\DeleteDialogStageWithReplacementAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
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
use UnitEnum;

class DialogStageResource extends Resource
{
    protected static ?string $model = DialogStage::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Стадия диалога';

    protected static ?string $pluralModelLabel = 'Стадии диалогов';

    protected static ?string $navigationLabel = 'Стадии диалогов';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationParentItem = 'Справочники';

    protected static ?int $navigationSort = 18;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('dialog_stages');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('dialogs')
            ->ordered();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Стадия')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('key')
                            ->label('Код')
                            ->helperText('Код создаётся автоматически и дальше не меняется.')
                            ->rules(['nullable', 'regex:/^[a-z][a-z0-9_]*$/'])
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->hidden(fn (?DialogStage $record): bool => ! ($record?->exists ?? false))
                            ->disabled(fn (?DialogStage $record): bool => $record?->exists ?? false)
                            ->dehydrated(fn (?DialogStage $record): bool => $record?->exists ?? false),
                        ColorPicker::make('color')
                            ->label('Цвет')
                            ->default(DialogStage::COLOR_GRAY)
                            ->required(),
                        TextInput::make('sort_order')
                            ->label('Порядок')
                            ->numeric()
                            ->minValue(0)
                            ->default(100)
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('key')
                    ->label('Код')
                    ->searchable()
                    ->copyable()
                    ->sortable()
                    ->toggleable(),
                ColorBadgeColumn::make('color')
                    ->label('Цвет')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('system_role')
                    ->label('Тип')
                    ->state(fn (DialogStage $record): string => $record->typeLabel())
                    ->badge()
                    ->color(fn (DialogStage $record): string => $record->isSystemDerivedStage() ? 'info' : 'gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('dialogs_count')
                    ->label('Диалоги')
                    ->badge()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActionsColumnLabel('Кнопки')
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerWidth(Width::Medium)
            ->defaultSort('sort_order')
            ->emptyStateHeading('Стадии ещё не добавлены')
            ->emptyStateDescription('Добавьте стадии для воронки диалогов.')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить стадию')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End),
                Action::make('delete')
                    ->label('Удалить')
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->tooltip(fn (DialogStage $record): string => $record->isSystemDerivedStage()
                        ? 'Автоматическую системную стадию нельзя удалить'
                        : 'Удалить стадию')
                    ->visible(fn (DialogStage $record): bool => ! $record->isSystemDerivedStage())
                    ->requiresConfirmation()
                    ->modalHeading(fn (DialogStage $record): string => "Удалить стадию «{$record->name}»?")
                    ->modalDescription(fn (DialogStage $record): string => static::deleteModalDescription($record))
                    ->modalSubmitActionLabel('Удалить')
                    ->form([
                        Placeholder::make('delete_summary')
                            ->hiddenLabel()
                            ->content(fn (DialogStage $record): string => static::deleteTransferSummary($record)),
                        Select::make('replacement_stage_id')
                            ->label('Куда перенести диалоги и ссылки сценариев')
                            ->options(fn (DialogStage $record): array => DialogStage::query()
                                ->whereKeyNot($record->getKey())
                                ->ordered()
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->searchable()
                            ->native(false),
                        Checkbox::make('confirm_scenario_reference_transfer')
                            ->label('Подтверждаю перенос ссылок сценариев на выбранную стадию')
                            ->helperText('Сценарии продолжат запускаться от стадии-замены.')
                            ->accepted(fn (DialogStage $record): bool => static::scenarioReferenceCount($record) > 0)
                            ->required(fn (DialogStage $record): bool => static::scenarioReferenceCount($record) > 0)
                            ->visible(fn (DialogStage $record): bool => static::scenarioReferenceCount($record) > 0)
                            ->dehydrated(false),
                    ])
                    ->action(function (DialogStage $record, array $data): void {
                        $result = app(DeleteDialogStageWithReplacementAction::class)->handle(
                            $record,
                            (int) $data['replacement_stage_id'],
                        );

                        Notification::make()
                            ->success()
                            ->title('Стадия удалена')
                            ->body("Перенесено: диалогов — {$result['dialogs']}, ссылок сценариев — {$result['scenario_references']}.")
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDialogStages::route('/'),
        ];
    }

    private static function deleteModalDescription(DialogStage $record): string
    {
        return static::scenarioReferenceCount($record) > 0
            ? 'Перед удалением выберите стадию-замену и подтвердите перенос ссылок сценариев. История старых переходов не переписывается.'
            : 'Перед удалением выберите стадию, в которую будут перенесены диалоги. История старых переходов не переписывается.';
    }

    private static function deleteTransferSummary(DialogStage $record): string
    {
        $dialogsCount = (int) ($record->dialogs_count ?? $record->dialogs()->count());
        $scenarioReferenceCount = static::scenarioReferenceCount($record);

        return "Будет перенесено: диалогов — {$dialogsCount}, ссылок сценариев — {$scenarioReferenceCount}.";
    }

    private static function scenarioReferenceCount(DialogStage $record): int
    {
        $cacheKey = 'dialog_stage_scenario_reference_count.'.$record->getKey();
        $request = request();

        if (! $request->attributes->has($cacheKey)) {
            $request->attributes->set(
                $cacheKey,
                app(DeleteDialogStageWithReplacementAction::class)->countScenarioReferences($record),
            );
        }

        return (int) $request->attributes->get($cacheKey);
    }
}
