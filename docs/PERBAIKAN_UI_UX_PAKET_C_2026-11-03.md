# Paket C — Tampilan dan alur kerja operasional

Status: **selesai pada lingkungan lokal**, 3 November 2026.

Rujukan: `docs/AUDIT_UI_UX_ADMIN_2026-11-02.md` serta hasil Paket A dan B. Urutan pengerjaan: Inbox → booking dan jadwal → Home Service → Laundry → Rental → verifikasi. Perubahan diterapkan pada workspace lokal dan server `127.0.0.1:8000` dengan lingkungan `baileys-local`.

## Hasil menurut prioritas

| Area | Hasil |
|---|---|
| WhatsApp Inbox | Daftar dan percakapan berdampingan pada desktop/tablet. Ponsel memakai daftar → percakapan → kembali. Tinggi chat mengikuti ruang layar; kotak balasan terlihat pada ukuran pengujian. Profil, catatan internal, dan follow-up dibuka melalui dialog. Kontrol takeover/resume/close dan penghapusan tetap mengikuti hak akses dan pemeriksaan server. |
| Perlindungan pekerjaan Inbox | Pembaruan 15 detik dijeda ketika ada draf, perubahan isian, dialog/menu terbuka, fokus pada isian, atau admin sedang membaca pesan lama. Tombol pembaruan manual juga mempertahankan draf. Isi chat tidak disimpan dalam browser storage; hanya preferensi hidup/mati pembaruan yang disimpan untuk sesi browser. Daftar awal tidak otomatis memilih atau menandai chat pertama terbaca. Filter dan halaman daftar dipertahankan setelah tindakan chat. |
| Booking | Daftar diringkas menjadi identitas, layanan, jadwal/terapis, status jadwal, pembayaran, dan tindak lanjut. Detail kunjungan rumah berada pada baris terkait, sehingga tidak diulang sebagai kartu di atas tabel. Edit jadwal dibedakan dari pembayaran/kuitansi. Pencarian kode/nama serta filter berlabel tersedia. Kalender memberi akses ke booking terkait. Konteks daftar/kalender dibawa ke formulir dan tautan kembali melalui daftar parameter yang diizinkan, tanpa menerima URL pengalihan bebas. |
| Data booking historis | Status lama dipertahankan dan diberi keterangan jurnal historis. Harga katalog sekarang tidak dinyatakan sebagai sisa utang baru pada daftar. Layanan tersimpan yang sudah tidak tersedia di pilihan booking ditampilkan sebagai pilihan historis yang perlu ditinjau; penggantian tidak dilakukan diam-diam. |
| Jadwal tersedia | Daftar slot menjadi fokus awal. Form tambah dan pembuatan banyak slot berada dalam bagian yang dibuka saat diperlukan. Filter tanggal, cabang, layanan, dan status diberi label. Tanggal/filter yang tidak valid ditolak sebelum diolah. Pembatasan kapasitas, bentrok, dan penghapusan slot tetap berlaku. |
| Home Service | Form dipisah menjadi status/lokasi kantor, tarif/jangkauan, layanan, terapis, dan penyedia rute. Pilihan layanan dikelompokkan berdasarkan keluarga dan variasi; tersedia pencarian, filter kelompok, pilihan yang sudah dicentang, dan pemilihan hasil terlihat. Menyaring daftar tidak menghapus centang yang tersembunyi. Tombol simpan mengikuti guliran. |
| Kesiapan Home Service | Pilihan booking langsung mengikuti layanan yang siap dibooking. Paket beberapa kunjungan, tambahan, dan data belum lengkap tetap melalui konfirmasi admin. Pilihan lama yang tidak memenuhi syarat ditampilkan untuk ditinjau secara eksplisit. Penyimpanan dan kalkulasi penawaran menolak layanan yang tidak memenuhi syarat. Data lama dan ongkos booking tidak ditulis ulang. |
| Laundry | Antrean memberi pintasan pesanan terbuka, lewat estimasi, QC, dan siap diserahkan. Tindakan tiap pesanan mengikuti kebutuhan timbang, ongkir, persetujuan, proses, QC, pelunasan, atau penyerahan. Detail mempunyai petunjuk langkah berikutnya dan tautan ke bagian relevan. Bagian dokumen, logistik, rincian, dan riwayat dapat dibuka sesuai kebutuhan; tautan langsung membuka bagian yang tertutup. Tautan kembali mempertahankan filter antrean atau penerimaan. |
| Rental | Transaksi, perawatan unit, dan jadwal antar-jemput menjadi daftar ringkas. Tindakan dibedakan antara konfirmasi, kesiapan serah terima, pengembalian, pemeriksaan, dan peninjauan refund. Peringatan dapat disaring berdasarkan prioritas/jenis. Detail transaksi memiliki bagian unit, transportasi, pembayaran, portal, ketentuan, dan dokumen yang dapat dibuka terarah. |
| Navigasi modul | Laundry dan Rental mengutamakan menu kerja harian; laporan, katalog, notifikasi, dan pengaturan tetap tersedia pada Menu lainnya. Halaman aktif diberi penanda, termasuk ketika berada di kelompok menu lainnya. |

## Temuan yang ditangani selama verifikasi

Detail salah satu transaksi demo Rental menyimpan periode `weekly`, sedangkan enum katalog menggunakan `week`. Pembacaan enum secara langsung menyebabkan error 500. Tampilan sekarang menampilkan kode periode historis beserta catatan peninjauan ketika nilainya tidak dikenal. Tanggal, periode tersimpan, nilai jasa, deposit, pembayaran, dan snapshot tidak dinormalisasi atau ditulis ulang. Pengujian regresi memastikan detail dapat dibuka dan data transaksi tetap sama.

Home Service masih menyimpan dua pilihan dari katalog lama: **Baby Spa Premium** dan **Mom Postnatal Massage**. Kedua pilihan ditandai pada formulir untuk ditinjau sebelum admin menyimpan pengaturan. Pengganti tidak dipilih otomatis karena variasi dari katalog baru perlu ditentukan oleh admin.

## Hasil pengecekan

| Pemeriksaan | Hasil |
|---|---|
| Seluruh suite aktif | **779 pengujian, 6.667 assertion lulus**. Grup `legacy-ai` dikecualikan sesuai pengamanan jalur transaksi AI lama. |
| Pengujian khusus Paket C | 15 pengujian baru mencakup status terbaca Inbox, batas akses, filter invalid, konteks navigasi, transaksi historis, kesiapan Home Service, isian invalid, petunjuk tahap Laundry/Rental, dan periode Rental historis. |
| Browser | 33 hasil pemeriksaan lulus setelah error periode historis diperbaiki. Lebar yang digunakan: desktop 1280, tablet 768, ponsel 390, dan ponsel kecil 320 piksel. Tidak ditemukan overflow halaman atau target anchor hilang dalam hasil akhir. |
| Interaksi browser | Perlindungan draf saat pembaruan, profil/dialog Inbox, kembali ke daftar pada ponsel, konteks booking → pembayaran → edit → daftar, pencarian layanan, centang yang tetap tersimpan saat disaring, pembukaan formulir slot, dan pembukaan bagian Laundry/Rental diperiksa langsung. |
| Sintaks dan tampilan | Pemeriksaan JavaScript, Pint pada berkas PHP terkait, serta kompilasi seluruh view Blade lulus. |
| Integritas data lokal | Jumlah baris dan SHA-256 isi **32 tabel** sama dengan baseline; SQLite integrity check: **ok**. Termasuk konfigurasi, booking/pembayaran, stok/transaksi Rental, Laundry, percakapan/pesan, antrean, dan knowledge AI. |

Pengujian aplikasi menggunakan SQLite `:memory:` dengan `APP_ENV=testing`, pengiriman antrean dipalsukan untuk skenario baru, dan permintaan HTTP yang tidak diharapkan diblokir. Browser menggunakan pembacaan data serta perubahan isian sementara tanpa menyimpan transaksi atau pengaturan. Tidak ada pesan WhatsApp nyata yang dikirim untuk pemeriksaan ini. Worker gateway dan scheduler lokal tetap tidak dijalankan. VPS tidak diakses atau diubah.

Perintah suite:

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
```

Jangan menjalankan pengujian aplikasi menggunakan basis data `baileys-local`.

## Bukti dan berkas acuan

- Hasil suite akhir: `storage/logs/ui-package-c/full-suite-final.txt` dan `full-suite-final.xml`.
- Hasil browser akhir: `storage/logs/ui-package-c/browser-final.json`. `browser-checks.json` juga menyimpan percobaan awal yang menemukan error periode historis.
- Perbandingan data: `storage/logs/ui-package-c/data-before.json` dan `data-after.json`.
- Backup sebelum pengerjaan: `storage/app/backups/ui-package-c-before-20261103-063616.sqlite`.
- Screenshot: `inbox-desktop.png`, `inbox-mobile.png`, `home-service-desktop.png`, `home-service-mobile.png`, dan `laundry-detail-desktop.png` pada direktori bukti yang sama.
- Pengujian regresi: `tests/Feature/AdminOperationalWorkflowTest.php`.
- Komponen tampilan: `public/css/admin-operations.css`, `public/js/admin-operations.js`, dan `resources/views/partials/admin-operations-assets.blade.php`.
- Petunjuk tindakan dan navigasi: `app/Support/OperationalGuidance.php`, `OperationalNavigation.php`, dan `OperationalQueue.php`.

Target Paket C telah tercapai untuk area prioritas tersebut. Penyusunan ulang dashboard/laporan, pengaturan/notifikasi lain, serta uji pengiriman WhatsApp nyata tetap menjadi pekerjaan lanjutan tersendiri.
