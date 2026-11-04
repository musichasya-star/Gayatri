<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\BookingFinance;
use App\Support\CustomerPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BookingFinanceController extends Controller
{
    public function show(Booking $booking, BookingFinance $finance)
    {
        $booking->load('customer', 'service', 'addOns', 'payments');

        return response()->view('admin.bookings.finance', ['booking' => $booking, 'figures' => $finance->figures($booking), 'fingerprint' => $finance->fingerprint($booking)])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function activate(Request $request, Booking $booking, BookingFinance $finance)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'credit' => ['required', 'numeric', 'min:0', 'max:100000000000', 'decimal:0,2'], 'legacy_service_price' => ['nullable', 'numeric', 'min:0', 'max:100000000000', 'decimal:0,2'], 'reference' => ['required', 'string', 'min:5', 'max:500'], 'confirmed' => ['required', 'accepted']]);
        $finance->activate($booking, $data, $request->user(), $request);

        return back()->with('status', 'Jurnal diaktifkan. Saldo awal historis tidak dihitung sebagai penerimaan uang baru.');
    }

    public function record(Request $request, Booking $booking, BookingFinance $finance)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'entry_key' => ['required', 'uuid'], 'fingerprint' => ['required', 'string', 'size:64'], 'kind' => ['required', Rule::in(array_keys(BookingPayment::KINDS))], 'amount' => ['required', 'numeric', 'gt:0', 'max:100000000000', 'decimal:0,2'], 'method' => ['required', Rule::in(array_keys(BookingPayment::METHODS))], 'paid_at' => ['required', 'date', 'before_or_equal:now'], 'payer_name' => ['required', 'string', 'max:150'], 'reference' => ['nullable', 'required_if:method,transfer', 'string', 'min:3', 'max:150'], 'notes' => ['nullable', 'required_if:kind,refund', 'string', 'min:5', 'max:1500'], 'file' => ['nullable', 'required_if:method,transfer', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'], 'confirmed' => ['required', 'accepted']]);
        $finance->record($booking, $data, $request->file('file'), $request->user(), $request);

        return back()->with('status', 'Transaksi manual dan kuitansi tersimpan. Status pembayaran mengikuti saldo jurnal.');
    }

    public function void(Request $request, Booking $booking, BookingPayment $payment, BookingFinance $finance)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:1000'], 'confirmed' => ['required', 'accepted']]);
        $finance->void($booking, $payment, $data, $request->user(), $request);

        return back()->with('status', 'Pencatatan dan kuitansi ditandai dikoreksi. Tindakan ini tidak mengembalikan uang.');
    }

    public function proof(Booking $booking, BookingPayment $payment)
    {
        abort_unless($payment->booking_id === $booking->id && $payment->proof_path && str_starts_with($payment->proof_path, 'bookings/payment-proofs/') && Storage::disk('local')->exists($payment->proof_path), 404);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$payment->proof_mime] ?? 'bin';

        return Storage::disk('local')->download($payment->proof_path, 'bukti-booking-'.$payment->id.'.'.$ext, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function receipt(Booking $booking, BookingPayment $payment)
    {
        abort_unless($payment->booking_id === $booking->id, 404);

        return response()->view('admin.bookings.receipt', ['payment' => $payment, 'booking' => $booking, 'snapshot' => $payment->snapshot])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function verify(Request $request, Booking $booking, AuditLogService $audit)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'min:5', 'max:500'], 'confirmed' => ['required', 'accepted'], 'update_customer' => ['nullable', 'boolean']]);
        DB::transaction(function () use ($booking, $request, $data, $audit) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $customer = $booking->customer()->lockForUpdate()->firstOrFail();
            abort_if(in_array($customer->status, ['blacklisted', 'blocked'], true) || CustomerPhone::normalize($customer->whatsapp_number) !== CustomerPhone::normalize($booking->public_request_data['whatsapp_number'] ?? $customer->whatsapp_number), 422);
            $old = $customer->only(['name', 'email', 'baby_name']);
            abort_unless($booking->captureContactSnapshot(), 422);
            if ($request->boolean('update_customer')) {
                $customer->update(collect($booking->public_request_data)->only(['name', 'email', 'baby_name'])->all());
            }
            $booking->forceFill(['contact_verified_at' => now(), 'contact_verified_by' => $request->user()->id, 'contact_verification_reference' => $data['reference'], 'finance_revision' => $booking->finance_revision + 1])->save();
            $audit->log($request->user(), 'booking.contact.verified', $booking, $request, $old, ['update_customer' => $request->boolean('update_customer'), 'reference' => $data['reference']], 'Kontak diperiksa admin; perubahan profil hanya jika dipilih secara eksplisit.');
        });

        return back()->with('status', 'Verifikasi kontak tersimpan. Profil CRM hanya diperbarui jika dipilih pada formulir.');
    }
}
