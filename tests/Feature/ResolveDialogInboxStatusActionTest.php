<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolveDialogInboxStatusActionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_external_account_outgoing_after_inbound_counts_as_dialog_answer(): void
    {
        $identity = ContactIdentity::factory()->create();
        $dialog = Dialog::factory()->create([
            'contact_id' => $identity->contact_id,
            'channel_id' => $identity->channel_id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700001',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '700001',
            'external_message_id' => '900001',
            'received_at' => '2026-05-26 10:00:00',
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_EXTERNAL_ACCOUNT,
            'external_chat_id' => '700001',
            'external_message_id' => '910001',
            'received_at' => '2026-05-26 10:05:00',
        ]);

        $status = app(ResolveDialogInboxStatusAction::class)->handle($dialog);

        $this->assertSame(DialogInboxStatusData::CODE_NO_NEW, $status->code);
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
