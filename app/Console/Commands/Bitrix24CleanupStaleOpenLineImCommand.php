<?php

namespace App\Console\Commands;

use App\Models\Bitrix24Connection;
use App\Models\Contact;
use App\Models\Dialog;
use App\Services\Bitrix24\Bitrix24ApiClient;
use App\Services\Bitrix24\Bitrix24ApiException;
use App\Services\Bitrix24\ResolveCurrentBitrix24ConnectionAction;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Console\Command;
use Throwable;

class Bitrix24CleanupStaleOpenLineImCommand extends Command
{
    protected $signature = 'bitrix24:cleanup-stale-openline-im
        {--contact= : Bitrix24 CONTACT ID}
        {--dialog= : Abrikosoff dialog ID; root contact Bitrix24 ID will be used}
        {--connection= : Bitrix24 connection ID; defaults to current runtime connection}
        {--remove-user-code= : Exact CRM IM VALUE to delete, including imol| prefix}
        {--keep-user-code= : Exact CRM IM VALUE that must remain after cleanup}
        {--expected-im-id= : Optional expected Bitrix24 CRM multifield IM row ID}
        {--apply : Delete the matched CRM IM row}
        {--dry-run : Explicitly run without mutating Bitrix24}';

    protected $description = 'Dry-run or delete one stale Bitrix24 Open Lines CRM IM multifield row.';

    public function __construct(
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnectionAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $contactId = $this->resolveBitrix24ContactId();
        $removeUserCode = $this->stringOption('remove-user-code');
        $keepUserCode = $this->stringOption('keep-user-code');

        if ($contactId === null || $removeUserCode === null || $keepUserCode === null) {
            $this->error('Options --contact or --dialog, --remove-user-code and --keep-user-code are required.');

            return self::FAILURE;
        }

        if ($removeUserCode === $keepUserCode) {
            $this->error('--remove-user-code and --keep-user-code must be different.');

            return self::FAILURE;
        }

        $connection = $this->resolveConnection();

        if (! $connection instanceof Bitrix24Connection) {
            return self::FAILURE;
        }

        $beforeContact = $this->fetchContact($contactId, $connection);

        if ($beforeContact === null) {
            return self::FAILURE;
        }

        $beforeRows = $this->extractImRows($beforeContact);
        $removeRows = $this->rowsMatchingValue($beforeRows, $removeUserCode);
        $keepRows = $this->rowsMatchingValue($beforeRows, $keepUserCode);

        if (count($removeRows) !== 1) {
            $this->error(sprintf(
                'Expected exactly one stale IM row for remove-user-code, found %d.',
                count($removeRows),
            ));
            $this->renderRows($beforeRows);

            return self::FAILURE;
        }

        if ($keepRows === []) {
            $this->error('Required keep-user-code row was not found. Cleanup is blocked.');
            $this->renderRows($beforeRows);

            return self::FAILURE;
        }

        $removeRow = $removeRows[0];
        $removeRowId = $removeRow['id'];

        if ($removeRowId === null) {
            $this->error('Matched stale IM row does not have an ID; Bitrix24 multifield delete is unsafe.');

            return self::FAILURE;
        }

        $expectedImId = $this->stringOption('expected-im-id');

        if ($expectedImId !== null && $expectedImId !== $removeRowId) {
            $this->error(sprintf(
                'Matched stale IM row ID [%s] does not match expected ID [%s].',
                $removeRowId,
                $expectedImId,
            ));

            return self::FAILURE;
        }

        $payload = [
            'ID' => $contactId,
            'FIELDS' => [
                'IM' => [[
                    'ID' => $removeRowId,
                    'DELETE' => 'Y',
                ]],
            ],
        ];

        $this->table(
            ['Field', 'Value'],
            [
                ['Mode', $this->option('apply') ? 'apply' : 'dry-run'],
                ['Bitrix24 connection', '#'.$connection->id],
                ['Bitrix24 contact', $contactId],
                ['Remove IM row ID', $removeRowId],
                ['Remove IM value', $removeUserCode],
                ['Keep IM rows found', (string) count($keepRows)],
            ],
        );
        $this->line('crm.contact.update payload:');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $this->option('apply')) {
            $this->info('Dry-run completed. Bitrix24 was not mutated.');

            return self::SUCCESS;
        }

        if (! $this->deleteImRow($payload, $connection)) {
            return self::FAILURE;
        }

        $afterContact = $this->fetchContact($contactId, $connection);

        if ($afterContact === null) {
            return self::FAILURE;
        }

        $afterRows = $this->extractImRows($afterContact);

        if ($this->rowsMatchingValue($afterRows, $removeUserCode) !== []) {
            $this->error('Stale IM row still exists after crm.contact.update.');
            $this->renderRows($afterRows);

            return self::FAILURE;
        }

        if ($this->rowsMatchingValue($afterRows, $keepUserCode) === []) {
            $this->error('Required keep-user-code row disappeared after crm.contact.update.');
            $this->renderRows($afterRows);

            return self::FAILURE;
        }

        $this->info('Stale Bitrix24 Open Lines IM row deleted and verified.');

        return self::SUCCESS;
    }

    private function resolveBitrix24ContactId(): ?string
    {
        $contactId = $this->positiveIntegerString($this->option('contact'));
        $dialogId = $this->positiveIntegerString($this->option('dialog'));

        if ($contactId !== null && $dialogId !== null) {
            $this->error('Use either --contact or --dialog, not both.');

            return null;
        }

        if ($contactId !== null) {
            return $contactId;
        }

        if ($dialogId === null) {
            return null;
        }

        $dialog = Dialog::query()->with('contact')->find((int) $dialogId);

        if (! $dialog instanceof Dialog || ! $dialog->contact instanceof Contact) {
            $this->error('Dialog #'.$dialogId.' with contact was not found.');

            return null;
        }

        $rootContact = $this->resolveRootContactAction->handle($dialog->contact);
        $resolvedContactId = $this->positiveIntegerString($rootContact->bitrix24_contact_id);

        if ($resolvedContactId === null) {
            $this->error('Dialog #'.$dialogId.' root contact does not have a valid Bitrix24 contact ID.');

            return null;
        }

        return $resolvedContactId;
    }

    private function resolveConnection(): ?Bitrix24Connection
    {
        $connectionId = $this->positiveIntegerString($this->option('connection'));

        if ($connectionId === null) {
            try {
                return $this->resolveCurrentConnectionAction->handle();
            } catch (Throwable $throwable) {
                $this->error('Current Bitrix24 connection could not be resolved: '.$throwable->getMessage());

                return null;
            }
        }

        $connection = Bitrix24Connection::query()->find((int) $connectionId);

        if (! $connection instanceof Bitrix24Connection) {
            $this->error('Bitrix24 connection #'.$connectionId.' was not found.');

            return null;
        }

        if ($connection->status !== Bitrix24Connection::STATUS_ACTIVE) {
            $this->error('Bitrix24 connection #'.$connectionId.' is not active.');

            return null;
        }

        return $connection;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchContact(string $contactId, Bitrix24Connection $connection): ?array
    {
        try {
            $response = $this->bitrix24ApiClient->call(
                'crm.contact.get',
                ['ID' => $contactId],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            $this->error('crm.contact.get transport failed: '.$exception->getMessage());

            return null;
        }

        if (! $response->successful || ! is_array($response->result)) {
            $this->error('crm.contact.get failed: '.($response->errorMessage ?? 'Unknown error.'));

            return null;
        }

        return $response->result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deleteImRow(array $payload, Bitrix24Connection $connection): bool
    {
        try {
            $response = $this->bitrix24ApiClient->call(
                'crm.contact.update',
                $payload,
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            $this->error('crm.contact.update transport failed: '.$exception->getMessage());

            return false;
        }

        if (! $response->successful) {
            $this->error('crm.contact.update failed: '.($response->errorMessage ?? 'Unknown error.'));

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return list<array{id: ?string, value: string, value_type: ?string}>
     */
    private function extractImRows(array $contact): array
    {
        $rawRows = $contact['IM'] ?? [];

        if (! is_array($rawRows)) {
            return [];
        }

        $rows = [];

        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $value = $this->stringValue($row['VALUE'] ?? null);

            if ($value === null) {
                continue;
            }

            $rows[] = [
                'id' => $this->stringValue($row['ID'] ?? null),
                'value' => $value,
                'value_type' => $this->stringValue($row['VALUE_TYPE'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{id: ?string, value: string, value_type: ?string}>  $rows
     * @return list<array{id: ?string, value: string, value_type: ?string}>
     */
    private function rowsMatchingValue(array $rows, string $value): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['value'] === $value,
        ));
    }

    /**
     * @param  list<array{id: ?string, value: string, value_type: ?string}>  $rows
     */
    private function renderRows(array $rows): void
    {
        if ($rows === []) {
            $this->warn('Contact has no CRM IM rows.');

            return;
        }

        $this->table(
            ['ID', 'VALUE_TYPE', 'VALUE'],
            array_map(
                static fn (array $row): array => [
                    $row['id'] ?? '',
                    $row['value_type'] ?? '',
                    $row['value'],
                ],
                $rows,
            ),
        );
    }

    private function positiveIntegerString(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        if ($value === null || ! ctype_digit($value) || (int) $value <= 0) {
            return null;
        }

        return $value;
    }

    private function stringOption(string $name): ?string
    {
        return $this->stringValue($this->option($name));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
