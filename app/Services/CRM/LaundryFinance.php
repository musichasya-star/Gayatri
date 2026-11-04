<?php

namespace App\Services\CRM;

use App\Models\LaundryDocument;
use App\Models\LaundryOrder;
use App\Models\LaundryPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LaundryFinance
{
    public function __construct(private readonly LaundryActivity $activity) {}

    private function requireApproved(LaundryOrder $order): void
    {
        LaundryPortal::requireApprovedPrice($order);
        if ($order->status !== 'approved' || ! $order->approved_at || $order->actual_total === null) {
            throw ValidationException::withMessages(['laundry' => 'Invoice dan pembayaran memerlukan nilai jasa hasil timbang yang sudah disetujui pelanggan.']);
        }
    }

    public function payment(LaundryOrder $order, array $data, ?UploadedFile $proof, User $actor, Request $request, ?int $reviewedProofId = null): LaundryPayment
    {
        LaundryActivity::authorize($actor);
        $canonical = ['order_id' => $order->id, 'amount' => (int) $data['amount'], 'method' => $data['method'], 'paid_at' => Carbon::parse($data['paid_at'])->setTimezone(config('app.timezone'))->toDateTimeString(), 'payer_name' => trim($data['payer_name']), 'reference' => trim($data['reference'] ?? ''), 'notes' => trim($data['notes'] ?? ''), 'proof_hash' => $proof ? hash_file('sha256', $proof->getRealPath()) : null];
        $hash = hash('sha256', json_encode($canonical));
        $stored = null;
        try {
            return DB::transaction(function () use ($order, $data, $proof, $actor, $request, $canonical, $hash, $reviewedProofId, &$stored) {
                // All financial and operational mutations serialize on the same parent row.
                $locked = LaundryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $existing = LaundryPayment::where('entry_key', $data['entry_key'])->first();
                if ($existing) {
                    if ($existing->laundry_order_id !== $locked->id || $existing->input_hash !== $hash) {
                        throw ValidationException::withMessages(['entry_key' => 'Form pembayaran sudah digunakan untuk data berbeda. Muat ulang halaman.']);
                    }

                    // An old retry cannot recreate a payment that was subsequently voided.
                    return $existing;
                }
                $locked = $this->activity->lock($locked, (int) $data['revision']);
                $this->requireApproved($locked);
                if ($locked->paymentProofs()->where('status', 'pending')->when($reviewedProofId, fn ($q) => $q->whereKeyNot($reviewedProofId))->exists()) {
                    throw ValidationException::withMessages(['laundry' => 'Periksa bukti pembayaran pelanggan yang masih menunggu sebelum menambah pembayaran manual.']);
                }
                $balance = $locked->totalDue() - (int) $locked->payments()->whereNull('voided_at')->sum('amount');
                if ($canonical['amount'] < 1 || $canonical['amount'] > $balance) {
                    throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari nol dan tidak melebihi sisa tagihan Rp '.number_format($balance, 0, ',', '.').'.']);
                }
                if (Carbon::parse($canonical['paid_at'])->lt($locked->received_at) || Carbon::parse($canonical['paid_at'])->isFuture()) {
                    throw ValidationException::withMessages(['paid_at' => 'Waktu pembayaran harus sejak penerimaan pesanan sampai saat ini.']);
                }
                if ($proof) {
                    $stored = $proof->store('laundry/payment-proofs', 'local');
                    if (! $stored) {
                        throw ValidationException::withMessages(['proof' => 'Bukti pembayaran gagal disimpan. Pembayaran belum dicatat.']);
                    }
                }
                $payment = $locked->payments()->create(['entry_key' => $data['entry_key'], 'input_hash' => $hash, 'amount' => $canonical['amount'], 'method' => $canonical['method'], 'paid_at' => $canonical['paid_at'], 'payer_name' => $canonical['payer_name'], 'reference' => $canonical['reference'] ?: null, 'notes' => $canonical['notes'] ?: null, 'proof_path' => $stored, 'proof_mime' => $proof?->getMimeType(), 'created_by' => $actor->id]);
                $document = $this->persistDocument($locked, 'receipt', $actor, $payment);
                $this->activity->record($locked, 'payment_recorded', $actor, ['payment_id' => $payment->id, 'amount' => $payment->amount, 'method' => $payment->method, 'reference' => $payment->reference, 'notes' => $payment->notes, 'document_number' => $document->number], $request);

                return $payment;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $exception;
        }
    }

    public function voidPayment(LaundryOrder $order, LaundryPayment $payment, array $data, User $actor, Request $request): LaundryPayment
    {
        LaundryActivity::authorize($actor);

        return DB::transaction(function () use ($order, $payment, $data, $actor, $request) {
            $order = $this->activity->lock($order, (int) $data['revision']);
            $payment = $order->payments()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->voided_at) {
                throw ValidationException::withMessages(['laundry' => 'Pembayaran sudah dikoreksi. Muat ulang halaman.']);
            }
            $payment->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $data['reason']]);
            $payment->document()->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => 'Pembayaran dikoreksi: '.$data['reason']]);
            $this->activity->record($order, 'payment_voided', $actor, ['payment_id' => $payment->id, 'amount' => $payment->amount, 'reason' => $data['reason'], 'notes' => 'Koreksi pencatatan, bukan pengembalian dana.'], $request);

            return $payment;
        });
    }

    public function invoice(LaundryOrder $order, int $revision, User $actor, Request $request): LaundryDocument
    {
        LaundryActivity::authorize($actor);

        return DB::transaction(function () use ($order, $revision, $actor, $request) {
            $order = LaundryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->requireApproved($order);
            $existing = $order->documents()->where('type', 'invoice')->whereNull('voided_at')->first();
            if ($existing) {
                return $existing;
            }
            $order = $this->activity->lock($order, $revision);
            $document = $this->persistDocument($order, 'invoice', $actor);
            $this->activity->record($order, 'invoice_issued', $actor, ['document_id' => $document->id, 'document_number' => $document->number, 'amount' => $order->totalDue()], $request);

            return $document;
        });
    }

    public function voidInvoice(LaundryOrder $order, LaundryDocument $document, array $data, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($order, $document, $data, $actor, $request) {
            $order = $this->activity->lock($order, (int) $data['revision']);
            $document = $order->documents()->whereKey($document->id)->where('type', 'invoice')->lockForUpdate()->firstOrFail();
            if ($document->voided_at) {
                throw ValidationException::withMessages(['laundry' => 'Invoice sudah dibatalkan.']);
            }
            $document->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $data['reason']]);
            $this->activity->record($order, 'document_voided', $actor, ['document_number' => $document->number, 'reason' => $data['reason']], $request);
        });
    }

    private function persistDocument(LaundryOrder $order, string $type, User $actor, ?LaundryPayment $payment = null): LaundryDocument
    {
        $paid = (int) $order->payments()->whereNull('voided_at')->sum('amount');
        $snapshot = ['schema' => 2, 'order_code' => $order->code, 'is_demo' => $order->is_demo, 'customer' => $order->customer_snapshot, 'outlet' => $order->outlet_snapshot, 'received_at' => $order->received_at->toIso8601String(), 'approved_at' => $order->approved_at->toIso8601String(),
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item) => ['bag_code' => $item->bag_code, 'bag_label' => $item->bag_label, 'name' => $item->tariff_snapshot['name'], 'unit' => $item->tariff_snapshot['unit'], 'price' => $item->tariff_snapshot['price'], 'quantity_milli' => $item->actual_milli, 'billable_milli' => $item->actual_billable_milli, 'amount' => $item->actual_amount])->all(),
            'service_total' => $order->actual_total, 'pickup_fee' => (int) $order->pickup_fee, 'delivery_fee' => (int) $order->delivery_fee, 'transport_pricing' => $order->transport_pricing, 'total' => $order->totalDue(), 'paid' => $paid, 'balance' => $order->totalDue() - $paid, 'issuer' => $actor->name, 'payment' => $payment ? array_merge($payment->only(['id', 'amount', 'method', 'payer_name', 'reference', 'notes']), ['paid_at' => $payment->paid_at->toIso8601String()]) : null];

        return $order->documents()->create(['laundry_payment_id' => $payment?->id, 'type' => $type, 'number' => ($type === 'invoice' ? 'INV' : 'KWT').'-LND-'.now()->format('Ymd').'-'.strtoupper(Str::random(10)), 'snapshot' => $snapshot, 'issued_by' => $actor->id, 'issued_at' => now()]);
    }
}
