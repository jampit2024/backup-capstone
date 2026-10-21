<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Enums\UserStatus;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::with('role');
        if ($request->query('role') === 'proctor') {
            $query->with([
                'examinationSchedules:id,title,exam_date,start_time,end_time,time_slot,batch_code,status,proctor_id',
                'examinationRooms:id,examination_schedule_id,room_name,capacity,proctor_id',
                'examinationRooms.schedule:id,title,exam_date,start_time,end_time,time_slot,batch_code,status',
            ]);
        }

        $users = $query
            ->search($request->query('search'))
            ->roleSlug($request->query('role'))
            ->status($request->query('status'))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = User::create([
            ...$request->validated(),
            'status' => UserStatus::Active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => new UserResource($user->load('role')),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user->load('role')),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => new UserResource($user->fresh()->load('role')),
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $status = UserStatus::from($request->validated('status'));
        $user->status = $status;
        $user->save();

        if ($status === UserStatus::Inactive) {
            $user->tokens()->delete();
        }

        $message = $status === UserStatus::Active
            ? 'User activated successfully.'
            : 'User disabled successfully.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new UserResource($user->fresh()->load('role')),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($user->status !== UserStatus::Inactive) {
            $user->status = UserStatus::Inactive;
            $user->save();
        }

        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'User disabled successfully.',
            'data' => new UserResource($user->fresh()->load('role')),
        ]);
    }
}
