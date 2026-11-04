<?php

namespace App\Services\CRM;

use App\Models\Customer;
use App\Models\LaundryOrder;
use App\Models\LaundryPublicRequest;
use App\Models\LaundryTariff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LaundryPublicBooking
{
    public function __construct(private readonly LaundryPublicSettings $publicSettings, private readonly LaundrySettings $outlet, private readonly LaundryTransportPricing $transport, private readonly AuditLogService $audit) {}

    public function preview(array $data): array
    {
        $public = $this->publicSettings->get();
        if (! $public['enabled']) {
            throw ValidationException::withMessages(['laundry' => 'Pemesanan laundry melalui website belum aktif.']);
        }
        $outlet = $this->outlet->requireOpen();
        $data['whatsapp_number'] = LaundryOrderService::phone($data['whatsapp_number']);
        $shipping = $data['fulfilment'] === 'pickup' || ! empty($data['delivery_required']);
        $policy = $this->transport->settings();
        if ($shipping && (! $policy['enabled'] || ($data['fulfilment'] === 'pickup' && ! $outlet['allow_collection']))) {
            throw ValidationException::withMessages(['fulfilment' => 'Antar-jemput belum tersedia. Pilih datang dan ambil di outlet.']);
        }
        if ($shipping && mb_strlen(trim($data['address'] ?? '')) < 10) {
            throw ValidationException::withMessages(['address' => 'Isi alamat lengkap untuk antar-jemput.']);
        }
        if (! empty($data['preferred_at']) && (Carbon::parse($data['preferred_at'])->lt(now()->startOfMinute()) || Carbon::parse($data['preferred_at'])->gt(now()->addDays(30)))) {
            throw ValidationException::withMessages(['preferred_at' => 'Pilih waktu mulai saat ini hingga 30 hari ke depan. Jadwal tetap perlu dikonfirmasi admin.']);
        }
        $lines = [];
        $ids = [];
        foreach ($data['items'] as $input) {
            $tariff = LaundryTariff::where('is_active', true)->find($input['laundry_tariff_id']);
            if (! $tariff || in_array($tariff->id, $ids, true)) {
                throw ValidationException::withMessages(['items' => 'Pilih layanan aktif tanpa duplikasi.']);
            }
            $ids[] = $tariff->id;
            $milli = isset($input['quantity']) && $input['quantity'] !== '' ? LaundryPricing::milli($input['quantity']) : null;
            if ($tariff->unit === 'piece' && $milli === null) {
                throw ValidationException::withMessages(['items' => 'Isi jumlah potong untuk layanan satuan.']);
            }
            $lines[] = ['tariff' => $tariff->snapshot()] + LaundryPricing::calculate($tariff->snapshot(), $milli);
        }
        $serviceTotal = collect($lines)->contains(fn ($line) => $line['amount'] === null) ? null : array_sum(array_column($lines, 'amount'));
        $quotes = [];
        foreach (['pickup' => $data['fulfilment'] === 'pickup', 'delivery' => (bool) ($data['delivery_required'] ?? false)] as $kind => $needed) {
            if ($needed && isset($data['latitude'], $data['longitude']) && $policy['origin_latitude'] !== null && $policy['origin_longitude'] !== null) {
                $quotes[$kind] = $this->transport->quote(['kind' => $kind, 'address' => $data['address'], 'latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'distance_source' => 'coordinates']);
            } elseif ($needed) {
                $quotes[$kind] = null;
            }
        }
        $transportTotal = in_array(null, $quotes, true) ? null : array_sum(array_map(fn ($quote) => $quote['fee'], $quotes));
        $estimate = ['service_total' => $serviceTotal, 'transport_total' => $transportTotal, 'total' => $serviceTotal === null || $transportTotal === null ? null : $serviceTotal + $transportTotal, 'transport' => $quotes, 'policy' => $policy];
        $payload = ['data' => $data, 'items' => $lines, 'estimate' => $estimate, 'outlet' => $outlet, 'public_settings' => $public, 'expires' => now()->addMinutes(15)->timestamp];

        return $payload + ['token' => Crypt::encryptString(json_encode($payload))];
    }

    public function create(string $token, Request $request): LaundryPublicRequest
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['laundry' => 'Estimasi tidak valid. Periksa kembali formulir.']);
        }
        $data = $payload['data'];
        if ($request->session()->get('laundry.public_entry_key') !== $data['entry_key']) {
            throw ValidationException::withMessages(['laundry' => 'Buka formulir pemesanan pada browser ini terlebih dahulu.']);
        }

        return DB::transaction(function () use ($payload, $data, $request) {
            $hash = hash('sha256', json_encode($data));
            $existing = LaundryPublicRequest::where('entry_key', $data['entry_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->input_hash !== $hash) {
                    throw ValidationException::withMessages(['laundry' => 'Formulir sudah dipakai untuk permintaan berbeda.']);
                }

                return $existing;
            }
            $current = $this->preview($data);
            if ($payload['expires'] < now()->timestamp || $payload['outlet'] !== $current['outlet'] || $payload['public_settings'] !== $current['public_settings'] || $payload['items'] !== $current['items'] || $payload['estimate']['policy'] !== $current['estimate']['policy']) {
                throw ValidationException::withMessages(['laundry' => 'Tarif/pengaturan berubah atau estimasi kedaluwarsa. Periksa estimasi terbaru.']);
            }
            $rateKey = 'laundry-public-phone:'.hash('sha256', $data['whatsapp_number']);
            if (RateLimiter::tooManyAttempts($rateKey, 5)) {
                throw ValidationException::withMessages(['laundry' => 'Terlalu banyak permintaan. Hubungi admin untuk permintaan yang sudah dibuat.']);
            }
            $entry = LaundryPublicRequest::create(['public_id' => (string) Str::uuid(), 'entry_key' => $data['entry_key'], 'input_hash' => $hash, 'name' => $data['name'], 'whatsapp_number' => $data['whatsapp_number'], 'fulfilment' => $data['fulfilment'], 'delivery_required' => (bool) ($data['delivery_required'] ?? false), 'preferred_at' => $data['preferred_at'] ?? null, 'address' => $data['address'] ?? null, 'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'notes' => $data['notes'] ?? null, 'items' => $payload['items'], 'estimate' => $payload['estimate'], 'outlet_snapshot' => $payload['outlet'], 'status' => 'pending', 'portal_version' => 0, 'portal_expires_at' => now()->addDays($payload['public_settings']['portal_days']), 'is_demo' => false, 'revision' => 1]);
            app(LaundryPortal::class)->issue($entry, $request);
            DB::afterCommit(fn () => RateLimiter::hit($rateKey, 3600));
            $this->audit->log(null, 'laundry.public.request', $entry, $request, [], ['public_id' => $entry->public_id, 'status' => 'pending'], 'Permintaan laundry dari website.');

            return $entry->fresh();
        });
    }

    public function verify(LaundryPublicRequest $entry, array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($entry, $data, $actor, $request) {
            $entry = LaundryPublicRequest::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->revision !== (int) $data['revision'] || $entry->status !== 'pending') {
                throw ValidationException::withMessages(['laundry' => 'Permintaan sudah berubah atau sudah ditinjau.']);
            }
            if ($data['decision'] === 'reject') {
                $entry->update(['status' => 'rejected', 'review_notes' => $data['reference'], 'revision' => $entry->revision + 1]);
            } else {
                $customer = Customer::where('whatsapp_number', $entry->whatsapp_number)->lockForUpdate()->first();
                if ($customer?->status === 'blacklisted') {
                    throw ValidationException::withMessages(['laundry' => 'Kontak perlu ditinjau admin sebelum menerima permintaan.']);
                }
                $customer ??= Customer::create(['name' => $entry->name, 'whatsapp_number' => $entry->whatsapp_number, 'phone' => $entry->whatsapp_number, 'status' => 'lead', 'notes' => 'Pelanggan dari permintaan laundry website yang diverifikasi admin.']);
                $entry->update(['status' => 'confirmed', 'customer_id' => $customer->id, 'verified_at' => now(), 'verified_by' => $actor->id, 'verification_reference' => $data['reference'], 'revision' => $entry->revision + 1]);
            }
            $this->audit->log($actor, 'laundry.public.'.$data['decision'], $entry, $request, [], ['status' => $entry->status, 'reference' => $data['reference']], 'Kontak/permintaan laundry ditinjau.');
        });
    }

    public function intakeEntry(array $data, bool $lock = false): ?LaundryPublicRequest
    {
        if (empty($data['public_request_id'])) {
            return null;
        }
        $entry = LaundryPublicRequest::whereKey($data['public_request_id'])->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        if ($entry->status !== 'confirmed' || ! $entry->verified_at || $entry->laundry_order_id || (int) $data['customer_id'] !== $entry->customer_id || (bool) ($data['is_demo'] ?? false) !== $entry->is_demo || $data['intake_mode'] !== ($entry->fulfilment === 'pickup' ? 'collection' : 'walkin')) {
            throw ValidationException::withMessages(['laundry' => 'Penerimaan harus sesuai permintaan terverifikasi, pelanggan, cara penerimaan, dan jenis datanya.']);
        }
        if ($entry->pickupTask && ! in_array($entry->pickupTask->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['laundry' => 'Selesaikan penjemputan yang sudah dijadwalkan sebelum mencatat penerimaan.']);
        }

        return $entry;
    }

    public function bindIntake(LaundryPublicRequest $entry, LaundryOrder $order, User $actor, Request $request): void
    {
        if ($entry->pickupTask?->status === 'completed') {
            app(LaundryTransportService::class)->attach($entry->pickupTask, $order, ['order_revision' => $order->revision, 'revision' => $entry->pickupTask->revision], $actor, $request);
            $order->refresh();
        }
        $entry->update(['laundry_order_id' => $order->id, 'status' => 'received', 'revision' => $entry->revision + 1]);
        $this->audit->log($actor, 'laundry.public.intake', $entry, $request, [], ['order_id' => $order->id, 'code' => $order->code], 'Permintaan website dihubungkan ke penerimaan barang yang diperiksa petugas.');
    }

    public function pickupEntry(array $data, bool $lock = false): ?LaundryPublicRequest
    {
        if (empty($data['public_request_id'])) {
            return null;
        }
        $entry = LaundryPublicRequest::whereKey($data['public_request_id'])->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        if ($entry->status !== 'confirmed' || ! $entry->verified_at || $entry->laundry_order_id || $entry->fulfilment !== 'pickup' || $data['kind'] !== 'pickup' || ! empty($data['laundry_order_id']) || (int) $data['customer_id'] !== $entry->customer_id || (bool) ($data['is_demo'] ?? false) !== $entry->is_demo || ($entry->pickupTask && $entry->pickupTask->status !== 'cancelled')) {
            throw ValidationException::withMessages(['transport' => 'Jadwal harus sesuai permintaan jemput terverifikasi yang belum terhubung ke penerimaan atau kunjungan aktif lain.']);
        }

        return $entry;
    }

    public function confirmOutletCollection(LaundryPublicRequest $entry, array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($entry, $data, $actor, $request) {
            $order = $entry->order ? LaundryOrder::whereKey($entry->laundry_order_id)->lockForUpdate()->firstOrFail() : null;
            $entry = LaundryPublicRequest::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->revision !== (int) $data['revision'] || ! $entry->verified_at || $entry->status === 'rejected' || ! $entry->delivery_required) {
                throw ValidationException::withMessages(['laundry' => 'Permintaan berubah atau pengambilan di outlet sudah disepakati.']);
            }
            if ($order && ($order->workflow_status === 'handed_over' || $order->payments()->whereNull('voided_at')->exists() || $order->paymentProofs()->where('status', 'pending')->exists() || $order->documents()->whereNull('voided_at')->exists() || $order->transportTasks()->where('kind', 'delivery')->whereIn('status', ['planned', 'in_transit', 'failed'])->exists())) {
                throw ValidationException::withMessages(['laundry' => 'Selesaikan jadwal pengantaran dan tinjau bukti/pembayaran/dokumen aktif sebelum mengubah kesepakatan.']);
            }
            $entry->update(['delivery_required' => false, 'review_notes' => $data['reference'], 'revision' => $entry->revision + 1]);
            if ($order?->status === 'approved') {
                $order->update(['status' => 'awaiting_approval', 'approved_at' => null, 'approved_name' => null, 'approval_channel' => null, 'approval_reference' => null, 'approval_fingerprint' => null, 'revision' => $order->revision + 1]);
            }
            $this->audit->log($actor, 'laundry.public.collect-at-outlet', $entry, $request, [], ['reference' => $data['reference']], 'Pelanggan menyepakati pengambilan di outlet; ongkir yang sudah tercatat tidak dihapus otomatis.');
        });
    }
}
