<?php

namespace App\Http\Requests\Admin;

use App\Models\Customer;
use App\Support\CustomerPhone;
use App\Support\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('whatsapp_number')) {
            if (! is_string($this->input('whatsapp_number'))) {
                throw ValidationException::withMessages(['whatsapp_number' => 'Nomor WhatsApp harus berupa teks.']);
            }
            $customer = $this->route('customer');
            if ($customer instanceof Customer && $this->input('whatsapp_number') === $customer->whatsapp_number) {
                return;
            }
            try {
                $this->merge(['whatsapp_number' => CustomerPhone::normalize($this->input('whatsapp_number'))]);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['whatsapp_number' => $e->getMessage()]);
            }
        }
    }

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
            'whatsapp_number' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'whatsapp_number')->ignore($customer), function ($attribute, $value, $fail) use ($customer) {
                if ($customer && $value === $customer->whatsapp_number) {
                    return;
                }
                if (CustomerPhone::matching($value)->when($customer, fn ($query) => $query->whereKeyNot($customer->id))->exists()) {
                    $fail('Nomor WhatsApp sudah digunakan profil CRM lain.');
                }
            }],
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
