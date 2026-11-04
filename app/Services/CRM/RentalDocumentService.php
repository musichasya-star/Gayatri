<?php

namespace App\Services\CRM;

use App\Models\RentalDocument;
use App\Models\RentalOrder;
use App\Models\RentalPayment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RentalDocumentService
{
    public function __construct(private readonly RentalSettings $settings, private readonly AuditLogService $audit) {}

    public function issueInvoice(RentalOrder $order, User $actor, ?Request $request = null): RentalDocument
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($order, $actor, $request) {
            // Use the same parent lock as payments, inspection and extensions.
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, RentalDocument::INVOICE_STATUSES, true) || $order->isExpired()) {
                throw new InvalidArgumentException('Invoice hanya dapat diterbitkan setelah pesanan dikonfirmasi. Permintaan pending atau kedaluwarsa tidak dapat diterbitkan.');
            }
            $snapshot = $this->snapshot($order);
            $fingerprint = RentalDocument::fingerprintForSnapshot($snapshot);
            $documents = RentalDocument::where('rental_order_id', $order->id)->where('type', RentalDocument::TYPE_INVOICE);
            $existing = (clone $documents)->where('fingerprint', $fingerprint)->first();
            if ($existing) {
                return $existing;
            }
            $version = (int) (clone $documents)->max('version') + 1;

            return $this->persist($order, $actor, RentalDocument::TYPE_INVOICE, $snapshot, 'INV', $order->id, $version, $request);
        }, 3);
    }

    public function issueReceipt(RentalOrder $order, RentalPayment $payment, User $actor, ?Request $request = null): RentalDocument
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($order, $payment, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // Never trust the caller's payment instance or unscoped route binding.
            $payment = $order->payments()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $existing = RentalDocument::where('rental_order_id', $order->id)->where('rental_payment_id', $payment->id)->first();
            if ($existing) {
                // Return BEFORE loading customer/settings: this is the original receipt.
                return $existing;
            }
            if (! in_array($payment->kind, ['payment', 'refund'], true) || (float) $payment->amount <= 0) {
                throw new InvalidArgumentException('Kuitansi memerlukan pencatatan pembayaran atau pengembalian dana dengan nominal positif.');
            }
            $snapshot = $this->snapshot($order);
            $snapshot['receipt'] = [
                'payment_id' => $payment->id,
                'kind' => $payment->kind,
                'amount' => $this->money($payment->amount),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'recorded_at' => $payment->created_at?->toIso8601String(),
                // The existing ledger has no independently recorded payer field.
                'party_name' => $payment->party_name ?: $snapshot['customer']['name'],
                'party_basis' => $payment->party_name ? 'recorded_party' : 'order_customer',
            ];
            $refund = $payment->kind === 'refund';

            return $this->persist($order, $actor, $refund ? RentalDocument::TYPE_REFUND_RECEIPT : RentalDocument::TYPE_PAYMENT_RECEIPT, $snapshot, $refund ? 'RFD' : 'KWT', $payment->id, null, $request);
        }, 3);
    }

    private function authorize(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasRole(RentalDocument::AUTHORIZED_ROLES)) {
            throw new AuthorizationException('Anda tidak memiliki akses untuk menerbitkan dokumen rental.');
        }
    }

    private function persist(RentalOrder $order, User $actor, string $type, array $snapshot, string $prefix, int $sequence, ?int $version = null, ?Request $request = null): RentalDocument
    {
        $issuedAt = now();
        $fingerprint = RentalDocument::fingerprintForSnapshot($snapshot);
        $snapshot['issuance'] = ['issued_at' => $issuedAt->toIso8601String(), 'issued_by' => $actor->id, 'issuer_name' => $actor->name];

        $document = RentalDocument::create([
            'rental_order_id' => $order->id,
            'rental_payment_id' => $snapshot['receipt']['payment_id'] ?? null,
            'type' => $type,
            'number' => $prefix.'-'.$issuedAt->format('Ymd').'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT).($version !== null ? '-V'.$version : ''),
            'version' => $version,
            'fingerprint' => $fingerprint,
            'snapshot' => $snapshot,
            'issued_by' => $actor->id,
            'issued_at' => $issuedAt,
        ]);
        $this->audit->log($actor, 'rental.document.issue', $document, $request ?? Request::create('/admin/rentals/documents', 'POST'), [], [
            'number' => $document->number, 'type' => $document->type, 'rental_order_id' => $order->id,
            'order_code' => $order->code, 'rental_payment_id' => $document->rental_payment_id, 'version' => $document->version,
        ], 'Dokumen rental diterbitkan');

        return $document;
    }

    private function snapshot(RentalOrder $order): array
    {
        $order->load(['customer', 'items' => fn ($q) => $q->orderBy('id'), 'payments' => fn ($q) => $q->orderBy('id'), 'extensions' => fn ($q) => $q->orderBy('id')]);
        $settings = $this->settings->get();
        $cancelled = $order->status === 'cancelled';
        $extensions = $order->extensions->sum('additional_charge');

        return [
            'schema_version' => 2,
            'currency' => 'IDR',
            'office' => ['name' => (string) $settings['office_name'], 'address' => (string) $settings['office_address']],
            'customer' => ['id' => $order->customer_id, 'name' => $order->customer_snapshot['name'] ?? $order->customer->name, 'phone' => $order->customer_snapshot['whatsapp_number'] ?? ($order->customer->phone ?: $order->customer->whatsapp_number)],
            'order' => [
                'id' => $order->id, 'code' => $order->code, 'status' => $order->status,
                'status_label' => RentalOrder::STATUSES[$order->status] ?? $order->status,
                'start_date' => $order->start_date->toDateString(), 'end_date' => $order->end_date->toDateString(),
                'period' => $order->period, 'period_label' => RentalOrder::PERIODS[$order->period] ?? $order->period, 'period_count' => (int) $order->period_count,
                'fulfilment' => $order->fulfilment, 'fulfilment_label' => RentalOrder::FULFILMENTS[$order->fulfilment] ?? $order->fulfilment,
                'address' => $order->address, 'notes' => $order->notes,
            ],
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id, 'product_name' => $item->product_name, 'unit_code' => $item->unit_code,
                'rental_price' => $this->money($item->rental_price), 'deposit_original' => $this->money($item->deposit),
                'late_fee' => $this->money($item->late_fee), 'damage_fee' => $this->money($item->damage_fee),
                'end_date' => ($item->rental_end_date ?? $order->end_date)->toDateString(),
            ])->values()->all(),
            'extensions' => $order->extensions->map(fn ($extension) => [
                'id' => $extension->id, 'old_end_date' => $extension->old_end_date->toDateString(), 'new_end_date' => $extension->new_end_date->toDateString(),
                'additional_charge' => $this->money($extension->additional_charge), 'notes' => $extension->notes,
            ])->values()->all(),
            'original' => [
                'rental_subtotal' => $this->money($order->pricing_snapshot['rental_subtotal'] ?? $order->rental_subtotal),
                'deposit_total' => $this->money($order->pricing_snapshot['deposit_total'] ?? $order->deposit_total),
                'delivery_fee' => $this->money($order->delivery_fee), 'collection_fee' => $this->money($order->collection_fee),
            ],
            'totals' => [
                'cancellation_fee' => $this->money($order->cancellation_fee),
                'rental_subtotal' => $this->money($cancelled ? 0 : $order->rental_subtotal),
                // Already part of rental_subtotal AND item rental_price; never add again.
                'extension_included' => $this->money($cancelled ? 0 : $extensions),
                'delivery_fee' => $this->money($cancelled ? 0 : $order->delivery_fee),
                'collection_fee' => $this->money($cancelled ? 0 : $order->collection_fee),
                'late_fee' => $this->money($cancelled ? 0 : $order->items->sum('late_fee')),
                'damage_fee' => $this->money($cancelled ? 0 : $order->items->sum('damage_fee')),
                'charges' => $this->money($order->charges()),
                'deposit_original' => $this->money($order->deposit_total),
                'deposit_required' => $this->money($order->totalDue() - $order->charges()),
                'total_due' => $this->money($order->totalDue()),
                'payments' => $this->money($order->payments->where('kind', 'payment')->sum('amount')),
                'refunds' => $this->money($order->payments->where('kind', 'refund')->sum('amount')),
                'net_received' => $this->money($order->received()),
                'balance' => $this->money($order->balance()),
                'refundable' => $this->money($order->refundable()),
            ],
            'ledger' => $order->payments->map(fn ($entry) => [
                'id' => $entry->id, 'kind' => $entry->kind, 'amount' => $this->money($entry->amount),
                'method' => $entry->method, 'reference' => $entry->reference, 'notes' => $entry->notes, 'recorded_at' => $entry->created_at?->toIso8601String(),
            ])->values()->all(),
            'terms' => $order->terms_snapshot ? ['snapshot' => $order->terms_snapshot, 'accepted_at' => $order->terms_accepted_at?->toIso8601String(), 'source' => $order->terms_acceptance_source] : null,
            'cancellation_note' => $cancelled ? ((float) $order->cancellation_fee > 0 ? 'Pesanan dibatalkan. Biaya pembatalan mengikuti ketentuan saat pemesanan. Harga unit adalah riwayat; saldo pengembalian mengikuti potongan pembatalan dan buku kas.' : 'Pesanan dibatalkan. Seluruh biaya sewa, antar-jemput, denda dan deposit terutang adalah nol. Rincian unit merupakan riwayat harga; dana yang masih diterima bersih dapat dikembalikan sesuai buku kas.') : null,
        ];
    }

    private function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }
}
