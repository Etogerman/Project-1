<?php

namespace Tests\Feature;

use App\Jobs\EnsureBitrix24DealJob;
use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Services\Bitrix24\EnsureBitrix24DealAction;
use App\Services\Bitrix24\IsContactReadyForBitrix24DealSyncAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class Bitrix24DealSyncJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_skips_deal_sync_for_contact_that_is_not_ready(): void
    {
        $contact = $this->makePendingDealSyncContact();

        $readyAction = Mockery::mock(IsContactReadyForBitrix24DealSyncAction::class);
        $readyAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact))
            ->andReturn(false);

        $ensureDealAction = Mockery::mock(EnsureBitrix24DealAction::class);
        $ensureDealAction->shouldNotReceive('handle');

        $job = new EnsureBitrix24DealJob($contact->id);
        $job->handle(
            app(ResolveRootContactAction::class),
            $readyAction,
            $ensureDealAction,
            app(LogBitrix24ApiCallAction::class),
        );

        $contact->refresh();

        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING, $contact->bitrix24_deal_sync_status);
        $this->assertDatabaseCount('bitrix24_sync_logs', 0);
    }

    public function test_job_runs_deal_sync_action_and_clears_pending_flag_on_success(): void
    {
        $contact = $this->makePendingDealSyncContact();

        $readyAction = Mockery::mock(IsContactReadyForBitrix24DealSyncAction::class);
        $readyAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact))
            ->andReturn(true);

        $ensureDealAction = Mockery::mock(EnsureBitrix24DealAction::class);
        $ensureDealAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact));

        $job = new EnsureBitrix24DealJob($contact->id);
        $job->handle(
            app(ResolveRootContactAction::class),
            $readyAction,
            $ensureDealAction,
            app(LogBitrix24ApiCallAction::class),
        );

        $contact->refresh();

        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING, $contact->bitrix24_deal_sync_status);
        $this->assertDatabaseCount('bitrix24_sync_logs', 0);
    }

    public function test_job_marks_contact_as_failed_and_logs_when_deal_sync_throws(): void
    {
        Log::spy();

        $contact = $this->makePendingDealSyncContact();

        $readyAction = Mockery::mock(IsContactReadyForBitrix24DealSyncAction::class);
        $readyAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact))
            ->andReturn(true);

        $ensureDealAction = Mockery::mock(EnsureBitrix24DealAction::class);
        $ensureDealAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact))
            ->andThrow(new \RuntimeException('Deal sync failed.'));

        $job = new EnsureBitrix24DealJob($contact->id);
        $job->handle(
            app(ResolveRootContactAction::class),
            $readyAction,
            $ensureDealAction,
            app(LogBitrix24ApiCallAction::class),
        );

        $contact->refresh();

        $this->assertFalse($contact->bitrix24_deal_sync_pending);
        $this->assertSame(Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED, $contact->bitrix24_deal_sync_status);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context) use ($contact): bool {
                return $message === 'Bitrix24 deal sync job failed.'
                    && $context['job'] === EnsureBitrix24DealJob::class
                    && $context['contact_id'] === $contact->id
                    && $context['root_contact_id'] === $contact->id
                    && $context['bitrix24_contact_id'] === '501'
                    && $context['bitrix24_deal_id'] === null
                    && $context['exception_class'] === \RuntimeException::class
                    && $context['exception_message'] === 'Deal sync failed.';
            });

        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'deal_sync_lookup_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
            'error_message' => 'Deal sync failed.',
        ]);

        $syncLog = Bitrix24SyncLog::query()->latest('id')->firstOrFail();

        $this->assertSame([
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => '501',
        ], $syncLog->request_payload);
    }

    private function makePendingDealSyncContact(): Contact
    {
        return Contact::factory()->create([
            'bitrix24_contact_id' => '501',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_deal_sync_pending' => true,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING,
            'bitrix24_deal_id' => null,
        ]);
    }
}
