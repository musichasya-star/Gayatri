# Perapian navigasi dan konsistensi tampilan admin

Status: **selesai di lokal**, 4 November 2026. Lingkungan pemeriksaan browser: `http://127.0.0.1:8000`.

## Hasil perubahan

1. **Navigasi Laundry dan Rental disatukan.** `AdminModuleNavigation` menjadi sumber daftar menu, label, ikon, dan aturan halaman aktif untuk sidebar serta komponen `ui.module-navigation`. Halaman turunan seperti pengaturan permintaan Laundry dan formulir katalog tetap menandai menu induk yang benar. Bukti pembayaran tidak ikut menandai menu permintaan.
2. **Submenu lebih jelas dan responsif.** Menu utama dan tambahan berada dalam satu panel. “Menu lainnya” menunjukkan konteks menu tambahan yang aktif, dengan panah buka/tutup. Laporan memakai submenu tertutup saat awal; halaman tambahan lain membuka konteks aktifnya. Pada ponsel, pilihan tersusun dua kolom dan label dapat membungkus. Elemen `details/summary` tetap dapat dioperasikan melalui keyboard.
3. **Tindakan halaman lebih rapi.** Slot tindakan pada judul memakai jarak yang konsisten, dapat berpindah baris, dan menyesuaikan lebar layar. Tombol pada header tidak lagi saling menempel. Sidebar desktop menggulir seperlunya agar menu aktif terlihat, tanpa menggulir isi halaman.
4. **Konteks katalog dipertahankan.** Katalog Pijat & Spa/Laktasi, alat Rental, tarif Laundry, dan produk pendukung membawa filter ke formulir edit/tambah serta tombol batal/kembali dan tujuan sesudah penyimpanan. Pencarian, kategori, publikasi, kesiapan, kelompok layanan, dan halaman daftar mengikuti parameter yang diizinkan untuk masing-masing modul. Pilihan publikasi kosong tetap berarti “Semua publikasi”, sedangkan `0` tetap berarti arsip. URL pengalihan bebas tidak diterima.
5. **Umpan balik formulir Laundry/Rental dilengkapi.** Komponen bersama menampilkan pesan berhasil dan daftar kesalahan validasi dengan `role=status`/`role=alert`. Pesan pada halaman dokumen dan transport disesuaikan agar tidak ganda. Proses transaksi, harga, stok, serta perizinan tidak diganti.
6. **Tabel yang dapat digeser lebih mudah diakses.** Penanganan region dan fokus keyboard kini juga mencakup pembungkus tabel yang sudah dibuat oleh template, bukan hanya pembungkus yang dibuat JavaScript. Tabel hanya masuk urutan fokus ketika isinya lebih lebar daripada area tampil.

## Cakupan pemeriksaan

| Pemeriksaan | Hasil |
|---|---|
| Semua tujuan sidebar | 56 halaman utama diperiksa pada viewport 390 dan 1280 px: **112 pemeriksaan**. Mencakup operasional/CRM, Booking, layanan Pijat & Spa/Laktasi, produk pendukung, CMS, terapis/fee, seluruh Laundry dan Rental, AI, laporan, WhatsApp, Pengaturan, serta pengguna. |
| Layar kecil dan tablet | Laporan Laundry, katalog Rental, Knowledge Base, dan Settings diperiksa lagi pada 320 dan 768 px: **8 pemeriksaan**. |
| Kesalahan tampilan | Total **120 pemeriksaan setelah perbaikan** tidak menemukan judul halaman hilang, kode template mentah, atau luapan horizontal halaman. Pada 112 pemeriksaan menu utama, penanda sidebar aktif tepat satu. |
| Alur browser | Pencarian → edit → kembali pada katalog layanan, alat Rental, dan tarif Laundry mempertahankan pencarian. Browser hanya mengubah filter GET dan membuka formulir; tidak menyimpan katalog atau transaksi. |
| Interaksi | Buka/tutup submenu dengan Enter; sidebar mobile memfokuskan menu aktif; Escape menutup sidebar dan mengembalikan fokus; tabel katalog Rental pada tablet memperoleh `role=region` dan `tabindex=0` saat melebar. |
| Regresi aplikasi | **68 pengujian, 861 assertion lulus**: AdminNavigationConsistency, AdminCatalogue, AdminFoundation, AdminWorkbench, AdminOperationalWorkflow, dan CrmSecurityHardening. |
| Kompilasi/sintaks | Kompilasi view Blade berhasil. Pemeriksaan sintaks PHP terkait, JavaScript, dan diff berkas terkait lulus. Tidak ditemukan warning/error konsol pada sesi pemeriksaan akhir. |

Pengujian aplikasi memakai `APP_ENV=testing`, SQLite `:memory:`, antrean palsu, dan HTTP palsu/blokir permintaan tidak terduga. Pengujian baru mencakup render semua tujuan sidebar, satu menu aktif, konteks submenu, siklus daftar/edit/simpan empat katalog, parameter kembali yang tidak valid, serta pesan validasi/berhasil. Uji simpan hanya terjadi pada basis data pengujian tersebut.

Pemeriksaan lintas modul menggunakan data lokal yang tersedia. Semua kombinasi data transaksi, peran, dan ukuran layar tidak diuji ulang secara manual; batas cakupan browser dijelaskan pada tabel. Regresi Booking/AI dan perlindungan akses menggunakan suite terkait. Tidak ada deployment VPS atau pengiriman pesan WhatsApp dalam pengerjaan ini.

## Bukti

- `storage/logs/admin-navigation-audit/browser-checks.json`: daftar halaman, ukuran, penanda aktif, hasil lebar halaman, serta interaksi yang diperiksa.
- `storage/logs/admin-navigation-audit/php-tests.txt`: hasil 68 pengujian.
- `storage/logs/admin-navigation-audit/laundry-desktop.png`: navigasi desktop.
- `storage/logs/admin-navigation-audit/laundry-mobile.png`: submenu dua kolom pada ponsel.

## Berkas utama

- `app/Support/AdminModuleNavigation.php` dan `resources/views/components/ui/module-navigation.blade.php`.
- `resources/views/partials/sidebar.blade.php`, navigasi Laundry/Rental, dan `resources/views/components/ui/page-header.blade.php`.
- `app/Support/AdminReviewContext.php`, controller serta template daftar/form empat katalog.
- `resources/views/components/ui/form-feedback.blade.php`.
- `public/css/gayatri-theme.css`, `public/css/admin-operations.css`, `public/js/gayatri-ui.js`.
- `tests/Feature/AdminNavigationConsistencyTest.php`.
