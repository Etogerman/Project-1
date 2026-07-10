<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Analytics\BuildAnalyticsOverviewAction;
use App\Services\Dialogs\BuildDialogNotificationStateAction;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use App\Services\Dialogs\UpdateDialogInboxStatusAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DialogBlockedByUserInboxBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_dialog_is_not_required_across_filters_notifications_and_analytics(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $now = Carbon::parse('2026-07-10 12:00:00');
        $normalDialog = $this->createDialogWithInbound($admin, $now->copy()->subHours(2));
        $blockedDialog = $this->createDialogWithInbound(
            $admin,
            $now->copy()->subHours(3),
            blocked: true,
        );

        $this->assertSame(
            DialogInboxStatusData::CODE_REQUIRES_REPLY,
            app(ResolveDialogInboxStatusAction::class)->handle($normalDialog)->code,
        );
        $this->assertSame(
            DialogInboxStatusData::CODE_NOT_REQUIRED,
            app(ResolveDialogInboxStatusAction::class)->handle($blockedDialog)->code,
        );

        $requiresReplyQuery = Dialog::query();
        DialogResource::applyInboxStatusFilter(
            $requiresReplyQuery,
            DialogInboxStatusData::CODE_REQUIRES_REPLY,
        );

        $this->assertSame([$normalDialog->id], $requiresReplyQuery->pluck('dialogs.id')->all());

        $notRequiredQuery = Dialog::query();
        DialogResource::applyInboxStatusFilter(
            $notRequiredQuery,
            DialogInboxStatusData::CODE_NOT_REQUIRED,
        );

        $this->assertSame([$blockedDialog->id], $notRequiredQuery->pluck('dialogs.id')->all());

        $noNewQuery = Dialog::query();
        DialogResource::applyInboxStatusFilter($noNewQuery, DialogInboxStatusData::CODE_NO_NEW);

        $this->assertSame([], $noNewQuery->pluck('dialogs.id')->all());

        $notificationState = app(BuildDialogNotificationStateAction::class)->handle($admin);

        $this->assertSame(1, $notificationState['count']);
        $this->assertSame($normalDialog->id, $notificationState['items'][0]['dialog_id']);

        $overview = app(BuildAnalyticsOverviewAction::class)->handle(
            $now->copy()->subDay(),
            $now,
            $now,
        );
        $snapshotMetrics = collect($overview['snapshotMetrics'])->keyBy('key');
        $problemDialogs = collect($overview['problemDialogs'])->keyBy('id');

        $this->assertSame(1, $snapshotMetrics['requires_reply']['value']);
        $this->assertSame(1, $snapshotMetrics['requires_reply_overdue']['value']);
        $this->assertSame(1, $snapshotMetrics['blocked_now']['value']);
        $this->assertTrue($problemDialogs->has($normalDialog->id));
        $this->assertTrue($problemDialogs->has($blockedDialog->id));
        $this->assertSame(['Бот заблокирован'], $problemDialogs[$blockedDialog->id]['reasons']);
    }

    public function test_requires_reply_cannot_be_forced_while_blocked_and_returns_after_unblock(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithInbound($admin, now(), blocked: true);

        try {
            app(UpdateDialogInboxStatusAction::class)->handle(
                $dialog,
                $admin,
                DialogInboxStatusData::CODE_REQUIRES_REPLY,
            );

            $this->fail('Blocked dialog accepted requires_reply status.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Клиент заблокировал бота. Статус «Требует ответа» станет доступен после разблокировки.'],
                $exception->errors()['dialogInboxStatusSelection'],
            );
        }

        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
        ]);

        $dialog->forceFill([
            'bot_subscription_status' => null,
            'bot_subscription_changed_at' => now()->addSecond(),
        ])->save();

        $this->assertSame(
            DialogInboxStatusData::CODE_REQUIRES_REPLY,
            app(ResolveDialogInboxStatusAction::class)->handle($dialog->fresh())->code,
        );
    }

    private function createDialogWithInbound(
        User $assignee,
        Carbon $receivedAt,
        bool $blocked = false,
    ): Dialog {
        $channel = Channel::factory()->create([
            'name' => 'Telegram',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $assignee->id,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'blocked-inbox-'.fake()->unique()->numerify('###'),
            'bot_subscription_status' => $blocked
                ? Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER
                : null,
            'bot_subscription_changed_at' => $blocked ? $receivedAt->copy()->addMinute() : null,
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $receivedAt,
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => $blocked ? 'Вопрос до блокировки' : 'Обычный вопрос',
            'received_at' => $receivedAt,
        ]);

        $dialog->forceFill([
            'last_message_id' => $message->id,
            'last_inbound_message_id' => $message->id,
            'last_message_preview' => $message->text,
            'last_inbound_message_preview' => $message->text,
        ])->save();

        return $dialog->fresh(['channel', 'contact.assignedUser', 'currentContactIdentity']);
    }
}
