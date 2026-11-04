# Paket A Chat AI — Audit dan Hasil Implementasi

| Informasi | Hasil |
| --- | --- |
| Tanggal | 1 November 2026 |
| Lingkup | Tahap 0–1 dari RANCANGAN_CHAT_AI_OTOMATIS.md |
| Lingkungan | Lokal: baileys-local, SQLite |
| Kebijakan aktif | information_web, revisi 1 |
| Laravel / PHP / Node lokal | 12.62.0 / 8.2.31 / v24.15.0 |
| Provider yang tersimpan | OpenRouter untuk AI; Baileys untuk WhatsApp |
| Pemanggilan provider pada alur baru | Tidak diperlukan; balasan sementara menggunakan template aplikasi |
| VPS | Belum diterapkan atau diperiksa melalui SSH dalam paket ini |

## 1. Hasil utama

Pemesanan dan perubahan transaksi melalui AI lama dinonaktifkan pada kode produksi. Pelanggan memperoleh petunjuk web serta bantuan admin. Koneksi gateway, inbox, transaksi manual admin, pembayaran dan notifikasi resmi modul tetap digunakan.

Tidak ada setting atau environment variable produksi yang dapat membuat AiInteractionPolicy mengizinkan mutasi legacy. Mode yang sah hanya information_web dan off. Kebijakan yang hilang, rusak, atau tidak dapat dibaca menyebabkan AI berhenti mengirim.

Paket ini merupakan fondasi keamanan. Router/menu interaktif, knowledge per domain, dan pengecekan status terverifikasi belum diterapkan; semuanya tetap mengikuti paket berikutnya.

## 2. Audit jalur dan pembatasannya

| Jalur yang diperiksa | Pengamanan |
| --- | --- |
| Webhook → ProcessIncomingWhatsAppMessageJob | Inbox tetap tercatat; tidak meneruskan ekstraksi transaksi pada mode baru |
| GenerateAiReplyJob | Memeriksa policy, pelanggan, ownership pesan, assignment admin dan takeover |
| ConversationFlowService | Tidak memulai/melanjutkan booking atau reschedule; flow aktif lama ditahan |
| ExtractDataFromIncomingMessageJob | Berhenti sebelum ekstraksi/dispatch automation |
| ProcessAiAutomationRuleJob | Ditahan sebelum memilih dan meneruskan job mutasi |
| CreateBookingFromAiDataJob | Runtime gate sebelum executor |
| CreateCustomerFromAiDataJob | Runtime gate sebelum perubahan CRM |
| UpdateCustomerFromAiDataJob | Runtime gate; tidak mengubah nomor/profil/status pelanggan |
| CreateFollowupFromAiDataJob | Tidak membuat followup dari ekstraksi AI |
| CreateReminderFromAiDataJob | Tidak membuat reminder dari keputusan AI |
| CreateAiAutomationApprovalJob | Tidak membuat approval baru dari automation |
| AiAutomationExecutorService::process / processWithRule | Gate sebelum seluruh efek samping, termasuk perubahan profil saat menunggu konfirmasi |
| AiAutomationApprovalService::create | Tidak membuat approval transaksi AI |
| AiAutomationApprovalService::approve / reject | Ditolak dengan validation error; catatan pending ditahan, booking tidak diubah |
| Endpoint approve / edit-approve / reject | Menggunakan service yang sama; tidak menjadi jalan alternatif |
| AiSimulatorController / AiService preview/simulate | Balasan informasi; tidak meneruskan flow, ekstraksi, provider atau lookup privat lama |
| AiAutomationTestController | Analisis/pratinjau aturan saja; tidak mengeksekusi transaksi |
| SendWhatsAppMessageJob / OutboundMessages | Validasi izin balasan AI saat claim dan tepat sebelum send |
| Retry inbox / monitoring | Memakai pemeriksaan konteks AI yang sama; pesan legacy/usang ditolak |
| Reminder AI yang sudah terjadwal | Sumber ai_automation ditahan sebelum send, termasuk replay job |
| Scheduler reminder/campaign/birthday/rental/laundry | Jalur resmi modul dipertahankan; bukan pemberian izin untuk automation AI |
| Dashboard, daftar booking dan approval | Pembaca data/tautan tetap ada; approval legacy tidak dapat memutasi melalui service |
| CustomerService penghapusan data | Tetap mengikuti perlindungan CRM sebelumnya; tidak dipakai sebagai mekanisme migrasi |

Pencatatan lead minimum untuk inbox berbeda dari perubahan profil hasil tebakan AI. Paket ini memblokir jalur kedua, termasuk upaya mengaktifkan ulang pelanggan inactive/blocked.

## 3. Komponen baru dan schema

- AiInteractionPolicy: pembacaan kebijakan langsung dari database, gate automation dan izin pengiriman.
- AiInformationReply: balasan sementara dengan tujuan web dari route resmi dan APP_URL.
- AiLegacyHold: menahan flow serta approval dan reminder AI lama tanpa membatalkan booking.
- SetAiInteractionMode: perintah pemeriksaan/pengubahan mode aman.
- ai_interaction_policies: mode dan revision untuk worker yang berjalan lama.
- messages.ai_reply_context: sumber pesan, pemilik, sesi/provider, hash teks, revisi dan masa berlaku izin.
- messages.ai_reply_dispatched_at: mencegah replay job generate mengantrekan balasan kedua.
- conversations.ai_control_revision: bertambah secara atomik saat kontrol, tujuan, pemilik atau sesi percakapan berubah.

Migrasi lokal yang diterapkan:

1. 2026_11_01_000002_secure_ai_interactions.php
2. 2026_11_01_000003_add_ai_control_revision.php

Metadata balasan AI tidak ditampilkan melalui serialisasi Message. Riwayat lama dan konten transaksi tidak dihapus.

## 4. Aturan pengiriman yang sudah berlaku

Balasan AI ditahan jika:

- AI global atau mode kebijakan dimatikan.
- Kebijakan tidak bisa diverifikasi.
- Percakapan diambil alih, ditutup, atau ditugaskan ke admin.
- Nomor tujuan, pelanggan, provider, atau sesi berubah.
- Pesan pemicu tidak sesuai pemilik/percakapan atau sudah kedaluwarsa.
- Ada pesan pelanggan atau balasan admin yang lebih baru.
- Revisi kebijakan atau kontrol percakapan berubah.
- Teks tidak cocok dengan hash izin.
- Job lama tidak membawa konteks izin baru.

Masa berlaku awal balasan: 15 menit. Pengaktifan kembali AI setelah takeover tidak mengizinkan backlog lama. Penyimpanan pengaturan balasan admin menaikkan policy revision sehingga job yang dibuat sebelum perubahan tidak ikut aktif kembali.

Pemberitahuan handoff yang dibuat aplikasi dapat dikirim sekali sebelum percakapan diserahkan, dengan konteks yang sesuai. Takeover admin berikutnya tetap membatalkan izin tersebut.

Outbox, dispatch token dan aturan hasil unknown dipertahankan. Sistem tidak mengklaim exactly-once lintas jaringan atau dapat menarik kembali pesan yang sudah diterima gateway.

## 5. Perilaku sementara di dashboard dan chat

- Dashboard AI menampilkan pemberitahuan mode dan pembatasan transaksi lama.
- Tombol approve, edit-approve dan reject legacy disembunyikan pada halaman review.
- Endpoint-nya tetap memeriksa kebijakan meskipun dipanggil langsung.
- Chat menampilkan link booking spa dan laktasi.
- Link rental diberikan sesuai pengaturan aktif.
- Link laundry diberikan hanya jika pemesanan publik dan penerimaan outlet beserta kontak/alamat siap.
- Link dibangun dari konfigurasi aplikasi, bukan Host request atau URL keluaran model.
- Nomor/nama/alamat pelanggan tidak disisipkan ke query string.
- Template/instruksi/rule lama tidak dapat menyisipkan klaim konfirmasi atau melewati pembatasan.
- Permintaan admin menghentikan AI dan mempertahankan unread untuk ditinjau.
- Status privat sementara diarahkan ke admin sampai Paket C selesai.

Pengaturan konten AI lama tetap tersimpan. Penerapannya kembali ke balasan harus melalui pemisahan domain dan peninjauan pada Paket B; banner dashboard menjelaskan keadaan sementara ini.

## 6. Hasil pengamanan database lokal

Backup konsisten dibuat sebelum perubahan:

~~~text
storage/app/backups/ai-package-a-before-20261101-174434.sqlite
~~~

Hasil pemeriksaan backup: integrity_check = ok.

| Data | Sebelum | Setelah aktivasi |
| --- | --- | --- |
| Pelanggan | 18 | 18 |
| Booking | 4 | 4 |
| Jurnal pembayaran booking | 2 | 2 |
| Order rental | 9 | 9 |
| Order laundry | 4 | 4 |
| Pesan inbox | 12 | 12 |
| Pesan keluar tersimpan | 8 | 8 |
| Job aktif | 0 | 0 |
| Flow legacy aktif | 1 | 0; ditahan sebagai held |
| Approval pending | 0 | 0 |

Perbandingan seluruh kolom asli dengan backup menunjukkan row pelanggan, booking, pembayaran, rental, laundry, messages, reminders, app_settings dan jobs identik. Perubahan yang disengaja adalah schema tambahan, baris kebijakan baru, dan satu flow legacy yang ditahan dengan payload historis tetap tersimpan.

Flag rental tetap aktif. Pengaturan laundry publik/outlet yang belum disimpan tetap memakai default nonaktif. Tidak ada worker/gateway/scheduler yang dinyalakan oleh pengerjaan ini.

Backup berisi data privat. Pemulihan dilakukan pada salinan database atau saat aplikasi/worker lokal dihentikan secara terkendali; jangan menimpa database aktif yang sudah mempunyai transaksi baru.

## 7. Bukti pengujian

Baseline dengan isolasi jaringan:

- 239 test, 1.460 assertion.
- 5 kegagalan lama direproduksi sebelum perubahan aplikasi.
- HTTP eksternal pada test dibatasi melalui preventStrayRequests.
- Default provider test adalah local; endpoint provider yang diuji menggunakan fake.
- Delay indikator mengetik dinonaktifkan pada konfigurasi test.

Hasil akhir:

| Kelompok | Test | Assertion | Hasil |
| --- | --- | --- | --- |
| Seluruh runtime aktif, tanpa legacy-ai | 571 | 5.361 | Lulus, 0 failure/error |
| AiInteractionPolicyTest, bagian dari runtime aktif | 42 | 249 | Lulus, 0 failure/error |
| Kompatibilitas historis legacy-ai | 116 | 608 | 5 kegagalan baseline yang sama, 0 error |

Pint untuk 41 file PHP yang disentuh lulus. Pemeriksaan sintaks seluruh file tersebut dan git diff --check juga lulus.

Suite aktif mencakup CRM, booking/manual payment, fee therapist, home service, rental, laundry, portal, notifikasi, inbox, gateway dan monitoring. Blade banner serta pembatasan tombol approval diverifikasi melalui request dashboard pada feature test.

Perubahan terakhir yang diuji termasuk pengaktifan ulang sesudah takeover, disable/enable AI global, assignment admin, pergantian sesi/provider dan pembacaan pengaturan terbaru oleh worker.

Artefak lokal privat:

- storage/logs/ai-package-a-baseline-safe.xml
- storage/logs/ai-package-a-runtime-final.xml
- storage/logs/ai-package-a-legacy-final.xml
- storage/logs/ai-package-a-policy.xml
- storage/logs/ai-package-a-pint.txt
- storage/logs/ai-package-a-files.json
- storage/logs/ai-package-a-before.json
- storage/logs/ai-package-a-after.json

### Perlakuan test legacy

Test perilaku chat transaksi terdahulu tetap tersedia dalam group legacy-ai. Test tersebut memakai mock yang hanya hidup di folder tests untuk menguji kode historis. Tidak ada bypass yang diaktifkan melalui konfigurasi aplikasi.

Lima kegagalan historis yang masih diketahui:

1. AiAutoReplyTest::test_service_choice_after_cancelled_context_starts_new_booking — perbedaan kalimat yang diharapkan.
2. AiAutoReplyTest::test_partial_booking_data_is_merged_with_missing_service_reply_before_provider — konteks tanggal/jam lama.
3. AiAutomationFoundationTest::test_active_booking_ambiguous_new_schedule_asks_for_booking_or_reschedule_clarification — klarifikasi booking/reschedule lama.
4. AiAutomationReminderRuleTest::test_reminder_uses_raw_ai_response_followup_scheduled_at_when_extracted_followup_data_missing — fixture tanggal tetap yang sudah lewat.
5. AiAutomationReminderRuleTest::test_reminder_uses_raw_ai_response_reminder_scheduled_at_when_followup_is_empty — fixture tanggal tetap yang sudah lewat.

Kasus-kasus tersebut tidak dijadikan alasan untuk membuka kembali jalur legacy. Perilaku aktif penggantinya diuji pada AiInteractionPolicyTest. Tidak ada test yang dihapus untuk menyembunyikan kegagalan; suite penuh tetap akan memperlihatkan 5 kegagalan historis sampai perawatan kompatibilitas tersebut dikerjakan.

## 8. Perintah operasional lokal

Dari root workspace:

~~~powershell
# Melihat mode; tidak mengubahnya
& 'C:\xampp\php\php.exe' artisan ai:interaction-mode --env=baileys-local

# Mematikan balasan AI, tetap menahan automation lama
& 'C:\xampp\php\php.exe' artisan ai:interaction-mode off --env=baileys-local

# Mengaktifkan alur informasi/web dan menahan item legacy
& 'C:\xampp\php\php.exe' artisan ai:interaction-mode information_web --env=baileys-local

# Suite perilaku aktif
& 'C:\xampp\php\php.exe' vendor/phpunit/phpunit/phpunit --exclude-group=legacy-ai

# Pemeriksaan historis terpisah; masih ada 5 kegagalan baseline
& 'C:\xampp\php\php.exe' vendor/phpunit/phpunit/phpunit --group=legacy-ai
~~~

Perintah mode tidak mengirim pesan, membuat transaksi, menyalakan gateway, atau melakukan bulk retry.

## 9. Batas verifikasi dan langkah berikutnya

- Paket A diterapkan di SQLite lokal. MySQL/MariaDB VPS belum diuji ulang.
- Pengiriman melalui nomor WhatsApp nyata tidak diuji dalam paket ini.
- Koneksi gateway hidup bukan syarat test keamanan; semua gateway pada suite menggunakan fake.
- Uji dua proses terhadap race condition engine produksi masih termasuk tahap stabilisasi berikutnya.
- Perubahan ini belum di-deploy ke VPS.
- Pekerjaan berikutnya: Paket B, yaitu router/menu empat layanan, knowledge yang memiliki scope, serta pengarahan web sesuai kemampuan modul.

Pengamanan Paket A harus tetap berlaku selama dan setelah paket berikutnya.
