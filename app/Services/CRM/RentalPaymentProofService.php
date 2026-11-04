<?php

namespace App\Services\CRM;

use App\Models\RentalOrder;
use App\Models\RentalPaymentProof;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class RentalPaymentProofService
{
    public function __construct(private readonly RentalPaymentTerms $terms, private readonly RentalService $rentals, private readonly AuditLogService $audit) {}

    public function submit(RentalOrder $order, array $data, UploadedFile $file): RentalPaymentProof
    {
        $path = null;
        $hash = hash_file('sha256', $file->getRealPath());
        try {
            return DB::transaction(function () use ($order, $data, $file, $hash, &$path) {
                $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $existing = $order->paymentProofs()->where('entry_key', $data['entry_key'])->first();
                if ($existing) {
                    if ($existing->amount !== (int) $data['amount'] || $existing->file_hash !== $hash || $existing->kind !== $data['kind'] || $existing->payer_name !== $data['payer_name'] || $existing->payment_date->toDateString() !== $data['payment_date']) {
                        throw new InvalidArgumentException('Form sudah dipakai untuk bukti berbeda. Muat ulang halaman.');
                    }

                    return $existing;
                }
                $summary = $this->terms->summary($order);
                if (! $summary['payable'] || $order->paymentProofs()->where('status', 'pending')->exists()) {
                    throw new InvalidArgumentException('Pembayaran belum dapat diajukan atau masih ada bukti yang menunggu verifikasi.');
                }
                if ($order->paymentProofs()->count() >= 20 || $order->paymentProofs()->where('file_hash', $hash)->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw new InvalidArgumentException('Bukti sudah dipakai atau batas unggah pesanan tercapai. Hubungi admin.');
                }
                if ((int) $data['amount'] > $summary['balance'] || ($data['kind'] === 'settlement' && (int) $data['amount'] !== $summary['balance'])
                    || ($data['kind'] === 'dp' && ($summary['dp_remaining'] < 1 || (int) $data['amount'] < $summary['dp_remaining']))) {
                    throw new InvalidArgumentException('Nominal DP/pelunasan tidak sesuai sisa tagihan terkini. Muat ulang halaman.');
                }
                $path = $file->store('rentals/payment-proofs', 'local');
                if (! $path) {
                    throw new InvalidArgumentException('Bukti gagal disimpan. Coba kembali.');
                }

                return $order->paymentProofs()->create(array_merge($data, ['file_path' => $path, 'file_hash' => $hash, 'file_mime' => $file->getMimeType(), 'file_size' => $file->getSize(), 'status' => 'pending']));
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function review(RentalPaymentProof $proof, string $action, string $notes, User $actor, Request $request): void
    {
        DB::transaction(function () use ($proof, $action, $notes, $actor, $request) {
            $order = RentalOrder::whereKey($proof->rental_order_id)->lockForUpdate()->firstOrFail();
            $proof = $order->paymentProofs()->whereKey($proof->id)->lockForUpdate()->firstOrFail();
            if ($proof->status === ($action === 'approve' ? 'approved' : 'rejected')) {
                return;
            }
            if ($proof->status !== 'pending') {
                throw new InvalidArgumentException('Bukti pembayaran sudah ditinjau. Muat ulang halaman.');
            }
            $paymentId = null;
            if ($action === 'approve') {
                $summary = $this->terms->summary($order);
                if (! $summary['payable'] || $proof->amount > $summary['balance']) {
                    throw new InvalidArgumentException('Status/saldo pesanan berubah. Periksa pembayaran lain sebelum menyetujui; tolak bukti jika sudah tercatat.');
                }
                $this->rentals->payment($order, ['entry_key' => $proof->entry_key, 'payment_proof_id' => $proof->id, 'kind' => 'payment', 'amount' => $proof->amount, 'method' => 'transfer', 'reference' => $proof->reference, 'party_name' => $proof->payer_name, 'notes' => 'Bukti #'.$proof->id.' diverifikasi admin.'], $actor, $request);
                $payment = $order->payments()->where('entry_key', $proof->entry_key)->firstOrFail();
                $paymentId = $payment->id;
                app(RentalDocumentService::class)->issueReceipt($order, $payment, $actor, $request);
            }
            $proof->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $notes, 'rental_payment_id' => $paymentId]);
            app(RentalNotificationService::class)->enqueue($order, $action === 'approve' ? 'proof_approved' : 'proof_rejected', 'proof:'.$proof->id, ['proof_id' => $proof->id, 'payment_id' => $paymentId]);
            $this->audit->log($actor, 'rental.proof.'.$action, $proof, $request, [], ['amount' => $proof->amount, 'status' => $proof->status], 'Bukti transfer ditinjau');
        });
    }

    public function file(RentalPaymentProof $proof)
    {
        abort_unless(Storage::disk('local')->exists($proof->file_path), 404);
        $headers = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"];

        return $proof->file_mime === 'application/pdf'
            ? Storage::disk('local')->download($proof->file_path, 'bukti-'.$proof->id.'.pdf', $headers)
            : Storage::disk('local')->response($proof->file_path, 'bukti-'.$proof->id, $headers);
    }
}
