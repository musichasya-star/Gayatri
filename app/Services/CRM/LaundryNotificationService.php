<?php

namespace App\Services\CRM;

use App\Jobs\SendLaundryNotificationJob;
use App\Models\LaundryNotification;
use App\Models\LaundryOrder;
use App\Models\LaundryPayment;
use App\Models\LaundryTransportTask;
use App\Models\User;
use App\Services\AppSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LaundryNotificationService
{
    public const MAX_MESSAGE_LENGTH = 6000;

    public const EVENTS = ['portal_shared' => 'Bagikan portal pribadi', 'proof_rejected' => 'Bukti perlu diperbaiki', 'received' => 'Penerimaan laundry', 'weighed' => 'Hasil timbang', 'payment' => 'Pembayaran tercatat', 'ready' => 'Siap diambil', 'handed_over' => 'Barang diserahkan', 'task_scheduled' => 'Jadwal antar-jemput', 'transport_started' => 'Petugas berangkat', 'transport_completed' => 'Kunjungan selesai', 'transport_failed' => 'Kunjungan gagal', 'transport_cancelled' => 'Jadwal dibatalkan', 'balance_reminder' => 'Pengingat sisa tagihan'];

    public const VARIABLES = ['nama', 'kode', 'outlet', 'total', 'sisa', 'nominal', 'jadwal', 'alamat', 'jenis', 'ongkir_jemput', 'ongkir_antar', 'tautan_portal', 'catatan'];

    public function __construct(private readonly AppSettingService $settings, private readonly AuditLogService $audit) {}

    public function settings(): array
    {
        $defaults = ['enabled' => false, 'dry_run' => true, 'events' => array_keys(self::EVENTS), 'start_hour' => 8, 'end_hour' => 18, 'min_interval_seconds' => 30, 'reminder_after_days' => 1, 'max_reminders' => 5,
            'templates' => [
                'received' => 'Halo {nama}, laundry {kode} telah diterima di {outlet}. Nilai akhir dikonfirmasi setelah penimbangan. Terima kasih.',
                'portal_shared' => 'Halo {nama}, berikut portal pribadi laundry {kode}: {tautan_portal}. Simpan tautan ini; hanya bagikan kepada pihak yang Anda percaya. {outlet}.',
                'proof_rejected' => 'Halo {nama}, bukti pembayaran laundry {kode} perlu diperbaiki: {catatan}. Tagihan belum berkurang. Periksa dan unggah ulang di portal: {tautan_portal}.',
                'weighed' => 'Halo {nama}, hasil timbang laundry {kode} sudah dicatat. Total sementara {total}, termasuk jemput {ongkir_jemput} dan antar {ongkir_antar}. Mohon konfirmasi kepada admin sebelum pengerjaan. {outlet}.',
                'payment' => 'Halo {nama}, pembayaran {nominal} untuk laundry {kode} telah dicatat admin. Total tagihan {total}, sisa {sisa}. Terima kasih, {outlet}.',
                'ready' => 'Halo {nama}, laundry {kode} sudah lulus pemeriksaan dan siap diambil. Sisa tagihan {sisa}. Hubungi admin untuk pengambilan atau pengantaran. {outlet}.',
                'handed_over' => 'Halo {nama}, laundry {kode} sudah diserahkan kepada penerima yang dicatat admin. Terima kasih telah menggunakan {outlet}.',
                'task_scheduled' => 'Halo {nama}, {jenis} laundry {kode} dijadwalkan {jadwal}. Alamat: {alamat}. Hubungi admin jika perlu perubahan.',
                'transport_started' => 'Halo {nama}, petugas {jenis} laundry {kode} sedang dalam perjalanan ke {alamat}. {outlet}.',
                'transport_completed' => 'Halo {nama}, kunjungan {jenis} laundry {kode} telah selesai dicatat petugas. Terima kasih, {outlet}.',
                'transport_failed' => 'Halo {nama}, kunjungan {jenis} laundry {kode} belum berhasil. Hubungi admin untuk penjadwalan ulang. {outlet}.',
                'transport_cancelled' => 'Halo {nama}, jadwal {jenis} laundry {kode} dibatalkan. Hubungi admin untuk pengaturan selanjutnya. {outlet}.',
                'balance_reminder' => 'Halo {nama}, pengingat sisa tagihan laundry {kode}: {sisa}, dari total {total}. Jika sudah membayar, hubungi admin untuk pencocokan. Terima kasih, {outlet}.',
            ]];
        $stored = json_decode($this->settings->get('laundry.notifications', '{}'), true) ?: [];
        $result = array_replace($defaults, $stored);
        $result['templates'] = array_replace($defaults['templates'], $stored['templates'] ?? []);

        return $result;
    }

    public function save(array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($data, $actor, $request) {
            $before = $this->settings();
            $this->settings->set('laundry.notifications', json_encode($data));
            $this->audit->log($actor, 'laundry.notifications.settings', null, $request, $before, $data, 'Pengaturan notifikasi laundry tersimpan.');
        });
    }

    public function captureOrder(LaundryOrder $order, string $event, array $details): ?LaundryNotification
    {
        $kind = ['received' => 'received', 'weighed' => 'weighed', 'payment_recorded' => 'payment', 'quality_passed' => 'ready', 'handed_over' => 'handed_over', 'proof_rejected' => 'proof_rejected'][$event] ?? null;
        if (! $kind) {
            return null;
        }

        return $this->enqueue($order, null, $kind, 'order:'.$order->id.':'.$order->revision.':'.$event, ['payment_id' => $details['payment_id'] ?? null, 'proof_id' => $details['proof_id'] ?? null]);
    }

    public function captureTask(LaundryTransportTask $task, string $event): ?LaundryNotification
    {
        if ($task->kind === 'delivery' && $event === 'complete') {
            return null;
        }
        $kind = ['scheduled' => 'task_scheduled', 'rescheduled' => 'task_scheduled', 'start' => 'transport_started', 'complete' => 'transport_completed', 'fail' => 'transport_failed', 'cancel' => 'transport_cancelled'][$event] ?? null;

        return $kind ? $this->enqueue($task->order, $task, $kind, 'task:'.$task->id.':'.$task->revision.':'.$event) : null;
    }

    private function phone(?string $number): ?string
    {
        $number = preg_replace('/\D+/', '', $number ?? '');
        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }

        return preg_match('/^62[1-9]\d{7,12}$/D', $number) ? $number : null;
    }

    public function recipient(?LaundryOrder $order, ?LaundryTransportTask $task, bool $live = true): ?string
    {
        $customer = $order?->customer ?? $task?->customer;
        $snapshot = $order?->customer_snapshot ?? $task?->customer_snapshot;
        if (! $customer || ! $snapshot || ($live && (($order?->is_demo ?? $task?->is_demo) || in_array($customer->status, ['inactive', 'blacklisted'], true)))) {
            return null;
        }
        $number = $this->phone($snapshot['whatsapp_number'] ?: $snapshot['phone']);
        $entry = $order?->publicRequest;
        if ($entry && (! $entry->verified_at || $entry->status !== 'received' || $number !== $this->phone($entry->whatsapp_number))) {
            return null;
        }
        if (! $number || $number !== $this->phone($customer->whatsapp_number ?: $customer->phone)) {
            return null;
        }

        return $number.'@c.us';
    }

    private function facts(?LaundryOrder $order, ?LaundryTransportTask $task, string $kind, array $context): ?array
    {
        if ($order?->status === 'cancelled') {
            return null;
        }
        $facts = ['destination' => $this->recipient($order, $task, false), 'customer' => $order?->customer_snapshot ?? $task?->customer_snapshot];
        $entry = $order?->publicRequest;
        if ($entry) {
            if (! $entry->verified_at || $entry->status !== 'received') {
                return null;
            }
            $facts['portal'] = [$entry->id, $entry->portal_version, $entry->portal_revoked_at?->toIso8601String(), $entry->portal_expires_at->toIso8601String()];
        }
        if (in_array($kind, ['portal_shared', 'proof_rejected'], true) && (! $entry || ! app(LaundryPortal::class)->link($entry))) {
            return null;
        }
        if ($kind === 'proof_rejected') {
            $proof = $order?->paymentProofs()->whereKey($context['proof_id'] ?? 0)->where('status', 'rejected')->first();
            if (! $proof) {
                return null;
            }
            $facts['proof'] = [$proof->id, $proof->revision, $proof->review_notes];
        }
        if ($order && $kind !== 'received' && ! $task) {
            $facts['total'] = $order->totalDue();
        }
        if ($kind === 'weighed' && ($order?->actual_total === null || $order->status !== 'awaiting_approval')) {
            return null;
        }
        if ($kind === 'ready') {
            if ($order?->workflow_status !== 'ready') {
                return null;
            }
            $facts['ready_at'] = $order->ready_at->toIso8601String();
        }
        if ($kind === 'handed_over' && $order?->workflow_status !== 'handed_over') {
            return null;
        }
        if ($kind === 'payment') {
            $payment = $order?->payments()->whereKey($context['payment_id'] ?? 0)->whereNull('voided_at')->first();
            if (! $payment) {
                return null;
            }
            $facts['payment'] = $payment->only(['id', 'amount', 'method', 'paid_at']);
        }
        if ($kind === 'balance_reminder') {
            if ($order?->status !== 'approved' || ($order->balance() ?? 0) <= 0) {
                return null;
            }
            $facts['balance'] = $order->balance();
        }
        if ($task) {
            $expected = ['task_scheduled' => 'planned', 'transport_started' => 'in_transit', 'transport_completed' => 'completed', 'transport_failed' => 'failed', 'transport_cancelled' => 'cancelled'][$kind] ?? null;
            if ($expected !== $task->status) {
                return null;
            }
            $facts['task'] = [$task->id, $task->revision, $task->status, $task->address, $task->scheduled_start->toIso8601String()];
        }

        return $facts;
    }

    public function render(?LaundryOrder $order, ?LaundryTransportTask $task, string $kind, array $context = [], bool $forDelivery = false): string
    {
        $snapshot = $order?->customer_snapshot ?? $task->customer_snapshot;
        $money = fn ($value) => $value === null ? 'belum tersedia' : 'Rp '.number_format($value, 0, ',', '.');
        $payment = isset($context['payment_id']) ? LaundryPayment::find($context['payment_id']) : null;
        $values = ['nama' => $snapshot['name'], 'kode' => $order?->code ?? 'JEMPUT-'.$task?->id, 'outlet' => $order?->outlet_snapshot['outlet_name'] ?? app(LaundrySettings::class)->get()['outlet_name'], 'total' => $money($order?->totalDue()), 'sisa' => $money($order?->balance()), 'nominal' => $money($payment?->amount), 'jenis' => $task ? LaundryTransportTask::KINDS[$task->kind] : 'kunjungan', 'jadwal' => $task ? $task->scheduled_start->format('d M Y H:i').'–'.$task->scheduled_end->format('H:i').' WIB' : '-', 'alamat' => $task?->address ?? '-', 'ongkir_jemput' => $money($order ? (int) $order->pickup_fee : ($task?->kind === 'pickup' ? $task->quote_snapshot['fee'] : 0)), 'ongkir_antar' => $money($order ? (int) $order->delivery_fee : ($task?->kind === 'delivery' ? $task->quote_snapshot['fee'] : 0))];
        $replace = [];
        $entry = $order?->publicRequest;
        $portalLink = $entry && $entry->verified_at && $entry->status === 'received' ? app(LaundryPortal::class)->link($entry) : null;
        $values['tautan_portal'] = $portalLink ? ($forDelivery ? $portalLink : LaundryPortal::redact($portalLink)) : 'Hubungi admin untuk akses portal baru.';
        $values['catatan'] = $order?->paymentProofs()->whereKey($context['proof_id'] ?? 0)->where('status', 'rejected')->value('review_notes') ?? '-';
        foreach ($values as $key => $value) {
            $replace['{'.$key.'}'] = (string) $value;
        }

        $template = $this->settings()['templates'][$kind];
        if ($portalLink && in_array($kind, ['weighed', 'payment', 'ready', 'balance_reminder'], true) && ! str_contains($template, '{tautan_portal}')) {
            $template .= ' Portal pribadi: {tautan_portal}';
        }

        return strtr($template, $replace);
    }

    public function enqueue(?LaundryOrder $order, ?LaundryTransportTask $task, string $kind, string $eventKey, array $context = []): ?LaundryNotification
    {
        $settings = $this->settings();
        if (! $settings['enabled'] || ! in_array($kind, $settings['events'], true)) {
            return null;
        }
        $order = $order?->fresh();
        $task = $task?->fresh();
        $facts = $this->facts($order, $task, $kind, $context);
        $recipient = $this->recipient($order, $task, ! $settings['dry_run']);
        $status = ! $facts || ! $recipient ? 'skipped' : ($settings['dry_run'] ? 'simulated' : 'queued');
        $content = $this->render($order, $task, $kind, $context);
        $tooLong = mb_strlen($content) > self::MAX_MESSAGE_LENGTH;
        if ($tooLong) {
            $status = 'skipped';
            $content = mb_substr($content, 0, self::MAX_MESSAGE_LENGTH).'…';
        }
        $row = LaundryNotification::firstOrCreate(['event_key' => hash('sha256', $eventKey)], ['laundry_order_id' => $order?->id, 'laundry_transport_task_id' => $task?->id, 'kind' => $kind, 'status' => $status, 'context' => $context, 'fingerprint' => hash('sha256', json_encode($facts)), 'chat_id' => $recipient, 'content' => $content, 'failure_reason' => $tooLong ? 'Pesan terlalu panjang. Ringkas template sebelum mengirim; tampilan riwayat dipotong.' : ($status === 'skipped' ? 'Tujuan/status tidak sesuai, kontak berubah, pelanggan nonaktif, atau data demo pada mode nyata.' : null), 'expires_at' => now()->addDay()]);
        if ($row->wasRecentlyCreated && $row->status === 'queued') {
            SendLaundryNotificationJob::dispatch($row->id)->onQueue('laundry')->afterCommit();
        }

        return $row;
    }

    public function current(LaundryNotification $row): bool
    {
        if ($row->expires_at->isPast()) {
            return false;
        }
        $facts = $this->facts($row->order, $row->task, $row->kind, $row->context);

        return $facts !== null && hash('sha256', json_encode($facts)) === $row->fingerprint;
    }

    public function retry(LaundryNotification $row, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($row, $actor, $request) {
            $row = LaundryNotification::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $settings = $this->settings();
            if (! in_array($row->status, ['failed', 'unknown'], true) || ! $settings['enabled'] || ! in_array($row->kind, $settings['events'], true)) {
                throw ValidationException::withMessages(['notification' => 'Hanya pesan gagal/perlu diperiksa yang dapat dicoba ulang pada fitur aktif.']);
            }
            $facts = $this->facts($row->order, $row->task, $row->kind, $row->context);
            $recipient = $this->recipient($row->order, $row->task, ! $settings['dry_run']);
            if (! $facts || ! $recipient) {
                throw ValidationException::withMessages(['notification' => 'Status atau tujuan sudah tidak sesuai.']);
            }
            $content = $this->render($row->order, $row->task, $row->kind, $row->context);
            if (mb_strlen($content) > self::MAX_MESSAGE_LENGTH) {
                throw ValidationException::withMessages(['notification' => 'Ringkas template, hasil pesan melebihi batas panjang.']);
            }
            $row->update(['status' => $settings['dry_run'] ? 'simulated' : 'queued', 'fingerprint' => hash('sha256', json_encode($facts)), 'chat_id' => $recipient, 'content' => $content, 'failure_reason' => null, 'started_at' => null, 'expires_at' => now()->addDay()]);
            $this->audit->log($actor, 'laundry.notification.retry', $row, $request, [], ['reason' => $request->input('reason')], 'Admin memeriksa WhatsApp dan meminta percobaan ulang.');
            if ($row->status === 'queued') {
                SendLaundryNotificationJob::dispatch($row->id)->onQueue('laundry')->afterCommit();
            }
        });
    }

    public function reminders(): int
    {
        LaundryNotification::where('status', 'sending')->where('started_at', '<', now()->subMinutes(10))->update(['status' => 'unknown', 'failure_reason' => 'Proses terputus. Periksa WhatsApp sebelum mencoba ulang.']);
        LaundryNotification::where('status', 'queued')->where('expires_at', '<', now())->update(['status' => 'skipped', 'failure_reason' => 'Batas waktu pengiriman terlewati.']);
        $settings = $this->settings();
        if (! $settings['enabled'] || ! in_array('balance_reminder', $settings['events'], true) || now()->hour < $settings['start_hour'] || now()->hour >= $settings['end_hour']) {
            return 0;
        }
        $count = 0;
        LaundryOrder::where('status', 'approved')->where('is_demo', false)->where('received_at', '<=', now()->subDays($settings['reminder_after_days']))->orderBy('id')->chunkById(100, function ($orders) use (&$count, $settings) {
            foreach ($orders as $order) {
                $counted = $settings['dry_run'] ? ['sent', 'queued', 'simulated', 'sending', 'unknown', 'failed'] : ['sent', 'queued', 'sending', 'unknown', 'failed'];
                if (($order->balance() ?? 0) <= 0 || LaundryNotification::where('laundry_order_id', $order->id)->where('kind', 'balance_reminder')->whereIn('status', $counted)->count() >= $settings['max_reminders']) {
                    continue;
                }
                $row = $this->enqueue($order, null, 'balance_reminder', 'reminder:'.$order->id.':'.today()->toDateString());
                $count += $row?->wasRecentlyCreated ? 1 : 0;
            }
        });

        return $count;
    }
}
