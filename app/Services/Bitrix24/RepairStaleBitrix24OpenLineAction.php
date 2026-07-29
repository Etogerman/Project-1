<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24SyncLog;
use App\Models\Dialog;

class RepairStaleBitrix24OpenLineAction
{
    public const COMPLETED_OPERATION = 'openlines_stale_chat_repair_completed';

    public const FAILED_OPERATION = 'openlines_stale_chat_repair_failed';

    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
        private readonly LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
        private readonly RunBitrix24OpenLineMutationWithAuthorityAction $runWithAuthority,
        private readonly Bitrix24OpenLineRouteMutationFence $mutationFence,
    ) {}

    /**
     * @return array{source_chat_id:string,current_chat_id:string,reset_dialog_count:int}
     */
    public function handle(Bitrix24Connection $connection, Bitrix24OpenLineRoute $route, Bitrix24SyncLog $staleLog): array
    {
        $routeData = new Bitrix24OpenLinesRouteData(
            platform: (string) $route->channel?->platform,
            connectorCode: (string) $route->connector_code,
            lineId: (string) $route->line_id,
            routeId: (int) $route->getKey(),
            status: (string) $route->status,
            channelType: (string) $route->channel_type,
        );

        return $this->runWithAuthority->handle(
            $routeData,
            'repair_stale_open_line',
            fn (Bitrix24OpenLineMutationAuthority $authority): array => $this->repairUnderAuthority(
                $connection,
                (int) $route->getKey(),
                (int) $staleLog->getKey(),
                $authority,
            ),
            requireUsable: false,
        );
    }

    /**
     * @return array{source_chat_id:string,current_chat_id:string,reset_dialog_count:int}
     */
    private function repairUnderAuthority(
        Bitrix24Connection $connection,
        int $routeId,
        int $staleLogId,
        Bitrix24OpenLineMutationAuthority $authority,
    ): array {
        $route = Bitrix24OpenLineRoute::query()->find($routeId);
        $staleLog = Bitrix24SyncLog::query()->find($staleLogId);

        if (! $route instanceof Bitrix24OpenLineRoute || ! $staleLog instanceof Bitrix24SyncLog) {
            throw new Bitrix24OpenLineRepairException('Маршрут или диагностика старой ОЛ уже изменены.');
        }

        $payload = is_array($staleLog->request_payload) ? $staleLog->request_payload : [];
        $rawSourceChatId = data_get($payload, 'source_bitrix_chat_id');
        $rawCurrentChatId = data_get($payload, 'current_bitrix_chat_id');
        $rawConnectorCode = data_get($payload, 'connector_code');
        $rawLineId = data_get($payload, 'line_id');
        $sourceChatId = is_scalar($rawSourceChatId) ? trim((string) $rawSourceChatId) : '';
        $currentChatId = is_scalar($rawCurrentChatId) ? trim((string) $rawCurrentChatId) : '';
        $connectorCode = is_scalar($rawConnectorCode) ? trim((string) $rawConnectorCode) : '';
        $lineId = is_scalar($rawLineId) ? (string) $rawLineId : '';

        if ($sourceChatId === '') {
            throw new Bitrix24OpenLineRepairException('В диагностике старой ОЛ нет Bitrix chat id.');
        }

        if ($currentChatId !== '' && $sourceChatId === $currentChatId) {
            throw new Bitrix24OpenLineRepairException('Старая и текущая ОЛ совпадают. Закрытие не требуется.');
        }

        if ($connectorCode !== $authority->connectorCode
            || ! Bitrix24OpenLineRoute::isValidLineId($lineId)
            || $lineId !== $authority->lineId
            || (string) $route->connector_code !== $authority->connectorCode
            || (string) $route->line_id !== $authority->lineId
        ) {
            throw new Bitrix24OpenLineRepairException('Диагностика относится к другому маршруту открытой линии.');
        }

        try {
            $response = $this->apiClient->call(
                'imopenlines.operator.another.finish',
                [
                    'CHAT_ID' => $sourceChatId,
                ],
                $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            $message = $exception->getMessage() ?: 'Bitrix24 REST call failed.';
            $this->logRepairOutcome(
                $connection,
                $route,
                $staleLog,
                $payload,
                null,
                Bitrix24SyncLog::STATUS_FAILED,
                0,
                $message,
                'transport_uncertain',
            );

            throw new Bitrix24OpenLineRepairException('Не удалось закрыть старую ОЛ: '.$message, previous: $exception);
        }

        if (! $this->isSuccessfulFinishResponse($response)) {
            $message = $response->errorMessage ?: 'Bitrix24 не подтвердил закрытие старой ОЛ.';
            $this->logRepairOutcome(
                $connection,
                $route,
                $staleLog,
                $payload,
                $response,
                Bitrix24SyncLog::STATUS_FAILED,
                0,
                $message,
            );

            throw new Bitrix24OpenLineRepairException('Не удалось закрыть старую ОЛ: '.$message);
        }

        $resetDialogCount = $this->mutationFence->runMutation(
            $authority,
            function () use ($route, $sourceChatId): int {
                $staleDialogIds = $route->dialogs()
                    ->select(['id', 'bitrix24_open_line_user_code_override', 'bitrix24_open_line_resolved_chat_id_override', 'bitrix24_open_line_binding_verified_at'])
                    ->whereNotNull('bitrix24_open_line_binding_verified_at')
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (Dialog $dialog): bool => $this->isDialogBindingStale($dialog, $route, $sourceChatId))
                    ->pluck('id')
                    ->all();

                if ($staleDialogIds !== []) {
                    Dialog::query()
                        ->whereKey($staleDialogIds)
                        ->update([
                            'bitrix24_open_line_user_code_override' => null,
                            'bitrix24_open_line_resolved_chat_id_override' => null,
                            'bitrix24_open_line_binding_verified_at' => null,
                        ]);
                }

                return count($staleDialogIds);
            },
        );
        $this->logRepairOutcome(
            $connection,
            $route,
            $staleLog,
            $payload,
            $response,
            Bitrix24SyncLog::STATUS_SUCCESS,
            $resetDialogCount,
        );

        return [
            'source_chat_id' => $sourceChatId,
            'current_chat_id' => $currentChatId,
            'reset_dialog_count' => $resetDialogCount,
        ];
    }

    private function isSuccessfulFinishResponse(Bitrix24RestResponseData $response): bool
    {
        if (! $response->successful) {
            return false;
        }

        if (is_bool($response->result)) {
            return $response->result;
        }

        if (is_scalar($response->result)) {
            $value = trim((string) $response->result);

            return $value !== '' && $value !== '0';
        }

        return false;
    }

    private function isDialogBindingStale(Dialog $dialog, Bitrix24OpenLineRoute $route, string $sourceChatId): bool
    {
        if (
            $sourceChatId !== ''
            && trim((string) $dialog->bitrix24_open_line_resolved_chat_id_override) === $sourceChatId
        ) {
            return true;
        }

        $parsed = $this->resolveDialogBindingAction->parseUserCode($dialog->bitrix24_open_line_user_code_override);

        if ($parsed === null) {
            return false;
        }

        return $parsed->connectorCode !== (string) $route->connector_code
            || $parsed->lineId !== (string) $route->line_id;
    }

    /**
     * @param  array<string, mixed>  $stalePayload
     */
    private function logRepairOutcome(
        Bitrix24Connection $connection,
        Bitrix24OpenLineRoute $route,
        Bitrix24SyncLog $staleLog,
        array $stalePayload,
        ?Bitrix24RestResponseData $response,
        string $status,
        int $resetDialogCount,
        ?string $errorMessage = null,
        ?string $errorCode = null,
    ): void {
        $operation = $status === Bitrix24SyncLog::STATUS_SUCCESS
            ? self::COMPLETED_OPERATION
            : self::FAILED_OPERATION;

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: $operation,
            status: $status,
            requestPayload: [
                'route_id' => $route->id,
                'channel_id' => $route->channel_id,
                'connector_code' => $route->connector_code,
                'line_id' => $route->line_id,
                'stale_log_id' => $staleLog->id,
                'source_bitrix_chat_id' => data_get($stalePayload, 'source_bitrix_chat_id'),
                'current_bitrix_chat_id' => data_get($stalePayload, 'current_bitrix_chat_id'),
                'dialog_id' => data_get($stalePayload, 'dialog_id'),
                'bitrix_message_id' => data_get($stalePayload, 'bitrix_message_id'),
                'reset_dialog_count' => $resetDialogCount,
            ],
            responsePayload: [
                'rest_method' => $response?->restMethod ?? 'imopenlines.operator.another.finish',
                'result' => $response?->result,
            ],
            connection: $connection,
            httpStatus: $response?->httpStatus,
            errorCode: $errorCode ?? $response?->errorCode,
            errorMessage: $errorMessage,
            entityType: 'open_line_route',
            entityId: (string) $route->id,
        );
    }
}
