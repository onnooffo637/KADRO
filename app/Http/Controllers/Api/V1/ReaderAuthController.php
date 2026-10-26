<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ReaderAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(12)],
            'role' => ['prohibited'],
            'roles' => ['prohibited'],
            'is_admin' => ['prohibited'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
        ]);

        event(new Registered($user));

        return response()->json([
            'message' => 'Reader account created. Verify your email before signing in on mobile.',
            'verification_required' => true,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', Str::lower(trim($data['email'])))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email verification required. Complete verification through the web login.',
                'code' => 'EMAIL_UNVERIFIED',
            ], 403);
        }

        $isAdmin = $user->isPlatformAdmin();
        $newToken = $user->createToken(
            ($isAdmin ? 'admin:' : 'reader:').trim($data['device_name']),
            [$isAdmin ? 'admin:dashboard' : 'reader:profile'],
            now()->addDays(30),
        );

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $newToken->plainTextToken,
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
            'is_admin' => $isAdmin,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->getAuthIdentifier(),
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'is_admin' => $user->isPlatformAdmin(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Only a real bearer-token request reaches this endpoint (middleware guard).
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Token revoked.']);
    }
}
