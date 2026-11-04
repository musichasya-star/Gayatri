<?php

namespace App\Services\CRM;

use App\Models\RentalCustomerRequest;
use App\Models\RentalItem;
use App\Models\RentalOrder;
use App\Models\RentalPaymentProof;
use App\Models\RentalUnit;
use Illuminate\Support\Collection;

class RentalOperations
{
    public function __construct(private readonly RentalInventory $inventory, private readonly RentalSettings $settings) {}

    public function alerts(?RentalOrder $onlyOrder = null): Collection
    {
        $items = RentalItem::with(['order', 'unit.product'])->whereNull('inspected_at')->whereHas('order', function ($q) {
            $this->inventory->blockingOrders($q);
        })->get();
        $alerts = collect();
        $horizon = today()->addDays((int) $this->settings->get()['ops_lookahead_days']);
        $add = function (string $key, string $type, string $severity, RentalItem $item, string $message, string $action, ?RentalOrder $blocking = null) use ($alerts, $onlyOrder) {
            if ($onlyOrder && $item->rental_order_id !== $onlyOrder->id && $blocking?->id !== $onlyOrder->id) {
                return;
            }
            $alerts->put($key, ['type' => $type, 'severity' => $severity, 'order' => $item->order, 'unit' => $item->unit, 'blocking_order' => $blocking, 'message' => $message, 'action' => $action]);
        };
        foreach ($items as $item) {
            if ($item->order->status === 'rented' && ! $item->returned_at && $item->order->end_date->lt(today())) {
                $add('overdue:'.$item->id, 'Terlambat kembali', 'critical', $item, $item->unit_code.' belum kembali sejak '.$item->order->end_date->format('d M Y').'.', 'Hubungi penyewa lalu catat pengembalian atau perpanjangan yang tersedia.');
            }
            if ($item->returned_at) {
                $add('inspection:'.$item->id, 'Menunggu pemeriksaan', 'warning', $item, $item->unit_code.' sudah kembali tetapi pemeriksaan belum selesai.', 'Periksa kondisi dan kelengkapan sebelum menyiapkan unit untuk penyewa berikutnya.');
            }
        }
        foreach ($items->groupBy('rental_unit_id') as $group) {
            foreach ($group as $target) {
                if (! in_array($target->order->status, ['pending', 'reserved'], true) || $target->order->start_date->gt($horizon)) {
                    continue;
                }
                $others = $group->where('rental_order_id', '!=', $target->rental_order_id);
                if (! in_array($target->unit->status, ['ready', 'rented'], true)) {
                    $add('not-ready:'.$target->id, 'Unit pesanan belum siap', 'critical', $target, $target->unit_code.' untuk '.$target->order->start_date->format('d M Y').' masih berstatus '.(RentalUnit::STATUSES[$target->unit->status] ?? $target->unit->status).'.', 'Selesaikan pemeriksaan/perawatan atau ganti unit melalui detail transaksi.');
                }
                if ($target->unit->next_maintenance_at && $target->unit->next_maintenance_at->lte($target->rental_end_date ?? $target->order->end_date)) {
                    $add('maintenance-risk:'.$target->id, 'Perawatan berbenturan dengan sewa', 'critical', $target, $target->unit_code.' jatuh tempo perawatan sebelum masa sewa pesanan selesai.', 'Selesaikan perawatan atau ganti unit sebelum serah-terima.');
                }
                foreach ($others as $source) {
                    if (in_array($source->order->status, ['rented', 'inspection'], true) && (! $source->returned_at && $source->order->end_date->lt(today()) || $source->returned_at)) {
                        $add('at-risk:'.$target->id.':'.$source->id, 'Pesanan berikutnya terancam', 'critical', $target, $target->unit_code.' belum siap dari '.$source->order->code.' untuk pesanan mulai '.$target->order->start_date->format('d M Y').'.', 'Tindak lanjuti transaksi asal atau ganti unit pesanan berikutnya.', $source->order);
                    } elseif ($source->order->status === 'rented' && ! $source->returned_at && $target->order->start_date->lte(today()->addDay())) {
                        $add('turnover:'.$target->id.':'.$source->id, 'Persiapan serah-terima', 'warning', $target, $target->unit_code.' masih di penyewa '.$source->order->code.'; pesanan berikutnya mulai '.$target->order->start_date->format('d M Y').'.', 'Pastikan pengembalian, pembersihan, dan pemeriksaan selesai tepat waktu.', $source->order);
                    }
                    if ($source->order->start_date->lt($target->reserved_until) && $source->reserved_until->gt($target->order->start_date)) {
                        $pair = collect([$target->id, $source->id])->sort()->join(':');
                        $add('overlap:'.$pair, 'Reservasi unit bertumpuk', 'critical', $target, $target->unit_code.' tercatat pada periode yang bertumpuk dengan '.$source->order->code.', termasuk jeda pembersihan.', 'Periksa tanggal dan ganti salah satu unit; jangan lakukan serah-terima ganda.', $source->order);
                    }
                }
            }
        }

        $dueUnits = RentalUnit::where('status', '!=', 'retired')->whereDate('next_maintenance_at', '<=', $horizon)
            ->when($onlyOrder, fn ($q) => $q->whereIn('id', $onlyOrder->items()->pluck('rental_unit_id')))->get();
        foreach ($dueUnits as $unit) {
            $alerts->put('maintenance:'.$unit->id, ['type' => 'Jadwal perawatan unit', 'severity' => $unit->maintenanceDue() ? 'critical' : 'warning', 'order' => $onlyOrder, 'unit' => $unit, 'blocking_order' => null,
                'message' => $unit->code.' memiliki jadwal perawatan '.$unit->next_maintenance_at->format('d M Y').'.', 'action' => 'Catat pekerjaan melalui Perawatan Unit sebelum melewati jadwal tersebut.', 'url' => route('admin.rentals.care.show', $unit)]);
        }

        $payments = RentalOrder::with(['items', 'payments'])->whereIn('status', ['reserved', 'rented', 'inspection', 'completed'])
            ->when($onlyOrder, fn ($q) => $q->whereKey($onlyOrder->id))->get();
        foreach ($payments as $paymentOrder) {
            $summary = app(RentalPaymentTerms::class)->summary($paymentOrder);
            if ($summary['dp_overdue'] || $summary['balance_overdue']) {
                $alerts->push(['type' => 'Tenggat pembayaran terlewati', 'severity' => 'warning', 'order' => $paymentOrder, 'unit' => null, 'blocking_order' => null, 'message' => $paymentOrder->code.' memiliki pembayaran yang belum terpenuhi pada tenggatnya.', 'action' => 'Tinjau bukti transfer dan saldo sebelum menghubungi penyewa.']);
            }
        }
        $waiting = RentalPaymentProof::with('order')->where('status', 'pending')->when($onlyOrder, fn ($q) => $q->where('rental_order_id', $onlyOrder->id))->get();
        $customerRequests = RentalCustomerRequest::with('order')->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'approved')->where('approved_amount', '>', 0)->whereNull('rental_payment_id')))
            ->when($onlyOrder, fn ($q) => $q->where('rental_order_id', $onlyOrder->id))->get();
        foreach ($customerRequests as $entry) {
            $alerts->push(['type' => $entry->status === 'pending' ? 'Pengajuan pelanggan menunggu keputusan' : 'Refund belum dicatat', 'severity' => 'warning', 'order' => $entry->order, 'unit' => null, 'blocking_order' => null,
                'message' => $entry->order->code.' · '.RentalCustomerRequest::KINDS[$entry->kind].' #'.$entry->id.'.', 'action' => 'Tinjau pengajuan atau bukti refund melalui Pembatalan & Refund.', 'url' => route('admin.rentals.customer-requests.index', ['status' => $entry->status])]);
        }
        foreach ($waiting as $proof) {
            $alerts->push(['type' => 'Bukti transfer menunggu verifikasi', 'severity' => 'warning', 'order' => $proof->order, 'unit' => null, 'blocking_order' => null, 'message' => 'Bukti #'.$proof->id.' sebesar Rp '.number_format($proof->amount, 0, ',', '.').' belum ditinjau.', 'action' => 'Cocokkan dana masuk lalu setujui atau tolak melalui detail transaksi.']);
        }

        return $alerts->concat(app(RentalTransportService::class)->alerts($onlyOrder))->sortBy(fn ($alert) => $alert['severity'] === 'critical' ? 0 : 1)->values();
    }
}
