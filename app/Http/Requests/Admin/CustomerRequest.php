<?php

namespace App\Http\Requests\Admin;

use App\Models\Customer;
use App\Support\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        return [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp_number' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'whatsapp_number')->ignore($customer)],
            'email' => ['nullable', 'email', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'baby_name' => ['nullable', 'string', 'max:255'],
            'baby_birth_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(CustomerStatus::all())],
            'notes' => ['nullable', 'string'],
            'tags' => ['nullable', 'string'],
        ];
    }
}
