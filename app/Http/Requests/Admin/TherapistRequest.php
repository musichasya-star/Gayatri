<?php

namespace App\Http\Requests\Admin;

use App\Models\Therapist;
use App\Support\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TherapistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Therapist|null $therapist */
        $therapist = $this->route('therapist');

        return [
            'user_id' => ['nullable', Rule::unique('therapists', 'user_id')->ignore($therapist), 'exists:users,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(UserStatus::all())],
            'notes' => ['nullable', 'string'],
        ];
    }
}
