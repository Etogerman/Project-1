<?php

namespace App\Filament\Resources\AiRequests;

use App\Filament\Resources\AiRequests\Pages\ListAiRequests;
use App\Models\AiRequest;
use App\Models\AiTask;
use App\Models\Channel;
use App\Models\Scenario;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class AiRequestResource extends Resource
{
    protected static ?string $model = AiRequest::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $modelLabel = 'ИИ-запрос';

    protected static ?string $pluralModelLabel = 'ИИ-запросы';

    protected static ?string $navigationLabel = 'ИИ-запросы';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('ai_requests')
            && auth()->user()?->canViewAnalytics() === true;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ИИ-запрос')
                ->schema([
                    TextEntry::make('id')->label('ID')->copyable(),
                    TextEntry::make('task_key')->label('Задача'),
                    TextEntry::make('status')->label('Статус')->formatStateUsing(fn (?string $state): string => AiRequest::statusLabel($state))->badge(),
                    TextEntry::make('provider')->label('Провайдер')->placeholder('—'),
                    TextEntry::make('model')->label('Модель')->placeholder('—'),
                    TextEntry::make('total_tokens')->label('Токены')->placeholder('Токены неизвестны'),
                    TextEntry::make('estimated_cost')->label('Расчётная стоимость')->placeholder('Не рассчитана'),
                    TextEntry::make('currency')->label('Валюта')->placeholder('—'),
                    TextEntry::make('cost_status')->label('Стоимость')->formatStateUsing(fn (?string $state): string => AiRequest::costStatusLabel($state))->badge(),
                ])
                ->columns(2),
            Section::make('Промпт и ответ')
                ->visible(fn (): bool => auth()->user()?->canDebugAnalytics() === true)
                ->schema([
                    TextEntry::make('system_prompt_preview')->label('System preview')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('user_prompt_preview')->label('User preview')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('response_preview')->label('Response preview')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('request_body_raw')->label('Raw request body')->placeholder('Raw body очищен')->columnSpanFull(),
                    TextEntry::make('response_body_raw')->label('Raw response body')->placeholder('Raw body очищен')->columnSpanFull(),
                    TextEntry::make('provider_reported_cost')->label('Стоимость провайдера')->placeholder('—'),
                    TextEntry::make('provider_reported_currency')->label('Валюта провайдера')->placeholder('—'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                TextColumn::make('task.name')
                    ->label('Задача')
                    ->placeholder(fn (AiRequest $record): string => $record->task_key)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state): string => AiRequest::statusLabel($state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('provider')
                    ->label('Провайдер')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('model')
                    ->label('Модель')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('attempts_count')
                    ->label('Попыток')
                    ->counts('attempts')
                    ->sortable(),
                TextColumn::make('total_tokens')
                    ->label('Токены')
                    ->placeholder('Токены неизвестны')
                    ->sortable(),
                TextColumn::make('estimated_cost')
                    ->label('Расчётная стоимость')
                    ->placeholder('Не рассчитана')
                    ->money('USD', divideBy: 1)
                    ->sortable(),
                TextColumn::make('cost_status')
                    ->label('Стоимость')
                    ->formatStateUsing(fn (?string $state): string => AiRequest::costStatusLabel($state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('latency_ms')
                    ->label('Время')
                    ->suffix(' мс')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('task_key')
                    ->label('Задача')
                    ->options(fn (): array => AiTask::query()->orderBy('name')->pluck('name', 'key')->all()),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        AiRequest::STATUS_SUCCESS => 'Успешно',
                        AiRequest::STATUS_ERROR => 'Ошибка',
                    ]),
                SelectFilter::make('channel_id')
                    ->label('Канал')
                    ->options(fn (): array => Channel::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('scenario_id')
                    ->label('Сценарий')
                    ->options(fn (): array => Scenario::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('period')
                    ->label('Период')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('Открыть')
                    ->modalWidth(Width::FiveExtraLarge),
            ])
            ->toolbarActions([
                BulkAction::make('clearRawBodies')
                    ->label('Удалить raw-данные')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => auth()->user()?->canCleanupAiRequestRawBodies() === true)
                    ->action(function (Collection $records): void {
                        $records->each(fn (AiRequest $record): bool => $record
                            ->forceFill([
                                'request_body_raw' => null,
                                'response_body_raw' => null,
                                'raw_body_truncated' => false,
                            ])
                            ->save());
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiRequests::route('/'),
        ];
    }
}
