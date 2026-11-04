<?php

namespace App\Services\CRM;

use App\Jobs\SendRentalNotificationJob;
use App\Models\RentalCustomerRequest;
use App\Models\RentalNotification;
use App\Models\RentalOrder;
use App\Services\AppSettingService;
use Carbon\Carbon;

class RentalNotificationService
{
    public const EVENTS = ['confirmed' => 'Konfirmasi sewa', 'return_reminder' => 'Pengingat pengembalian', 'overdue' => 'Keterlambatan', 'extended' => 'Perpanjangan disetujui', 'returned' => 'Unit diterima kembali', 'completed' => 'Pemeriksaan selesai', 'deposit_refunded' => 'Pengembalian dana', 'cancelled' => 'Pembatalan sewa',
        'dp_reminder' => 'Pengingat DP', 'balance_reminder' => 'Pengingat pelunasan', 'payment_received' => 'Pembayaran dicatat admin', 'proof_approved' => 'Bukti pembayaran disetujui', 'proof_rejected' => 'Bukti pembayaran ditolak', 'request_approved' => 'Pengajuan pembatalan/refund disetujui', 'request_rejected' => 'Pengajuan pembatalan/refund ditolak', 'extension_rejected' => 'Pengajuan perpanjangan ditolak', 'portal_shared' => 'Tautan portal pribadi'];

    public const VARIABLES = ['nama', 'kode_sewa', 'alat', 'tanggal_mulai', 'tanggal_kembali', 'total_sewa', 'deposit', 'sisa_tagihan', 'saldo_deposit', 'hari_terlambat', 'unit_kembali', 'unit_tersisa', 'tanggal_lama', 'biaya_perpanjangan', 'refund', 'kantor', 'tautan_portal', 'dp_minimum', 'sisa_dp', 'batas_dp', 'batas_pelunasan', 'nominal_pembayaran', 'jenis_pembayaran', 'catatan_keputusan', 'jenis_pengajuan', 'refund_disetujui'];

    public const VERIFIED_EVENTS = ['dp_reminder', 'balance_reminder', 'payment_received', 'proof_approved', 'proof_rejected', 'request_approved', 'request_rejected', 'extension_rejected', 'portal_shared'];

    public function __construct(private readonly AppSettingService $settings) {}

    public function settings(): array
    {
        $defaults = ['enabled' => false, 'events' => array_keys(self::EVENTS), 'send_time' => '09:00', 'reminder_days' => 1, 'min_interval_seconds' => 10, 'payment_reminder_days' => 1, 'payment_reminder_interval_days' => 1, 'payment_max_reminders' => 5, 'public_base_url' => '',
            'templates' => [
                'confirmed' => 'Halo {nama}, sewa {kode_sewa} dikonfirmasi. Alat: {alat}. Periode {tanggal_mulai} sampai {tanggal_kembali}. Biaya sewa {total_sewa}, deposit {deposit}. Sisa tagihan {sisa_tagihan}. Terima kasih, {kantor}.',
                'return_reminder' => 'Halo {nama}, pengingat pengembalian sewa {kode_sewa}: {alat}, tanggal {tanggal_kembali}. Jika ingin memperpanjang, hubungi admin agar ketersediaan diperiksa terlebih dahulu. {kantor}.',
                'overdue' => 'Halo {nama}, unit sewa {kode_sewa} belum kembali sejak {tanggal_kembali} ({hari_terlambat} hari). Mohon hubungi admin untuk mengatur pengembalian atau perpanjangan. Terima kasih, {kantor}.',
                'extended' => 'Halo {nama}, perpanjangan {kode_sewa} disetujui dari {tanggal_lama} menjadi {tanggal_kembali}. Tambahan biaya {biaya_perpanjangan}. Sisa tagihan {sisa_tagihan}. {kantor}.',
                'returned' => 'Halo {nama}, unit {unit_kembali} dari sewa {kode_sewa} sudah diterima dan menunggu pemeriksaan. Unit yang masih di luar: {unit_tersisa}. Deposit diselesaikan setelah seluruh pemeriksaan selesai. {kantor}.',
                'completed' => 'Halo {nama}, pemeriksaan sewa {kode_sewa} selesai. Sisa tagihan {sisa_tagihan}. Saldo yang dapat dikembalikan {saldo_deposit}. Hubungi admin untuk penyelesaian. {kantor}.',
                'deposit_refunded' => 'Halo {nama}, pengembalian dana {refund} untuk sewa {kode_sewa} sudah dicatat admin. Saldo pengembalian tersisa {saldo_deposit}. Terima kasih, {kantor}.',
                'cancelled' => 'Halo {nama}, sewa {kode_sewa} dibatalkan. Saldo yang dapat dikembalikan {saldo_deposit}. Hubungi admin untuk penyelesaiannya. {kantor}.',
                'dp_reminder' => 'Halo {nama}, pengingat DP sewa {kode_sewa}. Kekurangan DP {sisa_dp}, batas {batas_dp}. Unggah bukti melalui portal pribadi: {tautan_portal}. Abaikan transfer baru jika sudah membayar dan hubungi admin. {kantor}.',
                'balance_reminder' => 'Halo {nama}, sisa tagihan sewa {kode_sewa} {sisa_tagihan}. Batas pelunasan {batas_pelunasan}. Periksa rincian dan unggah bukti: {tautan_portal}. Hubungi admin jika sudah membayar. {kantor}.',
                'payment_received' => 'Halo {nama}, pembayaran {nominal_pembayaran} untuk {kode_sewa} sudah dicatat admin. Sisa tagihan {sisa_tagihan}. Portal pribadi: {tautan_portal}. {kantor}.',
                'proof_approved' => 'Halo {nama}, bukti {jenis_pembayaran} {nominal_pembayaran} untuk {kode_sewa} disetujui. Sisa tagihan {sisa_tagihan}. Catatan: {catatan_keputusan}. Kuitansi dan rincian: {tautan_portal}. {kantor}.',
                'proof_rejected' => 'Halo {nama}, bukti {jenis_pembayaran} {nominal_pembayaran} untuk {kode_sewa} belum dapat disetujui. Alasan: {catatan_keputusan}. Tinjau dan unggah pengganti: {tautan_portal}. {kantor}.',
                'request_approved' => 'Halo {nama}, pengajuan {jenis_pengajuan} sewa {kode_sewa} disetujui. Catatan: {catatan_keputusan}. Refund disetujui {refund_disetujui}; persetujuan belum berarti dana dikembalikan. Status dan bukti terbaru: {tautan_portal}. {kantor}.',
                'request_rejected' => 'Halo {nama}, pengajuan {jenis_pengajuan} sewa {kode_sewa} ditolak. Catatan: {catatan_keputusan}. Rincian: {tautan_portal}. {kantor}.',
                'extension_rejected' => 'Halo {nama}, pengajuan perpanjangan {kode_sewa} belum disetujui. Catatan: {catatan_keputusan}. Tanggal kembali tetap {tanggal_kembali}. Portal: {tautan_portal}. {kantor}.',
                'portal_shared' => 'Halo {nama}, ini portal pribadi pesanan {kode_sewa}: {tautan_portal}. Anda dapat membaca ketentuan, melihat tagihan/kuitansi, mengunggah bukti, serta mengajukan perpanjangan atau pembatalan/refund. Simpan tautan untuk Anda sendiri. {kantor}.',
            ]];
        $stored = json_decode($this->settings->get('rental.notifications', '{}'), true) ?: [];
        $result = array_replace($defaults, $stored);
        $result['templates'] = array_replace($defaults['templates'], $stored['templates'] ?? []);

        return $result;
    }

    public function save(array $settings): void
    {
        $this->settings->set('rental.notifications', json_encode($settings));
    }

    public function recipient(RentalOrder $order): ?string
    {
        $customer = $order->customer;
        if (! $customer || in_array($customer->status, ['inactive', 'blacklisted'], true)) {
            return null;
        }
        if ($order->notification_verified_at && preg_match('/^62[1-9]\d{7,13}$/', (string) $order->notification_phone)) {
            return $order->notification_phone.'@c.us';
        }
        $number = $customer->normalized_whatsapp_number;
        if ($number && preg_match('/^62[1-9]\d{7,13}$/', $number)) {
            return $number.'@c.us';
        }
        $conversation = $customer->conversations()->whereNotNull('wa_chat_id')->latest('last_message_at')->first();
        if ($conversation && preg_match('/^\d{5,20}@(c\.us|s\.whatsapp\.net|lid)$/', $conversation->wa_chat_id)) {
            return $conversation->wa_chat_id;
        }

        return null;
    }

    public function enqueue(RentalOrder $order, string $kind, string $event, array $context = []): ?RentalNotification
    {
        $settings = $this->settings();
        if (! $settings['enabled'] || ! in_array($kind, $settings['events'], true)) {
            return null;
        }
        $order = $order->fresh();
        $recipient = $this->recipientFor($order, $kind, $settings);
        $context = array_merge($context, ['recipient_hash' => $recipient ? hash('sha256', $recipient) : null, 'contact_version' => $order->notification_contact_version, 'portal_version' => $order->portal_version]);
        $row = RentalNotification::firstOrCreate(['event_key' => $order->id.':'.$kind.':'.$event], [
            'rental_order_id' => $order->id, 'kind' => $kind, 'context' => $context,
            'status' => $recipient ? 'queued' : 'skipped',
            'failure_reason' => $recipient ? null : 'Tujuan WhatsApp belum dicocokkan admin, tidak valid, atau pelanggan tidak aktif.',
            'expires_at' => in_array($kind, ['return_reminder', 'overdue', 'dp_reminder', 'balance_reminder'], true) ? now('Asia/Jakarta')->endOfDay() : now()->addDays(2),
        ]);
        if ($row->wasRecentlyCreated && $row->status === 'queued') {
            SendRentalNotificationJob::dispatch($row->id)->afterCommit();
        }

        return $row;
    }

    public function requiresVerified(string $kind, array $settings): bool
    {
        return in_array($kind, self::VERIFIED_EVENTS, true) || str_contains($settings['templates'][$kind] ?? '', '{tautan_portal}');
    }

    public function recipientFor(RentalOrder $order, string $kind, ?array $settings = null): ?string
    {
        $settings ??= $this->settings();
        if ($this->requiresVerified($kind, $settings) && ! $order->notification_verified_at) {
            return null;
        }

        return $this->recipient($order);
    }

    public function paymentDue(RentalOrder $order, ?array $settings = null): ?array
    {
        $settings ??= $this->settings();
        $summary = app(RentalPaymentTerms::class)->summary($order);
        if (! $summary['payable'] || $order->paymentProofs()->where('status', 'pending')->exists() || $order->customerRequests()->where('kind', 'cancellation')->where('status', 'pending')->exists()) {
            return null;
        }
        $kind = $summary['dp_remaining'] > 0 && in_array($order->status, ['reserved', 'rented'], true) && $order->dp_due_at && in_array('dp_reminder', $settings['events'], true) ? 'dp_reminder' : 'balance_reminder';
        $deadline = $kind === 'dp_reminder' ? $order->dp_due_at : $order->balance_due_at;
        if (! $deadline || $deadline->copy()->startOfDay()->gt(today()->addDays((int) $settings['payment_reminder_days']))) {
            return null;
        }

        return ['kind' => $kind, 'deadline' => $deadline->toIso8601String()];
    }

    public function dispatchDue(): int
    {
        $this->settings->set('rental.scheduler_last_ran_at', now('Asia/Jakarta')->format('d M Y H:i:s').' WIB');
        RentalNotification::where('status', 'sending')->where('started_at', '<', now()->subMinutes(3))->update(['status' => 'unknown', 'failure_reason' => 'Proses pengiriman terputus. Periksa WhatsApp sebelum mencoba ulang.']);
        RentalNotification::where('status', 'queued')->where('expires_at', '<', now())->update(['status' => 'skipped', 'failure_reason' => 'Batas waktu pengiriman terlewati.']);
        $settings = $this->settings();
        if (! $settings['enabled'] || now('Asia/Jakarta')->format('H:i') < $settings['send_time']) {
            return 0;
        }
        $count = 0;
        $date = now('Asia/Jakarta')->toDateString();
        RentalOrder::where('status', 'rented')->whereHas('items', fn ($q) => $q->whereNull('returned_at'))->whereDate('end_date', '<=', now('Asia/Jakarta')->addDays((int) $settings['reminder_days'])->toDateString())
            ->with('customer')->chunkById(100, function ($orders) use (&$count, $settings, $date) {
                foreach ($orders as $order) {
                    $kind = $order->end_date->lt(Carbon::parse($date)) ? 'overdue' : ($order->end_date->toDateString() === Carbon::parse($date)->addDays((int) $settings['reminder_days'])->toDateString() ? 'return_reminder' : null);
                    if (! $kind) {
                        continue;
                    }
                    $row = $this->enqueue($order, $kind, $date, ['on' => $date, 'end_date' => $order->end_date->toDateString(), 'schedule_version' => $order->schedule_version]);
                    if ($row?->wasRecentlyCreated && $row->status === 'queued') {
                        $count++;
                    }
                }
            });

        RentalOrder::whereIn('status', ['reserved', 'rented', 'inspection', 'completed'])->whereNotNull('payment_terms')->with('customer')->chunkById(100, function ($orders) use (&$count, $settings, $date) {
            foreach ($orders as $order) {
                $due = $this->paymentDue($order, $settings);
                if (! $due || ! in_array($due['kind'], $settings['events'], true)) {
                    continue;
                }
                $history = $order->notifications()->whereIn('kind', ['dp_reminder', 'balance_reminder']);
                if ((clone $history)->where('context->on', $date)->exists()) {
                    continue;
                }
                $attempted = (clone $history)->where('kind', $due['kind'])->whereIn('status', ['queued', 'sending', 'sent', 'failed', 'unknown']);
                if ((clone $attempted)->count() >= (int) $settings['payment_max_reminders']) {
                    continue;
                }
                $last = (clone $attempted)->latest('created_at')->first();
                if ($last && $last->created_at->copy()->startOfDay()->addDays((int) $settings['payment_reminder_interval_days'])->gt(today())) {
                    continue;
                }
                $row = $this->enqueue($order, $due['kind'], $date, ['on' => $date, 'deadline' => $due['deadline']]);
                if ($row?->wasRecentlyCreated && $row->status === 'queued') {
                    $count++;
                }
            }
        });

        return $count;
    }

    public function current(RentalNotification $notification): bool
    {
        $order = $notification->order;
        if (! $order || $notification->expires_at->isPast()) {
            return false;
        }
        $context = $notification->context ?? [];
        $settings = $this->settings();
        $recipient = $this->recipientFor($order, $notification->kind, $settings);
        if (array_key_exists('recipient_hash', $context) && (! $recipient || $context['recipient_hash'] !== hash('sha256', $recipient))) {
            return false;
        }
        if ($this->requiresVerified($notification->kind, $settings) && (int) ($context['contact_version'] ?? -1) !== $order->notification_contact_version) {
            return false;
        }
        if (str_contains($settings['templates'][$notification->kind] ?? '', '{tautan_portal}') && (int) ($context['portal_version'] ?? -1) !== $order->portal_version) {
            return false;
        }
        $due = in_array($notification->kind, ['dp_reminder', 'balance_reminder'], true) ? $this->paymentDue($order, $settings) : null;

        return match ($notification->kind) {
            'confirmed' => in_array($order->status, ['reserved', 'rented'], true),
            'extended' => in_array($order->status, ['reserved', 'rented'], true) && (int) ($context['schedule_version'] ?? 0) === (int) $order->schedule_version,
            'return_reminder', 'overdue' => $order->status === 'rented' && $order->items()->whereNull('returned_at')->exists() && ($context['on'] ?? '') === now('Asia/Jakarta')->toDateString() && ($context['end_date'] ?? '') === $order->end_date->toDateString() && (int) ($context['schedule_version'] ?? 0) === (int) $order->schedule_version && ($notification->kind === 'overdue' ? $order->end_date->lt(today()) : $order->end_date->gte(today())),
            'returned' => in_array($order->status, ['rented', 'inspection', 'completed'], true) && $order->items()->whereKey($context['item_id'] ?? 0)->whereNotNull('returned_at')->exists(),
            'completed' => $order->status === 'completed',
            'cancelled' => $order->status === 'cancelled',
            'deposit_refunded' => $order->payments()->whereKey($context['payment_id'] ?? 0)->where('kind', 'refund')->exists(),
            'dp_reminder', 'balance_reminder' => $due && $due['kind'] === $notification->kind && ($context['on'] ?? '') === today()->toDateString() && ($context['deadline'] ?? '') === $due['deadline'],
            'payment_received' => $order->status !== 'cancelled' && $order->payments()->whereKey($context['payment_id'] ?? 0)->where('kind', 'payment')->exists(),
            'proof_approved' => $order->status !== 'cancelled' && $order->paymentProofs()->whereKey($context['proof_id'] ?? 0)->where('status', 'approved')->exists(),
            'proof_rejected' => $order->status !== 'cancelled' && $order->paymentProofs()->whereKey($context['proof_id'] ?? 0)->where('status', 'rejected')->exists() && (int) $order->paymentProofs()->latest('id')->value('id') === (int) ($context['proof_id'] ?? 0),
            'request_approved' => $order->customerRequests()->whereKey($context['request_id'] ?? 0)->where('status', 'approved')->exists(),
            'request_rejected' => $order->customerRequests()->whereKey($context['request_id'] ?? 0)->where('status', 'rejected')->exists() && (int) $order->customerRequests()->latest('id')->value('id') === (int) ($context['request_id'] ?? 0),
            'extension_rejected' => in_array($order->status, ['reserved', 'rented'], true) && $order->extensionRequests()->whereKey($context['extension_request_id'] ?? 0)->where('status', 'rejected')->exists() && (int) $order->extensionRequests()->latest('id')->value('id') === (int) ($context['extension_request_id'] ?? 0),
            'portal_shared' => $order->notification_verified_at && app(RentalMessagingService::class)->tokenAvailable($order) && app(RentalMessagingService::class)->baseUrl($settings),
            default => false,
        };
    }

    public function render(RentalOrder $order, string $kind, array $context = [], ?array $settings = null, bool $private = false): string
    {
        $settings ??= $this->settings();
        $order->loadMissing(['customer', 'items', 'payments']);
        $money = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');
        $extension = $order->extensions()->whereKey($context['extension_id'] ?? 0)->first();
        $payment = $order->payments->firstWhere('id', $context['payment_id'] ?? 0);
        $item = $order->items->firstWhere('id', $context['item_id'] ?? 0);
        $proof = $order->paymentProofs()->whereKey($context['proof_id'] ?? 0)->first();
        $entry = $order->customerRequests()->whereKey($context['request_id'] ?? 0)->first();
        $extensionRequest = $order->extensionRequests()->whereKey($context['extension_request_id'] ?? 0)->first();
        $paymentSummary = app(RentalPaymentTerms::class)->summary($order);
        $listedItems = in_array($kind, ['return_reminder', 'overdue', 'extended'], true) ? $order->items->whereNull('returned_at') : $order->items;
        $values = ['nama' => $order->customer_snapshot['name'] ?? ($order->customer?->name ?: 'Pelanggan'), 'kode_sewa' => $order->code,
            'alat' => $listedItems->map(fn ($i) => $i->product_name.' ('.$i->unit_code.')')->join(', '),
            'tanggal_mulai' => $order->start_date->format('d M Y'), 'tanggal_kembali' => $order->end_date->format('d M Y'),
            'total_sewa' => $money($order->rental_subtotal), 'deposit' => $money($order->deposit_total), 'sisa_tagihan' => $money($order->balance()), 'saldo_deposit' => $money($order->refundable()),
            'hari_terlambat' => (string) max(0, (int) $order->end_date->diffInDays(today(), false)), 'unit_kembali' => $item?->unit_code ?: '-',
            'unit_tersisa' => (string) $order->items->whereNull('returned_at')->count(), 'tanggal_lama' => $extension?->old_end_date->format('d M Y') ?: '-',
            'biaya_perpanjangan' => $money($extension?->additional_charge ?? 0), 'refund' => $money($payment?->amount ?? 0),
            'kantor' => app(RentalSettings::class)->get()['office_name'],
            'tautan_portal' => app(RentalMessagingService::class)->link($order, $settings, $private),
            'dp_minimum' => $money($paymentSummary['minimum_dp']), 'sisa_dp' => $money($paymentSummary['dp_remaining']),
            'batas_dp' => $order->dp_due_at ? $order->dp_due_at->format('d M Y H:i').' WIB' : 'belum ditetapkan', 'batas_pelunasan' => $order->balance_due_at ? $order->balance_due_at->format('d M Y H:i').' WIB' : 'belum ditetapkan',
            'nominal_pembayaran' => $money($proof?->amount ?? $payment?->amount ?? 0), 'jenis_pembayaran' => $proof?->kind === 'dp' ? 'DP' : 'pelunasan',
            'catatan_keputusan' => $proof?->review_notes ?? $entry?->review_notes ?? $extensionRequest?->review_notes ?? '-',
            'jenis_pengajuan' => $entry ? RentalCustomerRequest::KINDS[$entry->kind] : 'pesanan', 'refund_disetujui' => $money($entry?->approved_amount ?? 0)];

        return strtr($settings['templates'][$kind], collect($values)->mapWithKeys(fn ($value, $key) => ['{'.$key.'}' => (string) $value])->all());
    }
}
