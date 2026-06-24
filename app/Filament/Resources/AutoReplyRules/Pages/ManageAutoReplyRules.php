<?php

namespace App\Filament\Resources\AutoReplyRules\Pages;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Filament\Resources\Pages\ManageRecords;
use App\Services\AutoReplyRules\ExportAutoReplyRulesWorkbookAction;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ManageAutoReplyRules extends ManageRecords
{
    protected static string $resource = AutoReplyRuleResource::class;

    public function mount(): void
    {
        parent::mount();

        $tagId = request()->integer('tag');

        if ($tagId <= 0) {
            return;
        }

        $this->tableFilters ??= [];
        $this->tableFilters['tag'] = [
            'value' => (string) $tagId,
        ];
    }

    public function getHeading(): string
    {
        return 'Архив старых автоответов';
    }

    public function getSubheading(): ?string
    {
        return 'Не используется после перехода на V3-конструктор. Действующие автоответы настраиваются во вкладке «Автоответчик» конструктора.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openV3AutoReplyConstructor')
                ->label('Открыть V3 автоответчик')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('success')
                ->url(url('/admin/constructor?builder_view=auto_reply')),
            Action::make('exportWorkbook')
                ->label('Экспорт в XLSX')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    $spreadsheet = app(ExportAutoReplyRulesWorkbookAction::class)->handle();

                    return response()->streamDownload(function () use ($spreadsheet): void {
                        try {
                            (new Xlsx($spreadsheet))->save('php://output');
                        } finally {
                            $spreadsheet->disconnectWorksheets();
                            unset($spreadsheet);
                        }
                    }, 'auto-reply-rules-'.now()->format('Ymd-His').'.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
        ];
    }
}
