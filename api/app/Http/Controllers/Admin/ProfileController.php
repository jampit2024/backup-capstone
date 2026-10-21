<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UploadPhotoRequest;
use App\Http\Resources\UserResource;
use App\Services\ProfilePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private ProfilePhotoService $photoService) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new UserResource($request->user()->load('role')),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => new UserResource($user->fresh()->load('role')),
        ]);
    }

    public function uploadPhoto(UploadPhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->photoService->upload($user, $request->file('photo'));

        return response()->json([
            'success' => true,
            'message' => 'Profile photo uploaded successfully.',
            'data' => new UserResource($user->fresh()->load('role')),
        ]);
    }

    public function removePhoto(Request $request): JsonResponse
    {
        $this->photoService->delete($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Profile photo removed successfully.',
        ]);
    }
}
