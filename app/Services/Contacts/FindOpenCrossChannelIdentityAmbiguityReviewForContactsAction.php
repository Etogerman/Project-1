<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use Illuminate\Database\Eloquent\Builder;

class FindOpenCrossChannelIdentityAmbiguityReviewForContactsAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    /**
     * @param  Contact|int|array<int, Contact|int>  $contacts
     */
    public function handle(Contact|int|array $contacts): ?ContactDuplicateReview
    {
        $rootContactIds = $this->normalizeRootContactIds($contacts);

        if ($rootContactIds === []) {
            return null;
        }

        return ContactDuplicateReview::query()
            ->where('review_type', ContactDuplicateReview::TYPE_CROSS_CHANNEL_IDENTITY_AMBIGUITY)
            ->where('status', ContactDuplicateReview::STATUS_OPEN)
            ->where(function (Builder $query) use ($rootContactIds): void {
                $query->whereIn('contact_id', $rootContactIds);

                foreach ($rootContactIds as $rootContactId) {
                    $query->orWhereJsonContains('candidate_root_contact_ids', $rootContactId);
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  Contact|int|array<int, Contact|int>  $contacts
     * @return list<int>
     */
    private function normalizeRootContactIds(Contact|int|array $contacts): array
    {
        $items = is_array($contacts) ? $contacts : [$contacts];
        $rootContactIds = [];

        foreach ($items as $contact) {
            $rootContact = $this->resolveRootContactAction->handle($contact);
            $rootContactIds[] = $rootContact->id;
        }

        $rootContactIds = array_values(array_unique(array_map('intval', $rootContactIds)));
        sort($rootContactIds);

        return $rootContactIds;
    }
}
