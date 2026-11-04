<?php

namespace App\Services\CRM;

use App\Models\RentalCustomerRequest;
use App\Models\RentalOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class RentalCustomerRequestService
{
    public function __construct(private readonly RentalPolicy $policy, private readonly RentalService $rentals, private readonly AuditLogService $audit) {}

    public function preview(RentalOrder $order, string $kind): array
    {
        $order = $order->fresh(['payments', 'items']);
        $estimate = $this->estimate($order, $kind);
        $quote = ['order_id' => $order->id, 'kind' => $kind, 'estimate' => $estimate, 'state_hash' => $this->state($order), 'expires_at' => now()->addMinutes(15)->timestamp, 'date' => today()->toDateString()];

        return ['quote' => $quote, 'token' => Crypt::encryptString(json_encode($quote))];
    }

    private function estimate(RentalOrder $order, string $kind, ?CarbonInterface $at = null): array
    {
        if ($kind === 'cancellation' && in_array($order->status, ['pending', 'reserved'], true)) {
            return $this->policy->cancellation($order, $at);
        }
        if ($kind === 'refund' && in_array($order->status, ['completed', 'cancelled'], true) && $order->refundable() > 0) {
            return ['received' => (int) $order->received(), 'fee' => 0, 'refund' => (int) $order->refundable()];
        }
        throw new InvalidArgumentException('Pembatalan hanya sebelum serah-terima. Refund tersedia setelah pembatalan/pemeriksaan selesai dan terdapat saldo pengembalian.');
    }

    private function state(RentalOrder $order): string
    {
        return hash('sha256', json_encode([$order->id, $order->status, $order->terms_snapshot, $order->start_date->toDateString(), $order->end_date->toDateString(), $order->charges(), $order->received(), $order->payments->map->only(['id', 'kind', 'amount'])->all()]));
    }

    public function submit(RentalOrder $order, array $data, Request $request): RentalCustomerRequest
    {
        return DB::transaction(function () use ($order, $data, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail()->load('items', 'payments');
            $existing = RentalCustomerRequest::where('entry_key', $data['entry_key'])->first();
            if ($existing) {
                if ($existing->rental_order_id !== $order->id || $existing->kind !== $data['kind'] || $existing->reason !== $data['reason']) {
                    throw new InvalidArgumentException('Form pengajuan sudah dipakai. Muat ulang portal.');
                }

                return $existing;
            }
            if ($this->hasOpenRequest($order) || $order->customerRequests()->count() >= 20) {
                throw new InvalidArgumentException('Masih ada pengajuan yang belum selesai atau batas pengajuan tercapai. Hubungi admin.');
            }
            try {
                $quote = json_decode(Crypt::decryptString($data['request_token']), true, flags: JSON_THROW_ON_ERROR);
                if (($quote['order_id'] ?? null) !== $order->id || ($quote['kind'] ?? null) !== $data['kind'] || ($quote['expires_at'] ?? 0) < now()->timestamp || ($quote['date'] ?? '') !== today()->toDateString() || ($quote['state_hash'] ?? '') !== $this->state($order)) {
                    throw new InvalidArgumentException;
                }
            } catch (Throwable) {
                throw new InvalidArgumentException('Estimasi pengajuan berubah/kedaluwarsa. Muat ulang portal dan periksa rincian terbaru.');
            }
            $estimate = $this->estimate($order, $data['kind']);
            $entry = $order->customerRequests()->create(['entry_key' => $data['entry_key'], 'kind' => $data['kind'], 'reason' => $data['reason'], 'estimate' => $estimate, 'status' => 'pending']);
            $this->audit->log(null, 'rental.customer-request.submit', $entry, $request, [], ['kind' => $entry->kind, 'estimate' => $estimate], 'Pengajuan pelanggan diterima, belum mengubah pesanan atau saldo');

            return $entry;
        });
    }

    public function hasOpenRequest(RentalOrder $order): bool
    {
        return $order->customerRequests()->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'approved')->where('approved_amount', '>', 0)->whereNull('rental_payment_id')))->exists();
    }

    public function review(RentalCustomerRequest $entry, string $action, string $notes, User $actor, Request $request): void
    {
        DB::transaction(function () use ($entry, $action, $notes, $actor, $request) {
            $order = RentalOrder::whereKey($entry->rental_order_id)->lockForUpdate()->firstOrFail()->load('payments', 'items');
            $entry = $order->customerRequests()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $decision = $action === 'approve' ? 'approved' : 'rejected';
            if ($entry->status === $decision || ($entry->status === 'settled' && $action === 'approve')) {
                return;
            }
            if ($entry->status !== 'pending') {
                throw new InvalidArgumentException('Pengajuan sudah diputuskan. Muat ulang halaman.');
            }
            $amount = 0;
            if ($action === 'approve') {
                $current = $this->estimate($order, $entry->kind, $entry->created_at);
                if ($current['fee'] !== $entry->estimate['fee'] || $current['refund'] !== $entry->estimate['refund']) {
                    throw new InvalidArgumentException('Saldo atau biaya berubah dari estimasi pelanggan. Tolak pengajuan dan minta pengajuan baru setelah saldo dicocokkan.');
                }
                if ($order->paymentProofs()->where('status', 'pending')->exists()) {
                    throw new InvalidArgumentException('Tinjau semua bukti pembayaran terlebih dahulu agar saldo refund tepat.');
                }
                if ($entry->kind === 'cancellation') {
                    $this->rentals->action($order, 'cancel', ['customer_request_id' => $entry->id, 'requested_at' => $entry->created_at->toIso8601String(), 'notes' => 'Pengajuan pelanggan #'.$entry->id.': '.$notes], $actor, $request);
                    $order = $order->fresh(['payments', 'items']);
                }
                $amount = (int) $order->refundable();
            }
            $entry->update(['status' => $decision, 'approved_amount' => $amount, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $notes]);
            app(RentalNotificationService::class)->enqueue($order, $action === 'approve' ? 'request_approved' : 'request_rejected', 'request:'.$entry->id, ['request_id' => $entry->id]);
            $this->audit->log($actor, 'rental.customer-request.'.$action, $entry, $request, [], ['status' => $decision, 'approved_amount' => $amount], 'Pengajuan ditinjau; persetujuan belum mencatat transfer refund');
        });
    }

    public function settle(RentalCustomerRequest $entry, array $data, UploadedFile $file, User $actor, Request $request): void
    {
        $path = null;
        $hash = hash_file('sha256', $file->getRealPath());
        try {
            DB::transaction(function () use ($entry, $data, $file, $actor, $request, $hash, &$path) {
                $order = RentalOrder::whereKey($entry->rental_order_id)->lockForUpdate()->firstOrFail()->load('payments', 'items');
                $entry = $order->customerRequests()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                if ($entry->status === 'settled') {
                    $payment = $order->payments->firstWhere('id', $entry->rental_payment_id);
                    if ($entry->refund_file_hash !== $hash || $entry->refund_date->toDateString() !== $data['refund_date'] || $payment?->method !== $data['method'] || $payment?->reference !== $data['reference'] || $payment?->party_name !== $data['party_name']) {
                        throw new InvalidArgumentException('Refund sudah dicatat dengan bukti berbeda. Muat ulang halaman.');
                    }

                    return;
                }
                if ($entry->status !== 'approved' || $entry->approved_amount < 1 || $order->refundable() < $entry->approved_amount || ! in_array($order->status, ['completed', 'cancelled'], true)) {
                    throw new InvalidArgumentException('Refund belum disetujui atau saldo berubah. Periksa pencatatan lain sebelum melanjutkan.');
                }
                $path = $file->store('rentals/refund-proofs', 'local');
                if (! $path) {
                    throw new InvalidArgumentException('Bukti refund gagal disimpan.');
                }
                $this->rentals->payment($order, ['entry_key' => $entry->entry_key, 'customer_request_id' => $entry->id, 'kind' => 'refund', 'amount' => $entry->approved_amount,
                    'method' => $data['method'], 'party_name' => $data['party_name'], 'reference' => $data['reference'], 'notes' => 'Refund pengajuan #'.$entry->id.'; tanggal pengembalian dana '.$data['refund_date']], $actor, $request);
                $payment = $order->payments()->where('entry_key', $entry->entry_key)->firstOrFail();
                $entry->update(['status' => 'settled', 'rental_payment_id' => $payment->id, 'settled_by' => $actor->id, 'settled_at' => now(), 'refund_date' => $data['refund_date'], 'refund_file_path' => $path, 'refund_file_mime' => $file->getMimeType(), 'refund_file_hash' => $hash]);
                app(RentalDocumentService::class)->issueReceipt($order, $payment, $actor, $request);
                $this->audit->log($actor, 'rental.customer-request.refund-recorded', $entry, $request, [], ['amount' => $entry->approved_amount, 'payment_id' => $payment->id, 'refund_date' => $data['refund_date']], 'Refund yang sudah dilakukan dicatat dengan bukti; tidak menjalankan transfer bank');
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function file(RentalCustomerRequest $entry)
    {
        abort_unless($entry->status === 'settled' && $entry->refund_file_path && Storage::disk('local')->exists($entry->refund_file_path), 404);
        $headers = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"];

        return $entry->refund_file_mime === 'application/pdf'
            ? Storage::disk('local')->download($entry->refund_file_path, 'refund-'.$entry->id.'.pdf', $headers)
            : Storage::disk('local')->response($entry->refund_file_path, 'refund-'.$entry->id, $headers);
    }
}
