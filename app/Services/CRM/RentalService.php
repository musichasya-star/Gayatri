<?php

namespace App\Services\CRM;

use App\Models\Customer;
use App\Models\RentalItem;
use App\Models\RentalOrder;
use App\Models\RentalProduct;
use App\Models\RentalUnit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RentalService
{
    public function __construct(private readonly RentalInventory $inventory, private readonly RentalQuote $quotes, private readonly RentalSettings $settings, private readonly AuditLogService $audit) {}

    public function create(array $data, ?User $actor, Request $request): RentalOrder
    {
        $quote = $this->quotes->decode($data['quote_token'], $data);
        if (! $actor && ! in_array($data['terms_accepted'] ?? null, [true, 1, '1', 'yes', 'on'], true)) {
            throw new InvalidArgumentException('Setujui ketentuan sewa yang ditampilkan pada estimasi sebelum memesan.');
        }

        return DB::transaction(function () use ($data, $quote, $actor, $request) {
            $products = RentalProduct::whereIn('id', array_column($quote['rows'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sessionHash = hash_hmac('sha256', $request->session()->getId(), config('app.key'));
            $existing = RentalOrder::where('quote_id', $quote['id'])->first();
            if ($existing) {
                if ($actor || hash_equals($existing->request_session_hash, $sessionHash)) {
                    return $existing;
                }
                throw new InvalidArgumentException('Estimasi sudah dipakai. Silakan hitung ulang.');
            }
            $allocations = [];
            foreach (collect($quote['rows'])->sortBy('product_id') as $row) {
                $product = $products->get($row['product_id']);
                if (! $product || ! $product->is_active) {
                    throw new InvalidArgumentException('Alat sudah tidak aktif dalam katalog.');
                }
                $units = $this->inventory->available($product, $data['start_date'], $data['end_date'], null, $row['turnaround_days'])->lockForUpdate()->limit($row['quantity'])->get();
                if ($units->count() !== $row['quantity']) {
                    throw new InvalidArgumentException('Stok '.$product->name.' berubah. Silakan cek ketersediaan lagi.');
                }
                $allocations[] = [$row, $units];
            }
            if ($actor) {
                $customer = Customer::findOrFail($data['customer_id']);
            } else {
                $number = preg_replace('/\D/', '', $data['whatsapp_number']);
                if (str_starts_with($number, '0')) {
                    $number = '62'.substr($number, 1);
                }
                if (! preg_match('/^62\d{8,14}$/', $number)) {
                    throw new InvalidArgumentException('Isi nomor WhatsApp Indonesia yang valid.');
                }
                $customer = Customer::firstOrCreate(['whatsapp_number' => $number], ['name' => $data['name'], 'phone' => $data['whatsapp_number'], 'email' => $data['email'] ?? null, 'status' => 'lead']);
            }
            if ($customer->status === 'blacklisted') {
                throw new InvalidArgumentException('Pemesanan tidak dapat diproses. Hubungi admin Gayatri.');
            }
            $order = RentalOrder::create([
                'code' => 'RENT-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'quote_id' => $quote['id'], 'request_session_hash' => $sessionHash, 'customer_id' => $customer->id,
                'created_by' => $actor?->id, 'source' => $actor ? 'admin' : 'landing', 'status' => 'pending',
                'is_demo' => $actor ? (bool) ($data['is_demo'] ?? false) : false,
                'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'period' => $data['period'], 'period_count' => $quote['period_count'],
                'hold_expires_at' => now()->addHours((int) $this->settings->get()['hold_hours']), 'fulfilment' => $data['fulfilment'],
                'address' => $data['fulfilment'] !== 'self' ? $data['address'] : null, 'landmark' => $data['fulfilment'] !== 'self' ? ($data['landmark'] ?? null) : null,
                'latitude' => $data['fulfilment'] !== 'self' ? $data['latitude'] : null, 'longitude' => $data['fulfilment'] !== 'self' ? $data['longitude'] : null,
                'rental_subtotal' => $quote['rental_subtotal'], 'deposit_total' => $quote['deposit_total'], 'delivery_fee' => $quote['delivery_fee'], 'collection_fee' => $quote['collection_fee'],
                'pricing_snapshot' => $quote, 'notes' => $data['notes'] ?? null,
                'payment_terms' => $quote['payment_terms'] ?? null,
                'terms_snapshot' => $quote['terms_snapshot'] ?? null,
                'terms_accepted_at' => $actor ? null : now(), 'terms_acceptance_source' => $actor ? null : 'checkout',
                'customer_snapshot' => ['name' => $actor ? $customer->name : $data['name'], 'whatsapp_number' => $actor ? $customer->whatsapp_number : $data['whatsapp_number']],
            ]);
            foreach ($allocations as [$row, $units]) {
                foreach ($units as $unit) {
                    $order->items()->create(['rental_unit_id' => $unit->id, 'product_name' => $row['name'], 'unit_code' => $unit->code,
                        'components' => $row['components'], 'checkout_condition' => $unit->condition_notes, 'rental_price' => $row['unit_price'], 'deposit' => $row['unit_deposit'],
                        'inspection_checklist' => $products->get($row['product_id'])->inspection_checklist ?? [],
                        'rental_end_date' => $data['end_date'], 'reserved_until' => Carbon::parse($data['end_date'])->addDays(1 + $row['turnaround_days'])]);
                }
            }
            $this->audit->log($actor, 'rental.create', $order, $request, [], ['code' => $order->code, 'total' => $quote['total'], 'units' => $order->items()->pluck('unit_code')->all()], 'Transaksi sewa dibuat');
            if (! $actor) {
                $this->audit->log(null, 'rental.terms.accept', $order, $request, [], ['hash' => $order->terms_snapshot['hash'] ?? null, 'source' => 'checkout'], 'Ketentuan pesanan disetujui saat checkout');
            }

            return $order;
        });
    }

    public function action(RentalOrder $order, string $action, array $data, User $actor, Request $request): RentalOrder
    {
        return DB::transaction(function () use ($order, $action, $data, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->load(['items.unit.product', 'payments']);
            $before = $order->status;
            if ($action === 'confirm') {
                if ($order->status !== 'pending' || $order->isExpired()) {
                    throw new InvalidArgumentException('Permintaan bukan pending aktif. Buat transaksi baru jika masa konfirmasi habis.');
                }
                foreach ($order->items as $item) {
                    $product = $item->unit->product;
                    $buffer = max(0, (int) $order->end_date->copy()->addDay()->diffInDays($item->reserved_until));
                    if (! $this->inventory->available($product, $order->start_date->toDateString(), $order->end_date->toDateString(), $order->id, $buffer)->whereKey($item->rental_unit_id)->lockForUpdate()->first()) {
                        throw new InvalidArgumentException($item->unit_code.' belum tersedia. Ganti unit sebelum mengonfirmasi.');
                    }
                }
                $balanceDeadline = $order->payment_terms ? $order->start_date->copy()->subDays((int) $order->payment_terms['settlement_days_before'])->endOfDay() : null;
                $dpDeadline = ($order->payment_terms['minimum_dp'] ?? 0) > 0 ? now()->addHours((int) $order->payment_terms['dp_due_hours']) : null;
                if ($dpDeadline && $balanceDeadline) {
                    $dpDeadline = $dpDeadline->min($balanceDeadline);
                }
                $order->update(['status' => 'reserved', 'hold_expires_at' => null, 'dp_due_at' => $dpDeadline, 'balance_due_at' => $balanceDeadline]);
            } elseif ($action === 'dispatch') {
                if ($order->terms_snapshot && ! $order->terms_accepted_at) {
                    throw new InvalidArgumentException('Pelanggan perlu menyetujui ketentuan melalui portal pribadi sebelum serah-terima.');
                }
                if ($order->customerRequests()->where('kind', 'cancellation')->where('status', 'pending')->exists()) {
                    throw new InvalidArgumentException('Tinjau pengajuan pembatalan pelanggan sebelum serah-terima.');
                }
                if (($order->payment_terms['require_full_payment'] ?? false) && $order->balance() > 0) {
                    throw new InvalidArgumentException('Pelunasan harus diverifikasi sebelum serah-terima. Bukti yang masih menunggu verifikasi belum dihitung sebagai pembayaran.');
                }
                if ($order->status !== 'reserved' || today()->lt($order->start_date) || today()->gt($order->end_date)) {
                    throw new InvalidArgumentException('Serah-terima hanya untuk pesanan terkonfirmasi pada periode sewanya.');
                }
                foreach ($order->items as $item) {
                    $unit = RentalUnit::whereKey($item->rental_unit_id)->lockForUpdate()->firstOrFail();
                    if ($unit->status !== 'ready' || $this->inventory->hasPhysicalHold($unit)) {
                        throw new InvalidArgumentException($unit->code.' belum siap. Periksa atau ganti unit terlebih dahulu.');
                    }
                    app(RentalCareService::class)->assertCheckout($item, $unit);
                    if (! $item->checkout_inspected_at) {
                        $item->update(['checkout_condition' => $unit->condition_notes]);
                    }
                    $unit->update(['status' => 'rented']);
                }
                $order->update(['status' => 'rented', 'dispatched_at' => now(), 'dispatch_notes' => $data['notes'] ?? null]);
            } elseif ($action === 'cancel') {
                if (! in_array($order->status, ['pending', 'reserved'], true)) {
                    throw new InvalidArgumentException('Pembatalan hanya untuk alat yang belum diserahterimakan.');
                }
                if ($order->paymentProofs()->where('status', 'pending')->exists()) {
                    throw new InvalidArgumentException('Selesaikan verifikasi bukti transfer sebelum pembatalan agar saldo refund tepat.');
                }
                if ($order->customerRequests()->where('status', 'pending')->exists() && empty($data['customer_request_id'])) {
                    throw new InvalidArgumentException('Proses pengajuan pelanggan melalui menu Pembatalan & Refund.');
                }
                $at = isset($data['requested_at']) ? Carbon::parse($data['requested_at']) : now();
                $calculation = app(RentalPolicy::class)->cancellation($order, $at);
                $order->update(['status' => 'cancelled', 'cancellation_fee' => $calculation['fee'], 'cancellation_snapshot' => $calculation, 'notes' => trim($order->notes."\nPembatalan: ".($data['notes'] ?? '-'))]);
                $order->extensionRequests()->where('status', 'pending')->update(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => 'Pesanan dibatalkan sebelum perpanjangan diproses.']);
                app(RentalTransportService::class)->cancelForOrder($order, $actor, $request);
            } else {
                throw new InvalidArgumentException('Aksi transaksi tidak dikenal.');
            }
            $this->audit->log($actor, 'rental.'.$action, $order, $request, ['status' => $before], ['status' => $order->status], 'Status rental diperbarui');
            $notifications = app(RentalNotificationService::class);
            $decisionWillNotify = ! empty($data['customer_request_id']) && in_array('request_approved', $notifications->settings()['events'], true) && $notifications->recipientFor($order, 'request_approved');
            if ($action === 'confirm' || $action === 'cancel' && ! $decisionWillNotify) {
                app(RentalNotificationService::class)->enqueue($order, $action === 'confirm' ? 'confirmed' : 'cancelled', $action);
            }
            if ($action === 'confirm') {
                app(RentalMessagingService::class)->queuePortal($order, false);
            }

            return $order;
        });
    }

    public function returnItem(RentalOrder $order, RentalItem $item, array $data, User $actor, Request $request): void
    {
        DB::transaction(function () use ($order, $item, $data, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'rented' || $item->returned_at) {
                throw new InvalidArgumentException('Unit tidak sedang disewa atau sudah dikembalikan.');
            }
            RentalUnit::whereKey($item->rental_unit_id)->lockForUpdate()->firstOrFail()->update(['status' => 'inspection']);
            $item->update(['returned_at' => now(), 'return_notes' => $data['notes'], 'return_photo_path' => $data['photo_path'] ?? null]);
            if (! $order->items()->whereNull('returned_at')->exists()) {
                $order->update(['status' => 'inspection']);
            }
            $this->audit->log($actor, 'rental.return', $item, $request, [], ['unit_code' => $item->unit_code, 'return_notes' => $data['notes']], 'Unit diterima, menunggu pemeriksaan');
            app(RentalNotificationService::class)->enqueue($order, 'returned', 'item:'.$item->id, ['item_id' => $item->id]);
        });
    }

    public function inspect(RentalOrder $order, RentalItem $item, array $data, User $actor, Request $request): void
    {
        DB::transaction(function () use ($order, $item, $data, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if (! $item->returned_at || $item->inspected_at || ! in_array($order->status, ['rented', 'inspection'], true)) {
                throw new InvalidArgumentException('Unit harus sudah dikembalikan dan belum selesai diperiksa.');
            }
            $unit = RentalUnit::whereKey($item->rental_unit_id)->lockForUpdate()->firstOrFail();
            $care = app(RentalCareService::class);
            $checklist = $care->checklist($item->checklistLabels(), $data['checklist'] ?? [], $data['unit_status']);
            if ($data['unit_status'] === 'ready' && $unit->maintenanceDue()) {
                throw new InvalidArgumentException('Unit jatuh tempo perawatan. Pilih status dibersihkan/diperbaiki, lalu selesaikan melalui Perawatan Unit.');
            }
            $before = $unit->status;
            $unit->update(['status' => $data['unit_status'], 'condition_notes' => $data['notes'], 'last_inspected_at' => now(), 'care_version' => $unit->care_version + 1]);
            $calculation = app(RentalLateFee::class)->finalize($order, $item, (int) $data['late_fee'], $data['late_fee_reason'] ?? null);
            $item->update(['inspected_at' => now(), 'inspection_notes' => $data['notes'], 'late_fee' => $data['late_fee'], 'damage_fee' => $data['damage_fee'], 'late_fee_calculation' => $calculation]);
            $care->record($unit, 'return_inspection', $data['notes'], $actor, $request, ['rental_item_id' => $item->id, 'status_before' => $before, 'checklist' => $checklist, 'photo_path' => $item->return_photo_path]);
            if (! $order->items()->whereNull('inspected_at')->exists()) {
                $order->update(['status' => 'completed', 'completed_at' => now()]);
            }
            $this->audit->log($actor, 'rental.inspect', $item, $request, [], $data, 'Pemeriksaan unit dan biaya tambahan dicatat');
            if ($order->status === 'completed') {
                app(RentalNotificationService::class)->enqueue($order, 'completed', 'completed');
            }
        });
    }

    public function payment(RentalOrder $order, array $data, User $actor, Request $request): void
    {
        DB::transaction(function () use ($order, $data, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = $order->payments()->where('entry_key', $data['entry_key'])->first();
            if ($existing) {
                if ($existing->kind !== $data['kind'] || (float) $existing->amount !== (float) $data['amount'] || $existing->method !== $data['method']) {
                    throw new InvalidArgumentException('Form pencatatan sudah dipakai. Muat ulang halaman sebelum mencatat pembayaran berikutnya.');
                }

                return;
            }
            if ($data['kind'] === 'refund' && (float) $data['amount'] > $order->refundable()) {
                throw new InvalidArgumentException('Pengembalian dana melebihi saldo yang dapat dikembalikan setelah pemeriksaan/pembatalan.');
            }
            if ($data['kind'] === 'refund' && $order->customerRequests()->where('status', 'approved')->where('approved_amount', '>', 0)->whereNull('rental_payment_id')->when(isset($data['customer_request_id']), fn ($q) => $q->where('id', '!=', $data['customer_request_id']))->exists()) {
                throw new InvalidArgumentException('Selesaikan refund yang telah disetujui melalui menu Pembatalan & Refund agar tidak tercatat ganda.');
            }
            if ($data['kind'] === 'payment' && ($order->status === 'cancelled' || $order->isExpired())) {
                throw new InvalidArgumentException('Pembayaran tidak dapat dicatat untuk permintaan dibatalkan/kedaluwarsa.');
            }
            if ($data['kind'] === 'payment' && $order->status === 'pending') {
                throw new InvalidArgumentException('Konfirmasi pesanan sebelum mencatat pembayaran.');
            }
            $payment = $order->payments()->create(array_merge($data, ['created_by' => $actor->id]));
            $this->audit->log($actor, 'rental.payment.'.$data['kind'], $payment, $request, [], $data, 'Pencatatan pembayaran rental manual');
            if ($data['kind'] === 'refund') {
                app(RentalNotificationService::class)->enqueue($order, 'deposit_refunded', 'payment:'.$payment->id, ['payment_id' => $payment->id]);
            } elseif (empty($data['payment_proof_id'])) {
                app(RentalNotificationService::class)->enqueue($order, 'payment_received', 'payment:'.$payment->id, ['payment_id' => $payment->id]);
            }
        });
    }

    public function replaceUnit(RentalOrder $order, RentalItem $item, int $newUnitId, User $actor, Request $request): void
    {
        DB::transaction(function () use ($order, $item, $newUnitId, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($item->id)->firstOrFail();
            if (! in_array($order->status, ['pending', 'reserved'], true) || $order->isExpired()) {
                throw new InvalidArgumentException('Penggantian unit hanya sebelum serah-terima pada transaksi aktif.');
            }
            $product = RentalProduct::whereKey($item->unit->rental_product_id)->lockForUpdate()->firstOrFail();
            $buffer = max(0, (int) $order->end_date->copy()->addDay()->diffInDays($item->reserved_until));
            $unit = $this->inventory->available($product, $order->start_date->toDateString(), $order->end_date->toDateString(), $order->id, $buffer)->whereKey($newUnitId)->lockForUpdate()->first();
            if (! $unit || $order->items()->where('rental_unit_id', $newUnitId)->exists()) {
                throw new InvalidArgumentException('Unit pengganti tidak tersedia atau sudah dipakai pada transaksi ini.');
            }
            $old = $item->unit_code;
            $item->update(['rental_unit_id' => $unit->id, 'unit_code' => $unit->code, 'checkout_condition' => $unit->condition_notes, 'checkout_checklist' => null, 'checkout_inspected_at' => null, 'checkout_inspected_by' => null, 'checkout_photo_path' => null, 'checkout_state_hash' => null]);
            $this->audit->log($actor, 'rental.replace_unit', $item, $request, ['unit_code' => $old], ['unit_code' => $unit->code], 'Unit sewa diganti');
        });
    }
}
