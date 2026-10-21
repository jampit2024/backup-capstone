<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'theme' => ['sometimes', 'required', Rule::in(['light', 'dark', 'system'])],
            'locale' => ['sometimes', 'required', Rule::in(['en', 'fil', 'ceb', 'ilo', 'hil', 'war'])],
        ];
    }
}
