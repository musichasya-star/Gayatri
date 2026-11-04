<?php

namespace App\Services\CRM;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BookingFinance
{
    public function __construct(private readonly AuditLogService $audit) {}

    public static function cents(string|int|float $amount): int
    {
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', (string) $amount, $m)) {
            throw ValidationException::withMessages(['amount' => 'Nominal harus positif dan maksimal dua angka desimal.']);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function money(int $cents): string
    {
        return 'Rp '.number_format($cents / 100, $cents % 100 ? 2 : 0, ',', '.');
    }

    public function figures(Booking $booking): array
    {
        $booking->loadMissing('service', 'addOns');
        $total = self::cents(number_format($booking->totalPrice(), 2, '.', ''));
        $valid = $booking->payments()->whereNull('voided_at')->select('kind')->selectRaw('SUM(amount_cents) AS total')->groupBy('kind')->pluck('total', 'kind');
        $paid = (int) ($valid['payment'] ?? 0);
        $refund = (int) ($valid['refund'] ?? 0);
        $credit = (int) $booking->legacy_credit_cents;

        return ['total' => $total, 'paid' => $paid, 'refund' => $refund, 'credit' => $credit, 'net' => $credit + $paid - $refund, 'balance' => max(0, $total - $credit - $paid + $refund)];
    }

    public function fingerprint(Booking $booking): string
    {
        $booking->load('service', 'addOns');

        return hash('sha256', json_encode([$booking->customer_id, $booking->service_id, $booking->service_price_snapshot ?? $booking->service?->price, (string) $booking->promo_discount, (string) $booking->travel_fee, $booking->addOns->sortBy('id')->map(fn ($a) => $a->only(['service_addon_id', 'name', 'price']))->values()->all()]));
    }

    private function lock(Booking $booking, int $revision): Booking
    {
        $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        if ($booking->finance_revision !== $revision) {
            throw ValidationException::withMessages(['finance' => 'Data transaksi berubah. Muat ulang halaman.']);
        }

        return $booking;
    }

    public static function authorize(User $actor): void
    {
        abort_unless($actor->status === 'active' && $actor->hasRole(['owner', 'manager', 'admin']), 403);
    }

    private function eligible(Booking $booking): void
    {
        if (in_array($booking->status, ['cancelled', 'cancelled_by_user', 'no_show'], true)) {
            throw ValidationException::withMessages(['finance' => 'Booking sudah dibatalkan/tidak hadir. Periksa histori dan refund.']);
        }
        if ($booking->public_request_data && ! $booking->contact_verified_at) {
            throw ValidationException::withMessages(['finance' => 'Verifikasi kontak permintaan publik terlebih dahulu.']);
        }
        if ($booking->service_price_snapshot === null) {
            throw ValidationException::withMessages(['finance' => 'Harga booking lama belum memiliki salinan. Tinjau dan simpan harga sebelum memulai jurnal.']);
        }
    }

    public function activate(Booking $booking, array $data, User $actor, Request $request): void
    {
        self::authorize($actor);
        DB::transaction(function () use ($booking, $data, $actor, $request) {
            $booking = $this->lock($booking, (int) $data['revision']);
            if ($booking->service_price_snapshot === null && isset($data['legacy_service_price'])) {
                $booking->forceFill(['service_price_snapshot' => number_format(self::cents($data['legacy_service_price']) / 100, 2, '.', '')])->save();
            }
            $this->eligible($booking);
            if ($booking->finance_managed) {
                throw ValidationException::withMessages(['finance' => 'Jurnal sudah aktif.']);
            }
            $credit = self::cents($data['credit'] ?? '0');
            $total = $this->figures($booking)['total'];
            $old = $booking->payment_status;
            if (($old === 'paid' && $credit !== $total) || ($old === 'partial' && ($credit <= 0 || $credit >= $total)) || (in_array($old, ['unpaid', 'refunded'], true) && $credit !== 0)) {
                throw ValidationException::withMessages(['credit' => 'Saldo awal harus sesuai status historis: lunas=total, sebagian antara nol dan total, belum bayar/refund=nol.']);
            }
            $booking->forceFill(['finance_managed' => true, 'legacy_credit_cents' => $credit, 'legacy_payment_status' => $old, 'finance_opened_at' => now(), 'finance_revision' => $booking->finance_revision + 1])->save();
            $this->audit->log($actor, 'booking.finance.activate', $booking, $request, [], ['legacy_status' => $old, 'opening_credit_cents' => $credit, 'reference' => $data['reference']], 'Jurnal dimulai. Saldo awal historis bukan uang masuk baru.');
        });
    }

    public function record(Booking $booking, array $data, ?UploadedFile $file, User $actor, Request $request): BookingPayment
    {
        self::authorize($actor);
        $stored = null;
        $canonical = ['booking_id' => $booking->id, 'kind' => $data['kind'], 'amount_cents' => self::cents($data['amount']), 'method' => $data['method'], 'paid_at' => Carbon::parse($data['paid_at'])->timezone(config('app.timezone'))->toDateTimeString(), 'payer_name' => trim($data['payer_name']), 'reference' => trim($data['reference'] ?? ''), 'notes' => trim($data['notes'] ?? ''), 'file_hash' => $file ? hash_file('sha256', $file->getRealPath()) : null];
        $hash = hash('sha256', json_encode($canonical));
        try {
            return DB::transaction(function () use ($booking, $data, $file, $actor, $request, $canonical, $hash, &$stored) {
                $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
                $existing = BookingPayment::where('entry_key', $data['entry_key'])->first();
                if ($existing) {
                    if ($existing->booking_id !== $booking->id || $existing->input_hash !== $hash) {
                        throw ValidationException::withMessages(['finance' => 'Form transaksi sudah digunakan untuk data lain.']);
                    }

                    return $existing;
                }
                $booking = $this->lock($booking, (int) $data['revision']);
                if (! $booking->finance_managed) {
                    throw ValidationException::withMessages(['finance' => 'Aktifkan jurnal dan tinjau saldo historis terlebih dahulu.']);
                }
                if ($data['fingerprint'] !== $this->fingerprint($booking)) {
                    throw ValidationException::withMessages(['finance' => 'Rincian tagihan berubah. Periksa ulang sebelum mencatat uang.']);
                }
                if ($data['kind'] === 'payment') {
                    $this->eligible($booking);
                }
                $figures = $this->figures($booking);
                $limit = $data['kind'] === 'refund' ? $figures['net'] : $figures['balance'];
                if ($canonical['amount_cents'] <= 0 || $canonical['amount_cents'] > $limit) {
                    throw ValidationException::withMessages(['amount' => 'Nominal melebihi saldo yang dapat dicatat. Batas '.self::money($limit).'.']);
                }
                if (Carbon::parse($canonical['paid_at'])->isFuture() || Carbon::parse($canonical['paid_at'])->lt($booking->created_at->copy()->startOfMinute())) {
                    throw ValidationException::withMessages(['paid_at' => 'Tanggal transaksi harus sejak booking dibuat sampai saat ini.']);
                }
                if ($canonical['method'] === 'transfer' && (! $file || strlen($canonical['reference']) < 3)) {
                    throw ValidationException::withMessages(['file' => 'Transfer memerlukan referensi dan bukti pembayaran privat.']);
                }
                if ($canonical['kind'] === 'refund' && strlen($canonical['notes']) < 5) {
                    throw ValidationException::withMessages(['notes' => 'Catat alasan pengembalian uang.']);
                }
                if ($file) {
                    $stored = $file->store('bookings/payment-proofs', 'local');
                    if (! $stored) {
                        throw ValidationException::withMessages(['file' => 'Bukti gagal disimpan.']);
                    }
                }
                $snapshot = ['booking_code' => $booking->booking_code, 'customer' => $booking->customer?->only(['name', 'whatsapp_number']), 'service' => $booking->service?->name, 'service_date' => $booking->booking_date->toDateString(), 'service_price' => $booking->service_price_snapshot, 'addons' => $booking->addOns->map(fn ($a) => $a->only(['name', 'price']))->all(), 'discount' => $booking->promo_discount, 'travel_fee' => $booking->travel_fee, 'total_cents' => $figures['total'], 'credit_cents' => $figures['credit'], 'net_cents' => $figures['net'] + ($data['kind'] === 'refund' ? -$canonical['amount_cents'] : $canonical['amount_cents']), 'issuer' => $actor->name, 'is_demo' => str_starts_with((string) $booking->notes, '[DEMO]') || str_starts_with((string) $booking->customer?->name, '[DEMO]')];
                $payment = $booking->payments()->create(collect($canonical)->except('booking_id', 'file_hash')->all() + ['entry_key' => $data['entry_key'], 'input_hash' => $hash, 'proof_path' => $stored, 'proof_mime' => $file?->getMimeType(), 'receipt_number' => ($data['kind'] === 'refund' ? 'RFN' : 'KWT').'-BKG-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)), 'snapshot' => $snapshot, 'created_by' => $actor->id]);
                $this->sync($booking);
                $this->audit->log($actor, 'booking.payment.'.$data['kind'], $payment, $request, [], ['amount_cents' => $payment->amount_cents, 'booking_id' => $booking->id, 'reference' => $canonical['reference']], 'Transaksi manual yang sudah terjadi dicatat; tidak melakukan transfer bank.');

                return $payment;
            });
        } catch (Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }throw $e;
        }
    }

    private function sync(Booking $booking): void
    {
        $f = $this->figures($booking);
        $status = $f['net'] >= $f['total'] ? 'paid' : ($f['net'] > 0 ? 'partial' : ($f['refund'] > 0 ? 'refunded' : 'unpaid'));
        $booking->forceFill(['payment_status' => $status, 'finance_revision' => $booking->finance_revision + 1])->save();
        app(TherapistFeeService::class)->sync($booking, auth()->user(), request());
    }

    public function void(Booking $booking, BookingPayment $payment, array $data, User $actor, Request $request): void
    {
        self::authorize($actor);
        DB::transaction(function () use ($booking, $payment, $data, $actor, $request) {
            $booking = $this->lock($booking, (int) $data['revision']);
            $payment = $booking->payments()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->voided_at) {
                throw ValidationException::withMessages(['finance' => 'Pencatatan sudah dikoreksi.']);
            }
            $f = $this->figures($booking);
            $net = $f['net'] + ($payment->kind === 'refund' ? $payment->amount_cents : -$payment->amount_cents);
            if ($net < 0 || $net > $f['total']) {
                throw ValidationException::withMessages(['finance' => 'Koreksi membuat saldo tidak sesuai. Periksa transaksi pembayaran/refund terkait terlebih dahulu.']);
            }
            $payment->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $data['reason']]);
            $this->sync($booking);
            $this->audit->log($actor, 'booking.payment.void', $payment, $request, [], ['reason' => $data['reason']], 'Koreksi pencatatan dan kuitansi; bukan pengembalian uang.');
        });
    }
}
