<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthService
{
    public function isConfigured(): bool
    {
        return ! empty(Config::get('services.google.client_id'))
            && ! empty(Config::get('services.google.client_secret'));
    }

    public function getRedirectUrl(): string
    {
        return Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
    }

    public function findOrCreateUser(): User
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user) {
            if (! $user->google_id) {
                $user->update(['google_id' => $googleUser->getId()]);
            }

            if (! $user->isAdmin()) {
            throw new \RuntimeException('Admin access only. Proctor accounts use the mobile examination application.');
        }

            return $user;
        }

        throw new \RuntimeException('No admin account found for this Google email. Only administrators can sign in to the web portal.');
    }
}
