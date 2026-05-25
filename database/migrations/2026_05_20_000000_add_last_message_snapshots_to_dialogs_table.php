<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->foreignId('last_message_id')
                ->nullable()
                ->after('last_outbound_at')
                ->constrained('messages')
                ->nullOnDelete();
            $table->foreignId('last_inbound_message_id')
                ->nullable()
                ->after('last_message_id')
                ->constrained('messages')
                ->nullOnDelete();
            $table->foreignId('last_outbound_message_id')
                ->nullable()
                ->after('last_inbound_message_id')
                ->constrained('messages')
                ->nullOnDelete();
            $table->text('last_message_preview')->nullable()->after('last_outbound_message_id');
            $table->text('last_inbound_message_preview')->nullable()->after('last_message_preview');
            $table->text('last_outbound_message_preview')->nullable()->after('last_inbound_message_preview');
        });

        $this->backfillLastMessageSnapshotIds();
        $this->backfillLastMessageSnapshotPreviews();
    }

    public function down(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_message_id');
            $table->dropConstrainedForeignId('last_inbound_message_id');
            $table->dropConstrainedForeignId('last_outbound_message_id');
            $table->dropColumn([
                'last_message_preview',
                'last_inbound_message_preview',
                'last_outbound_message_preview',
            ]);
        });
    }

    private function backfillLastMessageSnapshotIds(): void
    {
        DB::statement(<<<'SQL'
            update dialogs
            set
                last_message_id = (
                    select messages.id
                    from messages
                    where messages.dialog_id = dialogs.id
                      and (
                        messages.message_kind is null
                        or messages.message_kind <> 'outbound_dialog_status_change'
                      )
                    order by coalesce(messages.received_at, messages.created_at) desc, messages.id desc
                    limit 1
                ),
                last_inbound_message_id = (
                    select messages.id
                    from messages
                    where messages.dialog_id = dialogs.id
                      and messages.direction = 'inbound'
                    order by coalesce(messages.received_at, messages.created_at) desc, messages.id desc
                    limit 1
                ),
                last_outbound_message_id = (
                    select messages.id
                    from messages
                    where messages.dialog_id = dialogs.id
                      and messages.direction = 'outbound'
                      and messages.external_message_id is not null
                      and (
                        messages.message_kind is null
                        or messages.message_kind <> 'outbound_dialog_status_change'
                      )
                    order by coalesce(messages.received_at, messages.created_at) desc, messages.id desc
                    limit 1
                )
        SQL);
    }

    private function backfillLastMessageSnapshotPreviews(): void
    {
        DB::statement(<<<'SQL'
            update dialogs
            set
                last_message_preview = (
                    select left(
                        case
                            when nullif(trim(messages.text), '') is not null then messages.text
                            when messages.message_kind = 'inbound_contact_share' then 'Поделился номером телефона'
                            when messages.message_kind = 'outbound_phone_capture_confirmation' then 'Спасибо, номер получили.'
                            when messages.message_kind = 'outbound_auto_reply' then 'Автоответ'
                            when messages.message_kind = 'outbound_manual_reply' then 'Ответ оператора'
                            when messages.message_kind = 'outbound_data_collection_question' then 'Вопрос анкеты'
                            when messages.message_kind = 'outbound_data_collection_completion' then 'Спасибо, данные сохранили.'
                            when messages.message_kind = 'inbound_system_event'
                                 and messages.system_event_code = 'bot_blocked_by_user' then 'Клиент заблокировал бота'
                            when messages.message_kind = 'inbound_system_event'
                                 and messages.system_event_code = 'bot_unblocked_by_user' then 'Клиент разблокировал бота'
                            else 'Системное сообщение'
                        end,
                        1000
                    )
                    from messages
                    where messages.id = dialogs.last_message_id
                ),
                last_inbound_message_preview = (
                    select left(
                        case
                            when nullif(trim(messages.text), '') is not null then messages.text
                            when messages.message_kind = 'inbound_contact_share' then 'Поделился номером телефона'
                            when messages.message_kind = 'inbound_system_event'
                                 and messages.system_event_code = 'bot_blocked_by_user' then 'Клиент заблокировал бота'
                            when messages.message_kind = 'inbound_system_event'
                                 and messages.system_event_code = 'bot_unblocked_by_user' then 'Клиент разблокировал бота'
                            else 'Системное сообщение'
                        end,
                        1000
                    )
                    from messages
                    where messages.id = dialogs.last_inbound_message_id
                ),
                last_outbound_message_preview = (
                    select left(
                        case
                            when nullif(trim(messages.text), '') is not null then messages.text
                            when messages.message_kind = 'outbound_phone_capture_confirmation' then 'Спасибо, номер получили.'
                            when messages.message_kind = 'outbound_auto_reply' then 'Автоответ'
                            when messages.message_kind = 'outbound_manual_reply' then 'Ответ оператора'
                            when messages.message_kind = 'outbound_data_collection_question' then 'Вопрос анкеты'
                            when messages.message_kind = 'outbound_data_collection_completion' then 'Спасибо, данные сохранили.'
                            else 'Системное сообщение'
                        end,
                        1000
                    )
                    from messages
                    where messages.id = dialogs.last_outbound_message_id
                )
        SQL);
    }
};
