<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Models\Dialog;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolveDialogInboxStatusActionTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('projectedInboxStatusProvider')]
    public function test_handle_uses_projected_message_attributes_without_querying_messages(
        array $attributes,
        string $expectedCode,
    ): void {
        $dialog = new Dialog;
        $dialog->setRawAttributes($attributes, true);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $status = app(ResolveDialogInboxStatusAction::class)->handle($dialog);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($expectedCode, $status->code);
        $this->assertSame([], $queries);
    }

    /**
     * @return array<string, array{attributes: array<string, mixed>, expectedCode: string}>
     */
    public static function projectedInboxStatusProvider(): array
    {
        return [
            'no inbound user message' => [
                'attributes' => self::projectedAttributes(
                    latestInboundUserMessageId: null,
                    latestInboundUserMessageSortAt: null,
                    latestOutboundManualReplyMessageId: null,
                    latestOutboundManualReplyMessageSortAt: null,
                ),
                'expectedCode' => DialogInboxStatusData::CODE_NO_NEW,
            ],
            'inbound without manual reply' => [
                'attributes' => self::projectedAttributes(
                    latestInboundUserMessageId: 10,
                    latestInboundUserMessageSortAt: '2026-05-26 10:00:00',
                    latestOutboundManualReplyMessageId: null,
                    latestOutboundManualReplyMessageSortAt: null,
                ),
                'expectedCode' => DialogInboxStatusData::CODE_REQUIRES_REPLY,
            ],
            'inbound after manual reply' => [
                'attributes' => self::projectedAttributes(
                    latestInboundUserMessageId: 12,
                    latestInboundUserMessageSortAt: '2026-05-26 10:05:00',
                    latestOutboundManualReplyMessageId: 11,
                    latestOutboundManualReplyMessageSortAt: '2026-05-26 10:00:00',
                ),
                'expectedCode' => DialogInboxStatusData::CODE_REQUIRES_REPLY,
            ],
            'manual reply after inbound' => [
                'attributes' => self::projectedAttributes(
                    latestInboundUserMessageId: 12,
                    latestInboundUserMessageSortAt: '2026-05-26 10:00:00',
                    latestOutboundManualReplyMessageId: 13,
                    latestOutboundManualReplyMessageSortAt: '2026-05-26 10:05:00',
                ),
                'expectedCode' => DialogInboxStatusData::CODE_NO_NEW,
            ],
            'latest inbound dismissed' => [
                'attributes' => self::projectedAttributes(
                    latestInboundUserMessageId: 12,
                    latestInboundUserMessageSortAt: '2026-05-26 10:05:00',
                    latestOutboundManualReplyMessageId: 11,
                    latestOutboundManualReplyMessageSortAt: '2026-05-26 10:00:00',
                    manualReplyDismissedSourceMessageId: 12,
                ),
                'expectedCode' => DialogInboxStatusData::CODE_NOT_REQUIRED,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function projectedAttributes(
        mixed $latestInboundUserMessageId,
        mixed $latestInboundUserMessageSortAt,
        mixed $latestOutboundManualReplyMessageId,
        mixed $latestOutboundManualReplyMessageSortAt,
        mixed $manualReplyDismissedSourceMessageId = null,
    ): array {
        return [
            'manual_reply_dismissed_source_message_id' => $manualReplyDismissedSourceMessageId,
            'latest_inbound_user_message_id' => $latestInboundUserMessageId,
            'latest_inbound_user_message_sort_at' => $latestInboundUserMessageSortAt,
            'latest_outbound_manual_reply_message_id' => $latestOutboundManualReplyMessageId,
            'latest_outbound_manual_reply_message_sort_at' => $latestOutboundManualReplyMessageSortAt,
        ];
    }
}
