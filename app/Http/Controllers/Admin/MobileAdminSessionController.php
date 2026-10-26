<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Exchange a scoped native admin bearer token for a separate web session
 * inside the in-app browser. The bearer token is never placed in a URL.
 */
class MobileAdminSessionController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $response = redirect()->route('dashboard');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
