<?php

namespace App\Services\AI;

use App\Models\RentalCustomerRequest;
use App\Models\RentalOrder;
use App\Models\RentalTransportTask;
use App\Services\CRM\BookingFinance;

class RentalStatusReader
{
    public function read(string $code, array $identity): ?array
    {
        $order = RentalOrder::with('items', 'payments', 'transportTasks')->where('code', $code)
            ->where('customer_id', $identity['customer_id'])->where('is_demo', $identity['is_demo'])->first();
        if (! $order) {
            return null;
        }
        $contact = app(LookupContact::class);
        $phone = $order->notification_verified_at ? $order->notification_phone : data_get($order->customer_snapshot, 'whatsapp_number');
        if (! $contact->matches($phone, $identity) || ($order->notification_phone && ! $order->notification_verified_at)) {
            return null;
        }
        $lines = ['Kode: '.$order->code, 'Layanan: Rental Perlengkapan Bayi',
            'Periode: '.$order->start_date->format('d-m-Y').' s.d. '.$order->end_date->format('d-m-Y'),
            'Status sewa: '.(RentalOrder::STATUSES[$order->status] ?? 'Perlu pemeriksaan admin')];
        $names = $order->items->pluck('product_name')->unique()->take(5)->map(fn ($name) => app(AiPublicContent::class)->clean($name, 100));
        if ($names->isNotEmpty()) {
            $lines[] = 'Alat: '.$names->implode(', ');
        }
        $balance = BookingFinance::cents(number_format($order->balance(), 2, '.', ''));
        $lines[] = 'Pembayaran: '.($balance === 0 ? 'Tidak ada sisa tagihan' : ($order->received() > 0 ? 'Sebagian / DP terverifikasi' : 'Belum ada pembayaran terverifikasi'));
        if (in_array($order->status, ['completed', 'cancelled'], true) && $order->refundable() > 0) {
            $lines[] = 'Saldo yang perlu ditinjau untuk refund: '.BookingFinance::money(BookingFinance::cents(number_format($order->refundable(), 2, '.', ''))).'. Pengembalian menunggu proses admin.';
        }
        if (! in_array($order->status, ['cancelled'], true)) {
            $lines[] = 'Sisa tagihan: '.BookingFinance::money($balance);
        }
        if ($order->paymentProofs()->where('status', 'pending')->exists()) {
            $lines[] = 'Bukti pembayaran: menunggu verifikasi admin.';
        }
        foreach ($order->transportTasks->sortBy('id')->groupBy('type') as $type => $tasks) {
            $task = $tasks->last();
            $lines[] = (RentalTransportTask::TYPES[$type] ?? 'Antar-jemput').': '.($task->hasStaleSchedule($order) ? 'Jadwal perlu dikonfirmasi ulang' : (RentalTransportTask::STATUSES[$task->status] ?? 'Perlu pemeriksaan admin'))
                .($task->scheduled_start ? ' · '.$task->scheduled_start->timezone('Asia/Jakarta')->format('d-m-Y H:i').' WIB' : '');
        }
        $extension = $order->extensionRequests()->latest('id')->first();
        if ($extension) {
            $lines[] = 'Permintaan perpanjangan: '.(['pending' => 'Menunggu keputusan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'][$extension->status] ?? 'Perlu pemeriksaan admin');
        }
        $request = $order->customerRequests()->latest('id')->first();
        if ($request) {
            $lines[] = (RentalCustomerRequest::KINDS[$request->kind] ?? 'Permintaan').': '.(RentalCustomerRequest::STATUSES[$request->status] ?? 'Perlu pemeriksaan admin');
        }

        return $contact->result('rental', $order, 'rental', $lines, [$phone, $order->notification_contact_version]);
    }
}
