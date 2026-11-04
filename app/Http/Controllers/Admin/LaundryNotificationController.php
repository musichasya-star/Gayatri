<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaundryNotification;
use App\Services\CRM\LaundryNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LaundryNotificationController extends Controller
{
    public function index(Request $request, LaundryNotificationService $service)
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(LaundryNotification::STATUSES))]]);
        $rows = LaundryNotification::with(['order', 'task'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))->latest('id')->paginate(20)->withQueryString();

        return response()->view('admin.laundry.notifications', ['rows' => $rows, 'settings' => $service->settings()])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function save(Request $request, LaundryNotificationService $service)
    {
        $request->merge(['events' => $request->input('events', [])]);
        $rules = ['enabled' => ['required', 'boolean'], 'dry_run' => ['required', 'boolean'], 'events' => ['present', 'array'], 'events.*' => ['distinct', Rule::in(array_keys(LaundryNotificationService::EVENTS))], 'start_hour' => ['required', 'integer', 'min:0', 'max:23'], 'end_hour' => ['required', 'integer', 'gt:start_hour', 'max:24'], 'min_interval_seconds' => ['required', 'integer', 'min:10', 'max:600'], 'reminder_after_days' => ['required', 'integer', 'min:1', 'max:30'], 'max_reminders' => ['required', 'integer', 'min:1', 'max:10']];
        foreach (array_keys(LaundryNotificationService::EVENTS) as $kind) {
            $rules['templates.'.$kind] = ['required', 'string', 'max:2000'];
        }
        if ($request->boolean('enabled') && ! $request->boolean('dry_run')) {
            $rules['live_confirmed'] = ['required', 'accepted'];
        }
        $data = $request->validate($rules);
        if ($request->boolean('enabled') && empty($data['events'])) {
            throw ValidationException::withMessages(['events' => 'Pilih minimal satu jenis notifikasi.']);
        }
        foreach ($data['templates'] as $template) {
            preg_match_all('/\{([^{}]+)\}/', $template, $matches);
            if (array_diff($matches[1], LaundryNotificationService::VARIABLES)) {
                throw ValidationException::withMessages(['templates' => 'Gunakan hanya variabel pesan yang tersedia pada halaman.']);
            }
        }
        unset($data['live_confirmed']);
        $data['enabled'] = $request->boolean('enabled');
        $data['dry_run'] = $request->boolean('dry_run');
        $service->save($data, $request->user(), $request);

        return back()->with('status', $data['dry_run'] ? 'Pengaturan tersimpan dalam mode simulasi. Tidak ada pesan nyata dikirim.' : 'Pengaturan pengiriman nyata tersimpan. Antrean laundry memerlukan worker aktif.');
    }

    public function retry(Request $request, LaundryNotification $notification, LaundryNotificationService $service)
    {
        $request->validate(['confirmed' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $service->retry($notification, $request->user(), $request);

        return back()->with('status', 'Permintaan percobaan ulang dicatat sesuai mode pengiriman saat ini.');
    }
}
