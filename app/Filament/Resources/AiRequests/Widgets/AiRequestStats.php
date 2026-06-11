<?php

namespace App\Filament\Resources\AiRequests\Widgets;

use App\Models\AiRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class AiRequestStats extends StatsOverviewWidget
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
        $success = (clone $query)->where('status', AiRequest::STATUS_SUCCESS)->count();
        $errors = (clone $query)->where('status', AiRequest::STATUS_ERROR)->count();
        $tokens = (clone $query)->whereNotNull('total_tokens')->sum('total_tokens');
        $cost = (clone $query)->whereNotNull('estimated_cost')->sum('estimated_cost');

        return [
            Stat::make('Всего запросов', number_format($total, 0, '.', ' ')),
            Stat::make('Успешно / ошибок', number_format($success, 0, '.', ' ').' / '.number_format($errors, 0, '.', ' ')),
            Stat::make('Токены', $tokens > 0 ? number_format((int) $tokens, 0, '.', ' ') : 'Токены неизвестны'),
            Stat::make('Расчётная стоимость', $cost > 0 ? '$'.number_format((float) $cost, 8, '.', ' ') : 'Не рассчитана'),
        ];
    }

    private function filteredQuery(): Builder
    {
        $filters = $this->tableFilters ?? [];

        return AiRequest::query()
            ->when($this->filterValue($filters, 'task_key'), fn (Builder $query, string $value): Builder => $query->where('task_key', $value))
            ->when($this->filterValue($filters, 'status'), fn (Builder $query, string $value): Builder => $query->where('status', $value))
            ->when($this->filterValue($filters, 'channel_id'), fn (Builder $query, string $value): Builder => $query->where('channel_id', $value))
            ->when($this->filterValue($filters, 'scenario_id'), fn (Builder $query, string $value): Builder => $query->where('scenario_id', $value))
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
