# Paket E — Validasi lokal dan penyempurnaan percakapan

Tanggal: 2 November 2026 (WIB). Status: implementasi dan validasi teknis lokal selesai menggunakan gateway simulasi, sesuai pilihan pengguna. Kesiapan konten bisnis dan rilis produksi masih memiliki tindak lanjut yang dijelaskan di bawah.

## 1. Audit dan materi layanan

Audit membedakan materi terbit yang layak untuk pelanggan dari demo, draft, materi internal, kedaluwarsa, dan konten tidak aman. Hasilnya ditampilkan melalui **Cakupan informasi per layanan** pada Knowledge Base serta perintah baca saja `ai:audit-knowledge`.

Ditambahkan **12 draft non-demo**, berdasarkan alur aplikasi yang sudah diimplementasikan: pembayaran booking, home service, formulir dan pembayaran laktasi, keranjang rental, perpanjangan, pengembalian/deposit, bukti pembayaran rental, persetujuan hasil timbang, pembayaran laundry, antar-jemput laundry, serta privasi status/portal. Perintah `ai:prepare-knowledge-review` idempoten; pengulangan tidak menimpa suntingan admin. Draft tidak diterbitkan otomatis.

Keadaan akhir lokal: **48 materi**, terdiri atas **30 demo, 17 draft, 1 perlu ditinjau, dan 0 materi non-demo terbit yang dapat dipakai live**. Artinya FAQ demo yang tampak lengkap di simulator belum membuktikan kesiapan FAQ WhatsApp pelanggan. Harga tetap diambil dari katalog aktif; batas usia, produk pewangi, prosedur sanitasi, jam outlet, janji hasil, dan kebijakan bisnis lain tidak dikarang.

Tindak lanjut konten: owner/manager meninjau draft dan melengkapi topik persiapan/pendamping, kebersihan alat, pewangi/noda, serta ketentuan usaha yang berlaku, kemudian menerbitkan materi melalui menu Knowledge Base. Panel cakupan merupakan bantuan tinjauan berbasis kata kunci, bukan sertifikasi kebenaran atau kelengkapan.

## 2. Peran agent dan kualitas percakapan

Alur agent lokal sekarang memisahkan pemahaman pesan, penentuan kebutuhan, pembacaan sumber, penyusunan jawaban, dan tindakan lanjutan:

1. `AiMessageUnderstanding` mengenali variasi terbatas bahasa sehari-hari seperti `brp`, `gmn`, `stroler`, `konsul`, `nyuci`, `bayarnya`, dan `dibalikin`. Teks asli tetap digunakan untuk kode transaksi, nomor, serta verifikasi identitas.
2. `AiDomainRouter` memahami koreksi layanan, pilihan menu yang lebih natural, sapaan, dan konteks pompa ASI/pijat laktasi. Permintaan manusia, kondisi medis, dan status privat tetap diprioritaskan.
3. `AiResponsePlanner` merencanakan jawaban dari daftar kemampuan baca yang diizinkan. Beberapa pertanyaan dalam satu layanan dapat dijawab bersama: tarif, pembayaran, antar-jemput, ketersediaan, ketentuan, FAQ, dan pemesanan web. Dua layanan yang belum jelas tetap diklarifikasi.
4. Pencarian knowledge menyertakan alias topik, misalnya `apa yang dibawa` ke persiapan. Domain, publikasi, audience, validitas, demo, dan pemeriksaan konten tetap berlaku.
5. Katalog disaring sesuai alat yang disebut. Beberapa stroller menampilkan pilihan stroller, tanpa memasukkan kursi makan. Nama varian yang jelas memfokuskan jawaban; varian ambigu tidak dipilih sembarang.
6. Konteks menyimpan ID item katalog publik, tanpa menyimpan teks atau data pribadi tambahan. `Yang tadi berapa?` dapat mengikuti item tersebut. Fokus diperiksa terhadap katalog aktif, domain, TTL 30 menit, serta revisi kendali admin.

Tautan yang sama ditampilkan sekali pada jawaban gabungan. FAQ tetap menjawab isi pertanyaan lebih dahulu, tanpa menyalin judul dokumen, signature panjang, atau mengulang sapaan setiap giliran. Gaya dan tawaran lanjutan Paket D dipertahankan.

Contoh yang diuji:

```text
Pelanggan: Harga Stroller Bayi berapa, kebersihannya gmn dan cara bayarnya?
Asisten: [tarif dan deposit varian tersebut dari katalog aktif]
         [tautan rental resmi]

         Pembayaran masih diperiksa admin secara manual. Mengirim bukti
         transfer belum berarti pembayaran telah disetujui. ...

         [jawaban kebersihan dari FAQ yang relevan]
```

Ini tetap agent lokal dengan kemampuan yang dibatasi aplikasi. Tidak ada model berbayar atau provider baru yang dipanggil, tidak ada tool mutasi booking/pembayaran, dan pengenalan bahasa tidak diklaim memahami seluruh variasi kalimat. Bila informasi tidak tersedia, asisten meminta penjelasan atau bantuan admin sesuai batas klarifikasi.

## 3. Validasi perjalanan empat layanan

`AiCustomerJourneyTest` menggunakan database SQLite `:memory:`, antrean palsu, penyimpanan bukti palsu, dan identitas pengirim uji. Pengujian melewati endpoint aplikasi, bukan hanya membuat model transaksi langsung.

| Layanan | Alur yang dibuktikan |
| --- | --- |
| Spa | Chat memberi tautan → GET formulir → POST booking publik → status menunggu konfirmasi → verifikasi kontak admin → DP manual → kuitansi → status pemilik menunjukkan sisa tagihan |
| Laktasi | Alur booking dan DP yang sama, dengan kelompok/formulir laktasi tersendiri |
| Rental | Chat → halaman alat → keranjang/quote → pengajuan → konfirmasi admin → unggah bukti pelunasan → belum ada ledger saat bukti pending → persetujuan admin → status sesuai ledger |
| Laundry | Chat → estimasi → permintaan publik → status belum menjadi order → verifikasi kontak → penerimaan → timbang → persetujuan pelanggan → unggah bukti → pemeriksaan admin → pembayaran dan kuitansi → status sesuai saldo |

Pengirim lain yang mengetahui kode dan nomor pemilik tetap ditolak. Pengujian Paket A–D untuk LID, duplikasi kontak, grant usang, pembatasan percobaan, bukti transfer, dan kebocoran data tetap dijalankan.

Browser lokal memverifikasi simulator, Knowledge Base, serta empat halaman publik. Form spa dan laktasi terpisah; rental menampilkan produk dan pembayaran manual. **Konfigurasi lokal yang sudah ada** menunjukkan katalog laktasi belum memiliki layanan aktif dan pemesanan laundry publik belum dibuka. Kedua keadaan ditampilkan dengan benar; pengaturan usaha tidak diaktifkan diam-diam. Alur aktifnya dibuktikan pada fixture terisolasi di atas, bukan dengan mengubah konfigurasi/data pelanggan lokal.

Pemeriksaan desktop 1366×900 dan ponsel 390×844 menemukan header terlalu lebar pada ponsel. Avatar dekoratif disembunyikan pada layar kecil dan jarak header disesuaikan. Setelah perbaikan, halaman simulator/knowledge tidak meluap horizontal; ukuran browser dikembalikan setelah pengujian.

## 4. Penerimaan pesan, handoff, dan antrean

Penerimaan pesan kini mengunci ulang percakapan saat menyimpan sumber, memakai pembuatan pesan yang idempoten, serta menghitung unread dari baris terkini. Incoming tidak membuka kembali status `human_handled`, `closed`, atau `escalated`. Kendali admin yang berubah saat penerimaan tidak ditimpa snapshot lama.

Media yang belum diproses, termasuk foto/voice note yang dinormalisasi sebagai media, ditandai `unsupported_media` dan perlu diperiksa admin. AI dijeda apabila sebelumnya boleh menangani percakapan. Tidak ada klaim membaca media maupun pengiriman otomatis palsu. Resume tetap melalui tindakan admin Paket D.

Uji dua proses PHP pada MariaDB lokal membuktikan:

- sumber yang sama menghasilkan satu keputusan dan satu dispatch;
- replay pengiriman paralel menghasilkan satu outgoing, satu percobaan, dan satu penerimaan gateway palsu;
- takeover yang memegang row lock menahan generasi;
- webhook duplikat menghasilkan satu incoming, satu kenaikan unread, dan satu job generasi;
- incoming bersamaan dengan takeover mempertahankan status manusia.

Gangguan, unknown, replay, perubahan penerima, revisi, serta retry manual yang aman tetap diperiksa suite monitoring dan Paket A–D. Tidak ada worker atau gateway WhatsApp nyata dijalankan.

## 5. Database, histori legacy, dan integritas

`scripts/ai-e-engine-check.php` memiliki pengaman nama schema acak, port **33079**, bind loopback, dan datadir khusus `storage/app/ai-e-mariadb`. Runner tidak memakai database dari `.env` workspace. Schema uji dihapus setelah pemeriksaan; server MariaDB khusus dihentikan setelah selesai.

Migrasi fresh serta upgrade schema sebelum Paket A diuji pada **SQLite 3.51.3** dan **MariaDB 10.4.32 melalui driver mysql**. Data historis customer, service, booking, dan tabel bisnis dibandingkan; `paid` historis tidak membentuk uang masuk baru. Knowledge lama tetap `needs_review`, dan mutasi AI lama tetap terkunci. Ini memvalidasi keluarga engine MySQL/MariaDB lokal, **belum memvalidasi versi MySQL persis yang berjalan pada VPS**.

Rekonsiliasi lokal: **0 flow aktif, 1 flow held, 0 approval pending, 0 jobs, 0 failed_jobs**. Histori tidak dihapus atau diteruskan massal.

Backup sebelum perubahan: `storage/app/backups/ai-package-e-before-20261102-071534.sqlite`. Pemeriksaan integritas backup: `ok`. Perbandingan nilai baris/kolom lama pada **20 kelompok tabel** menunjukkan semuanya utuh. Tambahan lokal hanya **12 knowledge, 12 chunk, 12 relasi domain, dan 3 log simulator**. Jumlah customer, booking, pembayaran, rental, laundry, pesan WhatsApp, reminder, dan antrean tidak berubah. Migrasi menambahkan fokus katalog dan menaikkan revisi policy sesuai rancangan; aktivitas login/sesi audit pengujian terpisah dari perbandingan tabel bisnis tersebut.

## 6. Hasil pengujian

| Pemeriksaan | Hasil |
| --- | --- |
| Runtime aktif, tanpa grup `legacy-ai` | **731 tes / 6.225 assertion lulus**, 0 failure/error |
| Tes baru E: kualitas, perjalanan pelanggan, penerimaan pesan | **38 tes / 200 assertion lulus** |
| Gateway Node/Baileys | **12 tes lulus** |
| Engine SQLite | Fresh, upgrade, replay dan histori lulus |
| Engine MariaDB | Fresh, upgrade dan lima skenario paralel di atas lulus |
| Vite build | Lulus |
| PHP syntax, Pint pada daftar file eksplisit, diff check | Lulus |
| Kompatibilitas legacy terpisah | **116 tes / 612 assertion; 3 failure lama**, 0 error |

Dua kegagalan legacy akibat fixture reminder Juli yang menjadi masa lalu diperbaiki dengan membekukan waktu sebelum tanggal fixture. Fixture parsial Juni juga diberi waktu eksplisit. Assertion transport legacy untuk membuka kembali chat tertutup diperbarui menjadi mempertahankan chat tertutup, menambah pemeriksaan `closed_at`, unread, dan tidak ada balasan AI; perubahan ini sesuai kendali admin yang diuji pada runtime aktif.

Tiga failure yang tersisa berada pada:

1. `AiAutoReplyTest::test_service_choice_after_cancelled_context_starts_new_booking`;
2. `AiAutoReplyTest::test_partial_booking_data_is_merged_with_missing_service_reply_before_provider`;
3. `AiAutomationFoundationTest::test_active_booking_ambiguous_new_schedule_asks_for_booking_or_reschedule_clarification`.

Ketiganya mengharapkan dialog pengumpulan/konfirmasi booking melalui AI lama. Jalur tersebut hanya dapat dibuka oleh mock pada grup kompatibilitas; produksi tetap mengembalikan `allowsLegacyAutomation() = false`. Penanganan baru diarahkan ke web dan dibuktikan oleh suite aktif. Tidak ada assertion keamanan dihapus atau jalur transaksi lama dibuka untuk membuat pengujian hijau. Menjalankan seluruh grup sekaligus masih menampilkan ketiga failure tersebut; jangan menyebut seluruh suite tanpa pengecualian lulus.

Evaluasi baca saja 50 respons publik lokal: median **29,85 ms**, p95 **58,50 ms**, maksimum **87,77 ms**; query median 28, p95 77. Pengukuran satu proses ini tidak mencakup jaringan, antrean, perangkat WhatsApp, atau beban produksi. Tidak digunakan sebagai jaminan kapasitas VPS.

Artefak privat pada `storage/logs`: `ai-package-e-runtime.xml/.txt`, `ai-package-e-new.xml/.txt`, `ai-package-e-ingress.xml/.txt`, `ai-package-e-legacy-final.xml/.txt`, `ai-package-e-gateway.txt`, `ai-package-e-build.txt`, `ai-package-e-engine-{mysql,sqlite}.json`, `ai-package-e-local-report.json`, `ai-package-e-knowledge-readiness.json`, `ai-package-e-after.json`, serta gambar `ai-package-e-conversation-desktop.png` dan `ai-package-e-knowledge-mobile.png`.

## 7. Panduan uji admin dan batas rilis

1. Buka **Knowledge Base → Cakupan informasi per layanan** untuk melihat materi yang belum tersedia. Edit draft dan lengkapi ketentuan sebenarnya. Owner/manager menerbitkan materi yang sudah disetujui.
2. Buka **Simulator**, centang FAQ demo untuk contoh lokal. Coba `harga Stroller Bayi berapa, kebersihannya gmn dan cara bayarnya?`, kemudian `yang tadi brp?` atau `mau pesan yang itu`.
3. Coba `bukan rental, maksudnya laundry`, `layanannya apa aja?`, dan `admin dong`. Tindakan manusia serta batas klarifikasi harus menghentikan simulasi sampai dilanjutkan.
4. Untuk status, pilih profil demo A dan gunakan kode/nomor contoh milik A. Kode B dengan identitas A harus ditolak. Jangan memakai identitas pelanggan nyata pada simulator.
5. Lihat **AI Logs → Lihat alur** dan **Monitoring WhatsApp** untuk menelusuri keputusan/pengiriman. Demo monitoring tidak dapat mengirim pesan nyata.

Perintah pengujian utama:

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --group=legacy-ai
C:\xampp\php\php.exe scripts/ai-e-local-check.php --env=baileys-local
C:\xampp\php\php.exe artisan ai:audit-knowledge --env=baileys-local
```

PHPUnit wajib menggunakan `APP_ENV=testing`, SQLite `:memory:`, serta gateway palsu sesuai guard `tests/TestCase.php`. Jangan memakai `artisan test --env=baileys-local`. Script engine terpisah memiliki guard sendiri dan memerlukan server lokal khusus yang sesuai; jangan mengarahkannya ke instance usaha.

**Sebelum Paket F/rilis:** tinjau dan terbitkan FAQ nyata, lengkapi katalog laktasi, tentukan kesiapan outlet/pemesanan laundry, verifikasi versi engine/runtime target, lalu jalankan smoke test pada deployment Gayatri yang terisolasi. Pengujian WhatsApp perangkat nyata ditunda sesuai pilihan pengguna untuk memakai gateway simulasi dahulu. Tidak ada SSH/deploy, perubahan VPS, pesan pelanggan, panggilan provider AI berbayar, atau aktivasi scheduler pada Paket E. Web lokal tetap tersedia; gateway, queue worker dan scheduler tetap berhenti.
