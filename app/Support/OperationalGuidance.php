<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\LaundryOrder;
use App\Models\RentalOrder;
use App\Services\CRM\LaundryPortal;

/** Read-only guidance. Every transition remains subject to its existing server guards. */
final class OperationalGuidance
{
    public static function booking(Booking $booking): array
    {
        if (in_array($booking->status, ['cancelled', 'cancelled_by_user', 'no_show'], true)) {
            return ['label' => 'Tinjau transaksi', 'hint' => 'Periksa riwayat pembayaran dan tindak lanjut pembatalan.', 'route' => 'admin.bookings.finance'];
        }
        if ($booking->status === 'completed') {
            return ['label' => 'Tinjau pembayaran', 'hint' => 'Layanan selesai. Pastikan jurnal dan kuitansi sesuai.', 'route' => 'admin.bookings.finance'];
        }

        return ['label' => $booking->therapist_id ? 'Kelola jadwal' : 'Tentukan terapis', 'hint' => 'Periksa kontak, slot, dan kesiapan terapis sebelum konfirmasi.', 'route' => 'admin.bookings.edit'];
    }

    public static function laundry(LaundryOrder $order): array
    {
        if ($order->status === 'cancelled' || $order->workflow_status === 'handed_over') {
            return ['label' => 'Lihat riwayat', 'anchor' => 'riwayat', 'hint' => 'Pencatatan sebelumnya tetap tersimpan.'];
        }
        if ($order->actual_total === null) {
            if (! $order->canReweigh()) {
                return ['label' => 'Periksa pengunci penimbangan', 'anchor' => 'pembayaran', 'hint' => 'Tinjau pembayaran, bukti yang menunggu verifikasi, atau dokumen aktif sebelum koreksi timbang.'];
            }

            return ['label' => 'Catat hasil timbang', 'anchor' => 'penimbangan', 'hint' => 'Periksa jumlah potong dan berat setiap kantong.'];
        }
        if ($order->status === 'awaiting_approval') {
            if (! LaundryPortal::priceReady($order)) {
                return ['label' => 'Konfirmasikan ongkir', 'anchor' => 'antar-jemput', 'hint' => 'Lengkapi kesepakatan ongkir sebelum meminta persetujuan total.'];
            }

            return ['label' => 'Minta persetujuan', 'anchor' => 'persetujuan', 'hint' => 'Sampaikan hasil timbang dan total termasuk ongkir kepada pelanggan.'];
        }
        if ($order->workflow_status === 'ready') {
            if ($order->balance() > 0) {
                return ['label' => 'Periksa pelunasan', 'anchor' => 'pembayaran', 'hint' => 'Barang sudah siap. Pelunasan diperlukan sebelum serah terima.'];
            }
            if ($order->transportTasks()->where('kind', 'delivery')->whereIn('status', ['planned', 'in_transit', 'failed'])->exists()) {
                return ['label' => 'Buka jadwal antar', 'anchor' => 'antar-jemput', 'hint' => 'Selesaikan serah terima melalui tugas pengantaran yang aktif.'];
            }

            return ['label' => 'Serahkan barang', 'anchor' => 'pengerjaan', 'hint' => 'Cocokkan identitas penerima, jumlah barang, dan kelulusan QC.'];
        }
        if ($order->workflow_status === 'pending' && $order->transportTasks()->where('kind', 'pickup')->whereIn('status', ['planned', 'in_transit', 'failed'])->exists()) {
            return ['label' => 'Selesaikan penjemputan', 'anchor' => 'antar-jemput', 'hint' => 'Pastikan barang sudah diterima di outlet sebelum memulai pengerjaan.'];
        }

        return ['label' => $order->workflow_status === 'pending' ? 'Mulai pengerjaan' : ($order->workflow_status === 'quality_check' ? 'Periksa kualitas' : 'Lanjutkan pengerjaan'), 'anchor' => 'pengerjaan', 'hint' => 'Ikuti urutan proses tersimpan. Tahap berikutnya tetap diperiksa oleh sistem.'];
    }

    public static function rental(RentalOrder $order): array
    {
        if ($order->isExpired()) {
            return ['label' => 'Tinjau permintaan kedaluwarsa', 'anchor' => 'ringkasan', 'hint' => 'Stok tidak lagi ditahan. Periksa ketersediaan melalui transaksi baru.'];
        }

        return match ($order->status) {
            'pending' => ['label' => 'Tinjau konfirmasi', 'anchor' => 'ringkasan', 'hint' => 'Periksa jadwal, unit, dan ketentuan sebelum mengunci reservasi.'],
            'reserved' => ['label' => 'Siapkan serah terima', 'anchor' => 'unit-pengembalian', 'hint' => 'Periksa kebersihan, kelengkapan, dan kesiapan setiap unit sebelum keluar.'],
            'rented' => ['label' => $order->isOverdue() ? 'Tindak lanjuti keterlambatan' : 'Kelola pengembalian', 'anchor' => 'unit-pengembalian', 'hint' => 'Catat kondisi ketika kembali. Perpanjangan tetap memeriksa bentrok jadwal.'],
            'inspection' => ['label' => 'Selesaikan pemeriksaan', 'anchor' => 'unit-pengembalian', 'hint' => 'Unit belum tersedia kembali sebelum diperiksa dan dinyatakan siap.'],
            default => ['label' => $order->refundable() > 0 ? 'Tinjau saldo refund' : 'Lihat pembayaran & dokumen', 'anchor' => 'pembayaran', 'hint' => 'Periksa saldo, bukti, dan ketentuan sebelum mencatat pengembalian dana.'],
        };
    }
}
