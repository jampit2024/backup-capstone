<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

use App\Services\AuthService;
use App\Services\GoogleAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class GoogleAuthController extends Controller
{
    public function __construct(
        private GoogleAuthService $googleAuthService,
        private AuthService $authService,
    ) {}

    public function redirect(): JsonResponse|RedirectResponse
    {
        if (! $this->googleAuthService->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Google OAuth is not configured. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REDIRECT_URI in your .env file.',
            ], 503);
        }

        return redirect($this->googleAuthService->getRedirectUrl());
    }

    public function callback(): RedirectResponse|JsonResponse
    {
        if (! $this->googleAuthService->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Google OAuth is not configured.',
            ], 503);
        }

        try {
            $user = $this->googleAuthService->findOrCreateUser();
            $token = $this->authService->createToken($user, 'google-auth-token');
            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

            return redirect("{$frontendUrl}/auth/google/callback?token={$token}");
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Google authentication failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
