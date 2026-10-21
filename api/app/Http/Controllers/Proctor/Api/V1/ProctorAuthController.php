<?php

namespace App\Http\Controllers\Proctor\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProctorAuthController extends Controller
{
    public function __construct(private AuthService $authService)
    {
    }

    /**
     * Mobile proctor login (Sanctum). Admins may also use this for testing.
     * Web /api/auth/login remains admin-only.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) ($validated['email'] ?? $validated['username'] ?? ''));
        if ($login === '') {
            return response()->json([
                'success' => false,
                'message' => 'Email or username is required.',
            ], 422);
        }

        $user = User::query()
            ->with('role')
            ->where('email', $login)
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (! $user->isProctor() && ! $user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Proctor access only. Use the admin web portal for administrator accounts.',
            ], 403);
        }

        if ($user->status !== UserStatus::Active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not active.',
            ], 403);
        }

        $token = $this->authService->createToken($user, 'proctor-mobile');

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'profile' => [
                    'id' => (string) $user->id,
                    'username' => $user->email,
                    'displayName' => $user->name,
                    'roleLabel' => $user->isProctor() ? 'Examination Proctor' : 'Administrator',
                ],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()?->load('role');
        if (! $user || (! $user->isProctor() && ! $user->isAdmin())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'profile' => [
                    'id' => (string) $user->id,
                    'username' => $user->email,
                    'displayName' => $user->name,
                    'roleLabel' => $user->isProctor() ? 'Examination Proctor' : 'Administrator',
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->revokeCurrentToken($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Logged out.',
        ]);
    }
}
