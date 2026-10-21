<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', Password::defaults()],
            'role_id' => [
                'sometimes',
                'required',
                Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('slug', Role::ASSIGNABLE_SLUGS)),
            ],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
        ];
    }
}
