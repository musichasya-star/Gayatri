# Paket C — Status transaksi dengan verifikasi pemilik

Tanggal: 1 November 2026. Status: selesai di lokal, belum rilis VPS.

## Hasil

Chat dapat membaca status booking Pijat/Spa dan Laktasi, rental, order laundry, serta permintaan laundry publik yang belum menjadi order. Tidak ada pembuatan atau perubahan transaksi melalui AI. Gate Paket A dan scope Knowledge Base Paket B tetap berlaku.

Verifikasi harus memenuhi ketiganya: **kode tepat + nomor pemesanan + identitas WhatsApp pengirim**. Mengetahui kode dan nomor pihak lain tidak memberikan akses. Seluruh query order dibatasi pemilik dan penanda demo; tidak ada alternatif `OR conversation_id` untuk melewati pemilik.

## Knowledge Base demo terlebih dahulu

Sebelum pengerjaan lookup, dibuat 30 FAQ, masing-masing enam untuk `spa`, `lactation`, `rental`, `laundry`, dan `general`. Topik mencakup persiapan, pendamping, pilihan layanan, alamat home service, kelengkapan/kebersihan alat, keranjang, pengembalian/deposit, pewangi/noda, penimbangan, antar-jemput, QC, pembayaran manual, portal, kontak admin dan privasi.

- Semua diberi judul `[DEMO]` dan `is_demo=true`.
- Demo dapat diambil hanya lewat pilihan **Sertakan FAQ demo (khusus uji lokal)** pada simulator lokal/testing.
- Balasan WA nyata mengecualikan demo; pemeriksaan evidence juga menolak demo agar tidak terkirim melalui antrean/retry.
- Tarif diambil dari katalog aktif. FAQ contoh tidak mengarang harga, ketentuan khusus, jam outlet, maupun klaim medis.
- Lima draft Paket B dan satu knowledge lama `needs_review` tetap utuh. Database lokal sekarang memuat 36 knowledge.
- Untuk konten operasional, owner/manager perlu meninjau materi dan membuat FAQ final non-demo dengan scope, audience dan publikasi yang tepat. Mengedit baris DEMO tidak menghilangkan pembatasan demo.

Command idempoten, ditolak pada production:

```powershell
C:\xampp\php\php.exe artisan ai:seed-knowledge-demo --env=baileys-local
C:\xampp\php\php.exe artisan ai:seed-lookup-demo --env=baileys-local
```

## Cara mencoba di dashboard

1. Buka [Knowledge Base lokal](http://127.0.0.1:8000/admin/ai/knowledge-base). Baris contoh mempunyai badge **DEMO · simulator saja**. Filter per layanan tersedia.
2. Buka [AI Simulator lokal](http://127.0.0.1:8000/admin/ai/simulator), centang **Sertakan FAQ demo**.
3. Uji pertanyaan misalnya `Apa persiapan pijat spa?`, `Apa persiapan konsultasi laktasi?`, `Bagaimana kelengkapan alat rental?`, atau `Bagaimana pewangi laundry untuk bayi?`. Periksa domain dan sources.
4. Untuk status, pilih **[DEMO] AI Status A · 6280000000901** sebagai identitas pengirim.
5. Kirim `Cek status BK-DEMO-C-SPA-A 080000000901`. Ringkasan booking dan pembayaran historis tampil. Bisa juga kirim `cek status`, pilih layanan, kirim kode, kemudian nomor pada giliran berikutnya.
6. Kirim `Cek status BK-DEMO-C-SPA-B 080000000901` dengan profil A. Hasil harus ditolak secara generik, tanpa detail transaksi B.
7. Kirim `menu`/`batal` untuk menghapus konteks lookup, atau `admin` untuk penyerahan ke admin. Ini tidak membatalkan transaksi.

| Profil | Nomor contoh | Kode contoh |
| --- | --- | --- |
| A | 6280000000901 / 080000000901 | BK-DEMO-C-SPA-A, BK-DEMO-C-ASI-A, RENT-DEMO-C-A, LND-DEMO-C-A |
| B | 6280000000902 / 080000000902 | BK-DEMO-C-SPA-B, BK-DEMO-C-ASI-B, RENT-DEMO-C-B, LND-DEMO-C-B |

Contoh kode permintaan laundry sebelum menjadi order: profil A `c0000001-0000-4000-8000-000000000001`, profil B `c0000002-0000-4000-8000-000000000002`. Nomor ini hanya fiksi lokal, bukan nomor untuk dihubungi.

Dua pelanggan demo berstatus inactive dan terdaftar dalam allowlist simulator. Lookup live secara eksplisit menolak pelanggan pada allowlist tersebut. Semua order contoh berpenanda demo. Tidak ada pembayaran, token portal, stok unit, pengiriman, reminder, atau campaign yang dibuat untuk contoh ini.

## Identitas dan privasi

- Controller webhook memvalidasi secret sesuai provider terlebih dahulu lalu memberi marker internal; payload pelanggan tidak dapat menyediakan bukti sender sendiri.
- Message key phone JID, `remoteJidAlt`, atau pasangan PN/LID dari cache Baileys menjadi sumber identitas. Angka pada LID tidak dianggap nomor telepon.
- Pemetaan diikat ke provider dan sesi. Identitas berselisih/di-revoke/kedaluwarsa ditolak. Konflik nomor tidak otomatis menimpa mapping atau menggabungkan CRM.
- Bukti sumber menggunakan HMAC yang mengikat ID pesan, conversation, pelanggan, ID gateway dan revisi identitas. Bukti dari pesan lain tidak dapat disalin.
- Nomor 08/+62 dinormalisasi. CRM harus memiliki tepat satu pemilik aktif/lead; nomor ganda, inactive/blacklisted, nomor berubah atau snapshot berbeda ditolak.
- Booking baru menyimpan salinan kontak. Booking manual lama tanpa salinan tidak otomatis dipercaya; admin memeriksa identitas lalu menyimpan verifikasi pada halaman transaksi booking. Tidak ada backfill kepemilikan massal.
- Rental memakai kontak notifikasi terverifikasi bila ada, selain itu snapshot pemesan; kontak notifikasi yang terisi tetapi belum diverifikasi ditolak. Laundry memakai snapshot dan kontak permintaan publik yang terkait.
- Balasan hanya memuat kode, nama layanan/alat, tanggal/periode, status proses, saldo yang sesuai jurnal dan status antar-jemput/permintaan terkait. Tidak memuat alamat lengkap, catatan kesehatan, file bukti, nomor lengkap pemesan, atau tautan/token privat.
- Lookup tidak membuat token/tautan portal. Bantuan portal dan perubahan transaksi tetap melalui alur verifikasi modul/admin yang sudah tersedia.

Landasan teknis: [migrasi Baileys v7](https://github.com/WhiskeySockets/docs/blob/main/migration/v7.mdx) menjelaskan `remoteJidAlt` serta keterbatasan pemetaan balik LID ke PN; [OWASP IDOR Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html) menekankan pemeriksaan pemilik pada setiap akses objek. Implementasi memakai pasangan yang sudah diketahui, bukan pencarian nomor balik yang tidak tersedia.

## Akurasi status dan antrean

`BookingStatusReader`, `RentalStatusReader`, dan `LaundryStatusReader` hanya membaca data bisnis. Booking memakai jurnal pembayaran/refund non-void dan saldo historis terpisah; status lunas lama tidak menjadi uang masuk baru. Rental memperhitungkan deposit, pembayaran/refund, biaya, periode, status pengembalian dan permintaan perpanjangan/pembatalan. Laundry membedakan estimasi, hasil timbang/persetujuan, pengerjaan, bukti pending, pembayaran non-void dan permintaan awal yang belum menjadi order.

- Konteks kode disimpan terenkripsi, berlaku 10 menit, terikat identitas dan revisi kendali percakapan.
- Hasil privat mempunyai grant terenkripsi maksimum lima menit. Sebelum kirim/retry, sistem memeriksa ulang identitas, scope, snapshot, status/saldo dan fingerprint hasil.
- Perubahan transaksi/pemilik, takeover, pesan baru, pergantian provider/sesi atau kebijakan menahan balasan lama. `unknown` tidak diulang otomatis.
- Maksimum lima percobaan lengkap per nomor per 10 menit, 100 per sesi dan 500 global. Counter tersimpan di DB dan penambahan memakai transaksi/lock; kegagalan penyimpanan menghentikan lookup.
- Audit percobaan hanya menyimpan digest nomor, ID sumber dan hasil. Nomor/kode/prompt privat serta grant tidak disalin ke AI Log. Ringkasan yang sah tetap berada di inbox asal sesuai hak akses yang ada.
- `ai:prune-lookups` dijadwalkan per jam untuk membuang konteks/counter lama serta audit lebih dari 30 hari. Scheduler tidak dinyalakan selama pengerjaan.
- Angka pilihan domain saat lookup tidak ditangkap sebagai rating feedback.

## Implementasi utama

- Migrasi `2026_11_01_000005_add_ai_lookup_security.php` dan `2026_11_01_000006_add_booking_lookup_contact.php`.
- `app/Services/WhatsApp/WhatsAppSenderIdentity.php`, metadata pada `services/baileys/src/normalize.mjs`, webhook dan job inbound.
- `app/Services/AI/AiTransactionLookup.php`, tiga status reader, `LookupContact`, `AiLookupLimiter`, context/evidence dan orchestrator.
- Simulator, badge Knowledge Base, verifikasi kontak booking lama dan command demo/retensi.
- `tests/Feature/AiTransactionLookupTest.php`, pengujian metadata/failure cache gateway, dan pengaman database pada `tests/TestCase.php`/`phpunit.xml`.

## Bukti verifikasi

| Pemeriksaan | Hasil |
| --- | --- |
| Seluruh runtime aktif, tanpa kelompok legacy-ai | **655 tes / 5.738 assertion lulus**, nol failure/error |
| Fokus Paket C setelah penyempurnaan teks fallback | **37 tes / 192 assertion lulus** |
| Gateway Node dengan socket/HTTP palsu | **12 tes lulus** |
| Kelompok legacy-ai terisolasi | 116 tes / 608 assertion, lima failure historis yang sama dengan Paket B, nol error baru |
| Browser lokal | FAQ empat domain; status booking sah; kode pihak lain ditolak; status laundry dua giliran berhasil |
| Integritas data asli | 15 kelompok tabel asli dibandingkan backup: seluruh baris lama sama |
| Uji negatif konfigurasi tes mewarisi environment/database lokal | Dihentikan oleh LogicException sebelum RefreshDatabase; data asli kembali dibandingkan dan tetap utuh |
| Formatter PHP dan git diff --check | Lulus; peringatan normalisasi CRLF pada file lama tidak mengubah hasil pemeriksaan whitespace |

Artefak lokal pada `storage/logs`: `ai-package-c-runtime.xml/.txt`, `ai-package-c-focused.xml/.txt`, `ai-package-c-legacy.xml/.txt`, `ai-package-c-gateway.txt`, `ai-package-c-after.json`, `ai-package-c-knowledge.png`, `ai-package-c-faq.png`, `ai-package-c-status-verified.png`, `ai-package-c-status-denied.png`, dan `ai-package-c-laundry.png`.

Lima failure legacy tetap berada pada dua skenario konteks booking `AiAutoReplyTest`, satu klarifikasi `AiAutomationFoundationTest`, dan dua fixture reminder Juli yang sudah lewat pada `AiAutomationReminderRuleTest`. Jalur legacy tetap tertutup pada runtime; ini bukan laporan seluruh tes legacy lulus.

### Insiden pengujian lokal dan pemulihan

Pemanggilan awal `artisan test --env=baileys-local` membawa variabel database proses induk dan `RefreshDatabase` mengosongkan SQLite lokal. Pengujian dihentikan dari jalur tersebut. Backup penuh **`storage/app/backups/ai-package-c-before-20261101-193710.sqlite`**, dibuat sebelum semua perubahan, dipulihkan saat web lokal dihentikan sebentar. Salinan keadaan insiden disimpan terpisah, migrasi C diterapkan ulang dan data demo dibuat ulang.

Pemulihan diverifikasi terhadap kolom/ID seluruh baris lama pada 15 kelompok tabel bisnis/knowledge/pengaturan. Pelanggan asli 18, booking asli empat, pembayaran booking dua, rental asli sembilan, order laundry asli empat, pesan 12/outgoing delapan, reminder satu dan job nol tetap utuh. Tambahan yang disengaja hanya dua pelanggan, empat booking demo, dua rental demo, dua order dan dua permintaan laundry demo serta 30 FAQ/chunk/domain demo. Tidak ada entri pembayaran baru.

Pengaman permanen: konfigurasi PHPUnit menetapkan `APP_ENV=testing`, SQLite `:memory:` dan DB_URL kosong dengan `force=true`; `TestCase::createApplication` menolak lingkungan/database lain **sebelum** trait `RefreshDatabase` berjalan. Uji negatif dengan environment/database lokal yang diwariskan dari proses induk benar-benar dihentikan oleh guard tersebut (exit gagal yang diharapkan, tanpa refresh database), dan 15 kelompok baris asli tetap utuh setelahnya. Untuk menjalankan tes gunakan langsung:

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai
```

## Batas pengujian dan kelanjutan

Pengerjaan ini hanya lokal. Tidak ada SSH/deploy/perubahan VPS, panggilan provider AI berbayar, pesan WhatsApp nyata, worker, gateway atau scheduler yang dinyalakan. Web lokal saja berjalan di `127.0.0.1:8000`. Pengaturan publik Laundry tetap mengikuti konfigurasi lama, tidak diaktifkan otomatis oleh seed.

Uji perangkat WhatsApp sungguhan, akun/nomor yang berganti pemilik, antrean beberapa worker dan perilaku lock/migrasi MySQL aktual belum dijalankan. Bukti sender mengasumsikan secret webhook dan perangkat sesi tetap dikuasai pihak yang sah; ini bukan OTP atau verifikasi identitas sipil.

Berikutnya **Paket D**: penyempurnaan kendali admin/handoff dan penelusuran incoming → keputusan → pengiriman pada monitoring, kemudian UAT/regression Paket E sebelum rilis VPS terpisah pada Paket F.
