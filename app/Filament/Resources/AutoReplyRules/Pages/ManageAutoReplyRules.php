<?php

namespace App\Filament\Resources\AutoReplyRules\Pages;

use App\Data\AutoReplyRules\AutoReplyRuleWorkbookPreviewData;
use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Models\AutoReplyRule;
use App\Services\AutoReplyRules\ApplyAutoReplyRulesWorkbookImportAction;
use App\Services\AutoReplyRules\ExportAutoReplyRulesWorkbookAction;
use App\Services\AutoReplyRules\ParseAutoReplyRulesWorkbookAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ManageAutoReplyRules extends ManageRecords
{
    protected static string $resource = AutoReplyRuleResource::class;

    public ?string $workbookImportPreviewToken = null;

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

    protected function getHeaderActions(): array
    {
        return [
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
            Action::make('importWorkbook')
                ->label('Импорт из XLSX')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalWidth(Width::Large)
                ->form([
                    FileUpload::make('workbook')
                        ->label('Файл XLSX')
                        ->disk('local')
                        ->directory('tmp/auto-reply-rule-imports')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/octet-stream',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $relativePath = (string) ($data['workbook'] ?? '');

                    if ($relativePath === '') {
                        return;
                    }

                    $this->clearWorkbookImportPreview();

                    $disk = Storage::disk('local');

                    try {
                        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($disk->path($relativePath));
                        $this->storeWorkbookImportPreview($preview);

                        $notification = Notification::make()
                            ->title('Предпросмотр импорта готов')
                            ->body($this->buildWorkbookImportSummary($preview));

                        if ($preview->hasErrors()) {
                            $notification->warning();
                        } else {
                            $notification->success();
                        }

                        $notification->send();
                    } catch (ValidationException $exception) {
                        $this->notifyWorkbookValidationFailure('Предпросмотр импорта не построен', $exception);

                        throw $exception;
                    } finally {
                        if ($disk->exists($relativePath)) {
                            $disk->delete($relativePath);
                        }
                    }
                }),
            Action::make('previewWorkbookImport')
                ->label('Предпросмотр импорта')
                ->icon(Heroicon::OutlinedEye)
                ->visible(fn (): bool => $this->hasWorkbookImportPreview())
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalHeading('Предпросмотр импорта XLSX')
                ->modalSubmitActionLabel('Закрыть')
                ->action(function (): void {})
                ->modalContent(fn (): View => view('filament.auto-reply-rules.import-preview', [
                    'preview' => $this->getWorkbookImportPreview(),
                ])),
            Action::make('applyWorkbookImport')
                ->label('Применить импорт')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('warning')
                ->visible(fn (): bool => $this->hasWorkbookImportPreview())
                ->disabled(fn (): bool => $this->workbookImportPreviewHasErrors())
                ->requiresConfirmation()
                ->modalHeading('Применить импорт XLSX')
                ->modalDescription(fn (): string => $this->buildWorkbookImportSummary($this->getWorkbookImportPreview()))
                ->action(function (): void {
                    $preview = $this->getWorkbookImportPreview();

                    if (! $preview instanceof AutoReplyRuleWorkbookPreviewData) {
                        return;
                    }

                    try {
                        app(ApplyAutoReplyRulesWorkbookImportAction::class)->handle($preview);
                    } catch (ValidationException $exception) {
                        $this->notifyWorkbookValidationFailure('Импорт не применён', $exception);

                        throw $exception;
                    }

                    $summary = $this->buildWorkbookImportSummary($preview);

                    $this->clearWorkbookImportPreview();

                    Notification::make()
                        ->title('Импорт применён')
                        ->body($summary)
                        ->success()
                        ->send();
                }),
            CreateAction::make()
                ->label('Добавить правило')
                ->modalWidth(Width::FiveExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->extraModalWindowAttributes([
                    'class' => 'ac-auto-reply-form-modal',
                    'style' => 'width: 90vw; max-width: 90vw;',
                ])
                ->using(function (array $data): AutoReplyRule {
                    try {
                        return AutoReplyRuleResource::saveAutoReplyRule($data);
                    } catch (\Illuminate\Validation\ValidationException $exception) {
                        AutoReplyRuleResource::notifyValidationFailure($exception);

                        throw $exception;
                    }
                })
                ->createAnother(false),
        ];
    }

    protected function hasWorkbookImportPreview(): bool
    {
        return $this->getWorkbookImportPreview() !== null;
    }

    protected function workbookImportPreviewHasErrors(): bool
    {
        return $this->getWorkbookImportPreview()?->hasErrors() ?? false;
    }

    protected function clearWorkbookImportPreview(): void
    {
        if ($this->workbookImportPreviewToken !== null) {
            Cache::forget($this->workbookImportPreviewToken);
        }

        $this->workbookImportPreviewToken = null;
    }

    protected function storeWorkbookImportPreview(AutoReplyRuleWorkbookPreviewData $preview): void
    {
        $this->clearWorkbookImportPreview();

        $token = $this->makeWorkbookImportPreviewCacheKey();

        Cache::put($token, $preview->toArray(), now()->addMinutes(30));

        $this->workbookImportPreviewToken = $token;
    }

    protected function getWorkbookImportPreview(): ?AutoReplyRuleWorkbookPreviewData
    {
        if ($this->workbookImportPreviewToken === null) {
            return null;
        }

        $payload = Cache::get($this->workbookImportPreviewToken);

        if (! is_array($payload)) {
            $this->workbookImportPreviewToken = null;

            return null;
        }

        return AutoReplyRuleWorkbookPreviewData::fromArray($payload);
    }

    protected function buildWorkbookImportSummary(?AutoReplyRuleWorkbookPreviewData $preview): string
    {
        if (! $preview instanceof AutoReplyRuleWorkbookPreviewData) {
            return 'Предпросмотр импорта ещё не подготовлен.';
        }

        return sprintf(
            'Создать: %d · Обновить: %d · Ошибок: %d',
            $preview->createCount(),
            $preview->updateCount(),
            $preview->errorCount(),
        );
    }

    protected function notifyWorkbookValidationFailure(string $title, ValidationException $exception): void
    {
        $message = collect($exception->errors())
            ->flatten()
            ->map(fn (mixed $value): string => is_string($value) ? trim($value) : '')
            ->first(fn (string $value): bool => $value !== '');

        Notification::make()
            ->title($title)
            ->body($message !== null && $message !== ''
                ? $message
                : 'Проверьте файл и попробуйте ещё раз.')
            ->danger()
            ->send();
    }

    protected function makeWorkbookImportPreviewCacheKey(): string
    {
        return 'auto-reply-rules-import-preview:'.Str::uuid()->toString();
    }
}
