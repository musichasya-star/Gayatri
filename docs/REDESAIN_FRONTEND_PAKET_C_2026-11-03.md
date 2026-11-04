# Redesain Frontend Gayatri — Paket C

Tanggal: **3 November 2026**. Status: **selesai dan diperiksa di lokal**. Acuan: `docs/RENCANA_REDESAIN_FRONTEND_GAYATRI_2026-11-03.md`, mengikuti fondasi serta aset Paket A–B.

## Hasil Sesuai Urutan Paket

| Urutan | Area | Hasil |
|---|---|---|
| 1 | Tema katalog `/layanan` | Header/menu, warna, tipografi, kartu, footer, keadaan kosong, dan bantuan mengikuti beranda. Gambar produk yang sudah tersedia tetap dipakai; keluarga tanpa foto memakai judul/ikon yang rapi. |
| 2 | Filter, keluarga, variasi | Pencarian, kelompok, kategori, jenis treatment/kelas/paket/tambahan, dan cara pemesanan. Kategori menyesuaikan kelompok dan kategori lama yang tidak relevan dibersihkan. Harga, durasi, jenis, gambar, rincian, dan tindakan mengikuti variasi. |
| 3 | Pijat & Spa | Hero foto natural dari Paket B, pengantar, pilihan kelompok, akses langsung ke form/jadwal, tautan katalog, dan bahasa status yang lebih mudah dipahami. |
| 4 | Laktasi & ASI | Pengantar serta foto pendampingan tersendiri; kelompok, pilihan, dan tindakan pemesanan terpisah dari Spa. |
| 5 | Rental | Katalog dan detail bertema sama. Tarif per periode, deposit, unit/kebutuhan konfirmasi, pencarian, tanggal, dan pembayaran manual dijelaskan. Pencarian/tanggal bertahan ke detail dan kembali. |
| 6 | Laundry | Hero dan informasi layanan, ringkasan konfirmasi timbang/pembayaran manual, enam tarif awal, tautan seluruh tarif dan portal. Informasi tetap terlihat saat pemesanan ditutup. Form hanya ditampilkan ketika layanan publik dan outlet siap. |

Komponen `x-public.page` dan `x-public.service-hero` dipakai bersama. Gambar natural dari Paket B diberi keterangan ilustrasi AI. Tidak ada gambar unit sewa baru yang diklaim sebagai dokumentasi produk tertentu.

## Alur dan Kesiapan

- Katalog memisahkan keluarga layanan dari variasinya. Tombol pemesanan membawa ID variasi dan kelompok yang dipilih ke formulir.
- Tautan kembali menyimpan pencarian, kelompok, kategori, jenis, cara pemesanan, halaman, variasi terpilih, dan penanda keluarga. Mengganti variasi juga memperbarui keadaan URL katalog tanpa mengirim transaksi.
- Tujuan kembali dibatasi pada `/layanan` dan `/rental`. URL luar, path admin, skema aktif, struktur query tidak sesuai, serta fragment tidak dikenal ditolak dan diganti tujuan lokal yang aman.
- Paket beberapa sesi, tambahan, harga/durasi yang belum lengkap, serta mode konfirmasi admin tetap melalui konfirmasi. Label jenis dan tindakan ikut berubah ketika variasi berubah.
- Rental menampilkan tarif per hari/minggu/dua minggu/bulan hanya ketika nominalnya tersedia. Deposit tidak diketahui tidak diganti nol. Alat tanpa unit/tarif/rincian siap diarahkan ke konfirmasi; pemesanan yang dinonaktifkan tidak menampilkan form checkout.
- Tanggal Rental harus berpasangan dan valid. Ketersediaan dihitung menggunakan pemeriksaan inventori yang sudah ada. Pemilihan alat atau tanggal tidak menahan stok.
- Laundry memerlukan pengaturan publik aktif, penerimaan outlet aktif, alamat, dan kontak outlet. Tarif dapat dilihat ketika form belum dibuka. Ketentuan hasil timbang, ongkir, persetujuan, dan pembayaran manual tetap mengikuti modul Laundry.
- Pencarian produk/rental/laundry memperlakukan karakter wildcard sebagai teks sesuai helper pencarian aplikasi.

## Cabang dan Jadwal Publik

Cabang tidak aktif serta penanda demo disaring dari pilihan publik. Cabang bawaan seeder yang masih persis memakai kode `GAY-JKT`, nama Gayatri Jakarta, alamat Jl. Mawar, dan telepon contoh juga disembunyikan. Pencocokan menggunakan seluruh identitas contoh tersebut, sehingga data cabang yang sudah benar-benar diperbarui tidak diblokir hanya berdasarkan kodenya.

Layanan yang terikat ke cabang tidak layak publikasi dan slotnya tidak ditawarkan. Pemeriksaan server menolak pengiriman langsung untuk layanan/cabang tersebut. Durasi harus positif dan harga harus tersedia untuk pemesanan online. Data cabang, slot, booking, dan histori tetap disimpan tanpa perubahan.

**Kondisi lokal saat selesai:** cabang aktif yang tersedia masih identitas Jakarta bawaan. Cabang/jadwal operasional Kediri perlu dilengkapi melalui admin sebelum booking online nyata digunakan. Halaman menampilkan pemberitahuan jika jadwal online belum tersedia. Identitas cabang serta jadwal nyata tidak dibuat dari perkiraan. Alamat dan jam pada footer mengikuti kontak publik terverifikasi dari Paket B.

## Hasil Pengujian

| Pemeriksaan | Hasil |
|---|---|
| Suite aplikasi aktif | **812 pengujian, 6.950 assertion lulus**. Grup `legacy-ai` dikecualikan mengikuti kebijakan transaksi AI aplikasi. |
| Regresi Paket C | **8 pengujian baru**, meliputi pengelompokan/filter dan kategori, perjalanan variasi ke booking/kembali, pembatasan URL kembali, pagination/keadaan kosong, penolakan cabang demo, tanggal Rental, alat belum siap/layanan ditutup, serta kesiapan Laundry. |
| Pemeriksaan setelah penyempurnaan label variasi | Delapan pengujian Paket C dijalankan kembali: **93 assertion lulus**. Sintaks JavaScript lulus. |
| Responsif | **45 pemeriksaan lulus**: sembilan halaman/jenis katalog × lebar 320, 390, 768, 1024, dan 1366 piksel. Satu H1, tidak ada overflow, kontrol melewati layar, ID ganda, target anchor hilang, atau gambar bersumber yang gagal dimuat; footer menjadi bagian visual terakhir. |
| Variasi dan booking | Back Massage 30 → 45 menit mengubah harga Rp50.000 → Rp70.000, membawa ID 23 ke form, dan mengembalikan pencarian/filter/variasi yang sama. Tidak ada form transaksi dikirim melalui browser. |
| Paket dan konfirmasi | Pijat Laktasi Premium 5 sesi menampilkan Rp475.000 dan tindakan konfirmasi admin. Beralih ke 1 sesi menampilkan Rp98.000 dan tujuan booking Laktasi ID 65. Tautan WhatsApp diperiksa tanpa mengirim pesan. |
| Rental | Pencarian Stroller dan tanggal 10–17 November 2026 bertahan pada detail serta saat kembali. Detail unit yang belum lengkap menampilkan konfirmasi dan tidak menampilkan checkout. Keadaan unit siap diuji dengan data di basis data pengujian. |
| Laundry | Halaman lokal yang ditutup tetap menampilkan tarif, bantuan, dan portal; tidak memuat form pemesanan. Keadaan outlet lengkap/terbuka diuji pada basis data pengujian. |
| Keyboard/menu | Shift+Tab dari tombol tutup menuju tautan terakhir, Tab kembali ke tombol tutup, Escape menutup dialog dan memulihkan fokus ke tombol buka menu. |
| Integritas data | Jumlah baris dan SHA-256 isi **44 tabel bisnis identik** dengan baseline; SQLite integrity check **ok**. |

Pada pengukuran browser awal, elemen gambar tersembunyi tanpa `src` ikut dihitung sebagai gambar gagal. Pengukuran diperbaiki untuk memeriksa gambar yang benar-benar memiliki sumber. Hasil akhir disimpan pada `responsive.json`.

Pengujian aplikasi memakai `APP_ENV=testing` dan SQLite `:memory:`. TestCase menolak basis data lain. Kompilasi Blade pengujian dipisahkan dari server lokal; antrean dipalsukan dan HTTP tak terduga diblokir. Tidak ada worker, scheduler, gateway WhatsApp, atau provider chat aplikasi yang dijalankan. VPS tidak diakses atau diubah.

```powershell
$env:VIEW_COMPILED_PATH='C:/xampp/htdocs/Gayatri/storage/framework/testing-views'
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
```

## Sumber dan Bukti

- Tata letak bersama: `resources/views/components/public/page.blade.php`, `service-hero.blade.php`, dan `empty.blade.php`.
- Katalog: `app/Http/Controllers/CatalogueController.php`, `resources/views/catalogue/index.blade.php`, dan `public/js/catalogue.js`.
- Pilihan publik/navigasi: `app/Services/Web/PublicCatalogue.php`.
- Spa/Laktasi: `app/Http/Controllers/LandingPageController.php`, `resources/views/landing/booking.blade.php`, dan `public/css/gayatri-booking.css`.
- Rental: `app/Http/Controllers/RentalController.php`, `resources/views/rental/index.blade.php`, `show.blade.php`, `partials/product-card.blade.php`, serta layout `rental-catalogue.blade.php`.
- Laundry: `resources/views/laundry/index.blade.php`, `partials/public-tariffs.blade.php`, dan layout `laundry-public.blade.php`.
- Gaya bersama: `public/css/gayatri-services.css`; menu memakai `public/js/gayatri-public.js` dengan penyesuaian agar penanda halaman tanpa anchor tetap dipertahankan.
- Pengujian: `tests/Feature/PublicServicePagesTest.php`.
- Log/screenshot: `storage/logs/landing-package-c/`, termasuk `full-suite-final.txt`, `full-suite-final.xml`, `service-tests-final.txt`, `responsive.json`, dan `browser-final.json`.
- Baseline/perbandingan: `data-before.json` dan `data-after.json` pada folder log tersebut.
- Backup basis data: `storage/app/backups/landing-package-c-before-20261103-222115.sqlite`.
- Snapshot **13 berkas sumber** sebelum diubah: `storage/app/backups/landing-package-c-source/`.

## Batas Paket dan Kelanjutan

Paket C selesai untuk presentasi katalog dan halaman layanan beserta perjalanan pilihan ke tujuan yang sesuai. Harga, durasi, stok, deposit, transaksi historis, pembayaran, dan pengaturan operasional tidak ditulis ulang.

Paket D melanjutkan penyempurnaan detail formulir, ringkasan Home Service, keranjang/checkout Rental, estimasi Laundry, pembayaran manual, dan portal pelanggan. Portal serta formulir bersama yang digunakan modul lain mempertahankan alur dan pengamanan yang sudah ada. Pengukuran Core Web Vitals produksi, audit aksesibilitas formal, dan validasi rilis menyeluruh tetap berada pada paket lanjutan.

## Pratinjau

![Kartu katalog desktop](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-c/catalogue-cards-desktop.png)

![Halaman Laktasi](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-c/lactation-desktop.png)

![Rental](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-c/rental-desktop.png)

![Laundry](C:/xampp/htdocs/Gayatri/storage/logs/landing-package-c/laundry-desktop.png)
