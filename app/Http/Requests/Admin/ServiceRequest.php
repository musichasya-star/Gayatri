<?php

namespace App\Http\Requests\Admin;

use App\Models\Service;
use App\Support\ServiceGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'service_group' => ['required', Rule::in(array_keys(ServiceGroup::LABELS))],
            'duration_minutes' => ['nullable', 'required_if:booking_mode,online', 'integer', 'min:15', 'max:600'],
            'price' => ['nullable', 'required_if:booking_mode,online', 'numeric', 'min:0'],
            'catalog_family' => ['nullable', 'string', 'max:255'],
            'variant_label' => ['nullable', 'string', 'max:255'],
            'booking_mode' => ['required', Rule::in(['online', 'admin'])],
            'catalog_kind' => ['sometimes', 'required', Rule::in(array_keys(Service::KINDS))],
            'package_quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'package_unit' => ['sometimes', 'required', Rule::in(['sesi', 'hari', 'kunjungan', 'paket'])],
            'addons_present' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
            'addons' => ['nullable', 'array', 'max:50'],
            'addons.*.id' => ['nullable', 'integer', 'exists:service_addons,id'],
            'addons.*.addon_service_id' => ['nullable', 'exists:services,id'],
            'addons.*.addon_name' => ['nullable', 'string', 'max:255'],
            'addons.*.duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'addons.*.price_adjustment' => ['nullable', 'numeric', 'min:0'],
            'addons.*.is_active' => ['nullable', 'boolean'],
            'addons.*.requires_confirmation' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('addons', []) ?? [] as $index => $addon) {
                if (empty($addon['addon_service_id']) && trim($addon['addon_name'] ?? '') === '') {
                    continue;
                }
                if (! ($addon['requires_confirmation'] ?? false)) {
                    foreach (['duration_minutes', 'price_adjustment'] as $field) {
                        if (! isset($addon[$field]) || $addon[$field] === '') {
                            $validator->errors()->add("addons.$index.$field", 'Isi angka yang sudah dipastikan atau tandai perlu konfirmasi.');
                        }
                    }
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['service_group' => $this->route('service')?->service_group
            ?? ServiceGroup::infer(is_string($this->input('name')) ? $this->input('name') : '', is_string($this->input('category')) ? $this->input('category') : null)]);
        $this->mergeIfMissing(['booking_mode' => $this->route('service')?->booking_mode ?? 'online']);
    }
}
