<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Services\Dialogs\BackfillDialogsForRootContactAction;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Console\Command;

class BackfillDialogsCommand extends Command
{
    protected $signature = 'dialogs:backfill
        {--apply : Persist changes instead of running in dry-run mode}
        {--dry-run : Explicitly run without persisting changes}
        {--chunk=500 : Number of root contacts to process per chunk}
        {--contact-id= : Restrict processing to a specific contact or root contact ID}';

    protected $description = 'Backfill dialogs and historical sender metadata for existing messages.';

    public function __construct(
        private readonly BackfillDialogsForRootContactAction $backfillDialogsForRootContactAction,
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

        $candidateContactIds = $this->resolveCandidateContactIds($requestedContactId);
        $rootContactGroups = $this->buildRootContactGroups($candidateContactIds);

        $stats = $this->emptyStats();

        foreach (array_chunk(array_keys($rootContactGroups), $chunk) as $rootContactIds) {
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

                $rootStats = $this->backfillDialogsForRootContactAction->handle(
                    $rootContact,
                    $rootContactGroups[$rootContactId],
                    $apply,
                );

                $this->accumulateStats($stats, $rootStats);
            }
        }

        $this->line($apply
            ? 'Dialog backfill completed.'
            : 'Dialog backfill dry-run completed.');

        $this->table(
            ['Metric', 'Count'],
            [
                ['root_contacts_processed', $stats['root_contacts_processed']],
                ['dialogs_created', $stats['dialogs_created']],
                ['dialogs_updated', $stats['dialogs_updated']],
                ['dialogs_already_correct', $stats['dialogs_already_correct']],
                ['messages_linked', $stats['messages_linked']],
                ['messages_relinked', $stats['messages_relinked']],
                ['messages_already_linked', $stats['messages_already_linked']],
                ['messages_sender_backfilled', $stats['messages_sender_backfilled']],
                ['messages_sender_already_present', $stats['messages_sender_already_present']],
                ['unknown_message_kind_count', $stats['unknown_message_kind_count']],
                ['missing_route_source_count', $stats['missing_route_source_count']],
                ['identity_only_dialogs_count', $stats['identity_only_dialogs_count']],
                ['relinked_dialog_mismatch_count', $stats['relinked_dialog_mismatch_count']],
            ],
        );

        $this->renderAnomalySamples($stats);

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveCandidateContactIds(?string $requestedContactId): array
    {
        $messageContactIds = Message::query()
            ->distinct()
            ->pluck('contact_id')
            ->map(fn (mixed $contactId): int => (int) $contactId)
            ->all();

        $identityContactIds = ContactIdentity::query()
            ->distinct()
            ->pluck('contact_id')
            ->map(fn (mixed $contactId): int => (int) $contactId)
            ->all();

        $candidateContactIds = collect($messageContactIds)
            ->merge($identityContactIds)
            ->unique()
            ->sort()
            ->values();

        if (! filled($requestedContactId)) {
            return $candidateContactIds->all();
        }

        $rootContactId = $this->resolveRootContactAction->handle((int) $requestedContactId)->id;

        return $candidateContactIds
            ->filter(function (int $contactId) use ($rootContactId): bool {
                return $this->resolveRootContactAction->handle($contactId)->id === $rootContactId;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $candidateContactIds
     * @return array<int, list<int>>
     */
    private function buildRootContactGroups(array $candidateContactIds): array
    {
        $groups = [];

        foreach ($candidateContactIds as $contactId) {
            $rootContactId = $this->resolveRootContactAction->handle($contactId)->id;
            $groups[$rootContactId] ??= [];
            $groups[$rootContactId][] = $contactId;
        }

        ksort($groups);

        foreach ($groups as &$memberContactIds) {
            $memberContactIds = array_values(array_unique($memberContactIds));
            sort($memberContactIds);
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $rootStats
     */
    private function accumulateStats(array &$stats, array $rootStats): void
    {
        $stats['root_contacts_processed']++;
        $stats['dialogs_created'] += $rootStats['dialogs_created'];
        $stats['dialogs_updated'] += $rootStats['dialogs_updated'];
        $stats['dialogs_already_correct'] += $rootStats['dialogs_already_correct'];
        $stats['messages_linked'] += $rootStats['messages_linked'];
        $stats['messages_relinked'] += $rootStats['messages_relinked'];
        $stats['messages_already_linked'] += $rootStats['messages_already_linked'];
        $stats['messages_sender_backfilled'] += $rootStats['messages_sender_backfilled'];
        $stats['messages_sender_already_present'] += $rootStats['messages_sender_already_present'];
        $stats['unknown_message_kind_count'] += $rootStats['unknown_message_kind_count'];
        $stats['missing_route_source_count'] += $rootStats['missing_route_source_count'];
        $stats['identity_only_dialogs_count'] += $rootStats['identity_only_dialogs_count'];
        $stats['relinked_dialog_mismatch_count'] += $rootStats['relinked_dialog_mismatch_count'];

        foreach (['unknown_message_kind', 'missing_route_source', 'relinked_dialog_mismatch'] as $key) {
            foreach ($rootStats['anomaly_samples'][$key] as $sample) {
                if (count($stats['anomaly_samples'][$key]) >= 10) {
                    break;
                }

                $stats['anomaly_samples'][$key][] = $sample;
            }
        }
    }

    /**
     * @param  array{
     *   root_contacts_processed: int,
     *   dialogs_created: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     *   messages_linked: int,
     *   messages_relinked: int,
     *   messages_already_linked: int,
     *   messages_sender_backfilled: int,
     *   messages_sender_already_present: int,
     *   unknown_message_kind_count: int,
     *   missing_route_source_count: int,
     *   identity_only_dialogs_count: int,
     *   relinked_dialog_mismatch_count: int,
     *   anomaly_samples: array{
     *     unknown_message_kind: list<string>,
     *     missing_route_source: list<string>,
     *     relinked_dialog_mismatch: list<string>,
     *   }
     * }  $stats
     */
    private function renderAnomalySamples(array $stats): void
    {
        foreach ([
            'unknown_message_kind' => 'Unknown message kinds',
            'missing_route_source' => 'Dialogs without route source',
            'relinked_dialog_mismatch' => 'Messages relinked from unexpected dialog',
        ] as $key => $label) {
            if ($stats['anomaly_samples'][$key] === []) {
                continue;
            }

            $this->newLine();
            $this->warn($label.':');

            foreach ($stats['anomaly_samples'][$key] as $sample) {
                $this->line(' - '.$sample);
            }
        }
    }

    /**
     * @return array{
     *   root_contacts_processed: int,
     *   dialogs_created: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     *   messages_linked: int,
     *   messages_relinked: int,
     *   messages_already_linked: int,
     *   messages_sender_backfilled: int,
     *   messages_sender_already_present: int,
     *   unknown_message_kind_count: int,
     *   missing_route_source_count: int,
     *   identity_only_dialogs_count: int,
     *   relinked_dialog_mismatch_count: int,
     *   anomaly_samples: array{
     *     unknown_message_kind: list<string>,
     *     missing_route_source: list<string>,
     *     relinked_dialog_mismatch: list<string>,
     *   }
     * }
     */
    private function emptyStats(): array
    {
        return [
            'root_contacts_processed' => 0,
            'dialogs_created' => 0,
            'dialogs_updated' => 0,
            'dialogs_already_correct' => 0,
            'messages_linked' => 0,
            'messages_relinked' => 0,
            'messages_already_linked' => 0,
            'messages_sender_backfilled' => 0,
            'messages_sender_already_present' => 0,
            'unknown_message_kind_count' => 0,
            'missing_route_source_count' => 0,
            'identity_only_dialogs_count' => 0,
            'relinked_dialog_mismatch_count' => 0,
            'anomaly_samples' => [
                'unknown_message_kind' => [],
                'missing_route_source' => [],
                'relinked_dialog_mismatch' => [],
            ],
        ];
    }
}
