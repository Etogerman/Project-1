<?php

namespace App\Http\Controllers;

use App\Services\Bitrix24\Bitrix24AdminOAuthException;
use App\Services\Bitrix24\BuildBitrix24AdminOAuthAuthorizeUrlAction;
use App\Services\Bitrix24\HandleBitrix24AdminOAuthCallbackAction;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class Bitrix24AdminOAuthController
{
    public function start(Request $request, BuildBitrix24AdminOAuthAuthorizeUrlAction $buildAuthorizeUrl): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            Notification::make()
                ->danger()
                ->title('Нужно войти в админку')
                ->body('После входа начните подключение Bitrix24 заново.')
                ->send();

            return redirect()->route('filament.admin.auth.login');
        }

        try {
            $start = $buildAuthorizeUrl->handle($user);
        } catch (Bitrix24AdminOAuthException $exception) {
            Notification::make()
                ->danger()
                ->title('Не удалось начать подключение')
                ->body($exception->getMessage())
                ->send();

            return redirect()->route('filament.admin.resources.bitrix24-connections.index');
        }

        return redirect()->away($start->authorizationUrl);
    }

    public function callback(Request $request, HandleBitrix24AdminOAuthCallbackAction $handleCallback): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            Notification::make()
                ->danger()
                ->title('Нужно войти в админку')
                ->body('После входа начните подключение Bitrix24 заново.')
                ->send();

            return redirect()->route('filament.admin.auth.login');
        }

        try {
            $connection = $handleCallback->handle($request, $user);
        } catch (Bitrix24AdminOAuthException $exception) {
            Notification::make()
                ->danger()
                ->title('Не удалось подключить Bitrix24')
                ->body($exception->getMessage())
                ->send();

            return redirect()->route('filament.admin.resources.bitrix24-connections.index');
        }

        Notification::make()
            ->success()
            ->title('Bitrix24 подключен')
            ->body('Подключение сохранено для портала '.$connection->portal_domain.'.')
            ->send();

        return redirect()->route('filament.admin.resources.bitrix24-connections.index');
    }
}
