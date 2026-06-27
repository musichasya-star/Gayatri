<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Support\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Branch|null $branch */
        $branch = $this->route('branch');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('branches', 'code')->ignore($branch)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::in(UserStatus::all())],
        ];
    }
}
