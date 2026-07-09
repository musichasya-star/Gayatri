<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
            'addons' => ['nullable', 'array'],
            'addons.*.id' => ['nullable', 'integer', 'exists:service_addons,id'],
            'addons.*.addon_service_id' => ['nullable', 'exists:services,id'],
            'addons.*.addon_name' => ['nullable', 'string', 'max:255'],
            'addons.*.duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'addons.*.price_adjustment' => ['nullable', 'numeric', 'min:0'],
            'addons.*.is_active' => ['nullable', 'boolean'],
        ];
    }
}
