# Hasil Paket B — katalog dan variasi layanan

Tanggal: 2 November 2026. Status: selesai dan diverifikasi di lokal, melanjutkan stabilisasi Paket A.

## 1. Keluarga layanan Pijat & Spa serta Laktasi

- Daftar admin menampilkan satu kelompok per keluarga layanan. Rincian variasi dibuka pada kelompok tersebut.
- Ringkasan memuat jumlah variasi sesuai filter, rentang harga yang diketahui, jumlah harga yang belum tersedia, kesiapan pemesanan, serta jumlah tayang/arsip.
- Nama keluarga dipasangkan dengan kelompok bisnis. Keluarga bernama sama pada Spa dan Laktasi tidak dicampur, termasuk pada katalog publik.
- Pengelompokan merupakan proyeksi baca saja dari layanan yang sudah ada. Tidak dibuat penggabungan record, penggantian ID, atau penulisan ulang transaksi.
- Paginasi berdasarkan keluarga, 12 keluarga per halaman. Treatment utama didahulukan dari tambahan. Rincian tetap menunjukkan jenis, cabang, kode, durasi, harga dan aksi setiap variasi.
- Tombol **Tambah variasi** mengisi identitas keluarga/kelompok dari pilihan asal. Harga dan durasi tetap kosong, serta publikasi awal diarsipkan, agar admin mengisi rincian yang benar.

## 2. Pencarian dan filter

- Pencarian mencakup nama layanan, keluarga, nama variasi, kategori dan kode katalog. Pencarian layanan memperlakukan karakter wildcard sebagai teks literal.
- Filter tersedia untuk jenis (treatment, paket, kelas, tambahan), kesiapan, kategori/usia, cabang dan publikasi.
- Publikasi awal menampilkan yang tayang. Pilihan arsip dan semua publikasi tersedia pada filter lanjutan.
- Filter lanjutan dapat dilipat; terbuka otomatis bila filternya sedang digunakan. Pencarian dan tombol terapkan/reset tetap mudah dijangkau.
- Jumlah hasil dinyatakan sebagai **keluarga dan variasi sesuai filter**. Tautan **Semua variasi keluarga** membuka seluruh pilihan keluarga tersebut dan melepas filter lain.
- Paginasi mempertahankan parameter filter, sedangkan Reset mempertahankan kelompok Spa/Laktasi yang sedang dikelola.

## 3. Kesiapan dan perlindungan pemesanan

| Kesiapan | Arti |
|---|---|
| Siap dipesan | Harga dan durasi tersedia serta alur mendukung pemilihan jadwal web. Ketersediaan tetap mengikuti jadwal yang dibuka. |
| Konfirmasi admin | Data dasar tersedia, tetapi paket/tambahan atau kebijakan pemesanan memerlukan admin. |
| Perlu dilengkapi | Harga atau durasi belum tersedia. Kekurangan disebutkan pada variasi. |

Publikasi **Tayang/Diarsipkan** ditampilkan terpisah. Variasi dapat tayang untuk informasi pelanggan meskipun masih memerlukan konfirmasi.

Jenis layanan, jumlah dan satuan paket dapat dikelola pada formulir. Jenis disimpan dalam metadata yang sudah tersedia; tidak memerlukan migrasi skema. Metadata sumber tetap dipertahankan. Pengaman model, query layanan yang dapat dipesan, dan penyimpanan admin menolak paket/tambahan sebagai satu booking web biasa, termasuk paket gabungan berjumlah satu. Paket/tambahan lama tidak dapat diturunkan menjadi treatment tunggal melalui penggantian jenis.

## 4. Formulir variasi dan tambahan

- Formulir dibagi menjadi **Informasi utama**, **Variasi & harga**, **Tambahan**, dan **Publikasi**, dengan tautan bagian dan aksi simpan yang tetap mudah dijangkau.
- Pemilihan tambahan menggunakan satu dialog pencarian bersama. Nama lengkap, jenis, harga dan durasi sumber terbaca; pilihan mengikuti kelompok layanan dan menyembunyikan tambahan yang sudah dipasang pada baris lain.
- Biaya/durasi tambahan tetap diisi atau diperiksa admin; memilih dari dialog tidak diam-diam mengubah angka komersial pada baris.
- Tambahan khusus tetap didukung. Data yang belum pasti ditandai perlu konfirmasi dan belum ditawarkan pada pemilihan booking.
- Data harga/durasi wajib dinyatakan bila tanda perlu konfirmasi dilepas. Angka nol eksplisit tetap dapat digunakan untuk tambahan yang gratis/tidak menambah durasi.
- Penomoran field seluruh baris diperbarui setelah tambah/lepas, mencegah field baru menimpa baris lama.
- Tambahan yang dilepas dinonaktifkan, bukan dihapus. Pembaruan layanan tanpa data tambahan mempertahankan daftar tambahan. ID milik layanan lain, pilihan diri sendiri, tambahan lintas kelompok, dan baris ganda ditolak.
- Pada Baby SPA yang diperiksa, jumlah elemen option berkurang dari 1.599 pada audit awal menjadi **29**, dengan enam tambahan yang tetap terbaca. Daftar menunjukkan enam tambahan tersebut memerlukan konfirmasi, bukan tanda kosong.

## 5. Penerapan pada katalog lainnya

### Rental

- Daftar ringkas menampilkan foto/nama/SKU/kategori, keempat tarif (harian, 7/14/30 hari), deposit, jeda pembersihan, jumlah unit fisik dan unit berstatus siap, publikasi serta aksi.
- Filter berdasarkan nama/SKU/kategori, publikasi dan kesiapan. Tidak adanya tarif/deposit/jeda yang sudah dikonfirmasi ditampilkan sebagai kekurangan, bukan angka yang diasumsikan.
- Status unit **tidak menjamin ketersediaan pada tanggal sewa**. Perhitungan persediaan dan pengecekan bentrok pada tanggal transaksi tetap memakai mekanisme rental yang ada.
- Jeda pembersihan impor yang belum dikonfirmasi memerlukan centang persetujuan admin. Menyimpan edit nama/foto tidak lagi otomatis mengonfirmasi jeda tersebut.
- Tidak dibuat unit fisik, deposit atau tarif baru dari asumsi.

### Laundry

- Filter nama/kode, sasaran layanan, satuan, publikasi dan kesiapan penerimaan.
- Daftar menunjukkan tarif, minimum, pembulatan dan estimasi yang diketahui; nilai yang kosong disebut **belum diisi**. Aksi **Lengkapi** mengarah ke tarif terkait.
- Label satuan pada katalog/form menggunakan **satuan**, sehingga sesuai untuk pakaian maupun perlengkapan. Kode internal `piece` dan perhitungan kuantitas tetap sama.
- Formulir membedakan publikasi katalog dari konfirmasi rincian operasional. Snapshot tarif pada pesanan lama tidak ditulis ulang.

### Produk pendukung dan pencarian global

- Foto, identitas, kategori, SKU, harga pembanding, harga jual, publikasi dan konfirmasi stok/admin ditampilkan konsisten.
- Filter nama/SKU/kategori dan publikasi. Tidak ditambahkan jumlah stok yang belum diketahui.
- Pencarian global diperluas ke **katalog alat rental** dan **tarif laundry**, dengan batas akses operator yang sama. Sales/terapis tidak memperoleh akses katalog admin melalui hasil pencarian tersebut.

## 6. Hasil verifikasi

| Pemeriksaan | Hasil |
|---|---|
| Suite aplikasi aktif | **764 pengujian, 6.590 assertion lulus** |
| Pengujian terarah akhir katalog/rental | **54 pengujian, 524 assertion lulus** |
| Responsif | **55 kombinasi**: lima daftar katalog dan enam formulir, masing-masing pada lebar 320, 390, 768, 1024 dan 1366 px |
| Render/reflow | Tidak ada error halaman, pelebaran dokumen, atau isi sel yang melampaui sel pada pemeriksaan terkait; tabel lebar mempunyai pembungkus gulir |
| Filter paket | Paket + konfirmasi admin pada Laktasi menghasilkan lima keluarga/14 variasi, seluruhnya berstatus konfirmasi admin |
| Interaksi tambahan | Cari, pilih, tanpa hasil, tambah/lepas baris, penomoran ulang, Escape dan pemulihan fokus lulus; dialog 320 px tidak melebar keluar layar |
| Alur pelanggan | Satu sesi Laktasi Premium membuka booking Laktasi yang benar; paket lima sesi menampilkan tautan konfirmasi admin. Tautan WhatsApp hanya diperiksa, tidak digunakan mengirim pesan. |
| Paginasi | Halaman kedua keluarga Spa terbuka dengan kelompok tetap Spa |
| Pencarian global | Mom Cozy M6 ditemukan sebagai katalog alat rental dan mengarah ke edit alat yang benar |
| Kualitas kode | Pint pada 14 file PHP terkait dan pemeriksaan sintaks dua file JavaScript lulus |
| Integritas data lokal | **26 tabel sama persis** terhadap baseline, jumlah baris dan SHA-256 identik; SQLite integrity `ok` |

Suite dijalankan pada SQLite `:memory:` dengan pengaman lingkungan pengujian:

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai --log-junit storage/logs/ui-package-b/full-tests.xml
```

Grup `legacy-ai` tetap mengikuti pengecualian suite aktif. Alur transaksi WhatsApp lama tidak diaktifkan kembali. Uji simpan/mutasi menggunakan database pengujian; formulir pada database bisnis lokal hanya dibuka dan perubahan interaksi dibuang tanpa menyimpan.

Pengujian baru meliputi pemisahan keluarga/kelompok, rentang harga, paginasi keluarga, filter kode/variasi/publikasi/kesiapan, inisialisasi variasi tanpa angka asumsi, pengaman paket, pemeliharaan ID tambahan dan nilai booking historis, rollback pilihan tidak sah/ganda, pemilihan tambahan bersama, tiga katalog lain, batas akses pencarian global, serta konfirmasi jeda rental yang eksplisit.

## Bukti dan batas cakupan

- Backup: `storage/app/backups/ui-package-b-before-20261102-221853.sqlite`.
- Perbandingan baca saja: `tmp/check-ui-package-b-data.py`; baseline/hasil pada `storage/logs/ui-package-b/data-before.json` dan `data-after.json`.
- Bukti pengujian: `full-tests.txt/.xml`, `final-catalogue-tests.txt/.xml`.
- Bukti browser: `screens.json`, `targets.json`, `extra-forms.json`, snapshot halaman, `interactions.json`, `filter-check.json`, `public-flow.json`, dan `picker-mobile.json` pada folder log yang sama.
- Screenshot: `katalog-laktasi-desktop.png`, `katalog-laktasi-mobile.png`, `addon-picker-desktop.png`, dan `addon-picker-mobile.png`.
- Implementasi utama: `ServiceCatalogueBrowser`, model `Service`, `ServiceCatalogService`, `ServiceRequest`, formulir/daftar katalog, `admin-services.js`, dan `admin-catalogue.css`.

Paket B selesai pada lingkungan lokal. Tidak dilakukan deployment/VPS, impor ulang, reseed, migrasi data bisnis, perubahan angka sumber, atau pengiriman WhatsApp nyata. Gateway, worker dan scheduler tetap tidak diaktifkan. Kekurangan durasi, deposit dan stok dari sumber tetap memerlukan data nyata dari admin.

Penyusunan ulang Inbox, Home Service, dashboard/laporan dan menu operasional lain mengikuti paket lanjutan audit; bukan bagian dari perubahan katalog ini.
