<?php

namespace App\Services\AI;

use App\Models\LaundryOrder;
use App\Models\LaundryPublicRequest;
use App\Models\LaundryTransportTask;
use App\Services\CRM\BookingFinance;

class LaundryStatusReader
{
    public function read(string $code, array $identity): ?array
    {
        $contact = app(LookupContact::class);
        if (preg_match('/^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$/iD', $code)) {
            $request = LaundryPublicRequest::where('public_id', strtolower($code))->where('is_demo', $identity['is_demo'])
                ->where(fn ($q) => $q->where('customer_id', $identity['customer_id'])->orWhere(fn ($p) => $p->whereNull('customer_id')
                    ->whereIn('whatsapp_number', [$identity['phone'], '0'.substr($identity['phone'], 2)])))->first();
            if (! $request || ! $contact->matches($request->whatsapp_number, $identity)) {
                return null;
            }
            if ($request->laundry_order_id) {
                $linkedCode = LaundryOrder::whereKey($request->laundry_order_id)->where('customer_id', $identity['customer_id'])->value('code');

                return $linkedCode ? $this->read($linkedCode, $identity) : null;
            }
            $lines = ['Kode permintaan: '.$request->public_id, 'Layanan: Laundry Ibu & Bayi',
                'Status permintaan: '.(LaundryPublicRequest::STATUSES[$request->status] ?? 'Perlu pemeriksaan admin'),
                'Belum menjadi order laundry; tagihan final menunggu proses penerimaan dan penimbangan.'];

            return $contact->result('laundry_request', $request, 'laundry', $lines, [$request->whatsapp_number, $request->customer_id, $request->revision]);
        }
        $order = LaundryOrder::with('payments', 'transportTasks', 'publicRequest')->where('code', $code)
            ->where('customer_id', $identity['customer_id'])->where('is_demo', $identity['is_demo'])->first();
        if (! $order || ! $contact->matches(data_get($order->customer_snapshot, 'whatsapp_number'), $identity)
            || ($order->publicRequest && ! $contact->matches($order->publicRequest->whatsapp_number, $identity))) {
            return null;
        }
        $lines = ['Kode: '.$order->code, 'Layanan: Laundry Ibu & Bayi',
            'Status penerimaan: '.(LaundryOrder::STATUSES[$order->status] ?? 'Perlu pemeriksaan admin'),
            'Proses: '.(LaundryOrder::WORKFLOW[$order->workflow_status] ?? 'Perlu pemeriksaan admin')];
        $due = $order->totalDue();
        if ($due === null || in_array($order->status, ['received', 'awaiting_approval'], true)) {
            $lines[] = 'Tagihan: menunggu hasil timbang / persetujuan.';
        } elseif ($order->status === 'cancelled') {
            $lines[] = 'Pembayaran / pengembalian dana: konfirmasi melalui admin sesuai jurnal.';
        } else {
            $balance = max(0, $due - $order->paidAmount());
            $lines[] = 'Pembayaran: '.($balance === 0 ? 'Tidak ada sisa tagihan' : ($order->paidAmount() > 0 ? 'Sebagian / DP terverifikasi' : 'Belum ada pembayaran terverifikasi'));
            $lines[] = 'Sisa tagihan: '.BookingFinance::money($balance * 100);
        }
        if ($order->paymentProofs()->where('status', 'pending')->exists()) {
            $lines[] = 'Bukti pembayaran: menunggu verifikasi admin.';
        }
        foreach ($order->transportTasks->sortBy('id')->groupBy('kind') as $kind => $tasks) {
            $task = $tasks->last();
            $lines[] = (LaundryTransportTask::KINDS[$kind] ?? 'Antar-jemput').': '.(LaundryTransportTask::STATUSES[$task->status] ?? 'Perlu pemeriksaan admin')
                .($task->scheduled_start ? ' · '.$task->scheduled_start->timezone('Asia/Jakarta')->format('d-m-Y H:i').' WIB' : '');
        }

        return $contact->result('laundry', $order, 'laundry', $lines, [$order->customer_snapshot, $order->publicRequest?->whatsapp_number, $order->revision]);
    }
}
