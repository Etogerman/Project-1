<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Contacts\BrokenContactMergeChainException;
use App\Services\DataCollection\InferGenderByFirstNameAction;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class InferContactGenderFromFirstNameJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $contactId,
        public string $expectedFirstName,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("infer-contact-gender:contact:{$this->contactId}"))->expireAfter(180),
        ];
    }

    public function handle(
        InferGenderByFirstNameAction $inferGenderByFirstNameAction,
        ResolveRootContactAction $resolveRootContactAction,
    ): void
    {
        try {
            $contact = $resolveRootContactAction->handle($this->contactId);
        } catch (BrokenContactMergeChainException) {
            return;
        }

        if (filled($contact->gender)) {
            return;
        }

        if ($contact->first_name_source !== Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED) {
            return;
        }

        $currentFirstName = trim((string) ($contact->first_name ?? ''));

        if ($currentFirstName === '' || $currentFirstName !== trim($this->expectedFirstName)) {
            return;
        }

        try {
            $gender = $inferGenderByFirstNameAction->handle($currentFirstName);
        } catch (Throwable $throwable) {
            Log::warning('contact.gender_inference_failed', [
                'contact_id' => $contact->id,
                'first_name' => $currentFirstName,
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            return;
        }

        $contact->forceFill([
            'gender' => $gender,
        ])->save();
    }
}
