<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReportPeriod
{
    public static function dates(Request $request, ?Carbon $defaultStart = null, ?Carbon $defaultEnd = null): array
    {
        $data = Validator::make($request->query(), ['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d']])->validate();
        $start = Carbon::parse($data['start_date'] ?? ($defaultStart ?? now()->startOfMonth())->toDateString())->startOfDay();
        $end = Carbon::parse($data['end_date'] ?? ($defaultEnd ?? now()->endOfMonth())->toDateString())->endOfDay();
        if ($end->lt($start) || $start->diffInDays($end->copy()->startOfDay()) > 365 || $end->gt(today()->addYear())) {
            throw ValidationException::withMessages(['start_date' => 'Pilih rentang maksimal 366 hari; tanggal akhir tidak boleh sebelum tanggal awal atau lebih dari setahun ke depan.']);
        }

        return [$start, $end];
    }
}
