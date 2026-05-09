<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackAdminUserActivity
{
    private const TOUCH_INTERVAL_MINUTES = 5;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->shouldTouch($user)) {
            $user
                ->forceFill(['last_seen_at' => now()])
                ->saveQuietly();
        }

        return $next($request);
    }

    private function shouldTouch(User $user): bool
    {
        if ($user->last_seen_at === null) {
            return true;
        }

        return $user->last_seen_at->lte(now()->subMinutes(self::TOUCH_INTERVAL_MINUTES));
    }
}
