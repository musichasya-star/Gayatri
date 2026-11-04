<?php

namespace App\Support;

use App\Models\LaundryOrder;
use App\Models\RentalOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class OperationalQueue
{
    public static function inbox(Request $request): array
    {
        return self::read($request, [
            'search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1'],
            'unread' => ['nullable', 'boolean'], 'need_followup' => ['nullable', 'boolean'],
            'need_admin' => ['nullable', 'boolean'], 'ai_handled' => ['nullable', 'boolean'],
            'assigned_user_id' => ['nullable', 'integer', 'min:1'],
        ], 'inbox_filters');
    }

    public static function laundry(Request $request): array
    {
        if ($request->input('queue_view') === 'intake') {
            return self::read($request, [
                'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(array_keys(LaundryOrder::STATUSES))],
                'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'],
                'include_demo' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'],
            ]);
        }

        return self::read($request, [
            'search' => ['nullable', 'string', 'max:100'], 'workflow' => ['nullable', Rule::in(array_keys(LaundryOrder::WORKFLOW))],
            'deadline' => ['nullable', 'in:overdue,soon'], 'include_demo' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    public static function rental(Request $request): array
    {
        return self::read($request, [
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(array_keys(RentalOrder::STATUSES))],
            'customer_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private static function read(Request $request, array $rules, string $key = 'queue'): array
    {
        $input = $request->input($key, []);
        if (! is_array($input)) {
            return [];
        }
        $validator = Validator::make($input, $rules);

        return $validator->fails() ? [] : $validator->validated();
    }
}
