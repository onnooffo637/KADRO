<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileAccount
{
    public function handle(Request $request, Closure $next, string $access = 'account'): Response
    {
        $user = $request->user();

        // A web session must never stand in for a native app bearer token.
        if (! $user instanceof User || ! $user->currentAccessToken() instanceof PersonalAccessToken) {
            abort(401);
        }

        if (! $user->hasVerifiedEmail()) {
            abort(403);
        }

        if ($user->isPlatformAdmin()) {
            if (! $user->tokenCan('admin:dashboard')) {
                abort(403);
            }
        } elseif ($access === 'admin' || ! $user->tokenCan('reader:profile')) {
            abort(403);
        }

        return $next($request);
    }
}
