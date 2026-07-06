<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scenario;
use App\Models\Tag;
use App\Models\User;
use App\Services\Scenarios\BuildScenarioBuilderV3StateAction;
use App\Services\Scenarios\ExportScenarioBuilderV3AutoReplyWorkbookAction;
use App\Services\Scenarios\PublishScenarioBuilderV3Action;
use App\Services\Scenarios\SaveScenarioBuilderV3StateAction;
use App\Services\Scenarios\ScenarioBuilderV3AutoReplyImportPlanService;
use App\Services\Scenarios\ScenarioBuilderV3SheetTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScenarioBuilderV3StateController extends Controller
{
    public function show(
        Request $request,
        Scenario $scenario,
        BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
    ): JsonResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);

        return response()->json($buildScenarioBuilderV3StateAction->handle($scenario, $user));
    }

    public function update(
        Request $request,
        Scenario $scenario,
        SaveScenarioBuilderV3StateAction $saveScenarioBuilderV3StateAction,
    ): JsonResponse {
        $this->authorizeScenarioBuilderAccess($request, $scenario);

        return response()->json($saveScenarioBuilderV3StateAction->handle($scenario, $request->all()));
    }

    public function publish(
        Request $request,
        Scenario $scenario,
        PublishScenarioBuilderV3Action $publishScenarioBuilderV3Action,
        BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
    ): JsonResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);
        $draftVersionId = (int) ($request->integer('draft_version_id') ?: $request->integer('editable_version_id'));
        $baseRevision = trim((string) $request->input('base_revision', ''));
        $scheduledTransitionPolicy = trim((string) $request->input('scheduled_transition_policy', ''));

        if (
            $scheduledTransitionPolicy !== ''
            && ! in_array($scheduledTransitionPolicy, [
                PublishScenarioBuilderV3Action::SCHEDULED_TRANSITIONS_CANCEL,
                PublishScenarioBuilderV3Action::SCHEDULED_TRANSITIONS_KEEP,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'scheduled_transition_policy' => 'Неизвестное действие для запланированных переходов.',
            ]);
        }

        $scheduledTransitions = $publishScenarioBuilderV3Action->pendingScheduledTransitionsSummary($scenario);

        if ($scheduledTransitions['count'] > 0 && $scheduledTransitionPolicy === '') {
            return response()->json([
                'message' => 'Есть запланированные переходы. Выберите, сохранить их или отменить при публикации.',
                'code' => 'scheduled_transitions_pending',
                'warning' => [
                    'scheduled_transitions' => $scheduledTransitions,
                ],
            ], 409);
        }

        $result = $publishScenarioBuilderV3Action->handle(
            $scenario,
            $draftVersionId,
            $baseRevision,
            $user,
            $scheduledTransitionPolicy !== ''
                ? $scheduledTransitionPolicy
                : PublishScenarioBuilderV3Action::SCHEDULED_TRANSITIONS_KEEP,
        );
        $state = $buildScenarioBuilderV3StateAction->handle(
            $scenario->fresh(['draftVersion', 'publishedVersion']),
            $user,
        );
        $state['published'] = [
            'version_id' => (int) $result['published_version']->id,
            'version_number' => (int) $result['published_version']->version_number,
            'cancelled_scheduled_transitions' => (int) $result['cancelled_scheduled_transitions'],
        ];

        return response()->json($state);
    }

    public function exportSheet(
        Request $request,
        Scenario $scenario,
        ScenarioBuilderV3SheetTransferService $sheetTransferService,
    ): JsonResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);
        $document = $sheetTransferService->export(
            $scenario,
            $user,
            $request->query('sheet_id'),
        );
        $sheetId = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) data_get($document, 'sheet.source_sheet_id', 'main'));

        return response()
            ->json($document)
            ->withHeaders([
                'Content-Disposition' => sprintf('attachment; filename="scenario-%d-sheet-%s.json"', $scenario->id, $sheetId ?: 'main'),
            ]);
    }

    public function previewSheetImport(
        Request $request,
        Scenario $scenario,
        ScenarioBuilderV3SheetTransferService $sheetTransferService,
    ): JsonResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);

        return response()->json($sheetTransferService->preview($scenario, $user, $request->all()));
    }

    public function applySheetImport(
        Request $request,
        Scenario $scenario,
        ScenarioBuilderV3SheetTransferService $sheetTransferService,
    ): JsonResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);

        return response()->json($sheetTransferService->apply($scenario, $user, $request->all()));
    }

    public function previewAutoReplyImport(
        Request $request,
        Scenario $scenario,
        ScenarioBuilderV3AutoReplyImportPlanService $autoReplyImportPlanService,
    ): JsonResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);

        $request->validate([
            'workbook' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        $workbook = $request->file('workbook');

        if (! $workbook instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'workbook' => 'Нужно выбрать XLSX-файл.',
            ]);
        }

        return response()->json($autoReplyImportPlanService->preview(
            $scenario,
            $user,
            $workbook,
            $this->decodeAutoReplyImportPayload($request),
        ));
    }

    public function exportAutoReplies(
        Request $request,
        Scenario $scenario,
        ExportScenarioBuilderV3AutoReplyWorkbookAction $exportScenarioBuilderV3AutoReplyWorkbookAction,
    ): StreamedResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);
        $spreadsheet = $exportScenarioBuilderV3AutoReplyWorkbookAction->handle($scenario, $user);

        return response()->streamDownload(function () use ($spreadsheet): void {
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            }
        }, 'constructor-auto-replies-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function createAutoReplyImportTag(Request $request, Scenario $scenario): JsonResponse
    {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);

        abort_unless($user->hasRolePermission('tags.edit'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'in:'.implode(',', array_keys(Tag::colorOptions()))],
            'reactivate_existing' => ['sometimes', 'boolean'],
        ]);

        $name = trim((string) $validated['name']);
        $reactivateExisting = (bool) ($validated['reactivate_existing'] ?? false);
        $existingTag = Tag::query()
            ->where('name', $name)
            ->first();

        if ($existingTag instanceof Tag) {
            if (! $existingTag->isActive()) {
                if (! $reactivateExisting) {
                    throw ValidationException::withMessages([
                        'name' => 'Тег с таким названием уже есть, но он выключен. Подтвердите включение существующего тега.',
                    ]);
                }

                $existingTag->forceFill([
                    'is_active' => true,
                ])->save();
                $existingTag->refresh();
            }

            return response()->json([
                'tag' => $this->tagPayload($existingTag),
            ]);
        }

        $tag = Tag::query()->create([
            'name' => $name,
            'color' => (string) ($validated['color'] ?? Tag::COLOR_GRAY),
            'is_active' => true,
        ]);

        return response()->json([
            'tag' => $this->tagPayload($tag),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAutoReplyImportPayload(Request $request): array
    {
        $payload = [];

        foreach ([
            'builder_state',
            'channel_mappings',
            'tag_mappings',
            'excluded_row_numbers',
            'overwrite_conflict_row_numbers',
            'placement_mode',
            'import_batch_id',
        ] as $key) {
            $value = $request->input($key);

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $payload[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;

                continue;
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private function authorizeScenarioBuilderAccess(Request $request, Scenario $scenario): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->hasRolePermission('scenarios.edit'), 403);
        abort_unless($user->can('update', $scenario), 403);

        return $user;
    }

    /**
     * @return array{id: int, name: string, slug: string, color: string, is_active: bool}
     */
    private function tagPayload(Tag $tag): array
    {
        return [
            'id' => (int) $tag->id,
            'name' => (string) $tag->name,
            'slug' => (string) $tag->slug,
            'color' => (string) $tag->color,
            'is_active' => (bool) $tag->is_active,
        ];
    }
}
