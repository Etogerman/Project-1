<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;
use App\Models\DialogStage;
use App\Services\Colors\ColorRegistry;
use Illuminate\Support\Facades\Schema;

class DialogStageCatalog
{
    /**
     * @var ?list<array{id:?int,key:string,name:string,color:string,color_source:?string,color_value:?string,sort_order:int,system_role:?string,is_seeded:bool}>
     */
    private ?array $stages = null;

    /**
     * @return list<array{id:?int,key:string,name:string,color:string,color_source:?string,color_value:?string,sort_order:int,system_role:?string,is_seeded:bool}>
     */
    public function stages(): array
    {
        if ($this->stages !== null) {
            return $this->stages;
        }

        if (! Schema::hasTable('dialog_stages')) {
            return $this->stages = $this->fallbackStages();
        }

        $hasColorSource = Schema::hasColumn('dialog_stages', 'color_source');
        $hasColorValue = Schema::hasColumn('dialog_stages', 'color_value');
        $columns = ['id', 'key', 'name', 'color', 'sort_order', 'system_role', 'is_seeded'];

        if ($hasColorSource) {
            $columns[] = 'color_source';
        }

        if ($hasColorValue) {
            $columns[] = 'color_value';
        }

        return $this->stages = DialogStage::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get($columns)
            ->map(fn (DialogStage $stage): array => [
                'id' => (int) $stage->id,
                'key' => (string) $stage->key,
                'name' => (string) $stage->name,
                'color' => (string) $stage->color,
                'color_source' => $hasColorSource ? $this->nullableString($stage->getAttribute('color_source')) : null,
                'color_value' => $hasColorValue ? $this->nullableString($stage->getAttribute('color_value')) : null,
                'sort_order' => (int) $stage->sort_order,
                'system_role' => $stage->system_role !== null ? (string) $stage->system_role : null,
                'is_seeded' => (bool) $stage->is_seeded,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return collect($this->stages())
            ->mapWithKeys(fn (array $stage): array => [$stage['key'] => $stage['name']])
            ->all();
    }

    /**
     * @return list<array{value:string,label:string,is_system:bool}>
     */
    public function options(): array
    {
        return collect($this->stages())
            ->map(fn (array $stage): array => [
                'value' => $stage['key'],
                'label' => $stage['name'],
                'is_system' => (bool) $stage['is_seeded'],
            ])
            ->values()
            ->all();
    }

    public function label(?string $key): string
    {
        $key = $this->normalizeKey($key);

        return $key !== null
            ? ($this->labels()[$key] ?? 'Неизвестный этап')
            : 'Неизвестный этап';
    }

    public function color(?string $key): string
    {
        $stage = $this->stageByKey($key);

        return $stage !== null ? $stage['color'] : 'gray';
    }

    /**
     * @return array{source:string,value:string,key:?string,name:string,hex:string,background:string,soft:string,border:string,text:string,filament_tone:string}
     */
    public function colorTokens(?string $key): array
    {
        $stage = $this->stageByKey($key);

        if ($stage === null) {
            return app(ColorRegistry::class)->resolve(null, null, 'gray');
        }

        return app(ColorRegistry::class)->resolve(
            source: $stage['color_source'],
            value: $stage['color_value'],
            legacy: $stage['color'],
        );
    }

    /**
     * @return list<string>
     */
    public function automaticStageKeys(): array
    {
        return [
            $this->keyForSystemRole(DialogStage::SYSTEM_ROLE_NEW_DIALOG),
            $this->keyForSystemRole(DialogStage::SYSTEM_ROLE_PHONE_RECEIVED),
            $this->keyForSystemRole(DialogStage::SYSTEM_ROLE_QUESTIONNAIRE_COMPLETED),
        ];
    }

    /**
     * @return list<string>
     */
    public function manualStageKeys(): array
    {
        return collect($this->stages())
            ->filter(fn (array $stage): bool => $stage['system_role'] === null)
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function serviceStageKeys(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function workingStageKeys(): array
    {
        return collect($this->stages())
            ->reject(fn (array $stage): bool => in_array($stage['key'], $this->serviceStageKeys(), true))
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function kanbanStageKeys(): array
    {
        return $this->workingStageKeys();
    }

    public function isAutomatic(?string $key): bool
    {
        return in_array($this->normalizeKey($key), $this->automaticStageKeys(), true);
    }

    public function isManual(?string $key): bool
    {
        return in_array($this->normalizeKey($key), $this->manualStageKeys(), true);
    }

    public function isService(?string $key): bool
    {
        return in_array($this->normalizeKey($key), $this->serviceStageKeys(), true);
    }

    public function isWorking(?string $key): bool
    {
        return in_array($this->normalizeKey($key), $this->workingStageKeys(), true);
    }

    public function stageIdForKey(?string $key): ?int
    {
        $stage = $this->stageByKey($key);

        return $stage !== null && $stage['id'] !== null ? (int) $stage['id'] : null;
    }

    public function keyForStageId(?int $stageId): ?string
    {
        if ($stageId === null) {
            return null;
        }

        $stage = collect($this->stages())
            ->first(fn (array $stage): bool => (int) ($stage['id'] ?? 0) === $stageId);

        return is_array($stage) ? $stage['key'] : null;
    }

    public function keyForDialog(Dialog $dialog): ?string
    {
        if ($dialog->relationLoaded('dialogStage') && $dialog->dialogStage instanceof DialogStage) {
            return $dialog->dialogStage->key;
        }

        $key = $this->keyForStageId($dialog->stage_id !== null ? (int) $dialog->stage_id : null);

        return $key ?? $this->normalizeKey($dialog->stage);
    }

    public function keyForSystemRole(string $systemRole): string
    {
        $stage = collect($this->stages())
            ->first(fn (array $stage): bool => $stage['system_role'] === $systemRole);

        if (is_array($stage)) {
            return $stage['key'];
        }

        return match ($systemRole) {
            DialogStage::SYSTEM_ROLE_PHONE_RECEIVED => DialogStage::KEY_PHONE_RECEIVED,
            DialogStage::SYSTEM_ROLE_QUESTIONNAIRE_COMPLETED => DialogStage::KEY_QUESTIONNAIRE_COMPLETED,
            default => DialogStage::KEY_NEW_DIALOG,
        };
    }

    public function normalizeKey(?string $key): ?string
    {
        $key = trim((string) $key);

        return $key !== '' ? $key : null;
    }

    /**
     * @return ?array{id:?int,key:string,name:string,color:string,color_source:?string,color_value:?string,sort_order:int,system_role:?string,is_seeded:bool}
     */
    private function stageByKey(?string $key): ?array
    {
        $key = $this->normalizeKey($key);

        if ($key === null) {
            return null;
        }

        $stage = collect($this->stages())
            ->first(fn (array $stage): bool => $stage['key'] === $key);

        return is_array($stage) ? $stage : null;
    }

    /**
     * @return list<array{id:?int,key:string,name:string,color:string,color_source:?string,color_value:?string,sort_order:int,system_role:?string,is_seeded:bool}>
     */
    private function fallbackStages(): array
    {
        return collect(DialogStage::seededStages())
            ->map(fn (array $stage, string $key): array => [
                'id' => null,
                'key' => $key,
                'name' => $stage['name'],
                'color' => $stage['color'],
                'color_source' => null,
                'color_value' => $stage['color'],
                'sort_order' => $stage['sort_order'],
                'system_role' => $stage['system_role'],
                'is_seeded' => $stage['is_seeded'],
            ])
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
