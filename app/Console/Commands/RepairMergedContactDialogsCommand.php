<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\ConsolidateDialogsForRootContactAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairMergedContactDialogsCommand extends Command
{
    protected $signature = 'dialogs:repair-merged-contacts
        {--apply : Persist changes instead of running in dry-run mode}
        {--dry-run : Explicitly run without persisting changes}
        {--chunk=500 : Number of root contacts to process per chunk}
        {--contact-id= : Restrict processing to a specific merged contact chain}';

    protected $description = 'Repair dialog consistency for already merged contact chains.';

    public function __construct(
        private readonly ConsolidateDialogsForRootContactAction $consolidateDialogsForRootContactAction,
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

        $rootGroups = $this->buildRootGroups($requestedContactId);
        $stats = $this->emptyStats();

        foreach (array_chunk(array_keys($rootGroups), $chunk) as $rootContactIds) {
            $rootContacts = Contact::query()
                ->whereIn('id', $rootContactIds)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            foreach ($rootContactIds as $rootContactId) {
                $rootContact = $rootContacts->get($rootContactId);

                if (! $rootContact instanceof Contact) {
                    continue;
                }

                $rootStats = $apply
                    ? DB::transaction(fn (): array => $this->consolidateDialogsForRootContactAction->handle(
                        $rootContact,
                        $rootGroups[$rootContactId],
                        true,
                        true,
                    ))
                    : $this->consolidateDialogsForRootContactAction->handle(
                        $rootContact,
                        $rootGroups[$rootContactId],
                        false,
                        true,
                    );

                $this->accumulateStats($stats, $rootStats);
                $stats['root_contacts_processed']++;
            }
        }

        $this->line($apply
            ? 'Merged contact dialog repair completed.'
            : 'Merged contact dialog repair dry-run completed.');

        $this->table(
            ['Metric', 'Count'],
            [
                ['root_contacts_processed', $stats['root_contacts_processed']],
                ['dialogs_created', $stats['dialogs_created']],
                ['dialogs_reassigned', $stats['dialogs_reassigned']],
                ['dialogs_updated', $stats['dialogs_updated']],
                ['dialogs_merged', $stats['dialogs_merged']],
                ['dialogs_deleted', $stats['dialogs_deleted']],
                ['messages_relinked', $stats['messages_relinked']],
                ['messages_null_linked', $stats['messages_null_linked']],
                ['messages_contact_reassigned', $stats['messages_contact_reassigned']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, list<int>>
     */
    private function buildRootGroups(?string $requestedContactId): array
    {
        $contacts = Contact::query()
            ->select(['id', 'merged_into_contact_id'])
            ->orderBy('id')
            ->get();

        if (filled($requestedContactId)) {
            $requestedRootId = $this->resolveRootContactAction->handle((int) $requestedContactId)->id;

            $groups = [];

            foreach ($contacts as $contact) {
                $rootContactId = $this->resolveRootContactAction->handle($contact->id)->id;

                if ($rootContactId !== $requestedRootId) {
                    continue;
                }

                $groups[$requestedRootId] ??= [];
                $groups[$requestedRootId][] = $contact->id;
            }

            return $groups;
        }

        $candidateRootIds = $contacts
            ->filter(fn (Contact $contact): bool => $contact->merged_into_contact_id !== null)
            ->map(fn (Contact $contact): int => $this->resolveRootContactAction->handle($contact->id)->id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $groups = [];

        foreach ($contacts as $contact) {
            $rootContactId = $this->resolveRootContactAction->handle($contact->id)->id;

            if (! in_array($rootContactId, $candidateRootIds, true)) {
                continue;
            }

            $groups[$rootContactId] ??= [];
            $groups[$rootContactId][] = $contact->id;
        }

        return $groups;
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<string, int>  $rootStats
     */
    private function accumulateStats(array &$stats, array $rootStats): void
    {
        foreach (array_keys($rootStats) as $key) {
            $stats[$key] += $rootStats[$key];
        }
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'root_contacts_processed' => 0,
            'dialogs_created' => 0,
            'dialogs_reassigned' => 0,
            'dialogs_updated' => 0,
            'dialogs_merged' => 0,
            'dialogs_deleted' => 0,
            'messages_relinked' => 0,
            'messages_null_linked' => 0,
            'messages_contact_reassigned' => 0,
        ];
    }
}
