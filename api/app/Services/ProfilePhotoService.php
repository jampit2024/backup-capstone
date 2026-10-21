<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoService
{
    public function upload(User $user, UploadedFile $file): string
    {
        $this->delete($user);

        $path = $file->store('profile-photos', 'public');
        $user->update(['profile_photo_path' => $path]);

        return $path;
    }

    public function delete(User $user): void
    {
        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->update(['profile_photo_path' => null]);
        }
    }
}
