# Deployment Gayatri — 4 November 2026

## Akses dan isolasi

- Situs: https://gayatri-kediri.web.id
- Login: https://gayatri-kediri.web.id/login
- `www` dan HTTP diarahkan ke HTTPS domain utama.
- VPS: `43.173.38.197`, Ubuntu 24.04, Apache, PHP 8.3, MariaDB.
- Root proyek baru: `/srv/gayatri`.
- Release: `/srv/gayatri/releases/20261104-3bc8cd1`, diakses melalui `/srv/gayatri/current`.
- Akun Linux dan database/user MariaDB khusus: `gayatri` dan `gayatri_app`.
- Konfigurasi host khusus: `/etc/apache2/sites-available/zz-gayatri.conf`.
- PHP-FPM khusus: `/srv/gayatri/config/php-fpm.conf`, socket `/run/gayatri/php-fpm.sock`.
- Baileys: `127.0.0.1:13001`; callback aplikasi: `127.0.0.1:18080`. Keduanya hanya listen di loopback.
- Node 24.15.0 dan Composer berada di `/srv/gayatri/runtime`; runtime proyek lain tidak diganti.

Release memuat source commit `3bc8cd1` dan penghapusan panel akun demo dari halaman login. `APP_ENV=production`, `APP_DEBUG=false`, dan cookie sesi memakai HTTPS. Lima password bawaan diganti khusus pada salinan produksi; akun dan perannya dipertahankan. Akses baru tersimpan di file lokal privat `vps/AKSES-GAYATRI-PRODUKSI.md`, bukan dalam repository. `vps.txt` juga diabaikan Git.

Konfigurasi Apache/PHP kedua proyek lama diverifikasi dengan SHA-256 sebelum dan sesudah deployment. Keduanya tetap HTTP 200. Apache hanya di-reload secara graceful setelah konfigurasi lolos pemeriksaan; layanan PHP dan database proyek lain tidak diubah.

## Pemindahan data

Sumber adalah database aktif aplikasi lokal `.env.baileys-local`, yaitu `storage/app/baileys-local.sqlite`. Database MySQL dalam `.env` utama bukan sumber aplikasi lokal yang sedang digunakan.

Snapshot dibuat dengan SQLite backup API. Seluruh 48 migrasi dijalankan pada database MariaDB baru, kemudian 70 tabel data diimpor dan dibandingkan per baris. Perbandingan menormalkan representasi angka, tanggal, dan waktu antarmesin database. Pengaturan terenkripsi tetap dapat dibuka dengan application key yang sama.

| Data | Jumlah |
| --- | ---: |
| Layanan dan variasi | 270 |
| Relasi tambahan layanan | 157 |
| Produk rental | 24 |
| Unit rental | 7 |
| Tarif laundry | 17 |
| Produk retail | 9 |
| Pelanggan | 21 |
| Booking | 8 |
| Pesanan rental | 11 |
| Pesanan laundry | 6 |
| Knowledge base | 48 |
| Akun pengguna | 5 |

Seluruh 57 berkas unggahan publik/privat dibandingkan dengan SHA-256, termasuk katalog, gambar, dan bukti pembayaran. Aset frontend, font, video desktop/mobile, serta hasil build juga ikut deployment. Snapshot gateway Baileys ikut dipindahkan; tidak ada callback tertunda yang diputar ulang.

Cache, sesi browser, token reset, dan antrean proses tidak disalin sebagai sesi/proses aktif. Data sumber utuh tetap disimpan sebagai backup privat. Satu campaign impor dijeda dan satu reminder impor dibatalkan untuk mencegah pengiriman jadwal uji yang sudah lewat. Riwayatnya tetap ada, dan penyesuaian deployment tercatat di audit log. Pengiriman WhatsApp nyata tidak dilakukan selama pengujian.

## Layanan dan backup

- `gayatri-php.service`: PHP-FPM, maksimum 4 worker, batas memori 768 MB.
- `gayatri-baileys.service`: gateway WhatsApp, batas memori 512 MB.
- `gayatri-queue.service`: antrean aplikasi, batas memori 384 MB.
- `gayatri-scheduler.timer`: menjalankan scheduler setiap menit.
- `gayatri-backup.timer`: backup setiap hari sekitar 03.30 WIB, retensi 14 hari.
- Semua unit tersebut khusus Gayatri dan otomatis aktif setelah reboot.

Backup harian berada di `/srv/gayatri/backups/daily`, hanya dapat dibaca root. Isinya dump MariaDB, snapshot konsisten SQLite gateway, berkas unggahan, dan konfigurasi rahasia yang diperlukan untuk restore. Backup pertama berhasil dibuat dan integritas arsip diperiksa. Ini adalah backup lokal VPS; belum ada salinan otomatis di server lain.

Sertifikat Let's Encrypt mencakup domain utama dan `www`, berlaku hingga 2 Februari 2027. Renewal memakai timer Certbot yang sudah tersedia, webroot khusus Gayatri, dan graceful reload Apache. Log aplikasi diputar khusus melalui `/etc/logrotate.d/gayatri`.

Perintah pemeliharaan terbatas pada proyek ini:

```bash
sudo systemctl status gayatri-php gayatri-baileys gayatri-queue
sudo systemctl list-timers gayatri-scheduler.timer gayatri-backup.timer
sudo systemctl start gayatri-backup.service
sudo -u gayatri sh -c 'cd /srv/gayatri/current && php artisan migrate:status'
sudo journalctl -u gayatri-queue -u gayatri-baileys --since '30 minutes ago'
```

Jangan menjalankan `migrate:fresh`, seeder demo, atau impor ulang pada database produksi. Update berikutnya harus membuat release baru, mempertahankan `shared/.env` dan `shared/storage`, melakukan backup, menjalankan migrasi kompatibel, lalu mengganti symlink `current` dan me-restart hanya unit Gayatri yang relevan.

## Hasil pemeriksaan

- 22 tes keamanan/navigasi lokal lulus (506 assertion) setelah panel demo dihapus.
- 99 pemeriksaan HTTP awal lulus: 56 menu admin, halaman publik/pemesanan, 27 aset, serta proteksi akses tanpa login.
- 58 pemeriksaan operasional lulus: 47 halaman detail transaksi/dokumen, proteksi 3 bukti pembayaran privat, koneksi gateway, 5 simulasi AI, serta penolakan webhook tanpa autentikasi.
- Pengujian koneksi provider OpenRouter dengan pengaturan impor berhasil. Simulasi menghasilkan tautan domain produksi.
- Login owner melalui browser berhasil; halaman login tidak menampilkan akun demo.
- Video desktop 1280×720 dan mobile 360×640 berhasil diputar; tidak ada overflow horizontal pada pemeriksaan 1280/390 px.
- Penyimpanan publik dan privat dapat ditulis/dibaca; berkas probe pengujian dihapus setelah pemeriksaan.
- Queue dan failed jobs kosong. PHP-FPM, gateway, dan queue aktif tanpa restart akibat error; scheduler berhasil dijalankan.
- Aplikasi produksi mengembalikan HTTP 200; domain HTTP/`www` mengembalikan redirect 301 yang sesuai.

Bukti pemeriksaan lokal berada di `storage/logs/deployment-audit/` dan tidak masuk Git.

## Pengaturan operasional yang mengikuti lokal

Deployment mempertahankan keputusan operasional lokal:

1. Sesi WhatsApp `gayatri-main` masih **stopped** dan belum terdaftar. Buka menu WhatsApp Gateway, mulai sesi, lalu lakukan scan QR untuk mengaktifkan pengiriman/penerimaan nyata.
2. Belum ada slot pada **Jadwal Tersedia**. Admin perlu menentukan jadwal dan kapasitas yang benar sebelum pelanggan dapat memilih jadwal booking.
3. Pemesanan laundry publik masih **belum dibuka**. Aktifkan melalui pengaturan pemesanan laundry setelah outlet siap.
4. Email masih menggunakan `MAIL_MAILER=log`; pengiriman email/reset password memerlukan konfigurasi SMTP yang valid.

Harga, stok, jadwal, dan status pembukaan layanan tidak dibuat-buat saat deployment. Status tersebut dapat disesuaikan melalui admin sesuai kondisi usaha.
