<?php

namespace App\Filament\Resources\AiTasks;

use App\Filament\Resources\AiTasks\Pages\ManageAiTasks;
use App\Models\AiTask;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class AiTaskResource extends Resource
{
    protected static ?string $model = AiTask::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'ИИ-задача';

    protected static ?string $pluralModelLabel = 'ИИ-задачи';

    protected static ?string $navigationLabel = 'ИИ-задачи';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('ai_tasks')
            && auth()->user()?->canDebugAnalytics() === true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ИИ-задача')
                ->schema([
                    TextInput::make('key')
                        ->label('Ключ')
                        ->helperText('Технический ключ, например name_resolution.')
                        ->required()
                        ->maxLength(64)
                        ->regex('/^[a-z0-9_]+$/')
                        ->unique(ignoreRecord: true),
                    TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('is_active')
                        ->label('Активна')
                        ->default(true)
                        ->inline(false),
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
                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('ai_requests_count')
                    ->label('Запросов')
                    ->counts('aiRequests')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('key')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить')
                    ->modalWidth(Width::Large)
                    ->modalFooterActionsAlignment(Alignment::End),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->tooltip('Удалить')
                    ->hidden(fn (AiTask $record): bool => $record->aiRequests()->exists()),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiTasks::route('/'),
        ];
    }
}
