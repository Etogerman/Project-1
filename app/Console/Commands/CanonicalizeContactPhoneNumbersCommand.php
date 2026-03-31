<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Services\Contacts\NormalizePhoneNumberAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CanonicalizeContactPhoneNumbersCommand extends Command
{
    protected $signature = 'contacts:canonicalize-phone-numbers
        {--apply : Persist canonicalized values instead of running in dry-run mode}
        {--dry-run : Explicitly run without persisting changes}
        {--chunk=500 : Number of contacts to process per chunk}';

    protected $description = 'Canonicalize saved contact phone numbers and report newly exposed duplicates.';

    public function __construct(
        private readonly NormalizePhoneNumberAction $normalizePhoneNumberAction,
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

        $stats = [
            'processed' => 0,
            'already_canonical' => 0,
            'changed' => 0,
            'invalid' => 0,
            'same_contact_collisions' => 0,
        ];

        $firstContactIdByCanonicalPhone = [];
        $crossContactCanonicalPhones = [];

        Contact::query()
            ->whereHas('phoneNumbers')
            ->with(['phoneNumbers' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id')])
            ->orderBy('id')
            ->chunkById($chunk, function ($contacts) use (
                $apply,
                &$stats,
                &$firstContactIdByCanonicalPhone,
                &$crossContactCanonicalPhones
            ): void {
                foreach ($contacts as $contact) {
                    $result = $this->analyzeContactPhoneNumbers($contact);

                    $stats['processed'] += count($result['rows']);
                    $stats['already_canonical'] += $result['already_canonical'];
                    $stats['changed'] += $result['changed'];
                    $stats['invalid'] += $result['invalid'];
                    $stats['same_contact_collisions'] += $result['same_contact_collisions'];

                    foreach ($result['surviving_canonical_phones'] as $phoneNormalized) {
                        if (! isset($firstContactIdByCanonicalPhone[$phoneNormalized])) {
                            $firstContactIdByCanonicalPhone[$phoneNormalized] = $contact->id;

                            continue;
                        }

                        if ($firstContactIdByCanonicalPhone[$phoneNormalized] !== $contact->id) {
                            $crossContactCanonicalPhones[$phoneNormalized] = true;
                        }
                    }

                    if (! $apply || $result['has_writes'] === false) {
                        continue;
                    }

                    $this->applyCanonicalization($contact, $result['groups'], $result['primary_survivor_id']);
                }
            });

        $this->line($apply
            ? 'Phone canonicalization completed.'
            : 'Phone canonicalization dry-run completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['processed', $stats['processed']],
                ['already_canonical', $stats['already_canonical']],
                ['changed', $stats['changed']],
                ['invalid', $stats['invalid']],
                ['same_contact_collisions', $stats['same_contact_collisions']],
                ['cross_contact_matches', count($crossContactCanonicalPhones)],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   rows: list<ContactPhoneNumber>,
     *   groups: array<string, array{survivor: ContactPhoneNumber, duplicates: list<ContactPhoneNumber>, canonical: string, had_primary: bool}>,
     *   surviving_canonical_phones: list<string>,
     *   primary_survivor_id: int|null,
     *   already_canonical: int,
     *   changed: int,
     *   invalid: int,
     *   same_contact_collisions: int,
     *   has_writes: bool
     * }
     */
    private function analyzeContactPhoneNumbers(Contact $contact): array
    {
        $groups = [];
        $alreadyCanonical = 0;
        $changed = 0;
        $invalid = 0;
        $sameContactCollisions = 0;
        $primarySurvivorId = null;
        $firstSurvivorId = null;
        $hasWrites = false;

        foreach ($contact->phoneNumbers as $phoneNumber) {
            $canonicalPhone = $this->normalizePhoneNumberAction->handle($phoneNumber->phone_raw);

            if ($canonicalPhone === '') {
                $invalid++;

                continue;
            }

            if ($phoneNumber->phone_normalized === $canonicalPhone) {
                $alreadyCanonical++;
            } else {
                $changed++;
                $hasWrites = true;
            }

            if (! isset($groups[$canonicalPhone])) {
                $groups[$canonicalPhone] = [
                    'survivor' => $phoneNumber,
                    'duplicates' => [],
                    'canonical' => $canonicalPhone,
                    'had_primary' => $phoneNumber->is_primary,
                ];
            } else {
                $currentSurvivor = $groups[$canonicalPhone]['survivor'];
                $groups[$canonicalPhone]['had_primary'] = $groups[$canonicalPhone]['had_primary'] || $phoneNumber->is_primary;
                $sameContactCollisions++;
                $hasWrites = true;

                if ($this->shouldReplaceSurvivor($phoneNumber, $currentSurvivor)) {
                    $groups[$canonicalPhone]['duplicates'][] = $currentSurvivor;
                    $groups[$canonicalPhone]['survivor'] = $phoneNumber;
                } else {
                    $groups[$canonicalPhone]['duplicates'][] = $phoneNumber;
                }
            }
        }

        foreach ($groups as $group) {
            $survivor = $group['survivor'];
            $firstSurvivorId ??= $survivor->id;

            if ($group['had_primary'] && $primarySurvivorId === null) {
                $primarySurvivorId = $survivor->id;
            }
        }

        $primarySurvivorId ??= $firstSurvivorId;

        foreach ($groups as $group) {
            $survivor = $group['survivor'];
            $shouldBePrimary = $primarySurvivorId !== null && $survivor->id === $primarySurvivorId;

            if ($survivor->is_primary !== $shouldBePrimary) {
                $hasWrites = true;
            }
        }

        return [
            'rows' => $contact->phoneNumbers->all(),
            'groups' => $groups,
            'surviving_canonical_phones' => array_keys($groups),
            'primary_survivor_id' => $primarySurvivorId,
            'already_canonical' => $alreadyCanonical,
            'changed' => $changed,
            'invalid' => $invalid,
            'same_contact_collisions' => $sameContactCollisions,
            'has_writes' => $hasWrites,
        ];
    }

    /**
     * @param  array<string, array{survivor: ContactPhoneNumber, duplicates: list<ContactPhoneNumber>, canonical: string, had_primary: bool}>  $groups
     */
    private function applyCanonicalization(Contact $contact, array $groups, ?int $primarySurvivorId): void
    {
        DB::transaction(function () use ($contact, $groups, $primarySurvivorId): void {
            foreach ($groups as $group) {
                foreach ($group['duplicates'] as $duplicate) {
                    ContactPhoneNumber::query()
                        ->whereKey($duplicate->id)
                        ->delete();
                }
            }

            foreach ($groups as $group) {
                $survivor = ContactPhoneNumber::query()->findOrFail($group['survivor']->id);
                $shouldBePrimary = $primarySurvivorId !== null && $survivor->id === $primarySurvivorId;

                $survivor->forceFill([
                    'phone_normalized' => $group['canonical'],
                    'is_primary' => $shouldBePrimary,
                ])->save();
            }

            if ($primarySurvivorId === null) {
                ContactPhoneNumber::query()
                    ->where('contact_id', $contact->id)
                    ->update(['is_primary' => false]);

                return;
            }

            ContactPhoneNumber::query()
                ->where('contact_id', $contact->id)
                ->whereKeyNot($primarySurvivorId)
                ->update(['is_primary' => false]);
        });
    }

    private function shouldReplaceSurvivor(ContactPhoneNumber $candidate, ContactPhoneNumber $current): bool
    {
        if ($candidate->is_primary !== $current->is_primary) {
            return $candidate->is_primary;
        }

        return $candidate->id < $current->id;
    }
}
