<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\RentalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RentalSettingController extends Controller
{
    public function index(RentalSettings $settings)
    {
        return view('admin.rentals.settings', ['settings' => $settings->get()]);
    }

    public function update(Request $request, RentalSettings $settings, AuditLogService $audit)
    {
        $required = $request->boolean('delivery_enabled') ? 'required' : 'nullable';
        $validator = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'], 'delivery_enabled' => ['required', 'boolean'],
            'hold_hours' => ['required', 'integer', 'min:1', 'max:72'], 'max_rental_days' => ['required', 'integer', 'min:1', 'max:365'],
            'ops_lookahead_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'dp_type' => ['sometimes', 'required', 'in:none,percent,fixed'], 'dp_value' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000000'],
            'dp_due_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:168'], 'settlement_days_before' => ['sometimes', 'required', 'integer', 'min:0', 'max:30'],
            'require_full_payment' => ['sometimes', 'required', 'boolean'], 'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'rental_terms' => ['sometimes', 'required', 'string', 'min:20', 'max:10000'],
            'late_fee_daily' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000'],
            'late_fee_grace_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:30'], 'late_fee_cap' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000'],
            'cancellation_fee_type' => ['sometimes', 'required', 'in:none,percent,fixed'], 'cancellation_fee_value' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000'],
            'cancellation_free_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:365'],
            'office_name' => ['required', 'string', 'max:150'], 'office_address' => [$required, 'string', 'max:2000'],
            'latitude' => [$required, 'numeric', 'between:-90,90'], 'longitude' => [$required, 'numeric', 'between:-180,180'],
            'delivery_rate_per_km' => ['required', 'integer', 'min:0', 'max:10000000'], 'delivery_base_fee' => ['required', 'integer', 'min:0', 'max:10000000'],
            'collection_rate_per_km' => ['required', 'integer', 'min:0', 'max:10000000'], 'collection_base_fee' => ['required', 'integer', 'min:0', 'max:10000000'],
            'free_km' => ['required', 'numeric', 'min:0', 'max:500'], 'max_distance_km' => ['required', 'numeric', 'gt:0', 'max:500'],
            'routing_provider' => ['required', 'in:osrm,osrm_private'],
            'routing_base_url' => ['nullable', 'required_if:routing_provider,osrm_private', 'url:http,https', 'max:500', function ($attribute, $value, $fail) {
                $parts = parse_url($value);
                if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    $fail('Gunakan URL dasar tanpa kredensial, query, atau fragment.');
                }
            }],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except('routing_base_url'));
        }
        $data = $validator->validated();
        $merged = array_replace($settings->get(), $data);
        if ($merged['dp_type'] === 'percent' && (int) $merged['dp_value'] > 100 || $merged['dp_type'] !== 'none' && (int) $merged['dp_value'] < 1) {
            return back()->withErrors(['dp_value' => 'DP persentase harus 1–100%, atau nominal tetap lebih dari nol.'])->withInput();
        }
        $data = $merged;
        if ($data['cancellation_fee_type'] === 'percent' && (int) $data['cancellation_fee_value'] > 100) {
            return back()->withErrors(['cancellation_fee_value' => 'Potongan pembatalan persentase maksimal 100% dari biaya jasa, di luar deposit.'])->withInput();
        }
        $data['enabled'] = $request->boolean('enabled');
        $data['delivery_enabled'] = $request->boolean('delivery_enabled');
        $data['routing_base_url'] = rtrim($data['routing_base_url'] ?? '', '/');
        DB::transaction(function () use ($settings, $audit, $request, $data) {
            $old = $settings->get();
            $settings->save($data);
            $audit->log($request->user(), 'rental.settings.update', null, $request, $old, $data, 'Pengaturan rental diperbarui');
        });

        return back()->with('status', 'Pengaturan rental tersimpan. Tarif transaksi lama tetap.');
    }
}
