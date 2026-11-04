<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Therapist;
use App\Services\AppSettingService;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\HomeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HomeServiceController extends Controller
{
    public function index(HomeService $homeService)
    {
        $settings = $homeService->settings();
        $services = Service::query()->bookable()->where('is_active', true)->orderBy('name')->get();
        $therapists = Therapist::where('status', 'active')->orderBy('name')->get();
        $ids = fn ($values) => collect(is_array($values) ? $values : [])->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->values()->all();
        $selectedServices = $ids(old('selection_submitted') ? old('service_ids', []) : $settings['service_ids']);
        $selectedTherapists = $ids(old('selection_submitted') ? old('therapist_ids', []) : $settings['therapist_ids']);
        $staleServices = collect($selectedServices)->diff($services->modelKeys())->mapWithKeys(fn ($id) => [(int) $id => Service::find($id)?->name ?? 'Layanan #'.$id]);
        $staleTherapists = collect($selectedTherapists)->diff($therapists->modelKeys())->mapWithKeys(fn ($id) => [(int) $id => Therapist::find($id)?->name ?? 'Terapis #'.$id]);

        return view('admin.home-service.index', [
            'settings' => $settings, 'services' => $services, 'therapists' => $therapists,
            'selectedServices' => $selectedServices, 'selectedTherapists' => $selectedTherapists,
            'staleServices' => $staleServices, 'staleTherapists' => $staleTherapists,
        ]);
    }

    public function update(Request $request, HomeService $homeService, AppSettingService $settings, AuditLogService $audit)
    {
        $required = $request->boolean('enabled') ? 'required' : 'nullable';
        $validator = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'office_name' => ['required', 'string', 'max:150'],
            'office_address' => [$required, 'string', 'max:2000'],
            'latitude' => [$required, 'numeric', 'between:-90,90'],
            'longitude' => [$required, 'numeric', 'between:-180,180'],
            'rate_per_km' => [$required, 'integer', 'min:0', 'max:10000000'],
            'max_distance_km' => [$required, 'numeric', 'gt:0', 'max:500'],
            'base_fee' => ['required', 'integer', 'min:0', 'max:10000000'],
            'free_km' => ['required', 'numeric', 'min:0', 'max:500'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'service_ids' => [$required, 'array', ...($request->boolean('enabled') ? ['min:1'] : [])],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')->where('is_active', true)],
            'therapist_ids' => [$required, 'array', ...($request->boolean('enabled') ? ['min:1'] : [])],
            'therapist_ids.*' => ['integer', 'distinct', Rule::exists('therapists', 'id')->where('status', 'active')],
            'routing_provider' => ['required', 'in:osrm,osrm_private'],
            'routing_base_url' => ['nullable', 'required_if:routing_provider,osrm_private', 'url:http,https', 'max:500', function ($attribute, $value, $fail) {
                $url = parse_url($value);
                if (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                    $fail('Endpoint rute harus berupa URL dasar tanpa kredensial, query, atau fragment.');
                }
            }],
        ]);
        $validator->after(function ($validator) use ($request) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($request->input('service_ids', []) ?? [] as $index => $id) {
                if (! Service::query()->bookable()->where('is_active', true)->whereKey($id)->exists()) {
                    $validator->errors()->add('service_ids.'.$index, 'Layanan belum siap untuk booking ke rumah. Lepaskan pilihan lama atau lengkapi layanan terlebih dahulu.');
                }
            }
        });
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except('routing_base_url'));
        }
        $data = $validator->validated();
        $old = $homeService->settings();
        $data['routing_base_url'] = rtrim($data['routing_base_url'] ?? '', '/');
        $data['enabled'] = $request->boolean('enabled');
        $data['service_ids'] = array_map('intval', $data['service_ids'] ?? []);
        $data['therapist_ids'] = array_map('intval', $data['therapist_ids'] ?? []);
        DB::transaction(function () use ($settings, $audit, $request, $old, $data) {
            $settings->set('home_service.settings', json_encode($data));
            $audit->log($request->user(), 'home_service.settings.update', null, $request, $old, $data, 'Pengaturan Home Service diperbarui');
        });

        return back()->with('status', 'Pengaturan Home Service tersimpan. Ongkos booking lama tetap mengikuti tarif saat pemesanan.');
    }
}
