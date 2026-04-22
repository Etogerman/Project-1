<?php

use App\Services\Bitrix24\NormalizeBitrix24ProfileCallbackBaseUrlsAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(NormalizeBitrix24ProfileCallbackBaseUrlsAction::class)->handle();
    }

    public function down(): void
    {
        // Canonical callback_base_url normalization is not reversible.
    }
};
