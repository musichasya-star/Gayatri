# Paket D — Dashboard, laporan, AI, dan pengaturan

Status: **selesai pada lingkungan lokal**, 3 November 2026.

Acuan: `docs/AUDIT_UI_UX_ADMIN_2026-11-02.md`, dilanjutkan dari Paket A, B, dan C. Urutan pengerjaan: dashboard utama → dashboard/laporan Laundry dan Rental → fee terapis → pengelolaan AI → WhatsApp/pengaturan → CRM, CMS, dan pengguna → verifikasi.

Lingkungan peninjauan: `http://127.0.0.1:8000`, aplikasi `baileys-local`, basis data `storage/app/baileys-local.sqlite`. Tidak ada deployment atau perubahan VPS dalam paket ini.

## Hasil implementasi

| Area | Perubahan |
|---|---|
| Dashboard utama | Pekerjaan hari ini dan antrean aktif ditampilkan terlebih dahulu: booking aktif, permintaan menunggu konfirmasi, percakapan perlu admin, dan follow-up jatuh tempo. Laundry/Rental mempunyai pintasan antrean dan bukti pembayaran. Kondisi saat ini dipisahkan dari ringkasan periode. Tugas modul mengecualikan demo. |
| Ketepatan antrean | Booking tertunda dari tanggal lama tetap dihitung. Tautan booking membawa filter antrean yang sama. Follow-up jatuh tempo memakai jumlah seluruh tugas yang cocok, bukan jumlah lima baris pratinjau. Catatan pengiriman yang sudah memiliki bukti berhasil tidak ikut dianggap kegagalan aktif. |
| Pusat laporan | Navigasi ke laporan Laundry, Rental, dan fee tersedia untuk peran yang berhak. Filter tanggal diberi label. Definisi angka dapat dibuka sesuai kebutuhan. Saldo awal booking historis tetap terpisah dari penerimaan baru; estimasi nilai booking tetap diberi keterangan sebagai estimasi. |
| Laundry | Dashboard mendahulukan piutang disetujui, estimasi terlewat, bukti menunggu pemeriksaan, dan pesanan siap diambil. Laporan mendahulukan aktivitas periode serta rinciannya. Saldo saat ini, tren, status pesanan, dan metode pembayaran tidak lagi semuanya terbuka sekaligus. |
| Rental | Dashboard mendahulukan bukti transfer, permintaan pembatalan/refund, dan keterlambatan. Laporan mempertahankan rincian periode, saldo, produk, serta ekspor. Tren dan rincian panjang dikelompokkan. Deposit, tagihan jasa, refund, dan penerimaan tetap mempunyai definisi masing-masing. Navigasi tambahan Laundry/Rental diringkas pada dashboard/laporan. |
| Fee terapis | Ringkasan kebijakan default/khusus dan tanggal berlaku tersedia. Kalkulator contoh interaktif menunjukkan dasar treatment + tambahan − diskon, tanpa ongkos perjalanan. Kalkulator berada di luar formulir penyimpanan. Daftar terapis dapat dicari; pengaturan per terapis dibuka satu per satu. Filter lanjutan laporan dikelompokkan. |
| Knowledge Base | Cakupan layanan, status publikasi, penggunaan, masa berlaku, serta tindak lanjut menjadi informasi utama. Demo dapat disaring terpisah. Detail tipe/revisi/potongan informasi dipindahkan ke bagian teknis. Daftar memakai sepuluh materi per halaman. Konteks pencarian/filter dipertahankan ketika mengedit dan kembali. |
| Kendali dan pengujian AI | Banner kebijakan diringkas. Pengaturan identitas, bahasa percakapan, dan jam admin dikelompokkan. Simulator mendahulukan pertanyaan dan jawaban; konteks uji, sumber, alasan, serta riwayat dapat dibuka. Persona dan arsip otomasi lama diberi konteks hubungannya dengan mode aktif. |
| Log AI | Pintasan semua/perlu admin/gagal/hasil belum pasti/simulasi, label keputusan yang terbaca, dan detail teknis terpisah. Sepuluh keputusan per halaman mengurangi guliran ponsel. Detail keputusan mempertahankan filter saat kembali. Redaksi informasi transaksi privat tetap berlaku. |
| WhatsApp | Status izin balasan AI, koneksi gateway, dan worker dijelaskan terpisah. Error koneksi disampaikan secara ringkas, dengan detail teknis tertutup. Halaman GET gateway hanya memeriksa sesi; sesi yang belum ada baru dibuat melalui tindakan Mulai sesi. Pengamanan kirim ulang dan identitas pengiriman tetap berlaku. |
| Pengaturan provider | Pilihan model mengikuti provider. Provider Local hanya menawarkan model lokal dan menyembunyikan isian API key. Isian nonrahasia dipulihkan setelah validasi gagal. API key dan webhook secret tidak dikembalikan dalam old input. |
| CRM dan akun | Impor CSV berada setelah daftar dalam bagian tersendiri. Kontak pelanggan digabung tanpa menghilangkan nomor telepon asli. Arsip/hapus dan perubahan status akun dipisahkan dari edit utama. Filter dibawa ke detail/edit/kembali melalui parameter yang diizinkan. Profil pelanggan menampilkan Laundry terbaru hanya untuk owner/manager/admin. Sales tidak mendapat pintasan membuat booking. |
| CMS | Pengaturan dikelompokkan per tujuan; editor bagian halaman ditutup sampai diperlukan. Tab mempunyai status aksesibel dan navigasi keyboard. Perubahan yang belum disimpan diberi indikator pada formulir dan tab. Kolom pengaturan bertingkat mempertahankan struktur array; halaman booking tidak lagi ditimpa sebagai string JSON. Form kembali ke tab terkait sesudah penyimpanan atau validasi gagal. |

Komponen perlindungan draf hanya menggunakan memori halaman. Nilai isian tidak ditulis ke localStorage/sessionStorage, log, atau layanan eksternal. Mengembalikan nilai awal membersihkan indikator. Draf pratinjau kendali AI tetap ditandai belum disimpan.

## Perbaikan yang ditemukan saat pengecekan

- Isian lampiran kosong menghasilkan objek File dengan waktu yang berubah pada pembacaan ulang. Pemeriksaan draf sekarang menormalkan lampiran kosong, sehingga formulir Knowledge yang belum diedit tidak menghalangi navigasi.
- Pemeriksaan QR tidak dijalankan ketika sesi gateway belum ditemukan. Pesan untuk memulai sesi tidak tertimpa error pemeriksaan QR.
- Pembacaan CMS memakai isian bertingkat dan validasi struktur halaman booking. Penggabungan pengaturan tetap mempertahankan nilai saudara yang tidak ikut dikirim.
- Indeks item baru CMS memakai indeks tertinggi + 1, sehingga penghapusan item tengah tidak membuat nama input bertabrakan saat menambah item.
- Regresi daftar pelanggan menemukan nomor telepon asli yang sempat terlewat ketika menggabungkan kolom kontak. Nomor tersebut telah dikembalikan. Label rating dashboard tetap menyatakan rata-rata.

## Hasil verifikasi

| Pemeriksaan | Hasil |
|---|---|
| Suite aktif | **790 pengujian, 6.743 assertion lulus**. Grup `legacy-ai` dikecualikan sesuai pengamanan transaksi AI lama. |
| Regresi baru | 11 pengujian pada `tests/Feature/AdminWorkbenchTest.php`: backlog booking, hitungan follow-up, pembatasan dashboard per peran, filter invalid, pemisahan knowledge demo, konteks kembali yang aman, hak akun/profil, perlindungan secret, GET gateway tanpa pembuatan sesi, dan struktur CMS. |
| Browser | **83 hasil pemeriksaan tanpa error halaman, overflow halaman, atau target anchor hilang**. Lebar yang diperiksa: 320, 390, 768, 1024, 1280, dan 1366 piksel. Cakupan per ukuran tercatat pada berkas bukti; bukan klaim semua kombinasi menu × ukuran telah diuji. |
| Interaksi | Kalkulator fee, tab/keyboard CMS, editor pada 320 piksel, indikator draf dan pengembalian nilai awal, pilihan model/provider, serta konteks filter Knowledge/log/pelanggan/akun diperiksa langsung. Tidak ditemukan error/warning konsol pada sesi pemeriksaan akhir. |
| Sintaks/tampilan | Pint pada berkas PHP terkait, pemeriksaan sintaks JavaScript, dan kompilasi seluruh view Blade lulus. |
| Integritas data | Jumlah baris dan SHA-256 isi **44 tabel** sama dengan baseline; SQLite integrity check **ok**. Meliputi katalog, transaksi/pembayaran, stok, pesan/antrean, knowledge/log AI, fee, pengaturan/CMS, follow-up, dan sesi WhatsApp. |

Suite menggunakan `APP_ENV=testing` dan SQLite `:memory:`. TestCase menolak basis data lain sebelum RefreshDatabase berjalan. Permintaan HTTP tak terduga diblokir; pengujian baru memalsukan antrean. Pemeriksaan browser hanya membaca data dan mengubah isian sementara, tanpa menyimpan transaksi, pengaturan, atau publikasi CMS.

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
```

Jangan menjalankan suite dengan lingkungan/basis data `baileys-local`.

Gateway Baileys, worker, dan scheduler tidak dijalankan untuk paket UI ini. Status gateway lokal yang belum terhubung ditampilkan sesuai kondisi tersebut. Tidak ada pesan WhatsApp nyata, panggilan provider AI berbayar, atau uji transaksi nyata. Pengujian koneksi/pengiriman nyata tetap memerlukan sesi dan skenario operasional tersendiri.

## Bukti dan acuan berkas

- Suite: `storage/logs/ui-package-d/full-suite-final.txt` dan `full-suite-final.xml`.
- Browser: `storage/logs/ui-package-d/browser-final.json`. `browser-checks.json` menyimpan riwayat pemeriksaan selama pengerjaan.
- Data: `storage/logs/ui-package-d/data-before.json` dan `data-after.json`.
- Backup basis data: `storage/app/backups/ui-package-d-before-20261103-170930.sqlite`.
- Snapshot sumber sebelum perubahan: `storage/app/backups/ui-package-d-source/`.
- Daftar sumber dan hash hasil: `storage/logs/ui-package-d/source-manifest.json`; perbandingan berkas yang memiliki snapshot: `source-changes.diff`.
- Komponen bersama: `public/css/admin-workbench.css`, `public/js/admin-workbench.js`, `resources/views/partials/admin-workbench-assets.blade.php`.
- Hitungan operasional: `app/Services/CRM/OperationalOverview.php`; konteks navigasi: `app/Support/AdminReviewContext.php`.

Pratinjau dashboard akhir:

![Dashboard desktop](C:/xampp/htdocs/Gayatri/storage/logs/ui-package-d/dashboard-desktop.png)

Pratinjau laporan fee pada ponsel:

![Laporan fee ponsel](C:/xampp/htdocs/Gayatri/storage/logs/ui-package-d/fee-report-mobile.png)

Target Paket D tercapai untuk area pada acuan audit. Validasi lintas modul/peran dan skenario operasional yang lebih luas dapat dilanjutkan sebagai Paket E; hasil di atas merupakan cakupan yang sudah diperiksa dalam Paket D.
