<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{name:string,color:string,sort_order:int,system_role:?string}>
     */
    private array $seededStages = [
        'new_dialog' => [
            'name' => 'Новый диалог',
            'color' => 'gray',
            'sort_order' => 10,
            'system_role' => 'new_dialog',
        ],
        'phone_received' => [
            'name' => 'Телефон получен',
            'color' => 'info',
            'sort_order' => 20,
            'system_role' => 'phone_received',
        ],
        'questionnaire_completed' => [
            'name' => 'Данные собраны',
            'color' => 'success',
            'sort_order' => 30,
            'system_role' => 'questionnaire_completed',
        ],
        'transferred_to_mpl' => [
            'name' => 'МПЛ взял в работу',
            'color' => 'warning',
            'sort_order' => 40,
            'system_role' => null,
        ],
        'transferred_to_mpp' => [
            'name' => 'Передан в МПП',
            'color' => 'primary',
            'sort_order' => 50,
            'system_role' => null,
        ],
    ];

    public function up(): void
    {
        Schema::create('dialog_stages', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('color', 32)->default('gray');
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->string('system_role', 64)->nullable()->unique();
            $table->boolean('is_seeded')->default(false);
            $table->timestamps();
        });

        $now = now();

        foreach ($this->seededStages as $key => $stage) {
            DB::table('dialog_stages')->insert([
                'key' => $key,
                'name' => $stage['name'],
                'color' => $stage['color'],
                'sort_order' => $stage['sort_order'],
                'system_role' => $stage['system_role'],
                'is_seeded' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('dialogs', function (Blueprint $table): void {
            $table->foreignId('stage_id')
                ->nullable()
                ->after('stage')
                ->constrained('dialog_stages')
                ->nullOnDelete();
        });

        $stageIds = DB::table('dialog_stages')->pluck('id', 'key');

        foreach ($stageIds as $key => $id) {
            DB::table('dialogs')
                ->where('stage', $key)
                ->update(['stage_id' => $id]);
        }

        $this->backfillDerivedStage(
            key: 'questionnaire_completed',
            stageId: (int) $stageIds['questionnaire_completed'],
            callback: fn ($query) => $query->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('contacts')
                    ->whereColumn('contacts.id', 'dialogs.contact_id')
                    ->where(function ($query): void {
                        $query
                            ->where('contacts.data_collection_status', 'completed')
                            ->orWhereNotNull('contacts.data_collection_completed_at');
                    });
            }),
        );

        $this->backfillDerivedStage(
            key: 'phone_received',
            stageId: (int) $stageIds['phone_received'],
            callback: fn ($query) => $query->whereNotNull('dialogs.phone_confirmed_at'),
        );

        DB::table('dialogs')
            ->whereNull('stage_id')
            ->update([
                'stage' => 'new_dialog',
                'stage_id' => (int) $stageIds['new_dialog'],
            ]);
    }

    public function down(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stage_id');
        });

        Schema::dropIfExists('dialog_stages');
    }

    private function backfillDerivedStage(string $key, int $stageId, callable $callback): void
    {
        $query = DB::table('dialogs')->whereNull('stage_id');

        $callback($query);

        $query->update([
            'stage' => $key,
            'stage_id' => $stageId,
        ]);
    }
};
