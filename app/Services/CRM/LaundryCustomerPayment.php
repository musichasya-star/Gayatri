<?php

namespace App\Services\CRM;

use App\Models\LaundryOrder;
use App\Models\LaundryPaymentProof;
use App\Models\LaundryPublicRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class LaundryCustomerPayment
{
    public function __construct(private readonly LaundryFinance $finance, private readonly LaundryActivity $activity, private readonly AuditLogService $audit) {}

    public function upload(LaundryPublicRequest $entry, array $data, UploadedFile $file, Request $request): LaundryPaymentProof
    {
        abort_unless($entry->laundry_order_id && $entry->verified_at, 404);
        $stored = null;
        $canonical = ['amount' => (int) $data['amount'], 'paid_at' => Carbon::parse($data['paid_at'])->timezone(config('app.timezone'))->toDateTimeString(), 'payer_name' => trim($data['payer_name']), 'reference' => trim($data['reference']), 'notes' => trim($data['notes'] ?? ''), 'file_hash' => hash_file('sha256', $file->getRealPath())];
        $hash = hash('sha256', json_encode($canonical));
        try {
            return DB::transaction(function () use ($entry, $data, $file, $canonical, $hash, $request, &$stored) {
                $order = LaundryOrder::whereKey($entry->laundry_order_id)->lockForUpdate()->firstOrFail();
                $existing = LaundryPaymentProof::where('entry_key', $data['entry_key'])->first();
                if ($existing) {
                    if ($existing->laundry_order_id !== $order->id || $existing->laundry_public_request_id !== $entry->id || $existing->input_hash !== $hash) {
                        throw ValidationException::withMessages(['proof' => 'Form unggahan sudah dipakai untuk data berbeda. Muat ulang halaman.']);
                    }

                    return $existing;
                }
                LaundryPortal::requireApprovedPrice($order);
                if ($order->status !== 'approved' || ! $order->approved_at || $order->revision !== (int) $data['revision'] || ! hash_equals($order->pricingFingerprint(), $data['fingerprint'])) {
                    throw ValidationException::withMessages(['proof' => 'Tagihan belum disetujui atau sudah berubah. Periksa rincian terbaru.']);
                }
                if ($canonical['amount'] < 1 || $canonical['amount'] > ($order->balance() ?? 0)) {
                    throw ValidationException::withMessages(['amount' => 'Nominal harus positif dan tidak melebihi sisa tagihan.']);
                }
                if ($order->paymentProofs()->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['proof' => 'Masih ada bukti yang menunggu pemeriksaan. Hubungi admin bila perlu koreksi.']);
                }
                if (Carbon::parse($canonical['paid_at'])->lt($order->received_at) || Carbon::parse($canonical['paid_at'])->isFuture()) {
                    throw ValidationException::withMessages(['paid_at' => 'Tanggal pembayaran harus sejak penerimaan barang sampai saat ini.']);
                }
                $stored = $file->store('laundry/payment-proofs', 'local');
                if (! $stored) {
                    throw ValidationException::withMessages(['file' => 'Bukti belum berhasil disimpan. Silakan coba lagi.']);
                }
                $proof = $order->paymentProofs()->create(['laundry_public_request_id' => $entry->id, 'entry_key' => $data['entry_key'], 'input_hash' => $hash, 'pricing_fingerprint' => $order->pricingFingerprint(), 'amount' => $canonical['amount'], 'paid_at' => $canonical['paid_at'], 'payer_name' => $canonical['payer_name'], 'reference' => $canonical['reference'], 'notes' => $canonical['notes'] ?: null, 'file_path' => $stored, 'mime_type' => $file->getMimeType(), 'status' => 'pending', 'revision' => 1]);
                $order->increment('revision');
                $order->events()->create(['actor_id' => null, 'kind' => 'proof_uploaded', 'revision' => $order->revision, 'details' => ['proof_id' => $proof->id, 'amount' => $proof->amount, 'notes' => 'Bukti pelanggan menunggu pemeriksaan, belum dihitung sebagai pembayaran.'], 'created_at' => now()]);
                $this->audit->log(null, 'laundry.portal.proof', $proof, $request, [], ['order_id' => $order->id, 'amount' => $proof->amount, 'status' => 'pending'], 'Pelanggan mengunggah bukti pembayaran untuk diperiksa.');

                return $proof;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $exception;
        }
    }

    public function review(LaundryPaymentProof $proof, array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($proof, $data, $actor, $request) {
            $order = LaundryOrder::whereKey($proof->laundry_order_id)->lockForUpdate()->firstOrFail();
            $proof = $order->paymentProofs()->whereKey($proof->id)->lockForUpdate()->firstOrFail();
            $target = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            if ($proof->status === $target) {
                return;
            }
            if ($proof->status !== 'pending' || $proof->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['proof' => 'Bukti sudah ditinjau atau berubah. Muat ulang halaman.']);
            }
            $payment = null;
            if ($target === 'approved') {
                if ($order->status !== 'approved' || ! hash_equals($order->pricingFingerprint(), $proof->pricing_fingerprint) || $proof->amount > ($order->balance() ?? 0)) {
                    throw ValidationException::withMessages(['proof' => 'Tagihan berubah atau nominal melebihi sisa tagihan. Jangan setujui; periksa dan beri catatan kepada pelanggan.']);
                }
                $payment = $this->finance->payment($order, ['revision' => $order->revision, 'entry_key' => $proof->entry_key, 'amount' => $proof->amount, 'method' => 'transfer', 'paid_at' => $proof->paid_at->toIso8601String(), 'payer_name' => $proof->payer_name, 'reference' => $proof->reference, 'notes' => 'Bukti portal disetujui: '.$data['notes']], null, $actor, $request, $proof->id);
                $payment->update(['proof_path' => $proof->file_path, 'proof_mime' => $proof->mime_type]);
                $order->refresh();
            }
            $proof->update(['status' => $target, 'laundry_payment_id' => $payment?->id, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $data['notes'], 'revision' => $proof->revision + 1]);
            $this->activity->record($order, 'proof_'.$target, $actor, ['proof_id' => $proof->id, 'amount' => $proof->amount, 'notes' => $data['notes']], $request);
        });
    }
}
