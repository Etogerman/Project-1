<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\SyncDialogsStageForRootContactAction;
use Illuminate\Console\Command;
use Generator;

class BackfillDialogStagesCommand extends Command
{
    protected $signature = 'dialogs:backfill-stage
        {--apply : Persist changes instead of running in dry-run mode}
        {--dry-run : Explicitly run without persisting changes}
        {--chunk=500 : Number of root contacts to process per chunk}
        {--contact-id= : Restrict processing to a specific contact or root contact ID}';

    protected $description = 'Backfill dialog stages for existing dialogs.';

    public function __construct(
        private readonly SyncDialogsStageForRootContactAction $syncDialogsStageForRootContactAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));
        $requestedContactId = $this->option('contact-id');

        $stats = [
            'root_contacts_processed' => 0,
            'dialogs_processed' => 0,
            'dialogs_updated' => 0,
            'dialogs_already_correct' => 0,
        ];

        foreach ($this->resolveRootContactIdChunks($requestedContactId, $chunk) as $rootContactIdChunk) {
            foreach ($rootContactIdChunk as $rootContactId) {
                $rootStats = $this->syncDialogsStageForRootContactAction->handle(
                    contact: $rootContactId,
                    apply: $apply,
                    writeHistory: false,
                );

                $stats['root_contacts_processed']++;
                $stats['dialogs_processed'] += $rootStats['dialogs_processed'];
                $stats['dialogs_updated'] += $rootStats['dialogs_updated'];
                $stats['dialogs_already_correct'] += $rootStats['dialogs_already_correct'];
            }
        }

        $this->line($apply
            ? 'Dialog stage backfill completed.'
            : 'Dialog stage backfill dry-run completed.');

        $this->table(
            ['Metric', 'Count'],
            [
                ['root_contacts_processed', $stats['root_contacts_processed']],
                ['dialogs_processed', $stats['dialogs_processed']],
                ['dialogs_updated', $stats['dialogs_updated']],
                ['dialogs_already_correct', $stats['dialogs_already_correct']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return Generator<int, list<int>>
     */
    private function resolveRootContactIdChunks(?string $requestedContactId, int $chunk): Generator
    {
        if (filled($requestedContactId)) {
            yield [$this->resolveRootContactAction->handle((int) $requestedContactId)->id];

            return;
        }

        $processedRootContactIds = [];
        $rootContactIdChunk = [];

        foreach ($this->streamDialogContactIds($chunk) as $contactId) {
            $rootContactId = $this->resolveRootContactAction->handle($contactId)->id;

            if (array_key_exists($rootContactId, $processedRootContactIds)) {
                continue;
            }

            $processedRootContactIds[$rootContactId] = true;
            $rootContactIdChunk[] = $rootContactId;

            if (count($rootContactIdChunk) < $chunk) {
                continue;
            }

            yield $rootContactIdChunk;

            $rootContactIdChunk = [];
        }

        if ($rootContactIdChunk !== []) {
            yield $rootContactIdChunk;
        }
    }

    /**
     * @return Generator<int, int>
     */
    private function streamDialogContactIds(int $chunk): Generator
    {
        $queryChunk = max($chunk * 5, 500);

        foreach (
            Contact::query()
                ->select('id')
                ->whereHas('dialogs')
                ->orderBy('id')
                ->lazyById($queryChunk, 'id') as $contact
        ) {
            yield $contact->id;
        }
    }
}
