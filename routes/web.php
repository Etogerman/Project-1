<?php

use App\Http\Controllers\Admin\ScenarioBuilderV3StateController;
use App\Http\Controllers\Bitrix24AdminOAuthController;
use App\Http\Controllers\Bitrix24CallbackController;
use App\Http\Controllers\Bitrix24ProbeController;
use App\Http\Controllers\BotWebhookController;
use App\Http\Controllers\TelegramAccountGatewayController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::post('/webhooks/telegram/{channel}', [BotWebhookController::class, 'telegram'])
    ->name('webhooks.telegram.handle');

Route::post('/webhooks/max/{channel}', [BotWebhookController::class, 'max'])
    ->name('webhooks.max.handle');

Route::middleware('throttle:telegram-account-gateway')
    ->prefix('/internal/gateway/telegram-account/{channel}')
    ->group(function (): void {
        Route::post('/messages', [TelegramAccountGatewayController::class, 'inboundMessage'])
            ->name('internal.telegram-account.messages.handle');

        Route::post('/runtime-state', [TelegramAccountGatewayController::class, 'runtimeState'])
            ->name('internal.telegram-account.runtime-state.handle');

        Route::post('/peer-sync-state', [TelegramAccountGatewayController::class, 'peerSyncState'])
            ->name('internal.telegram-account.peer-sync-state.handle');

        Route::post('/outgoing-messages/claim', [TelegramAccountGatewayController::class, 'claimOutgoingMessage'])
            ->name('internal.telegram-account.outgoing-messages.claim');

        Route::post('/outgoing-messages/{outgoingMessage}/result', [TelegramAccountGatewayController::class, 'outgoingMessageResult'])
            ->name('internal.telegram-account.outgoing-messages.result');
    });

Route::get('/admin/bitrix24/oauth/start', [Bitrix24AdminOAuthController::class, 'start'])
    ->name('admin.bitrix24.oauth.start');

Route::get('/admin/bitrix24/oauth/callback', [Bitrix24AdminOAuthController::class, 'callback'])
    ->name('admin.bitrix24.oauth.callback');

Route::middleware('auth')
    ->prefix('/admin/scenario-constructor/{scenario}/v3')
    ->name('admin.scenario-constructor.v3.')
    ->group(function (): void {
        Route::get('/state', [ScenarioBuilderV3StateController::class, 'show'])
            ->name('state.show');

        Route::put('/state', [ScenarioBuilderV3StateController::class, 'update'])
            ->name('state.update');

        Route::post('/publish', [ScenarioBuilderV3StateController::class, 'publish'])
            ->name('publish');

        Route::get('/sheet/export', [ScenarioBuilderV3StateController::class, 'exportSheet'])
            ->name('sheet.export');

        Route::post('/sheet/import/preview', [ScenarioBuilderV3StateController::class, 'previewSheetImport'])
            ->name('sheet.import.preview');

        Route::post('/sheet/import/apply', [ScenarioBuilderV3StateController::class, 'applySheetImport'])
            ->name('sheet.import.apply');
    });

Route::match(['GET', 'POST'], '/callbacks/bitrix24/install', [Bitrix24CallbackController::class, 'install'])
    ->middleware('throttle:bitrix24-install')
    ->name('callbacks.bitrix24.install');

Route::match(['GET', 'POST'], '/callbacks/bitrix24/events', [Bitrix24CallbackController::class, 'events'])
    ->middleware('throttle:bitrix24-events')
    ->name('callbacks.bitrix24.events');

Route::match(['GET', 'POST'], '/callbacks/bitrix24/openlines', [Bitrix24CallbackController::class, 'openlines'])
    ->middleware('throttle:bitrix24-openlines')
    ->name('callbacks.bitrix24.openlines');

if (app()->environment(['local', 'testing'])) {
    Route::match(['GET', 'POST'], '/callbacks/bitrix24/probe', Bitrix24ProbeController::class)
        ->name('callbacks.bitrix24.probe');
}
