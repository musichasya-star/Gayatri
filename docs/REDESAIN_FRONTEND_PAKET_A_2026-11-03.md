# Fondasi Visual dan Beranda Gayatri

Status: **Paket A selesai di lokal**, 3 November 2026.

Acuan: `docs/RENCANA_REDESAIN_FRONTEND_GAYATRI_2026-11-03.md`, referensi gambar pengguna, dan audit pada `storage/logs/landing-audit-20261103/`. Hasil dapat dilihat melalui `http://127.0.0.1:8000/` dengan lingkungan `baileys-local`.

## Hasil Implementasi

| Area | Hasil |
|---|---|
| Fondasi visual | Warna ivory, cokelat, dan emas; judul Playfair Display dan isi Inter; pola tombol, kartu, jarak, fokus, dan status bersama. CSS dibatasi pada halaman yang memakainya. |
| Header | Navigasi desktop satu baris, menu dialog pada ponsel/tablet, CTA ke pemilih layanan, tautan lewati isi, penanda navigasi aktif, dan fokus keyboard. Escape mengembalikan fokus ke tombol menu; Tab/Shift+Tab berputar di dalam dialog. Memperbesar viewport ke desktop menutup menu dan memulihkan guliran. |
| Hero | Ilustrasi baru sesuai arah referensi, judul dan paragraf yang ringkas, CTA pemilih layanan serta katalog. Bingkai gambar mengendalikan tinggi; CTA berada pada layar pertama ukuran penerimaan. Gambar diberi keterangan ilustrasi. |
| Empat pintu layanan | Pijat/Spa dan Laktasi menuju kelompok booking terpisah; Rental menuju katalog alat; Laundry menuju halaman informasinya. Label pemesanan Laundry memerlukan layanan publik dan outlet yang sama-sama siap. |
| Susunan halaman | Pemilih layanan tampil sesudah hero. Bagian berulang disatukan menjadi pengenalan, paket katalog, pilihan rental, alur pemesanan, galeri, FAQ, kontak, dan footer. Footer merupakan elemen penutup halaman. |
| Informasi paket | Menggunakan keluarga paket dari katalog aktif, dengan harga dari variasi yang tersedia dan label konfirmasi admin. Teks paket dan promo contoh yang tidak terhubung ke katalog tidak dijadikan harga transaksi. |
| Kontak | Tautan WhatsApp memakai kontak publik valid, dengan fallback kontak katalog. Placeholder Jakarta Selatan, telepon contoh, Maps umum, Instagram `#`, serta teks CRM tidak ditampilkan sebagai informasi bisnis. Alamat yang belum lengkap tidak ditebak. |
| Konten CMS | Judul/pengantar/gambar hero khusus, pengenalan, galeri, FAQ, dan bagian tambahan yang sesuai tetap dibaca dari CMS. Nilai default hero lama dipetakan untuk tampilan baru tanpa menulis ulang baris CMS. |
| Aset dan ikon | Ilustrasi disimpan di workspace dan diekspor ke WebP sekitar 193 KB. Ikon SVG dibuat sebagai komponen Blade sehingga beranda tidak memerlukan skrip ikon dari CDN. |

## Perbandingan Ukuran

Angka merupakan hasil viewport browser lokal pada data saat pemeriksaan, dibulatkan ke piksel terdekat. Perbedaan isi FAQ terbuka/tertutup dapat mengubah tinggi halaman.

| Ukuran | Sebelum | Sesudah |
|---|---|---|
| Header pada ponsel 390 piksel | Sekitar 198 piksel | Sekitar 69 piksel |
| Hero desktop 1366 piksel | Sekitar 1.057 piksel | Sekitar 606 piksel |
| Posisi bawah CTA utama hero pada desktop 1366 × 768 | Tombol mulai sekitar y=839, di bawah layar pertama | Tombol berakhir sekitar y=561 |
| Panjang beranda pada ponsel 390 piksel | Sekitar 15.500 piksel | Sekitar 7.241 piksel pada FAQ tertutup |
| Panjang beranda desktop 1366 piksel | Sekitar 10.345 piksel | Sekitar 5.143 piksel |

## Hasil Pengecekan

| Pemeriksaan | Hasil |
|---|---|
| Suite aplikasi aktif | **796 pengujian, 6.795 assertion lulus**; grup `legacy-ai` dikecualikan sesuai kebijakan transaksi AI yang sudah berlaku. |
| Regresi baru | Enam pengujian pada `tests/Feature/LandingFoundationTest.php`: empat pintu layanan/urutan footer, kesiapan Laundry, CMS khusus dan escaping, penggantian tautan placeholder, CMS minimal/publikasi privat, serta URL aktif yang ditolak. |
| Browser beranda | **14 pemeriksaan lulus** pada lebar 320, 390, 768, 1024, dan 1366 piksel. Tidak ada overflow halaman, gambar gagal, ID ganda, atau target anchor hilang. Empat pintu layanan dan footer terakhir diperiksa. |
| Tujuan layanan | Empat halaman tujuan terbuka normal pada ponsel. Pengujian tidak mengirim permintaan booking/sewa/laundry atau pesan WhatsApp. |
| Interaksi | Buka/tutup menu, Tab/Shift+Tab, Escape, pemulihan fokus, penutupan saat resize, perpindahan anchor dari menu, pembukaan FAQ, dan iframe pratinjau CMS ponsel lulus. Pratinjau memuat hero baru dan empat layanan. |
| Sintaks dan kompilasi | Node memeriksa sintaks JavaScript; Pint pada tiga berkas PHP terkait lulus; seluruh view Blade berhasil dikompilasi. Pemeriksaan whitespace Git tidak menemukan kesalahan. |
| Integritas data | Jumlah baris dan SHA-256 isi **44 tabel bisnis** sama dengan baseline. SQLite integrity check: **ok**. Termasuk katalog, transaksi, pembayaran, stok, pengaturan/CMS, pesan, dan antrean. |

Suite memakai SQLite `:memory:` dengan `APP_ENV=testing`; TestCase menolak basis data lain. HTTP tak terduga diblokir dan antrean dipalsukan untuk pengujian baru. Cache kompilasi view pengujian dipisahkan dari server lokal melalui `VIEW_COMPILED_PATH`. Pemisahan ini mengatasi konflik rename berkas view Windows yang sempat terjadi saat browser dan suite melakukan kompilasi bersamaan pada pemeriksaan awal.

Perintah suite yang digunakan:

```powershell
$env:VIEW_COMPILED_PATH='C:/xampp/htdocs/Gayatri/storage/framework/testing-views'
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
```

Pemeriksaan browser mencakup perubahan ukuran viewport dan penggunaan akun demo lokal untuk pratinjau CMS. Data pengguna tidak dimasukkan ke formulir pemesanan. Gateway WhatsApp, worker, scheduler, dan provider chat aplikasi tidak dijalankan. VPS tidak diakses atau diubah.

## Berkas Implementasi

- Beranda: `resources/views/landing/show.blade.php`.
- Komponen layanan/rental: `resources/views/landing/partials/home-services.blade.php` dan `home-rental.blade.php`.
- Ikon: `resources/views/components/public/icon.blade.php`.
- Fondasi CSS dan interaksi: `public/css/gayatri-public.css` dan `public/js/gayatri-public.js`.
- Data tampilan baca saja: `app/Services/Web/LandingPresentation.php`, dipanggil oleh `LandingPageController::renderLanding`.
- Regresi: `tests/Feature/LandingFoundationTest.php`.

## Aset Hero

Hero dibuat menggunakan **built-in image_gen** dengan referensi visual pengguna. Prompt lengkap dan informasi ekspor berada di `storage/app/design/landing-package-a/asset-provenance.json`.

- Sumber PNG: `storage/app/design/landing-package-a/hero-family-source.png`.
- Aset yang dipakai halaman: `public/images/landing/hero-family-v1.webp`.
- Ekspor WebP kualitas 88 menggunakan PHP GD mempertahankan dimensi dan komposisi ilustrasi.

## Bukti dan Backup

- Suite: `storage/logs/landing-package-a/full-suite-final.txt` dan `full-suite-final.xml`.
- Browser dan interaksi akhir: `storage/logs/landing-package-a/browser-final.json`.
- Tujuan empat layanan: `storage/logs/landing-package-a/destination-checks.json`.
- Data sebelum/sesudah: `storage/logs/landing-package-a/data-before.json` dan `data-after.json`.
- Backup basis data: `storage/app/backups/landing-package-a-before-20261103-200355.sqlite`.
- Backup sumber: `storage/app/backups/landing-package-a-source/`.
- Screenshot: `hero-desktop.png`, `hero-mobile.png`, `services-desktop.png`, `services-mobile.png`, `menu-mobile.png`, dan `footer-mobile.png` pada direktori log paket ini.

## Pekerjaan Paket Berikutnya

Paket B melengkapi aset dan konten publik: foto outlet/tim yang benar, pilihan unggulan yang dikurasi admin, paket/promosi, serta verifikasi alamat, jam, dan testimoni. Visual Unsplash lama pada pengenalan/galeri saat ini diberi keterangan inspirasi; foto tersebut tidak dinyatakan sebagai foto outlet Gayatri.

Pemilihan unggulan melalui CMS dan pengaturan tampilan tambahan mengikuti paket lanjutan. Form booking, detail rental, serta halaman Laundry/portal masih memakai tampilan modul masing-masing dan menjadi cakupan Paket C–D. Penyaringan cabang demo pada formulir booking termasuk perbaikan alur publik berikutnya; homepage baru tidak menampilkan cabang demo. Tidak ada pengaturan bisnis yang diaktifkan otomatis untuk mempercepat tampilan seolah sudah siap.

## Pratinjau Hasil

![Hero desktop](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-a/hero-desktop.png)

![Hero ponsel](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-a/hero-mobile.png)
