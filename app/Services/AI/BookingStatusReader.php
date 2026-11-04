<?php

namespace App\Services\AI;

use App\Models\Booking;
use App\Services\CRM\BookingFinance;
use App\Support\AiDomain;

class BookingStatusReader
{
    public function read(string $code, array $identity, ?string $domain = null): ?array
    {
        $booking = Booking::with('service', 'branch')->where('booking_code', $code)
            ->where('customer_id', $identity['customer_id'])->where('is_demo', $identity['is_demo'])->first();
        if (! $booking) {
            return null;
        }
        $contact = app(LookupContact::class);
        $snapshot = $booking->contact_snapshot;
        $publicPhone = data_get($booking->public_request_data, 'whatsapp_number');
        if (($snapshot && ((int) ($snapshot['customer_id'] ?? 0) !== $identity['customer_id']
                || ! $contact->matches($snapshot['whatsapp_number'] ?? null, $identity)))
            || (! $snapshot && ! $contact->matches($publicPhone, $identity))
            || ($publicPhone && ! $contact->matches($publicPhone, $identity))) {
            return null;
        }
        $group = $booking->service?->service_group;
        if (! in_array($group, ['spa', 'lactation'], true) || ($domain && $domain !== $group)) {
            return null;
        }
        $statuses = ['draft' => 'Draft', 'pending' => 'Menunggu konfirmasi', 'pending_confirmation' => 'Menunggu konfirmasi',
            'confirmed' => 'Dikonfirmasi', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan', 'cancelled_by_user' => 'Dibatalkan pelanggan',
            'rescheduled' => 'Jadwal diubah', 'no_show' => 'Tidak hadir'];
        $lines = ['Kode: '.$booking->booking_code, 'Layanan: '.AiDomain::LABELS[$group].' — '.app(AiPublicContent::class)->clean($booking->service->name, 120),
            'Jadwal: '.$booking->booking_date?->format('d-m-Y').' '.substr((string) $booking->start_time, 0, 5).' WIB',
            'Status booking: '.($statuses[$booking->status] ?? 'Perlu pemeriksaan admin'),
            'Pelayanan: '.($booking->service_mode === 'home' ? 'Kunjungan rumah' : 'Di tempat layanan')];
        if ($booking->branch) {
            $lines[] = 'Cabang: '.app(AiPublicContent::class)->clean($booking->branch->name, 100);
        }
        if ($booking->finance_managed && $booking->service_price_snapshot !== null) {
            $figures = app(BookingFinance::class)->figures($booking);
            if (in_array($booking->status, ['cancelled', 'cancelled_by_user', 'no_show'], true)) {
                $lines[] = 'Jurnal pembayaran: penerimaan '.BookingFinance::money($figures['paid']).'; refund tercatat '.BookingFinance::money($figures['refund']).'. Saldo historis '.BookingFinance::money($figures['credit']).'.';
            } else {
                $lines[] = 'Pembayaran: '.($figures['balance'] === 0 ? 'Tidak ada sisa tagihan' : ($figures['net'] > 0 ? 'Sebagian / DP terverifikasi' : 'Belum ada pembayaran terverifikasi'));
                $lines[] = 'Sisa tagihan: '.BookingFinance::money($figures['balance']);
            }
        } else {
            $lines[] = 'Pembayaran: '.match ($booking->payment_status) {
                'paid' => 'Lunas (catatan historis; tidak membentuk penerimaan baru)',
                'partial' => 'Sebagian (catatan historis; rincian diperiksa admin)',
                'refunded' => 'Refund (catatan historis; rincian diperiksa admin)',
                default => 'Belum ada pembayaran terverifikasi pada jurnal aktif',
            };
        }

        return $contact->result('booking', $booking, $group, $lines, [$snapshot, $publicPhone, $booking->finance_revision]);
    }
}
