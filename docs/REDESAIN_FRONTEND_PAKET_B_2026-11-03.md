# Redesain Frontend Gayatri — Paket B

Tanggal: **3 November 2026**. Status: **selesai dan diperiksa di lokal** pada `http://127.0.0.1:8000/`. Acuan: `docs/RENCANA_REDESAIN_FRONTEND_GAYATRI_2026-11-03.md` dan fondasi Paket A.

Paket ini menyempurnakan aset serta konten beranda dengan gaya foto realistis, natural, hangat, dan konsisten. Pengerjaan mencakup empat kelompok layanan tanpa mengubah aturan transaksi atau mengaktifkan layanan yang belum siap.

## Perubahan

| Area | Hasil |
|---|---|
| Inventaris | Hero 3D Paket A dan gambar Unsplash default dicatat sebagai aset lama. Gambar produk katalog yang sudah tersedia tetap digunakan sebagai gambar produk. |
| Foto natural | Lima aset baru: Bunda dan bayi untuk hero, perawatan anak bersama orang tua, pendampingan menyusui, perlengkapan bayi, dan proses melipat pakaian bayi. |
| Empat kartu layanan | Foto dengan rasio seragam, label layanan, ringkasan, dan tujuan masing-masing. Laundry tetap bertuliskan pemesanan belum dibuka sesuai kesiapan outlet. |
| Pilihan layanan | Enam treatment/kelas awal dari katalog. Harga, durasi, variasi, kelompok layanan, dan kesiapan dibaca dari data aktif; tidak ditulis ulang sebagai konten promosi. |
| Kendali CMS | Tab **Informasi halaman → Layanan pilihan di beranda** memiliki enam pilihan berurutan. Entri ganda tampil sekali; semua dikosongkan berarti bagian disembunyikan. Tambahan, paket, demo, dan pilihan tidak aktif tidak menjadi kandidat unggulan utama. |
| Pengamanan daftar | Validasi kandidat di server, maksimum enam, dan penggantian daftar secara utuh. Mengurangi daftar tidak menyisakan entri lama; pengaturan CMS lain tetap tersimpan. |
| Tentang dan galeri | Gambar default memakai ilustrasi foto dengan konteks yang jelas. Gambar khusus CMS dipertahankan, galeri yang sengaja dikosongkan tetap kosong. Gambar asli khusus admin tidak otomatis dilabeli AI. |
| Kontak publik | Alamat, jam, WhatsApp, dan Instagram mengikuti katalog yang diberikan. Maps berupa pencarian alamat dengan label **Cari lokasi di Maps**, belum merupakan koordinat outlet terverifikasi. |
| FAQ | Enam pertanyaan bawaan mencakup empat layanan, treatment/konsultasi, kunjungan rumah, rental, kesiapan Laundry, dan pembayaran manual. FAQ khusus admin tetap dipakai; bagian yang dikosongkan tetap tersembunyi. |
| Paket dan klaim | Paket memakai keluarga katalog aktif dan label konfirmasi admin. Promo contoh, testimoni demo, angka pengalaman, dan jumlah pelanggan yang belum terverifikasi tetap tidak dipublikasikan. |

Pilihan yang dapat dipesan meneruskan `service_group` dan `service_id` ke formulir. Pilihan yang membutuhkan konfirmasi menuju rincian katalog, bukan formulir booking langsung. Form backend tetap memeriksa pilihan dan jadwal.

## Sumber Konten Publik

Sumber: **MENU GAYATRI-1.pdf**, halaman **1–2**, diverifikasi melalui gambar hasil ekstraksi pada `storage/app/public/catalog/gayatri-menu/pages/`. Manifest impor yang sudah ada: `database/data/gayatri-menu-2026.json`.

| Informasi | Nilai dari katalog |
|---|---|
| Alamat | Jl. Semeru No. 260 Kota Kediri |
| WhatsApp | 0821-4336-3017 / 6282143363017 |
| Jam | Setiap hari 08.00–20.00 WIB; reservasi terakhir 19.00 |
| Instagram | `gayatribabyspa` |

Jam merupakan informasi katalog, bukan jaminan slot tersedia. Profil Instagram tidak diuji dengan login atau diubah. Placeholder CMS lama dipetakan pada lapisan tampilan tanpa mengubah baris bisnis.

Promo contoh `BABY10` sudah melewati masa berlaku saat pemeriksaan dan tidak ditampilkan. Tidak ada testimoni pelanggan yang diada-adakan atau klaim kredensial baru. Foto outlet/tim asli belum disediakan; seluruh gambar hasil generasi diberi keterangan ilustrasi, dan gambar perlengkapan tidak dinyatakan sebagai foto unit/SKU tertentu.

## Enam Pilihan Awal

Kode berikut menjadi default saat belum ada pilihan CMS. Nominal dan durasi di tabel adalah kondisi lokal saat pemeriksaan, bukan harga yang ditanam dalam template.

| Kode | Pilihan | Durasi | Harga saat pemeriksaan |
|---|---|---|---|
| GM-07-072 | Baby Massage | 30 menit | Rp 40.000 |
| GM-07-074 | Baby SPA | 60 menit | Rp 110.000 |
| GM-03-003 | Mom Massage (20 Tahun ke Atas) | 60 menit | Rp 90.000 |
| GM-05-055 | Pijat Laktasi, 1 sesi | 30 menit | Rp 75.000 |
| GM-13-225 | Persiapan Menyusui untuk Ibu Hamil, Reguler | 60 menit | Rp 99.000 |
| GM-13-231 | Busui Kembali Bekerja, Reguler | 60 menit | Rp 99.000 |

## Aset dan Produksi

Mode: **built-in image_gen**, generasi gambar baru. Prompt lengkap, sumber keluaran, salinan sumber, dan tujuan ekspor tercatat di `storage/app/design/landing-package-b/asset-provenance.json`.

| Aset di `public/images/landing/` | Ukuran utama | Besar berkas |
|---|---|---|
| hero-natural-v2.webp | 1536 × 1024 | 146.898 byte |
| service-spa-natural-v1.webp | 960 × 640 | 71.196 byte |
| service-lactation-natural-v1.webp | 960 × 640 | 70.806 byte |
| service-rental-natural-v1.webp | 960 × 640 | 81.332 byte |
| service-laundry-natural-v1.webp | 960 × 640 | 75.938 byte |

Setiap aset juga memiliki versi `-480w.webp` berukuran 480 × 320. PHP GD hanya dipakai untuk ekspor/resizing, tanpa mengubah komposisi kreatif. Sumber PNG disimpan di `storage/app/design/landing-package-b/`. Gambar memakai `srcset`, dimensi eksplisit, dan alt yang relevan. Hero diprioritaskan; gambar di bawahnya dimuat bertahap. Seluruh lima hasil generasi dan tampilan penggunaannya diperiksa secara visual.

## Validasi

| Pemeriksaan | Hasil |
|---|---|
| Suite aplikasi aktif | **804 pengujian, 6.857 assertion lulus**, grup `legacy-ai` dikecualikan sesuai kebijakan transaksi AI aplikasi. |
| Regresi baru | Delapan pengujian di `tests/Feature/LandingContentTest.php`: aset/kontak, kurasi dan urutan, harga/kesiapan, pengurangan/pengosongan daftar, penolakan input tidak sah, galeri khusus/kosong, klaim contoh, serta FAQ khusus/kosong. |
| Lima viewport | Lebar 320, 390, 768, 1024, dan 1366 piksel: tidak ada overflow halaman, gambar gagal, atau target anchor hilang; empat foto layanan, enam pilihan, dan footer terakhir tersedia. |
| Booking dari unggulan | Baby Massage membuka kelompok Spa dengan ID 79 terpilih; Persiapan Menyusui membuka kelompok Laktasi dengan ID 232 terpilih. Tidak ada formulir transaksi dikirim. |
| Ponsel | Kartu foto, judul panjang, variasi, dan harga diperiksa pada 390 dan 320 piksel. Menu terbuka, Escape menutupnya dan memulihkan fokus ke tombol. |
| CMS | Enam pilihan awal tampil sesuai urutan; perubahan sementara dapat dilakukan dan dikembalikan. Tidak ada penyimpanan CMS pada database operasional saat QA browser. Iframe pratinjau ponsel memuat hero baru, empat foto layanan, dan enam pilihan. |
| Browser | Tidak ada log kesalahan konsol pada pemeriksaan. FAQ rental dapat dibuka. Label dan tujuan WhatsApp/Maps/Instagram diperiksa tanpa mengirim pesan. |
| Kualitas sumber | Pint pada enam berkas PHP terkait lulus; sintaks JavaScript lulus; seluruh Blade berhasil dikompilasi; pemeriksaan whitespace pada sumber terkait lulus. |
| Integritas | Jumlah baris dan SHA-256 isi **44 tabel bisnis** identik dengan baseline; SQLite integrity check **ok**. |

Pengujian memakai SQLite `:memory:` dan `APP_ENV=testing`, dengan direktori kompilasi view pengujian terpisah dari server lokal. HTTP tak terduga diblokir; antrean dipalsukan pada pengujian baru.

Pada verifikasi awal, ditemukan dan diperbaiki konflik sintaks directive Blade pada CMS serta assertion URL HTML yang perlu memperhitungkan escaping. Satu percobaan suite berjalan saat sumber FAQ sedang berubah sehingga proses tersebut masih memuat kelas lama. Setelah sumber dibekukan, suite penuh dijalankan ulang dan seluruhnya lulus; acuan akhir adalah `full-suite-final.txt` dan `full-suite-final.xml`.

```powershell
$env:VIEW_COMPILED_PATH='C:/xampp/htdocs/Gayatri/storage/framework/testing-views'
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
```

## Bukti dan Berkas

- Log, JUnit, responsif, screenshot: `storage/logs/landing-package-b/`.
- Perbandingan data: `data-before.json` dan `data-after.json` pada folder log tersebut.
- Backup database: `storage/app/backups/landing-package-b-before-20261103-214035.sqlite`.
- Snapshot sepuluh sumber sebelum pengerjaan: `storage/app/backups/landing-package-b-source/`.
- Konten/default: `config/gayatri_frontend.php`, `app/Services/Web/LandingContent.php`, dan `LandingPresentation.php`.
- Beranda: `resources/views/landing/show.blade.php`, `partials/home-services.blade.php`, dan `partials/home-featured.blade.php`.
- CMS: `app/Http/Controllers/Admin/LandingPageCmsController.php`, `resources/views/admin/landing/index.blade.php`, dan `featured.blade.php`.
- Gaya: `public/css/gayatri-public.css`; interaksi menu Paket A tetap digunakan.

## Batas dan Kelanjutan

Paket B selesai untuk beranda lokal. VPS, gateway WhatsApp, worker, scheduler, dan provider chat aplikasi tidak dijalankan atau diubah. Data stok, harga, transaksi, pembayaran, fee terapis, dan pengaturan operasional tidak ditulis ulang. Pemeriksaan lokal ini tidak menyatakan hasil Core Web Vitals produksi.

Paket C melanjutkan tema ke katalog dan halaman layanan Pijat/Spa, Laktasi, Rental, serta Laundry. Perombakan formulir/portal berada pada Paket D; pengelolaan aset lebih rinci, metadata, dan optimasi lanjutan berada pada Paket E. Foto asli outlet dan tim bisa ditambahkan melalui CMS ketika tersedia, menggantikan ilustrasi pada area yang sesuai.

## Pratinjau

![Empat layanan dengan foto natural](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-b/services-desktop.png)

![Hero ponsel](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-b/hero-mobile.png)

![Pilihan layanan CMS](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-b/cms-featured.png)
