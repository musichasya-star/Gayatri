<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Carries list context through a booking edit without accepting redirect URLs. */
final class OperationalNavigation
{
    public static function context(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'return_to' => ['nullable', Rule::in(['bookings', 'booking-calendar'])],
            'return_filters' => ['nullable', 'array:date,status,service_group,page,search,attention'],
            'return_filters.attention' => ['nullable', 'in:pending'],
            'return_filters.date' => ['nullable', 'date_format:Y-m-d'],
            'return_filters.status' => ['nullable', Rule::in(BookingStatus::all())],
            'return_filters.service_group' => ['nullable', Rule::in(array_keys(ServiceGroup::LABELS))],
            'return_filters.page' => ['nullable', 'integer', 'min:1'],
            'return_filters.search' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails() || ! $request->filled('return_to')) {
            return [];
        }

        return array_filter($validator->validated(), fn ($value) => $value !== null);
    }

    public static function back(Request $request): string
    {
        $context = self::context($request);

        return route(($context['return_to'] ?? '') === 'booking-calendar' ? 'admin.bookings.calendar' : 'admin.bookings.index', $context['return_filters'] ?? []);
    }
}
