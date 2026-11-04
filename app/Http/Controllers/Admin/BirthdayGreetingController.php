<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBirthdayGreetingJob;
use App\Models\BirthdayGreeting;
use App\Models\Customer;
use App\Services\AppSettingService;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\BirthdayGreetingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BirthdayGreetingController extends Controller
{
    public function __construct(
        private readonly BirthdayGreetingService $service,
        private readonly AppSettingService $settings,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): View
    {
        return $this->page($this->service->settings(), $request);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $old = $this->service->settings();
        DB::transaction(function () use ($data, $old, $request) {
            $this->settings->set(BirthdayGreetingService::SETTING_KEY, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->audit->log($request->user(), 'birthday.settings.update', null, $request, $old, $data, 'Pengaturan ucapan ulang tahun diperbarui');
        });

        return redirect()->route('admin.birthdays.index')->with('status', 'Pengaturan ulang tahun disimpan. Scheduler akan memeriksa jadwal setiap menit.');
    }

    public function preview(Request $request): View
    {
        $data = $this->validated($request);
        $sample = $request->validate([
            'preview_customer_id' => ['required', 'exists:customers,id'],
            'preview_type' => ['required', Rule::in(['customer', 'baby'])],
        ]);
        $customer = Customer::findOrFail($sample['preview_customer_id']);

        return $this->page($data, $request, [
            'customer_id' => $customer->id, 'type' => $sample['preview_type'],
            'message' => $this->service->render($customer, $sample['preview_type'], $data),
        ]);
    }

    public function process(Request $request): RedirectResponse
    {
        if (! $this->service->canDispatch()) {
            return back()->withErrors(['birthday' => 'Aktifkan fitur dan tunggu jam kirim yang dipilih terlebih dahulu.']);
        }
        $count = $this->service->dispatchDue();
        $this->audit->log($request->user(), 'birthday.process', null, $request, [], ['queued' => $count], 'Memeriksa ulang tahun hari ini');

        return redirect()->route('admin.birthdays.index')->with('status', "{$count} ucapan baru masuk antrean. Ucapan yang sudah tercatat tahun ini tidak dikirim ulang.");
    }

    public function retry(Request $request, BirthdayGreeting $greeting): RedirectResponse
    {
        DB::transaction(function () use ($request, $greeting) {
            $record = BirthdayGreeting::lockForUpdate()->findOrFail($greeting->id);
            $stale = $record->status === 'sending' && $record->started_at?->lt(now()->subMinutes(2));
            if (! in_array($record->status, ['failed', 'skipped', 'unknown'], true) && ! $stale) {
                throw ValidationException::withMessages(['birthday' => 'Ucapan ini tidak bisa dikirim ulang.']);
            }
            if (! $this->service->canDispatch() || $record->birthday_on->toDateString() !== $this->service->now()->toDateString()) {
                throw ValidationException::withMessages(['birthday' => 'Coba ulang hanya tersedia pada tanggal ulang tahun, setelah jam kirim, saat fitur aktif.']);
            }
            if ($record->status === 'unknown' || $stale) {
                $request->validate(['confirmed_not_sent' => ['required', 'accepted']]);
            }
            $old = $record->toArray();
            $record->update(['status' => 'queued', 'started_at' => null, 'failure_reason' => null]);
            $this->audit->log($request->user(), 'birthday.retry', $record, $request, $old, ['status' => 'queued'], 'Admin memeriksa dan mengantrekan ulang ucapan');
            SendBirthdayGreetingJob::dispatch($record->id)->afterCommit();
        });

        return back()->with('status', 'Ucapan masuk antrean ulang. Status dan penerima diperiksa lagi sebelum dikirim.');
    }

    private function page(array $settings, Request $request, ?array $preview = null): View
    {
        $today = $this->service->now();
        $upcoming = collect();
        for ($day = 1; $day <= 7; $day++) {
            $upcoming = $upcoming->concat($this->service->occurrences($today->copy()->addDays($day), $settings));
        }
        $logs = BirthdayGreeting::whereDate('birthday_on', $today->toDateString())->get();

        return view('admin.birthdays.index', [
            'settings' => $settings, 'today' => $today,
            'todayRecipients' => $this->service->occurrences($today, $settings),
            'todayLogs' => $logs->keyBy(fn ($log) => $log->customer_id.':'.$log->birthday_type),
            'counts' => $logs->countBy('status'),
            'upcoming' => $upcoming->take(30), 'upcomingCount' => $upcoming->count(),
            'history' => BirthdayGreeting::with(['customer', 'messageRecord'])->latest('id')->paginate(15),
            'statuses' => BirthdayGreeting::STATUSES,
            'previewCustomers' => Customer::orderBy('name')->limit(200)->get(['id', 'name']),
            'preview' => $preview,
            'canProcess' => $this->service->canDispatch(),
            'lastCheck' => $this->settings->get('crm.birthday_scheduler_last_check'),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'include_customer' => ['required', 'boolean'],
            'include_baby' => ['required', 'boolean'],
            'send_time' => ['required', 'date_format:H:i'],
            'leap_day' => ['required', Rule::in(['feb28', 'mar1'])],
            'customer_template' => ['required', 'string', 'max:3000'],
            'baby_template' => ['required', 'string', 'max:3000'],
        ]);
        foreach (['enabled', 'include_customer', 'include_baby'] as $key) {
            $data[$key] = (bool) $data[$key];
        }
        if (! $data['include_customer'] && ! $data['include_baby']) {
            throw ValidationException::withMessages(['include_customer' => 'Pilih minimal satu jenis ulang tahun.']);
        }

        return $data;
    }
}
