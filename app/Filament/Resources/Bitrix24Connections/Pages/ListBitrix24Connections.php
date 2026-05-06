<?php

namespace App\Filament\Resources\Bitrix24Connections\Pages;

use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use App\Filament\Resources\Pages\ListRecords;
use App\Models\Bitrix24Connection;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListBitrix24Connections extends ListRecords
{
    protected static string $resource = Bitrix24ConnectionResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Настройки Bitrix24';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Настройки Bitrix24';
    }

    public function getSubheading(): ?string
    {
        return 'Подключение, маршруты открытых линий, callback-и и sync-логи.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openCurrentSettings')
                ->label('Открыть настройки')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->visible(fn (): bool => Bitrix24Connection::query()->exists())
                ->url(fn (): string => Bitrix24ConnectionResource::getUrl('view', [
                    'record' => $this->resolveCurrentConnectionKey(),
                ])),
            Action::make('connectBitrix24')
                ->label(fn (): string => Bitrix24Connection::query()->exists()
                    ? 'Переподключить Bitrix24'
                    : 'Подключить Bitrix24')
                ->icon(Heroicon::OutlinedLink)
                ->color('success')
                ->visible(fn (): bool => (bool) auth()->user()?->isSuperadmin())
                ->url(route('admin.bitrix24.oauth.start')),
        ];
    }

    private function resolveCurrentConnectionKey(): int|string
    {
        return Bitrix24Connection::query()
            ->where('status', Bitrix24Connection::STATUS_ACTIVE)
            ->latest('id')
            ->value('id')
            ?? Bitrix24Connection::query()
                ->latest('id')
                ->value('id')
            ?? 0;
    }
}
