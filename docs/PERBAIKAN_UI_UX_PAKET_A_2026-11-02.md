# Hasil Paket A — stabilisasi halaman dan komponen admin

Tanggal: 2 November 2026. Status: selesai dan diverifikasi di lingkungan lokal.

Paket ini mengikuti urutan pada audit `AUDIT_UI_UX_ADMIN_2026-11-02.md`. Aplikasi lokal tersedia pada http://127.0.0.1:8000. Perubahan belum diterapkan ke VPS.

## 1. Pemulihan transaksi rental

- Daftar transaksi tidak lagi membaca kunci `pricing_snapshot.total` secara langsung.
- `RentalOrder::originalTotal()` menggunakan total yang tersimpan, termasuk angka nol yang valid. Snapshot lama dengan keempat komponen biaya yang lengkap dapat dijumlahkan dari snapshot tersebut.
- Jika total awal tidak dapat dipastikan, UI menampilkan **Total awal belum tercatat pada data lama**. Total tagihan saat ini, sisa tagihan dan saldo pengembalian ditampilkan terpisah.
- Total awal tidak diambil dari nilai transaksi saat ini: perpanjangan, denda atau pelepasan deposit tidak boleh dianggap sebagai nilai awal.
- Detail transaksi juga aman untuk snapshot yang tidak memuat informasi rute pengantaran.
- Snapshot, pembayaran, invoice dan saldo historis tidak ditulis ulang.

## 2. Komponen tabel dan tampilan dasar

- Sel tabel ponsel memakai label di atas isi, sehingga nama, badge, kode, harga dan tombol tidak menjadi item flex yang saling mendesak.
- Identitas layanan mempunyai pembungkus vertikal; produk pendukung mempunyai susunan foto dan identitas yang konsisten.
- Tabel pada desktop/tablet mempunyai pembungkus gulir sendiri. Pembungkus dapat difokuskan dengan keyboard ketika memang terdapat kolom di luar area terlihat. Terdapat dukungan CSS sebelum peningkatan JavaScript berjalan.
- Harga layanan tidak terpisah antara `Rp` dan nominal pada tabel desktop/tablet; angka rata kanan.
- Judul halaman bersama dibatasi sekitar 26–32 px, fokus keyboard terlihat, dan tersedia tautan lewati ke isi halaman.
- Kontrol filter utama katalog diberi nama aksesibel.

## 3. Arsip invoice dan kuitansi

- Halaman arsip memakai `layouts.app`, sidebar, topbar dan navigasi rental.
- Filter dapat menyesuaikan ukuran layar. Scroll mendatar dibatasi pada area tabel, termasuk pada lebar 320 px.
- Arsip menampilkan nilai dari snapshot dokumen saat diterbitkan.
- Halaman pratinjau/cetak tetap menggunakan layout dokumen. Invoice dan kuitansi yang sudah diterbitkan tetap tidak dapat diedit melalui arsip.

## 4. Pencarian, navigasi dan promo

### Pencarian admin

- Pencarian topbar bekerja pada desktop maupun ponsel melalui `/admin/search?q=…`.
- Operator yang berhak dapat menemukan pelanggan, percakapan, campaign, booking, layanan, transaksi rental, pesanan laundry dan produk pendukung. Pencarian ini belum mencakup nama produk katalog alat rental; transaksi rental dapat dicari berdasarkan kode atau pelanggan.
- Hasil dibatasi enam per jenis, dengan petunjuk mempersempit pencarian. Query minimal dua dan maksimal 100 karakter. Karakter wildcard diperlakukan sebagai teks literal dan parameter SQL dibind.
- Admin/owner/manager mengikuti hak akses operasional. Sales hanya mendapat hasil CRM dan percakapan yang sesuai aksesnya. Terapis hanya mendapat percakapan dalam batas penugasan dan cabangnya melalui `InboxAccess`.
- Isi pesan, catatan privat, token portal dan bukti pembayaran tidak dicantumkan dalam hasil. Melakukan pencarian tidak menandai percakapan sebagai telah dibaca.
- Middleware autentikasi, pengguna aktif, pembatasan peran, pembatasan permintaan dan header privasi tetap berlaku.

### Navigasi

- Menu induk tetap aktif pada halaman tambah/edit/detail. Layanan laktasi yang diedit tetap menandai menu laktasi, berdasarkan layanan yang terikat pada route.
- Penanda visual dilengkapi `aria-current="page"`.
- Sidebar menampilkan tautan sesuai peran, sehingga pengguna tidak diarahkan ke menu yang tidak dapat diaksesnya. Pembatasan backend tidak diperluas.
- Drawer ponsel mempunyai tombol tutup, `aria-expanded`, pengelolaan fokus, pembatasan fokus di dalam drawer, penutupan lewat Escape/backdrop, dan pemulihan fokus ke tombol pembuka.
- Perubahan breakpoint menutup drawer dan melepaskan penguncian halaman. Tombol tutup hanya tampil pada ukuran ponsel/tablet yang memakai drawer.

### Kompatibilitas promo

- Model membaca ejaan lama `percentage` sebagai `percent` tanpa migrasi atau pembaruan massal database.
- Daftar dan formulir mengenali promo lama sebagai persentase. Perhitungan booking baru dan pembaca lain memakai tipe model yang sama.
- Promo nominal tetap memakai rupiah; persentase baru dibatasi hingga 100. Seeder berikutnya memakai tipe `percent`.
- Diskon yang sudah tersimpan pada booking lama tetap dipertahankan; membuka daftar/form atau menggunakan promo pada booking lain tidak menghitung ulang booking tersebut.

## Verifikasi

| Pemeriksaan | Hasil |
|---|---|
| 56 tujuan sidebar, desktop 1366 × 900 | Seluruh halaman menampilkan kerangka admin; tidak ada 500 atau pelebaran halaman; satu menu aktif per halaman |
| 56 tujuan sidebar, ponsel 390 × 844 | Seluruh halaman tampil; tidak ada pelebaran halaman atau isi sel `.table-card` yang melampaui sel |
| Delapan halaman utama × lebar 320, 768, 1024 | 24 pemeriksaan lulus setelah perbaikan guliran: pelanggan, booking, promo, spa, laktasi, produk pendukung, arsip dokumen dan transaksi rental |
| Guliran tabel dengan keyboard | Fokus region dan ArrowRight menggeser tabel laktasi pada tablet |
| Drawer | Escape menutup menu, `aria-expanded=false`, fokus kembali ke Buka menu; Tab dari tautan terakhir kembali ke tombol tutup |
| Arsip pada 320 px | Lebar dokumen tidak melebihi viewport; tabel 736 px berada dalam region 235 px dengan `overflow-x:auto` |
| Pengujian terarah akhir | 60 pengujian, 572 assertion lulus |
| Suite aplikasi aktif | **752 pengujian, 6.483 assertion lulus** |
| PHP formatting pada enam file terkait | Lulus Pint |
| Sintaks JavaScript bersama | Lulus `node --check` |
| Perbandingan data lokal | **26 tabel sama persis**, jumlah baris dan SHA-256 identik; SQLite integrity `ok` |

Suite dijalankan dengan database SQLite `:memory:` melalui PHPUnit, bukan database bisnis lokal:

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai --log-junit storage/logs/ui-package-a/full-tests.xml
```

Grup `legacy-ai` mengikuti pengecualian suite aktif yang sudah ditetapkan untuk alur transaksi WhatsApp lama. Paket ini tidak mengaktifkan kembali transaksi AI lama.

Pengujian baru mencakup snapshot total awal kosong/lengkap/nol, detail rental tanpa rute, pemisahan nilai awal dengan tagihan berjalan, arsip versus layout cetak, pencarian tanpa mutasi data, batas akses sales/terapis, wildcard literal, pembatasan input/hasil, promo lama sampai ke booking baru, serta menu induk pada formulir.

## Perlindungan data dan bukti

- Backup lokal: `storage/app/backups/ui-package-a-before-20261102-214055.sqlite`.
- Pemeriksaan menggunakan `scripts/check-ui-package-a-data.py`: `before` menolak menimpa baseline yang sudah ada; `check` membandingkan jumlah dan hash baris pada 26 tabel, dengan koneksi baca saja.
- Tabel yang dibandingkan mencakup pelanggan, booking/pembayaran, rental/item/perpanjangan/pembayaran/dokumen, laundry/item/pembayaran/dokumen, promo, katalog, pengaturan, pesan, antrean, knowledge dan log AI.
- Baseline/hasil: `storage/logs/ui-package-a/data-before.json` dan `data-after.json`.
- Bukti 112 halaman: `pages.json`, `00-desktop.txt` sampai `55-desktop.txt` beserta versi ponsel, pada folder yang sama.
- Pemeriksaan breakpoint: `breakpoints-final.json`; interaksi akhir: `final-interactions.json`.
- Hasil tes: `full-tests.txt`, `full-tests.xml`, `final-focused-tests.xml`.
- Screenshot: `katalog-desktop.png`, `katalog-mobile.png`, `invoice-320.png`.

Gateway, queue worker dan scheduler WhatsApp tetap tidak dijalankan. Tidak ada pesan nyata atau panggilan provider AI yang digunakan untuk validasi ini. Tidak dilakukan reseed, impor ulang katalog, migrasi data, perubahan VPS, atau deployment.

## Batas paket dan pekerjaan berikutnya

Target stabilisasi Paket A telah terpenuhi pada lingkungan lokal. Pengelompokan keluarga/variasi layanan, kepadatan formulir tambahan, filter kesiapan katalog, penyusunan ulang Inbox dan Home Service, serta desain ulang laporan mengikuti paket lanjutan pada audit. Pemeriksaan ini bukan sertifikasi aksesibilitas menyeluruh maupun validasi operasional WhatsApp nyata.
