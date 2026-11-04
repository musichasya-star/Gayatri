<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendRentalNotificationJob;
use App\Models\RentalNotification;
use App\Models\RentalOrder;
use App\Services\AppSettingService;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\RentalMessagingService;
use App\Services\CRM\RentalNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class RentalNotificationController extends Controller
{
    public function index(Request $request, RentalNotificationService $service, AppSettingService $settings)
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(RentalNotification::STATUSES))]]);

        return view('admin.rentals.notifications', [
            'settings' => $service->settings(), 'events' => RentalNotificationService::EVENTS,
            'notifications' => RentalNotification::with('order.customer')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->latest('id')->paginate(20)->withQueryString(),
            'orders' => RentalOrder::with('customer')->latest('id')->limit(50)->get(),
            'lastRun' => $settings->get('rental.scheduler_last_ran_at'),
        ]);
    }

    public function update(Request $request, RentalNotificationService $service, AuditLogService $audit)
    {
        $rules = ['enabled' => ['required', 'boolean'], 'events' => ['nullable', 'array'], 'events.*' => [Rule::in(array_keys(RentalNotificationService::EVENTS)), 'distinct'], 'send_time' => ['required', 'date_format:H:i'], 'reminder_days' => ['required', 'integer', 'min:0', 'max:7'], 'min_interval_seconds' => ['required', 'integer', 'min:2', 'max:60'], 'templates' => ['required', 'array']];
        $rules += ['payment_reminder_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:7'], 'payment_reminder_interval_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:7'], 'payment_max_reminders' => ['sometimes', 'required', 'integer', 'min:1', 'max:30'],
            'public_base_url' => ['sometimes', 'nullable', 'url:https', 'max:250', function ($attribute, $value, $fail) {
                if ($value && ! app(RentalMessagingService::class)->baseUrl(['public_base_url' => $value])) {
                    $fail('Gunakan alamat publik HTTPS tanpa path, query, fragment, atau kredensial.');
                }
            }]];
        foreach (array_keys(RentalNotificationService::EVENTS) as $kind) {
            $rules['templates.'.$kind] = ['required', 'string', 'max:3000', $this->templateRule()];
        }
        $data = $request->validate($rules);
        $data['enabled'] = $request->boolean('enabled');
        $data['events'] = $data['events'] ?? [];
        $data = array_replace($service->settings(), $data);
        $data['public_base_url'] = rtrim($data['public_base_url'] ?? '', '/');
        DB::transaction(function () use ($service, $audit, $request, $data) {
            $old = $service->settings();
            $service->save($data);
            $audit->log($request->user(), 'rental.notifications.settings', null, $request, $old, $data, 'Pengaturan notifikasi rental diperbarui');
        });

        return back()->with('status', 'Pengaturan notifikasi WhatsApp rental tersimpan.');
    }

    public function preview(Request $request, RentalNotificationService $service)
    {
        $data = $request->validate(['order_id' => ['required', 'integer', 'exists:rental_orders,id'], 'kind' => ['required', Rule::in(array_keys(RentalNotificationService::EVENTS))], 'template' => ['nullable', 'string', 'max:3000', $this->templateRule()]]);
        $settings = $service->settings();
        if (filled($data['template'] ?? null)) {
            $settings['templates'][$data['kind']] = $data['template'];
        }
        $order = RentalOrder::findOrFail($data['order_id']);

        return response()->json(['preview' => $service->render($order, $data['kind'], ['extension_id' => $order->extensions()->latest('id')->value('id'), 'payment_id' => $order->payments()->where('kind', $data['kind'] === 'deposit_refunded' ? 'refund' : 'payment')->latest('id')->value('id'),
            'proof_id' => $order->paymentProofs()->where('status', $data['kind'] === 'proof_rejected' ? 'rejected' : 'approved')->latest('id')->value('id'),
            'request_id' => $order->customerRequests()->where('status', $data['kind'] === 'request_rejected' ? 'rejected' : 'approved')->latest('id')->value('id'),
            'extension_request_id' => $order->extensionRequests()->where('status', 'rejected')->latest('id')->value('id'), 'item_id' => $order->items()->whereNotNull('returned_at')->latest('id')->value('id')], $settings)]);
    }

    public function retry(Request $request, RentalNotification $notification, RentalNotificationService $service, AuditLogService $audit)
    {
        $data = $request->validate(['checked_not_sent' => ['sometimes', 'accepted']]);
        try {
            DB::transaction(function () use ($notification, $service, $audit, $request, $data) {
                $row = RentalNotification::whereKey($notification->id)->lockForUpdate()->firstOrFail();
                if (! in_array($row->status, ['failed', 'unknown'], true) || ! $service->current($row) || ! $service->settings()['enabled'] || ! in_array($row->kind, $service->settings()['events'], true) || ! $service->recipientFor($row->order, $row->kind)) {
                    throw new InvalidArgumentException('Pesan tidak dapat dicoba ulang. Periksa pengaturan, pelanggan, status, dan tanggal transaksi.');
                }
                if ($row->status === 'unknown' && empty($data['checked_not_sent'])) {
                    throw new InvalidArgumentException('Periksa WhatsApp dan centang bahwa pesan belum terkirim sebelum mencoba ulang.');
                }
                $oldStatus = $row->status;
                $row->update(['status' => 'queued', 'failure_reason' => null, 'started_at' => null]);
                $audit->log($request->user(), 'rental.notification.retry', $row, $request, ['status' => $oldStatus], ['status' => 'queued', 'checked_not_sent' => ! empty($data['checked_not_sent'])], 'Notifikasi rental dimasukkan kembali ke antrean');
                SendRentalNotificationJob::dispatch($row->id)->afterCommit();
            });
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rental' => $e->getMessage()]);
        }

        return back()->with('status', 'Notifikasi masuk kembali ke antrean.');
    }

    private function templateRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            preg_match_all('/\{([^{}]+)\}/', $value, $matches);
            if (array_diff($matches[1], RentalNotificationService::VARIABLES)) {
                $fail('Template memuat variabel yang tidak dikenal. Gunakan daftar variabel yang tersedia.');
            }
        };
    }
}
