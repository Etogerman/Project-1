<?php

namespace App\Http\Controllers;

use App\Models\Bitrix24Profile;
use App\Services\Bitrix24\HandleBitrix24EventsCallbackAction;
use App\Services\Bitrix24\HandleBitrix24InstallCallbackAction;
use App\Services\Bitrix24\HandleBitrix24OpenlinesCallbackAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class Bitrix24CallbackController extends Controller
{
    public function install(Request $request): JsonResponse|RedirectResponse
    {
        if ($this->isAdminOAuthReturn($request)) {
            return redirect()->away($this->adminOAuthCallbackUrl($request));
        }

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

    private function isAdminOAuthReturn(Request $request): bool
    {
        return $request->isMethod('GET')
            && filled($request->query('code'))
            && filled($request->query('state'));
    }

    private function adminOAuthCallbackUrl(Request $request): string
    {
        $baseUrl = $this->extractCallbackBaseUrl($request)
            ?? $request->getSchemeAndHttpHost();
        $query = http_build_query($request->query(), '', '&', PHP_QUERY_RFC3986);

        return rtrim($baseUrl, '/').Bitrix24Profile::ADMIN_OAUTH_CALLBACK_PATH.($query === '' ? '' : '?'.$query);
    }

    private function extractCallbackBaseUrl(Request $request): ?string
    {
        $url = $request->fullUrlWithoutQuery(array_keys($request->query()));
        $normalizedUrl = Bitrix24Profile::normalizeCallbackBaseUrl($url);

        if ($normalizedUrl === null || ! str_ends_with($normalizedUrl, Bitrix24Profile::INSTALL_CALLBACK_PATH)) {
            return null;
        }

        $baseUrl = substr($normalizedUrl, 0, -strlen(Bitrix24Profile::INSTALL_CALLBACK_PATH));

        return $baseUrl === false || $baseUrl === '' ? null : $baseUrl;
    }
}
