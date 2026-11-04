# Baileys gateway untuk Gayatri

Service Node.js khusus Gayatri, menggunakan `@whiskeysockets/baileys` **7.0.0-rc14** yang dikunci di lockfile. Service menyediakan kontrak HTTP yang dipakai Laravel untuk sesi, QR, teks, typing dan callback pesan/status. WAHA tetap tersedia sebagai pilihan legacy untuk rollback; pengujian ini belum mengubah VPS.

## Menjalankan di Windows lokal

Prasyarat: Node.js >=22.13 (diuji dengan 24.15), PHP >=8.2 dengan PDO SQLite, dependency Composer aplikasi sudah terpasang. Dari root repository:

```powershell
.\scripts\Start-BaileysLocal.ps1
# Jika php.exe berada di lokasi lain:
.\scripts\Start-BaileysLocal.ps1 -PhpPath 'D:\php\php.exe'
```

Script menyiapkan `.env.baileys-local`, `services/baileys/.env`, database `storage/app/baileys-local.sqlite`, demo data, web server, queue worker, gateway dan scheduler khusus ulang tahun. API key dan webhook secret diacak sekali, disamakan di kedua service, dan tidak ditampilkan. `.env` utama serta database MySQL lokal sebelumnya tidak diubah. Konfigurasi dan sesi lokal diabaikan Git. Script menolak memakai port yang sudah terisi.

- Aplikasi: <http://127.0.0.1:8000>
- Login demo: `owner@gayatri.local` / `password` (hanya pada database demo lokal).
- Settings → WhatsApp Gateway sudah memilih Baileys, Base URL `http://127.0.0.1:3001`, callback base `http://127.0.0.1:8000`, sesi `gayatri-main`.
- Klik **Test Connection**, kemudian buka **WhatsApp Gateway** dan scan QR melalui menu perangkat tertaut di ponsel. Halaman memperbarui QR/status setiap 15 detik selama pairing.
- Gunakan nomor WhatsApp lain untuk mengirim pesan ke nomor yang ditautkan. Periksa inbox, AI Logs, dan balasan pada ponsel. AI di lingkungan ini memakai provider `local` sehingga tidak membutuhkan panggilan API AI eksternal.
- Menu **AI Management → Balasan Otomatis** tersedia untuk owner, manager, dan admin. Atur aktif/nonaktif, nama asisten/bisnis, panggilan customer, emoji, template sapaan/terima kasih/fallback/kendala, penutup, dan hingga 20 aturan kata kunci. Prioritas terkecil didahulukan. Perubahan diterapkan pada pesan baru; pengambilalihan admin dan alur booking tetap dihormati. Instruksi gaya tambahan digunakan saat memakai provider AI eksternal.
- Tombol **Lihat Pratinjau** menggunakan isian yang belum disimpan dan balasan lokal. Pratinjau tidak menyimpan pengaturan, membuat AI log/booking, mengirim WhatsApp atau memanggil provider AI. Gunakan `{sapaan}`, `{nama_customer}`, `{nama_bisnis}`, `{nama_asisten}` sebagai variabel. Balasan yang benar-benar dikirim dicatat di AI Logs; balasan alur booking mengikuti pencatatan flow yang sudah ada.
- Uji manual setelah pairing: teks masuk/keluar, satu balasan AI per pesan, receipt delivered/read, stop/start, restart, dan pemulihan koneksi. Riwayat/backfill, pesan dari diri sendiri, grup, status dan protokol tidak memicu AI.

### Ucapan ulang tahun otomatis

Menu **Operasional → Ucapan Ulang Tahun** (`/admin/birthdays`) tersedia untuk owner, manager dan admin. Isi tanggal lahir ibu/pelanggan atau bayi pada Data Pelanggan, pilih jenis penerima, sesuaikan template dan jam kirim WIB, periksa pratinjau, lalu aktifkan fitur. Fitur awalnya nonaktif. Variabel template: `{nama_customer}`, `{nama_bayi}`, `{usia}`, `{nama_bisnis}`, `{nama_asisten}`, `{sapaan}`. Ucapan bayi dikirim ke WhatsApp pelanggan/ibu; ulang tahun pertama mulai usia satu tahun. Tanggal 29 Februari dapat diperingati pada 28 Februari atau 1 Maret di tahun nonkabisat.

Scheduler memeriksa setiap menit dan hanya memproses ulang tahun hari ini setelah jam kirim. Catch-up hari sebelumnya tidak dilakukan. Unique key customer/jenis/tahun dan klaim job atomik mencegah kirim ganda; pengiriman diberi jarak minimal 10 detik. Status pelanggan dan tanggal lahir diperiksa lagi di worker. Nomor tidak valid serta pelanggan nonaktif/blacklist dilewati; percakapan LID yang sudah dikenal dipertahankan. Pengiriman ulang tahun terpisah dari pengaturan aktif/nonaktif balasan AI dan tidak mengubah human takeover.

Riwayat menyimpan isi pesan, status, percobaan dan message ID, serta menautkan pesan ke inbox. “Terkirim” berarti gateway telah mengembalikan ID, bukan jaminan pesan telah dibaca; delivery/read mengikuti receipt WhatsApp. Timeout/hasil tidak pasti berstatus **Perlu diperiksa** dan tidak otomatis dikirim ulang. Admin harus memeriksa WhatsApp dan mencentang konfirmasi belum terkirim sebelum retry; pesan berstatus terkirim tidak dapat diulang. Retry hanya berlaku pada hari ulang tahun setelah jam kirim.

Script lokal menjalankan `crm:work-birthdays --env=baileys-local` supaya campaign/reminder demo lain tidak ikut berjalan. Log ada di `services/baileys/data/birthday-scheduler*.log`. Pada VPS nanti gunakan scheduler Laravel yang sudah ada: `crm:send-birthday-greetings` didaftarkan setiap menit di `routes/console.php`; tidak perlu menjalankan dua scheduler. Pertahankan queue database default agar pembuatan greeting dan job berada dalam transaksi yang sama.

`whatsapp:prepare-local` menambahkan pelanggan **Demo Ulang Tahun Lokal** tanpa nomor WhatsApp, sehingga dapat dipakai untuk pratinjau tanpa mengirim pesan nyata. Menu menampilkan ulang tahun hari ini, tujuh hari mendatang, dan riwayat. Untuk pengujian otomatis:

```powershell
& C:\xampp\php\php.exe vendor\bin\phpunit --filter BirthdayGreetingsTest
```

Hentikan semua proses yang diluncurkan script tanpa menghapus sesi/data:

```powershell
.\scripts\Stop-BaileysLocal.ps1
```

Log ada di `services/baileys/data/{web,queue,gateway}*.log`. `gateway.sqlite` menyimpan kredensial Signal, sesi, cache retry pesan 7 hari dan outbox webhook. Lindungi direktori ini seperti private key. Logout menghapus auth hanya untuk sesi yang dipilih; stop/restart menyimpannya. Jangan menjalankan dua instance dengan database/sesi yang sama.

## Alur dan autentikasi

Laravel memilih implementasi `WhatsAppGateway` berdasarkan `whatsapp.provider`. Baileys memakai namespace setting `baileys.*`; setting WAHA tidak ditimpa. Kredensial dashboard disimpan terenkripsi. Worker mengambil konfigurasi gateway terbaru ketika mengirim pesan.

Semua endpoint service termasuk `/health` dan QR membutuhkan header `X-Api-Key`. Callback hanya menuju `GAYATRI_URL/webhooks/whatsapp/{messages,status}` dan membawa `X-Webhook-Secret` terpisah. API menolak callback URL lain. Laravel menolak callback WAHA saat Baileys aktif dan sebaliknya.

Pesan masuk Baileys dinormalisasi ke payload CRM. Nomor `@s.whatsapp.net` menjadi `@c.us` untuk mempertahankan kontak lama; LID dipertahankan bila nomor alias tidak tersedia. Outbox SQLite menahan event saat Laravel belum siap, melakukan retry dengan backoff, dan membutuhkan respons JSON `{"ok":true}`. Message ID dideduplikasi di outbox dan database Laravel. Status ACK dinormalisasi dari protobuf Baileys ke kontrak CRM.

## Pengujian otomatis

```powershell
npm.cmd --prefix services/baileys ci
npm.cmd --prefix services/baileys test
& C:\xampp\php\php.exe vendor\bin\phpunit --filter 'BaileysIntegrationTest|WahaIntegrationTest|SettingsManagementTest'
& C:\xampp\php\php.exe vendor\bin\phpunit
```

Test Node memakai socket WhatsApp simulasi, tetapi HTTP API, SQLite, QR PNG dan worker webhook nyata. Test Laravel memakai database SQLite in-memory dan HTTP gateway simulasi untuk memeriksa dispatch, AI local, idempotensi, autentikasi, UI serta receipt. Pengujian otomatis tidak membuktikan pengiriman WhatsApp nyata sebelum nomor dipairing.

Hasil 26 Oktober 2026: **10 test Node dan 35 test integrasi lulus**; suite Laravel penuh **208/213 lulus** (1115 assertion). Kelima kegagalan yang sama direproduksi pada source HEAD sebelum migrasi: `test_service_choice_after_cancelled_context_starts_new_booking`, `test_partial_booking_data_is_merged_with_missing_service_reply_before_provider`, `test_active_booking_ambiguous_new_schedule_asks_for_booking_or_reschedule_clarification`, serta dua test `test_reminder_uses_raw_ai_response_*`. Tiga terkait perilaku/alur reservasi lama; dua terakhir mengharapkan 2 Juli 2026 yang sudah lewat. Perbaikannya terpisah dari migrasi gateway. Laporan JUnit tersimpan di `storage/logs/baileys-regression.xml`. Pemeriksaan browser membuktikan login demo, Test Connection, QR asli, callback status dan pemulihan sesi setelah restart service; pengiriman WhatsApp nyata masih menunggu pairing ponsel.

## Persiapan VPS setelah uji lokal disetujui

Belum ada upload/aktivasi VPS pada tahap ini. Gunakan akun service, direktori data, port loopback dan resource limit khusus Gayatri; Node >=22.13 diperlukan. Siapkan kredensial produksi baru, samakan `baileys.*` dan konfigurasi service, pertahankan backup database/WAHA, lakukan pairing dan uji pesan nyata sebelum mematikan WAHA. Jangan upload database demo lokal, file `.env`, auth state atau `node_modules` Windows ke VPS. `npm ci --omit=dev` harus dijalankan di Linux untuk dependency native yang sesuai. Baileys adalah library tidak resmi; perubahan protokol bisa membutuhkan pemutakhiran dan pairing ulang.

Referensi: [rilis Baileys](https://github.com/WhiskeySockets/Baileys/releases/tag/v7.0.0-rc14), [dokumentasi](https://baileys.wiki/), [penyimpanan auth](https://baileys.wiki/authentication/session-management).
