<?php

namespace App\Support;

/** One menu definition for the module navigation and the sidebar. */
final class AdminModuleNavigation
{
    public static function items(string $module): array
    {
        $items = match ($module) {
            'laundry' => [
                ['orders.index', 'Penerimaan & Penimbangan', 'washing-machine', 'orders.*', true, 'Penerimaan Laundry'],
                ['dashboard', 'Dashboard Laundry', 'layout-dashboard', 'dashboard'],
                ['reports', 'Laporan Laundry', 'chart-column', 'reports*'],
                ['requests.index', 'Permintaan Online', 'clipboard-list', 'requests.*', true, 'Permintaan Laundry', ['requests.proofs']],
                ['requests.proofs', 'Bukti Pelanggan', 'receipt', 'requests.proofs', true, 'Bukti Pembayaran Laundry'],
                ['operations', 'Proses & QC', 'clipboard-check', 'operations*', true, 'Proses & QC Laundry'],
                ['transport.index', 'Antar-Jemput', 'truck', 'transport.*', true, 'Antar-Jemput Laundry'],
                ['notifications.index', 'WhatsApp Laundry', 'message-circle', 'notifications.*'],
                ['tariffs.index', 'Katalog Tarif', 'tags', 'tariffs.*', false, 'Katalog Tarif Laundry'],
                ['settings', 'Pengaturan Outlet', 'store', 'settings*'],
            ],
            'rentals' => [
                ['products.index', 'Katalog Rental', 'baby', 'products.*'],
                ['dashboard', 'Dashboard Rental', 'layout-dashboard', 'dashboard'],
                ['reports', 'Laporan Rental', 'chart-column', 'reports*'],
                ['units.index', 'Stok Unit', 'boxes', 'units.*'],
                ['care.index', 'Perawatan Unit', 'clipboard-check', 'care.*', true],
                ['transport.index', 'Jadwal Antar-Jemput', 'truck', 'transport.*', true],
                ['documents.index', 'Invoice & Kuitansi', 'receipt-text', 'documents.*'],
                ['payment-reviews', 'Verifikasi Pembayaran', 'badge-check', 'payment-reviews*'],
                ['customer-requests.index', 'Pembatalan & Refund', 'receipt', 'customer-requests.*'],
                ['calendar', 'Kalender Sewa', 'calendar-days', 'calendar', true],
                ['orders.index', 'Transaksi Sewa', 'clipboard-list', 'orders.*', true],
                ['operations', 'Peringatan Rental', 'triangle-alert', 'operations*', true],
                ['notifications.index', 'WhatsApp Rental', 'message-circle', 'notifications.*'],
                ['settings.index', 'Pengaturan Rental', 'settings', 'settings*'],
            ],
        };

        return array_map(fn ($item) => [
            'route' => "admin.{$module}.{$item[0]}",
            'label' => $item[1], 'icon' => $item[2],
            'active' => "admin.{$module}.{$item[3]}",
            'primary' => $item[4] ?? false,
            'sidebar_label' => $item[5] ?? $item[1],
            'except' => array_map(fn ($route) => "admin.{$module}.{$route}", $item[6] ?? []),
        ], $items);
    }

    public static function isActive(array $item): bool
    {
        return request()->routeIs($item['active']) && ! request()->routeIs($item['except']);
    }
}
