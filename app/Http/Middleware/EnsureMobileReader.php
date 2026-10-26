<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileReader
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Deliberately do NOT accept Sanctum's SPA / transient session token.
        // These mobile endpoints require a narrowly scoped, real bearer token.
        if (! $user instanceof User || ! $user->currentAccessToken() instanceof PersonalAccessToken) {
            abort(401);
        }

        if ($user->isPlatformAdmin() || ! $user->tokenCan('reader:profile')) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail()) {
            abort(403);
        }

        return $next($request);
    }
}
