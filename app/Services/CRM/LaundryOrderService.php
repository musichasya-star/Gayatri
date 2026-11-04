<?php

namespace App\Services\CRM;

use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryTariff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LaundryOrderService
{
    public function __construct(private readonly LaundrySettings $settings, private readonly AuditLogService $audit) {}

    public static function phone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }
        if (! preg_match('/^62[1-9]\d{7,12}$/D', $phone)) {
            throw ValidationException::withMessages(['new_whatsapp' => 'Gunakan nomor WhatsApp Indonesia yang valid, diawali 08 atau 62.']);
        }

        return $phone;
    }

    private function customerSnapshot(array $data): array
    {
        if (! empty($data['customer_id'])) {
            $customer = Customer::findOrFail($data['customer_id']);

            return ['id' => $customer->id, 'name' => $customer->name, 'whatsapp_number' => $customer->whatsapp_number, 'phone' => $customer->phone];
        }
        $phone = self::phone($data['new_whatsapp']);
        if (Customer::where('whatsapp_number', $phone)->exists()) {
            throw ValidationException::withMessages(['new_whatsapp' => 'Nomor sudah terdaftar. Pilih pelanggan tersebut dari daftar pelanggan CRM.']);
        }

        return ['id' => null, 'name' => trim($data['new_name']), 'whatsapp_number' => $phone, 'phone' => $phone];
    }

    private function lines(array $data): array
    {
        $lines = [];
        $labels = [];
        foreach (array_values($data['items']) as $index => $input) {
            $tariff = LaundryTariff::where('is_active', true)->find($input['laundry_tariff_id']);
            if (! $tariff) {
                throw ValidationException::withMessages(['items' => 'Tarif tidak aktif/tidak ditemukan. Periksa ulang katalog laundry.']);
            }
            $label = trim($input['bag_label'] ?? '') ?: 'Kantong '.($index + 1);
            if (in_array(mb_strtolower($label), $labels, true)) {
                throw ValidationException::withMessages(['items' => 'Setiap baris/kantong harus memiliki label berbeda.']);
            }
            $labels[] = mb_strtolower($label);
            $snapshot = $tariff->snapshot();
            $milli = isset($input['estimated_quantity']) && $input['estimated_quantity'] !== '' ? LaundryPricing::milli($input['estimated_quantity']) : null;
            if ($snapshot['unit'] === 'piece') {
                if ($milli !== null && $milli !== (int) $input['received_pieces'] * 1000) {
                    throw ValidationException::withMessages(['items' => 'Jumlah satuan harus sesuai jumlah potong yang diterima.']);
                }
                $milli = (int) $input['received_pieces'] * 1000;
            }
            $price = LaundryPricing::calculate($snapshot, $milli);
            $lines[] = ['laundry_tariff_id' => $tariff->id, 'bag_label' => $label, 'tariff_snapshot' => $snapshot,
                'received_pieces' => (int) $input['received_pieces'], 'condition_notes' => $input['condition_notes'],
                'estimated_milli' => $price['quantity_milli'], 'estimated_billable_milli' => $price['billable_milli'], 'estimated_amount' => $price['amount']];
        }

        return $lines;
    }

    public function preview(array $data, User $actor): array
    {
        app(LaundryPublicBooking::class)->intakeEntry($data);
        $outlet = $this->settings->requireOpen();
        if ($data['intake_mode'] === 'collection' && ! $outlet['allow_collection']) {
            throw ValidationException::withMessages(['intake_mode' => 'Penerimaan melalui penjemputan belum diaktifkan outlet.']);
        }
        $customer = $this->customerSnapshot($data);
        $lines = $this->lines($data);
        $total = collect($lines)->contains(fn ($line) => $line['estimated_amount'] === null) ? null : array_sum(array_column($lines, 'estimated_amount'));
        $ready = Carbon::parse($data['received_at'])->addHours(max(array_column(array_column($lines, 'tariff_snapshot'), 'duration_hours')))->toDateTimeString();
        $payload = ['input' => $data, 'customer' => $customer, 'lines' => $lines, 'outlet' => $outlet, 'total' => $total, 'ready' => $ready, 'actor_id' => $actor->id, 'expires' => now()->addMinutes(15)->timestamp];

        return $payload + ['token' => Crypt::encryptString(json_encode($payload))];
    }

    public function create(string $token, User $actor, Request $request): LaundryOrder
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['laundry' => 'Pratinjau tidak valid. Periksa kembali form penerimaan.']);
        }
        if (($payload['actor_id'] ?? null) !== $actor->id) {
            throw ValidationException::withMessages(['laundry' => 'Pratinjau harus disimpan oleh admin yang membuatnya.']);
        }

        return DB::transaction(function () use ($payload, $actor, $request) {
            $data = $payload['input'];
            $hash = hash('sha256', json_encode($data));
            $existing = LaundryOrder::where('entry_key', $data['entry_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->input_hash !== $hash) {
                    throw ValidationException::withMessages(['laundry' => 'Form sudah dipakai untuk penerimaan berbeda. Buat form baru.']);
                }

                return $existing;
            }
            if ($payload['expires'] < now()->timestamp || $payload['outlet'] !== $this->settings->requireOpen()) {
                throw ValidationException::withMessages(['laundry' => 'Pratinjau kedaluwarsa atau pengaturan outlet berubah. Periksa ulang penerimaan.']);
            }
            $publicEntry = app(LaundryPublicBooking::class)->intakeEntry($data, true);
            $customerSnapshot = $this->customerSnapshot($data);
            if ($customerSnapshot !== $payload['customer']) {
                throw ValidationException::withMessages(['laundry' => 'Identitas pelanggan berubah. Periksa kembali penerimaan.']);
            }
            foreach ($payload['lines'] as $line) {
                $tariff = LaundryTariff::whereKey($line['laundry_tariff_id'])->lockForUpdate()->first();
                if (! $tariff?->is_active || $tariff->snapshot() !== $line['tariff_snapshot']) {
                    throw ValidationException::withMessages(['laundry' => 'Katalog tarif berubah. Buat pratinjau baru sebelum menyimpan.']);
                }
            }
            $customer = ! empty($data['customer_id']) ? Customer::whereKey($data['customer_id'])->lockForUpdate()->firstOrFail()
                : Customer::create(['name' => $customerSnapshot['name'], 'whatsapp_number' => $customerSnapshot['whatsapp_number'], 'phone' => $customerSnapshot['phone'], 'status' => 'lead', 'notes' => 'Pelanggan dari penerimaan laundry.']);
            $customerSnapshot['id'] = $customer->id;
            $order = LaundryOrder::create(['entry_key' => $data['entry_key'], 'input_hash' => $hash, 'code' => 'LND-'.now()->format('ymd').'-'.strtoupper(Str::random(8)), 'customer_id' => $customer->id, 'created_by' => $actor->id,
                'customer_snapshot' => $customerSnapshot, 'outlet_snapshot' => $payload['outlet'], 'received_at' => $data['received_at'], 'estimated_ready_at' => $payload['ready'],
                'intake_mode' => $data['intake_mode'], 'collection_address' => $data['intake_mode'] === 'collection' ? $data['collection_address'] : null, 'instructions' => $data['instructions'] ?? null,
                'estimated_total' => $payload['total'], 'actual_total' => null, 'status' => 'received', 'revision' => 1, 'is_demo' => (bool) ($data['is_demo'] ?? false)]);
            foreach ($payload['lines'] as $index => $line) {
                $order->items()->create($line + ['bag_code' => $order->code.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)]);
            }
            if ($publicEntry) {
                app(LaundryPublicBooking::class)->bindIntake($publicEntry, $order, $actor, $request);
            }
            $this->event($order, 'received', $actor, ['estimated_total' => $order->estimated_total, 'items' => $order->items()->get()->toArray()], $request);

            return $order;
        });
    }

    private function current(LaundryOrder $order, int $revision): LaundryOrder
    {
        $order = LaundryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($order->status === 'cancelled' || $order->revision !== $revision) {
            throw ValidationException::withMessages(['laundry' => 'Pesanan dibatalkan atau data sudah berubah. Muat ulang halaman sebelum melanjutkan.']);
        }

        return $order;
    }

    public function weigh(LaundryOrder $order, array $data, User $actor, Request $request): LaundryOrder
    {
        return DB::transaction(function () use ($order, $data, $actor, $request) {
            $order = $this->current($order, (int) $data['revision']);
            if (! $order->canReweigh()) {
                throw ValidationException::withMessages(['laundry' => 'Penimbangan dikunci setelah masuk pengerjaan atau ada pembayaran/dokumen aktif. Sebelum pengerjaan, koreksi pembayaran dan batalkan invoice terlebih dahulu bila perlu.']);
            }
            if ($order->actual_total !== null && mb_strlen(trim($data['reason'] ?? '')) < 5) {
                throw ValidationException::withMessages(['reason' => 'Isi alasan penimbangan ulang, minimal 5 karakter. Persetujuan sebelumnya akan diminta ulang.']);
            }
            $items = $order->items()->lockForUpdate()->get()->keyBy('id');
            $ids = collect($data['items'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($ids !== $items->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['items' => 'Seluruh kantong pesanan ini wajib ditimbang, tanpa duplikasi atau item pesanan lain.']);
            }
            $before = ['status' => $order->status, 'actual_total' => $order->actual_total, 'approved_name' => $order->approved_name, 'approved_at' => $order->approved_at?->toIso8601String(), 'items' => $items->toArray()];
            $total = 0;
            foreach ($data['items'] as $input) {
                $item = $items[(int) $input['id']];
                $pieces = (int) $input['actual_pieces'];
                if ($pieces !== $item->received_pieces && mb_strlen(trim($input['notes'] ?? '')) < 5) {
                    throw ValidationException::withMessages(['items' => 'Jumlah potong berbeda dari penerimaan. Catat alasan selisih pada kantong terkait.']);
                }
                if ($item->tariff_snapshot['unit'] === 'piece') {
                    $milli = $pieces * 1000;
                } else {
                    if (! isset($input['quantity']) || $input['quantity'] === '') {
                        throw ValidationException::withMessages(['items' => 'Isi berat aktual setiap kantong dengan tarif kilogram.']);
                    }
                    $milli = LaundryPricing::milli($input['quantity']);
                }
                $price = LaundryPricing::calculate($item->tariff_snapshot, $milli);
                $item->update(['actual_pieces' => $pieces, 'actual_milli' => $milli, 'actual_billable_milli' => $price['billable_milli'], 'actual_amount' => $price['amount'], 'weighing_notes' => $input['notes'] ?? null]);
                $total += $price['amount'];
            }
            $order->update(['actual_total' => $total, 'status' => 'awaiting_approval', 'revision' => $order->revision + 1, 'approved_at' => null, 'approved_name' => null, 'approval_channel' => null, 'approval_reference' => null, 'approval_fingerprint' => null]);
            $this->event($order, 'weighed', $actor, ['before' => $before, 'reason' => $data['reason'] ?? null, 'actual_total' => $total, 'items' => $order->items()->get()->toArray()], $request);

            return $order;
        });
    }

    public function approve(LaundryOrder $order, array $data, User $actor, Request $request): LaundryOrder
    {
        return DB::transaction(function () use ($order, $data, $actor, $request) {
            $order = $this->current($order, (int) $data['revision']);
            if ($order->status !== 'awaiting_approval' || $order->actual_total === null) {
                throw ValidationException::withMessages(['laundry' => 'Selesaikan penimbangan seluruh kantong sebelum mencatat persetujuan.']);
            }
            if (! LaundryPortal::priceReady($order)) {
                throw ValidationException::withMessages(['laundry' => 'Konfirmasikan ongkir yang diminta pelanggan sebelum menyetujui tagihan.']);
            }
            $order->update(['status' => 'approved', 'revision' => $order->revision + 1, 'approved_at' => now(), 'approved_name' => $data['approved_name'], 'approval_channel' => $data['approval_channel'], 'approval_reference' => $data['approval_reference'], 'approval_fingerprint' => $order->pricingFingerprint()]);
            $this->event($order, 'approved', $actor, ['amount' => $order->totalDue(), 'service_total' => $order->actual_total, 'pickup_fee' => $order->pickup_fee, 'delivery_fee' => $order->delivery_fee, 'fingerprint' => $order->approval_fingerprint, 'approved_name' => $order->approved_name, 'channel' => $order->approval_channel, 'reference' => $order->approval_reference], $request);

            return $order;
        });
    }

    public function cancel(LaundryOrder $order, array $data, User $actor, Request $request): LaundryOrder
    {
        return DB::transaction(function () use ($order, $data, $actor, $request) {
            $order = LaundryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'cancelled') {
                return $order;
            }
            $order = $this->current($order, (int) $data['revision']);
            $before = $order->only(['status', 'actual_total', 'approved_at', 'approved_name', 'approval_channel', 'approval_reference']);
            if ($order->paymentProofs()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['laundry' => 'Tinjau bukti pembayaran yang menunggu sebelum membatalkan pesanan.']);
            }
            if ($order->transportTasks()->whereIn('status', ['planned', 'in_transit', 'failed'])->exists()) {
                throw ValidationException::withMessages(['laundry' => 'Selesaikan pembatalan jadwal antar-jemput aktif sebelum membatalkan pesanan.']);
            }
            if ($order->workflow_status === 'handed_over' || $order->payments()->whereNull('voided_at')->exists() || $order->documents()->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['laundry' => 'Pesanan yang sudah diserahkan tidak dapat dibatalkan. Untuk pesanan lain, selesaikan koreksi pembayaran dan pembatalan invoice aktif terlebih dahulu.']);
            }
            $order->update(['status' => 'cancelled', 'workflow_status' => 'cancelled', 'revision' => $order->revision + 1, 'cancellation_reason' => $data['reason'], 'approved_at' => null, 'approved_name' => null, 'approval_channel' => null, 'approval_reference' => null, 'approval_fingerprint' => null]);
            $this->event($order, 'cancelled', $actor, ['before' => $before, 'reason' => $data['reason']], $request);

            return $order;
        });
    }

    private function event(LaundryOrder $order, string $kind, User $actor, array $details, Request $request): void
    {
        $order->events()->create(['kind' => $kind, 'revision' => $order->revision, 'actor_id' => $actor->id, 'details' => $details, 'created_at' => now()]);
        $this->audit->log($actor, 'laundry.order.'.$kind, $order, $request, [], ['revision' => $order->revision, 'status' => $order->status, 'estimated_total' => $order->estimated_total, 'actual_total' => $order->actual_total], 'Penerimaan/penimbangan laundry diperbarui.');
        app(LaundryNotificationService::class)->captureOrder($order, $kind, $details);
    }
}
