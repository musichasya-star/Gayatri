<?php

namespace App\Services\CRM;

use App\Models\Customer;
use App\Models\LaundryCourier;
use App\Models\LaundryOrder;
use App\Models\LaundryTransportTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class LaundryTransportService
{
    public function __construct(private readonly LaundryTransportPricing $pricing, private readonly LaundryActivity $activity, private readonly AuditLogService $audit) {}

    private function snapshot(Customer $customer): array
    {
        return $customer->only(['id', 'name', 'whatsapp_number', 'phone']);
    }

    private function slot(array $data, ?int $except = null): LaundryCourier
    {
        $courier = LaundryCourier::whereKey($data['laundry_courier_id'])->lockForUpdate()->firstOrFail();
        if (! $courier->is_active) {
            throw ValidationException::withMessages(['laundry_courier_id' => 'Petugas sudah tidak aktif.']);
        }
        $start = Carbon::parse($data['scheduled_start']);
        $end = Carbon::parse($data['scheduled_end']);
        if ($start->lt(now()->startOfMinute()) || $end->lte($start) || $start->diffInMinutes($end) > 480) {
            throw ValidationException::withMessages(['scheduled_start' => 'Pilih jadwal sejak saat ini, dengan rentang waktu maksimal 8 jam.']);
        }
        if (LaundryTransportTask::where('laundry_courier_id', $courier->id)->whereIn('status', ['planned', 'in_transit'])->when($except, fn ($q) => $q->whereKeyNot($except))->where('scheduled_start', '<', $end)->where('scheduled_end', '>', $start)->exists()) {
            throw ValidationException::withMessages(['scheduled_start' => 'Jadwal petugas bentrok dengan kunjungan laundry lain. Pilih waktu atau petugas berbeda.']);
        }

        return $courier;
    }

    private function orderEligible(LaundryOrder $order, string $kind, ?int $except = null): void
    {
        if ($order->status === 'cancelled' || $order->workflow_status === 'handed_over' || ($kind === 'pickup' && $order->intake_mode !== 'collection')) {
            throw ValidationException::withMessages(['laundry_order_id' => 'Pesanan tidak sesuai jenis kunjungan, sudah dibatalkan, atau sudah diserahkan.']);
        }
        if ($order->transportTasks()->where('kind', $kind)->where('status', '!=', 'cancelled')->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['laundry_order_id' => 'Sudah ada kunjungan jenis ini pada pesanan. Gunakan penjadwalan ulang pada kunjungan tersebut.']);
        }
    }

    private function applyFee(LaundryOrder $order, array $quote, User $actor, Request $request): void
    {
        $oldFingerprint = $order->pricingFingerprint();
        $column = $quote['kind'].'_fee';
        if ((int) $order->$column !== $quote['fee'] && ($order->payments()->whereNull('voided_at')->exists() || $order->documents()->whereNull('voided_at')->exists() || $order->paymentProofs()->where('status', 'pending')->exists())) {
            throw ValidationException::withMessages(['transport' => 'Ongkir berbeda dan tagihan sudah memiliki pembayaran/dokumen aktif. Tetapkan ongkir sebelum DP/invoice; koreksi dokumen dan pencatatan pembayaran terlebih dahulu bila memang keliru.']);
        }
        $snapshots = $order->transport_pricing ?? [];
        $snapshots[$quote['kind']] = $quote;
        $before = (int) $order->$column;
        $order->update([$column => $quote['fee'], 'transport_pricing' => $snapshots]);
        if ($order->status === 'approved' && $order->publicRequest()->exists() && $oldFingerprint !== $order->pricingFingerprint()) {
            $order->update(['status' => 'awaiting_approval', 'approved_at' => null, 'approved_name' => null, 'approval_channel' => null, 'approval_reference' => null, 'approval_fingerprint' => null]);
        }
        $this->activity->record($order, 'transport_fee', $actor, ['kind' => $quote['kind'], 'before' => $before, 'amount' => $quote['fee'], 'notes' => 'Ongkir disetujui '.$quote['approved_name'].': '.$quote['approval_reference']], $request);
    }

    public function preview(array $data, User $actor): array
    {
        LaundryActivity::authorize($actor);
        app(LaundryPublicBooking::class)->pickupEntry($data);
        if ($data['kind'] === 'delivery' && empty($data['laundry_order_id'])) {
            throw ValidationException::withMessages(['laundry_order_id' => 'Pengantaran harus terhubung ke pesanan laundry.']);
        }
        $order = ! empty($data['laundry_order_id']) ? LaundryOrder::findOrFail($data['laundry_order_id']) : null;
        if ($order) {
            $this->orderEligible($order, $data['kind']);
            $customer = $order->customer;
        } else {
            $customer = Customer::findOrFail($data['customer_id']);
        }
        $quote = $this->pricing->quote($data);
        $quote['approved_name'] = $data['approved_name'];
        $quote['approval_reference'] = $data['approval_reference'];
        DB::transaction(fn () => $this->slot($data));
        $payload = ['data' => $data, 'quote' => $quote, 'customer' => $this->snapshot($customer), 'order_revision' => $order?->revision, 'actor_id' => $actor->id, 'expires' => now()->addMinutes(15)->timestamp];

        return $payload + ['token' => Crypt::encryptString(json_encode($payload))];
    }

    public function create(string $token, User $actor, Request $request): LaundryTransportTask
    {
        LaundryActivity::authorize($actor);
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['transport' => 'Pratinjau tidak valid. Buat pratinjau baru.']);
        }
        if (($payload['actor_id'] ?? null) !== $actor->id) {
            throw ValidationException::withMessages(['transport' => 'Simpan dengan admin yang membuat pratinjau.']);
        }

        return DB::transaction(function () use ($payload, $actor, $request) {
            $data = $payload['data'];
            $hash = hash('sha256', json_encode($data));
            $existing = LaundryTransportTask::where('entry_key', $data['entry_key'])->first();
            if ($existing) {
                if ($existing->input_hash !== $hash) {
                    throw ValidationException::withMessages(['transport' => 'Form ini sudah dipakai untuk kunjungan berbeda.']);
                }

                return $existing;
            }
            if ($payload['expires'] < now()->timestamp || $payload['quote']['policy'] !== $this->pricing->settings()) {
                throw ValidationException::withMessages(['transport' => 'Pratinjau kedaluwarsa atau pengaturan ongkir berubah. Periksa kembali.']);
            }
            $publicEntry = app(LaundryPublicBooking::class)->pickupEntry($data, true);
            $order = ! empty($data['laundry_order_id']) ? $this->activity->lock(LaundryOrder::findOrFail($data['laundry_order_id']), $payload['order_revision']) : null;
            $customer = Customer::whereKey($payload['customer']['id'])->lockForUpdate()->firstOrFail();
            $existing = LaundryTransportTask::where('entry_key', $data['entry_key'])->first();
            if ($existing) {
                if ($existing->input_hash !== $hash) {
                    throw ValidationException::withMessages(['transport' => 'Form sudah digunakan untuk kunjungan berbeda.']);
                }

                return $existing;
            }
            if ($this->snapshot($customer) !== $payload['customer']) {
                throw ValidationException::withMessages(['transport' => 'Identitas pelanggan berubah. Buat pratinjau baru.']);
            }
            $this->slot($data);
            if ($order) {
                $this->orderEligible($order, $data['kind']);
                $this->applyFee($order, $payload['quote'], $actor, $request);
            }
            $task = LaundryTransportTask::create(['entry_key' => $data['entry_key'], 'input_hash' => $hash, 'laundry_order_id' => $order?->id, 'customer_id' => $customer->id, 'laundry_courier_id' => $data['laundry_courier_id'], 'kind' => $data['kind'], 'customer_snapshot' => $payload['customer'], 'address' => $data['address'], 'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'quote_snapshot' => $payload['quote'], 'scheduled_start' => $data['scheduled_start'], 'scheduled_end' => $data['scheduled_end'], 'notes' => $data['notes'] ?? null, 'is_demo' => $order ? $order->is_demo : (bool) ($data['is_demo'] ?? false), 'created_by' => $actor->id, 'history' => []]);
            $this->record($task, 'scheduled', $actor, ['notes' => $task->notes], $request, false);
            if ($publicEntry) {
                $publicEntry->update(['pickup_task_id' => $task->id, 'revision' => $publicEntry->revision + 1]);
            }

            return $task;
        });
    }

    private function locked(LaundryTransportTask $task, int $revision): LaundryTransportTask
    {
        if ($task->laundry_order_id) {
            LaundryOrder::whereKey($task->laundry_order_id)->lockForUpdate()->firstOrFail();
        }
        LaundryCourier::whereKey($task->laundry_courier_id)->lockForUpdate()->firstOrFail();
        $task = LaundryTransportTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
        if ($task->revision !== $revision) {
            throw ValidationException::withMessages(['transport' => 'Kunjungan sudah berubah. Muat ulang halaman.']);
        }

        return $task;
    }

    public function reschedule(LaundryTransportTask $task, array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($task, $data, $actor, $request) {
            if ($task->laundry_order_id) {
                LaundryOrder::whereKey($task->laundry_order_id)->lockForUpdate()->firstOrFail();
            }
            LaundryCourier::whereIn('id', [$task->laundry_courier_id, $data['laundry_courier_id']])->orderBy('id')->lockForUpdate()->get();
            $task = $this->locked($task, (int) $data['revision']);
            if (! in_array($task->status, ['planned', 'failed'], true) || $task->order?->status === 'cancelled') {
                throw ValidationException::withMessages(['transport' => 'Hanya jadwal terencana atau kunjungan gagal yang dapat dijadwalkan ulang.']);
            }
            $this->slot($data, $task->id);
            $before = $task->only(['scheduled_start', 'scheduled_end', 'laundry_courier_id', 'status']);
            $task->update(['scheduled_start' => $data['scheduled_start'], 'scheduled_end' => $data['scheduled_end'], 'laundry_courier_id' => $data['laundry_courier_id'], 'status' => 'planned', 'started_at' => null]);
            $this->record($task, 'rescheduled', $actor, ['before' => $before, 'notes' => $data['reason']], $request);
        });
    }

    public function action(LaundryTransportTask $task, array $data, ?UploadedFile $proof, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        $stored = null;
        try {
            DB::transaction(function () use ($task, $data, $proof, $actor, $request, &$stored) {
                $task = $this->locked($task, (int) $data['revision']);
                $order = $task->order;
                if ($order?->status === 'cancelled' || in_array($task->status, ['completed', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['transport' => 'Kunjungan sudah selesai/dibatalkan atau pesanan sudah dibatalkan.']);
                }
                $action = $data['action'];
                if ($action === 'start') {
                    if (LaundryTransportTask::where('laundry_courier_id', $task->laundry_courier_id)->where('status', 'in_transit')->whereKeyNot($task->id)->exists()) {
                        throw ValidationException::withMessages(['transport' => 'Petugas masih memiliki kunjungan dalam perjalanan. Selesaikan atau catat kegagalannya dahulu.']);
                    }
                    if ($task->status !== 'planned' || ! $task->courier->is_active) {
                        throw ValidationException::withMessages(['transport' => 'Kunjungan harus terjadwal dengan petugas aktif.']);
                    }
                    if ($task->kind === 'delivery' && (! $order || $order->workflow_status !== 'ready' || $order->balance() !== 0)) {
                        throw ValidationException::withMessages(['transport' => 'Pengantaran berangkat setelah QC lulus, barang siap, dan seluruh jasa beserta ongkir lunas.']);
                    }
                    $task->update(['status' => 'in_transit', 'started_at' => now()]);
                } elseif ($action === 'cancel') {
                    if (! in_array($task->status, ['planned', 'failed'], true)) {
                        throw ValidationException::withMessages(['transport' => 'Kunjungan yang sedang berjalan harus dicatat gagal terlebih dahulu.']);
                    }
                    $task->update(['status' => 'cancelled', 'outcome_notes' => $data['notes']]);
                } elseif ($action === 'fail') {
                    if ($task->status !== 'in_transit') {
                        throw ValidationException::withMessages(['transport' => 'Catat kegagalan pada kunjungan yang sedang berjalan.']);
                    }
                    $task->update(['status' => 'failed', 'outcome_notes' => $data['notes']]);
                } else {
                    if ($task->status !== 'in_transit') {
                        throw ValidationException::withMessages(['transport' => 'Mulai perjalanan sebelum mencatat penyelesaian.']);
                    }
                    if ($task->kind === 'pickup' && (int) ($data['pieces'] ?? 0) < 1) {
                        throw ValidationException::withMessages(['pieces' => 'Isi jumlah potong yang diterima dari pelanggan.']);
                    }
                    if ($task->kind === 'delivery') {
                        app(LaundryOperations::class)->handover($order, array_replace($data, ['revision' => $order->revision]), $actor, $request, $task->id);
                    }
                    if ($proof) {
                        $stored = $proof->store('laundry/transport-proofs', 'local');
                        if (! $stored) {
                            throw ValidationException::withMessages(['proof' => 'Bukti kunjungan gagal disimpan.']);
                        }
                    }
                    $task->update(['status' => 'completed', 'completed_at' => now(), 'receiver_name' => $data['receiver_name'], 'outcome_notes' => $data['notes'], 'proof_path' => $stored, 'proof_mime' => $proof?->getMimeType()]);
                }
                $this->record($task, $action, $actor, ['notes' => $data['notes'] ?? null, 'pieces' => $data['pieces'] ?? null, 'receiver_name' => $data['receiver_name'] ?? null], $request);
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $exception;
        }
    }

    public function attach(LaundryTransportTask $task, LaundryOrder $order, array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($task, $order, $data, $actor, $request) {
            $order = $this->activity->lock($order, (int) $data['order_revision']);
            $task = $this->locked($task, (int) $data['revision']);
            if ($task->kind !== 'pickup' || $task->status !== 'completed' || $task->laundry_order_id || $task->customer_id !== $order->customer_id || $task->is_demo !== $order->is_demo) {
                throw ValidationException::withMessages(['transport' => 'Hubungkan penjemputan selesai yang belum terhubung ke pesanan pelanggan dan jenis data yang sama.']);
            }
            $this->orderEligible($order, 'pickup');
            $this->applyFee($order, $task->quote_snapshot, $actor, $request);
            $task->update(['laundry_order_id' => $order->id]);
            $this->record($task, 'attached', $actor, ['notes' => 'Penjemputan dihubungkan ke '.$order->code], $request);
        });
    }

    public function waive(LaundryOrder $order, array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($order, $data, $actor, $request) {
            $order = $this->activity->lock($order, (int) $data['revision']);
            if ($order->workflow_status === 'handed_over') {
                throw ValidationException::withMessages(['transport' => 'Ongkir pesanan yang sudah diserahkan tidak dapat diubah.']);
            }
            if ((int) $order->{$data['kind'].'_fee'} === 0 && array_key_exists($data['kind'], $order->transport_pricing ?? [])) {
                throw ValidationException::withMessages(['transport' => 'Ongkir jenis ini sudah nol.']);
            }
            $quote = ['kind' => $data['kind'], 'fee' => 0, 'approved_name' => $data['approved_name'], 'approval_reference' => $data['reason'], 'waived' => true, 'previous' => $order->transport_pricing[$data['kind']] ?? null, 'quoted_at' => now()->toIso8601String()];
            $this->applyFee($order, $quote, $actor, $request);
        });
    }

    private function record(LaundryTransportTask $task, string $kind, User $actor, array $details, Request $request, bool $increment = true): void
    {
        $task->refresh();
        $history = $task->history;
        $history[] = ['kind' => $kind, 'actor' => $actor->name, 'at' => now()->toIso8601String(), 'status' => $task->status, 'scheduled_start' => $task->scheduled_start->toIso8601String(), 'scheduled_end' => $task->scheduled_end->toIso8601String(), 'courier' => $task->courier->name, 'details' => $details];
        $task->update(['history' => $history, 'revision' => $task->revision + ($increment ? 1 : 0)]);
        $this->audit->log($actor, 'laundry.transport.'.$kind, $task, $request, [], ['status' => $task->status, 'revision' => $task->revision, 'details' => $details], 'Jadwal laundry diperbarui.');
        app(LaundryNotificationService::class)->captureTask($task, $kind);
    }
}
