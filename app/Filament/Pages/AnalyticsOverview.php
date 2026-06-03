<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Analytics\BuildAnalyticsOverviewAction;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

class AnalyticsOverview extends Page
{
    protected static ?string $slug = 'analytics-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Обзор';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Аналитика';

    protected string $view = 'filament.pages.analytics-overview';

    public string $periodPreset = '7_days';

    public ?string $periodFrom = null;

    public ?string $periodUntil = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->applyPresetDates($this->periodPreset);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canViewAnalytics();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function selectPeriod(string $preset): void
    {
        if (! in_array($preset, ['today', '7_days', '30_days'], true)) {
            return;
        }

        $this->periodPreset = $preset;
        $this->applyPresetDates($preset);
    }

    public function updatedPeriodFrom(): void
    {
        $this->periodPreset = 'custom';
    }

    public function updatedPeriodUntil(): void
    {
        $this->periodPreset = 'custom';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        [$periodStart, $periodEnd] = $this->resolvePeriodBounds();

        return [
            ...app(BuildAnalyticsOverviewAction::class)->handle($periodStart, $periodEnd),
            'period' => [
                'start' => $periodStart,
                'end' => $periodEnd,
                'label' => $this->formatPeriodLabel($periodStart, $periodEnd),
            ],
            'periodPreset' => $this->periodPreset,
        ];
    }

    private function applyPresetDates(string $preset): void
    {
        $today = now()->startOfDay();

        [$from, $until] = match ($preset) {
            'today' => [$today, $today],
            '30_days' => [$today->copy()->subDays(29), $today],
            default => [$today->copy()->subDays(6), $today],
        };

        $this->periodFrom = $from->toDateString();
        $this->periodUntil = $until->toDateString();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriodBounds(): array
    {
        $periodStart = $this->parseDate($this->periodFrom, now()->copy()->subDays(6))->startOfDay();
        $periodEnd = $this->parseDate($this->periodUntil, now())->endOfDay();

        if ($periodEnd->lt($periodStart)) {
            return [
                $periodEnd->copy()->startOfDay(),
                $periodStart->copy()->endOfDay(),
            ];
        }

        return [$periodStart, $periodEnd];
    }

    private function parseDate(?string $value, Carbon $fallback): Carbon
    {
        if (! filled($value)) {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function formatPeriodLabel(Carbon $periodStart, Carbon $periodEnd): string
    {
        return $periodStart->format('d.m.Y').' - '.$periodEnd->format('d.m.Y');
    }
}
