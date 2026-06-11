<?php

namespace App\Filament\Resources\ContactFirstNameResolutionEvents\Widgets;

use App\Models\ContactFirstNameResolutionEvent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ContactFirstNameResolutionEventStats extends StatsOverviewWidget
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = [];

    protected ?string $heading = 'Сводка по текущим фильтрам';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $query = $this->filteredQuery();

        $total = (clone $query)->count();
        $failed = (clone $query)->failed()->count();
        $dictionaryMatched = (clone $query)
            ->where('source', ContactFirstNameResolutionEvent::SOURCE_DICTIONARY)
            ->where('result', ContactFirstNameResolutionEvent::RESULT_MATCHED)
            ->count();
        $aiAccepted = (clone $query)
            ->where('source', ContactFirstNameResolutionEvent::SOURCE_AI)
            ->where('result', ContactFirstNameResolutionEvent::RESULT_ACCEPTED)
            ->count();
        $written = (clone $query)
            ->where('event_type', ContactFirstNameResolutionEvent::EVENT_TYPE_NAME_WRITTEN)
            ->count();

        return [
            Stat::make('Всего событий', number_format($total, 0, '.', ' ')),
            Stat::make('Неуспешные', number_format($failed, 0, '.', ' ')),
            Stat::make('Справочник нашёл', number_format($dictionaryMatched, 0, '.', ' ')),
            Stat::make('ИИ принял', number_format($aiAccepted, 0, '.', ' ')),
            Stat::make('Имён записано', number_format($written, 0, '.', ' ')),
        ];
    }

    private function filteredQuery(): Builder
    {
        $filters = $this->tableFilters ?? [];

        return ContactFirstNameResolutionEvent::query()
            ->when($this->filterValue($filters, 'event_type'), fn (Builder $query, string $value): Builder => $query->where('event_type', $value))
            ->when($this->filterValue($filters, 'source'), fn (Builder $query, string $value): Builder => $query->where('source', $value))
            ->when($this->filterValue($filters, 'result'), fn (Builder $query, string $value): Builder => $query->where('result', $value))
            ->when($this->filterValue($filters, 'channel_id'), fn (Builder $query, string $value): Builder => $query->where('channel_id', $value))
            ->when($this->filterValue($filters, 'scenario_id'), fn (Builder $query, string $value): Builder => $query->where('scenario_id', $value))
            ->when((bool) data_get($filters, 'failed.isActive'), fn (Builder $query): Builder => $query->failed())
            ->when(data_get($filters, 'period.from'), fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
            ->when(data_get($filters, 'period.until'), fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filterValue(array $filters, string $key): ?string
    {
        $value = data_get($filters, "{$key}.value");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
