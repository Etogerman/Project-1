<?php

namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactMergeLog;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PurgeRuntimeDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_counts_without_deleting_runtime_data(): void
    {
        $fixture = $this->seedRuntimeFixture();

        $this->artisan('maintenance:purge-runtime-data')
            ->expectsOutput('Runtime data purge dry-run completed.')
            ->expectsOutputToContain('Runtime tables to purge: messages, dialogs, contact_phone_numbers, contact_tag, contact_identities, contact_merge_logs, contact_duplicate_reviews, contacts, channel_activity_logs, jobs, failed_jobs, job_batches')
            ->expectsTable(
                ['Table', 'Before', 'After'],
                [
                    ['messages', '1', '1'],
                    ['dialogs', '1', '1'],
                    ['contact_phone_numbers', '1', '1'],
                    ['contact_tag', '1', '1'],
                    ['contact_identities', '1', '1'],
                    ['contact_merge_logs', '1', '1'],
                    ['contact_duplicate_reviews', '1', '1'],
                    ['contacts', '2', '2'],
                    ['channel_activity_logs', '1', '1'],
                    ['jobs', '1', '1'],
                    ['failed_jobs', '1', '1'],
                    ['job_batches', '1', '1'],
                ],
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('contacts', ['id' => $fixture['contact']->id]);
        $this->assertDatabaseHas('dialogs', ['id' => $fixture['dialog']->id]);
        $this->assertDatabaseHas('messages', ['id' => $fixture['message']->id]);
        $this->assertDatabaseHas('contact_tag', ['contact_id' => $fixture['contact']->id, 'tag_id' => $fixture['tag']->id]);
        $this->assertDatabaseHas('channel_activity_logs', ['id' => $fixture['activityLog']->id]);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('job_batches')->count());
    }

    public function test_force_clears_only_runtime_tables_and_preserves_config_tables(): void
    {
        $fixture = $this->seedRuntimeFixture();

        $this->artisan('maintenance:purge-runtime-data', ['--force' => true])
            ->expectsOutput('Runtime data purge completed.')
            ->assertSuccessful();

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('dialogs', 0);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseCount('contact_tag', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('contact_merge_logs', 0);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('channel_activity_logs', 0);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('job_batches')->count());

        $this->assertDatabaseHas('users', ['id' => $fixture['user']->id]);
        $this->assertDatabaseHas('channels', ['id' => $fixture['channel']->id]);
        $this->assertDatabaseHas('auto_reply_rules', ['id' => $fixture['rule']->id]);
        $this->assertDatabaseHas('tags', ['id' => $fixture['tag']->id]);
    }

    public function test_force_is_blocked_in_production_without_confirm_flag(): void
    {
        $fixture = $this->seedRuntimeFixture();

        $this->runInEnvironment('production', function (): void {
            $this->artisan('maintenance:purge-runtime-data', ['--force' => true])
                ->expectsOutput('Destructive purge in production requires both --force and --confirm-production-purge.')
                ->assertFailed();
        });

        $this->assertDatabaseHas('contacts', ['id' => $fixture['contact']->id]);
        $this->assertDatabaseHas('dialogs', ['id' => $fixture['dialog']->id]);
        $this->assertDatabaseHas('messages', ['id' => $fixture['message']->id]);
        $this->assertDatabaseHas('users', ['id' => $fixture['user']->id]);
        $this->assertDatabaseHas('channels', ['id' => $fixture['channel']->id]);
        $this->assertDatabaseHas('auto_reply_rules', ['id' => $fixture['rule']->id]);
    }

    public function test_force_runs_in_production_with_confirm_flag(): void
    {
        $fixture = $this->seedRuntimeFixture();

        $this->runInEnvironment('production', function (): void {
            $this->artisan('maintenance:purge-runtime-data', [
                '--force' => true,
                '--confirm-production-purge' => true,
            ])
                ->expectsOutput('Runtime data purge completed.')
                ->assertSuccessful();
        });

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('dialogs', 0);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseCount('contact_tag', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('contact_merge_logs', 0);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('channel_activity_logs', 0);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('job_batches')->count());

        $this->assertDatabaseHas('users', ['id' => $fixture['user']->id]);
        $this->assertDatabaseHas('channels', ['id' => $fixture['channel']->id]);
        $this->assertDatabaseHas('auto_reply_rules', ['id' => $fixture['rule']->id]);
        $this->assertDatabaseHas('tags', ['id' => $fixture['tag']->id]);
    }

    public function test_force_preserves_sessions_by_default(): void
    {
        $this->seedRuntimeFixture();
        $this->seedSessionRow('session-kept');

        $this->artisan('maintenance:purge-runtime-data', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('sessions', ['id' => 'session-kept']);
    }

    public function test_force_can_purge_sessions_with_explicit_flag(): void
    {
        $this->seedRuntimeFixture();
        $this->seedSessionRow('session-purged');

        $this->artisan('maintenance:purge-runtime-data', [
            '--force' => true,
            '--include-sessions' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_command_logs_summary_event_for_dry_run(): void
    {
        Log::spy();

        $this->seedRuntimeFixture();

        $this->artisan('maintenance:purge-runtime-data')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('maintenance.runtime_data_purge_dry_run', \Mockery::on(function (array $context): bool {
                return $context['environment'] === app()->environment()
                    && $context['dry_run'] === true
                    && $context['force'] === false
                    && $context['production_guard_confirmed'] === false
                    && $context['included_sessions'] === false
                    && is_string($context['driver'])
                    && $context['messages_count'] === 1
                    && $context['dialogs_count'] === 1
                    && $context['contact_tag_count'] === 1
                    && $context['contacts_count'] === 2
                    && is_string($context['purged_at']);
            }));
    }

    public function test_command_logs_summary_event_for_force(): void
    {
        Log::spy();

        $this->seedRuntimeFixture();

        $this->artisan('maintenance:purge-runtime-data', ['--force' => true])->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('maintenance.runtime_data_purged', \Mockery::on(function (array $context): bool {
                return $context['environment'] === app()->environment()
                    && $context['dry_run'] === false
                    && $context['force'] === true
                    && $context['production_guard_confirmed'] === false
                    && $context['included_sessions'] === false
                    && is_string($context['driver'])
                    && $context['messages_count'] === 1
                    && $context['dialogs_count'] === 1
                    && $context['contact_tag_count'] === 1
                    && $context['contacts_count'] === 2
                    && is_string($context['purged_at']);
            }));
    }

    public function test_command_logs_summary_event_for_confirmed_production_force(): void
    {
        $realLogger = Log::getFacadeRoot();

        Log::spy();
        Log::shouldReceive('channel')
            ->with('deprecations')
            ->andReturnUsing(fn () => $realLogger->channel('deprecations'));

        $this->seedRuntimeFixture();

        $this->runInEnvironment('production', function (): void {
            $this->artisan('maintenance:purge-runtime-data', [
                '--force' => true,
                '--confirm-production-purge' => true,
            ])->assertSuccessful();
        });

        Log::shouldHaveReceived('info')
            ->once()
            ->with('maintenance.runtime_data_purged', \Mockery::on(function (array $context): bool {
                return $context['environment'] === 'production'
                    && $context['dry_run'] === false
                    && $context['force'] === true
                    && $context['production_guard_confirmed'] === true
                    && $context['included_sessions'] === false
                    && is_string($context['driver'])
                    && $context['messages_count'] === 1
                    && $context['dialogs_count'] === 1
                    && $context['contact_tag_count'] === 1
                    && $context['contacts_count'] === 2
                    && is_string($context['purged_at']);
            }));
    }

    /**
     * @return array{
     *   user: User,
     *   channel: Channel,
     *   rule: AutoReplyRule,
     *   contact: Contact,
     *   identity: ContactIdentity,
     *   dialog: Dialog,
     *   message: Message,
     *   phone: ContactPhoneNumber,
     *   tag: Tag,
     *   review: ContactDuplicateReview,
     *   mergeLog: ContactMergeLog,
     *   activityLog: ChannelActivityLog
     * }
     */
    private function seedRuntimeFixture(): array
    {
        if (Contact::query()->exists()) {
            return [
                'user' => User::query()->firstOrFail(),
                'channel' => Channel::query()->firstOrFail(),
                'rule' => AutoReplyRule::query()->firstOrFail(),
                'contact' => Contact::query()->firstOrFail(),
                'identity' => ContactIdentity::query()->firstOrFail(),
                'dialog' => Dialog::query()->firstOrFail(),
                'message' => Message::query()->firstOrFail(),
                'phone' => ContactPhoneNumber::query()->firstOrFail(),
                'tag' => Tag::query()->firstOrFail(),
                'review' => ContactDuplicateReview::query()->firstOrFail(),
                'mergeLog' => ContactMergeLog::query()->firstOrFail(),
                'activityLog' => ChannelActivityLog::query()->firstOrFail(),
            ];
        }

        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create();
        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'fixture-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'external_chat_id' => 'fixture-chat',
            'received_at' => now(),
        ]);
        $phone = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
        ]);
        $tag = Tag::factory()->create([
            'name' => 'Runtime Fixture',
            'color' => Tag::COLOR_PRIMARY,
        ]);
        $contact->tags()->attach($tag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $review = ContactDuplicateReview::factory()->create([
            'contact_id' => $contact->id,
            'trigger_message_id' => $message->id,
        ]);
        $secondary = Contact::factory()->create([
            'merged_into_contact_id' => $contact->id,
            'merged_at' => now(),
        ]);
        $mergeLog = ContactMergeLog::factory()->create([
            'primary_contact_id' => $contact->id,
            'secondary_contact_id' => $secondary->id,
            'trigger_message_id' => $message->id,
        ]);
        $activityLog = ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => 'info',
            'event' => 'fixture.runtime',
            'message' => 'Fixture runtime log',
            'context' => ['fixture' => true],
        ]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"fixture":true}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"fixture":true}',
            'exception' => 'Fixture failure',
            'failed_at' => now(),
        ]);
        DB::table('job_batches')->insert([
            'id' => 'fixture-batch-1',
            'name' => 'Fixture batch',
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => null,
            'created_at' => now()->timestamp,
            'finished_at' => null,
        ]);

        return compact('user', 'channel', 'rule', 'contact', 'identity', 'dialog', 'message', 'phone', 'tag', 'review', 'mergeLog', 'activityLog');
    }

    private function seedSessionRow(string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);
    }

    private function runInEnvironment(string $environment, Closure $callback): void
    {
        $original = app()->environment();
        $originalAppEnv = env('APP_ENV');

        putenv("APP_ENV={$environment}");
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;
        $this->app->detectEnvironment(fn (): string => $environment);

        try {
            $callback();
        } finally {
            if ($originalAppEnv === false || $originalAppEnv === null) {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
            } else {
                putenv("APP_ENV={$originalAppEnv}");
                $_ENV['APP_ENV'] = $originalAppEnv;
                $_SERVER['APP_ENV'] = $originalAppEnv;
            }

            $this->app->detectEnvironment(fn (): string => $original);
        }
    }
}
