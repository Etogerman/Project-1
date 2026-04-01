<?php

namespace App\Http\Controllers;

use App\Services\Bitrix24\HandleBitrix24EventsCallbackAction;
use App\Services\Bitrix24\HandleBitrix24InstallCallbackAction;
use App\Services\Bitrix24\HandleBitrix24OpenlinesCallbackAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Bitrix24CallbackController extends Controller
{
    public function install(Request $request): JsonResponse
    {
        app(HandleBitrix24InstallCallbackAction::class)->handle($request);

        return $this->response('install', $request);
    }

    public function events(Request $request): JsonResponse
    {
        app(HandleBitrix24EventsCallbackAction::class)->handle($request);

        return $this->response('events', $request);
    }

    public function openlines(Request $request): JsonResponse
    {
        app(HandleBitrix24OpenlinesCallbackAction::class)->handle($request);

        return $this->response('openlines', $request);
    }

    private function response(string $callbackType, Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'received' => true,
            'callback_type' => $callbackType,
            'method' => $request->getMethod(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
