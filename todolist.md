# Todo List Implementasi Gayatri CRM WhatsApp AI

Dokumen ini dibuat dari analisis mendalam `PRD.md` dan kondisi project saat ini. Project sudah berupa Laravel 12 awal dengan route admin, auth controller, middleware role/active, dan field user CRM sebagian sudah ada. Modul utama PRD seperti customer, WhatsApp inbox nyata, WAHA service, AI management, booking, reminder, follow-up, promo, campaign, report, dan audit log masih perlu dibangun bertahap.

## Prinsip Pengerjaan

- Kerjakan bertahap dari fondasi data ke flow bisnis inti, bukan langsung semua fitur.
- Prioritaskan MVP yang bisa berjalan normal: login, data master, WhatsApp connect, inbox, reply manual, AI dasar, booking, reminder, follow-up, dan laporan dasar.
- Semua konfigurasi penting wajib lewat `.env` dan `config`, bukan hardcode.
- Semua proses berat wajib queue: kirim WhatsApp, AI reply, reminder, campaign, extraction automation.
- Semua aksi penting wajib audit log: login, CRUD data penting, booking, campaign, AI automation approval.
- AI wajib aman: hanya gunakan knowledge base aktif, fallback jika tidak yakin, dan eskalasi topik medis/sensitif ke admin.

## Status Awal Project

- [x] Laravel project sudah ada.
- [x] `composer.json` menggunakan Laravel 12 dan PHP 8.2.
- [x] Route `/login`, `/logout`, dan `/admin/dashboard` sudah ada.
- [x] Middleware `active` dan `role` sudah ada.
- [x] Model `User` sudah memiliki field `phone`, `role`, `status`, dan `last_login_at`.
- [ ] Route admin saat ini masih banyak memakai placeholder view.
- [x] Model domain CRM sudah tersedia.
- [x] Migration domain CRM sudah tersedia.
- [ ] Service class WAHA, AI, booking, reminder, campaign belum tersedia.
- [ ] Queue job dan scheduler flow bisnis belum tersedia.
- [ ] UI dashboard masih perlu dihubungkan ke data nyata.

## Phase 0 - Stabilkan Fondasi Project

- [x] Jalankan `composer install` jika dependency belum lengkap.
- [x] Pastikan `.env` ada dan `APP_KEY` sudah dibuat.
- [x] Konfigurasi database lokal di `.env`.
- [x] Jalankan `php artisan migrate` untuk memastikan migration awal sukses.
- [x] Jalankan `php artisan test` sebagai baseline.
- [x] Tambahkan env PRD ke `.env.example`: `WAHA_BASE_URL`, `WAHA_API_KEY`, `WAHA_DEFAULT_SESSION`, `WAHA_WEBHOOK_SECRET`, `AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL`, `CRM_DEFAULT_AI_MODE`, `CRM_AI_CONFIDENCE_THRESHOLD`, `CRM_REMINDER_H1_ENABLED`, `CRM_REMINDER_H0_ENABLED`, `CRM_CAMPAIGN_RATE_LIMIT_SECONDS`, dan env AI automation.
- [x] Buat file config `config/waha.php`, `config/ai.php`, dan `config/crm.php`.
- [x] Pastikan middleware alias `active` dan `role` terdaftar di bootstrap Laravel 12.
- [x] Pastikan route admin hanya bisa diakses user login dan aktif.
- [x] Buat layout admin utama yang reusable: sidebar, topbar, content, flash message, dan error state.

Definition of done Phase 0:

- [x] Aplikasi bisa dibuka tanpa error.
- [x] Login/logout berjalan.
- [x] User nonaktif tidak bisa masuk.
- [x] Route admin terlindungi middleware.
- [x] Config penting tidak hardcoded.

## Phase 1 - Auth, Role, User, Seeder

- [x] Rapikan auth controller agar update `last_login_at` saat login berhasil.
- [x] Buat enum/constant role: `owner`, `manager`, `admin`, `sales`, `therapist`.
- [x] Buat enum/constant user status: `active`, `inactive`.
- [x] Buat policy atau helper permission untuk matrix akses dari PRD.
- [x] Buat halaman user management untuk owner/manager.
- [x] Implement create, edit, activate, deactivate user.
- [x] Buat seeder user awal: owner, admin, sales, terapis dengan password `password`.
- [x] Tambahkan test login role utama.

Definition of done Phase 1:

- [x] Owner bisa membuat user.
- [x] Menu tampil sesuai role.
- [x] User inactive otomatis ditolak login.
- [x] Seeder membuat akun demo sesuai PRD.

## Phase 2 - Database Schema Utama CRM

- [x] Buat migration dan model `Customer`.
- [x] Buat migration dan model `Branch`.
- [x] Buat migration dan model `Service`.
- [x] Buat migration dan model `Therapist`.
- [x] Buat migration dan model `WhatsAppSession`.
- [x] Buat migration dan model `Conversation`.
- [x] Buat migration dan model `Message`.
- [x] Buat migration dan model `Booking`.
- [x] Buat migration dan model `Reminder`.
- [x] Buat migration dan model `Followup`.
- [x] Buat migration dan model `Promo`.
- [x] Buat migration dan model `Campaign`.
- [x] Buat migration dan model `CampaignRecipient`.
- [x] Buat migration dan model `AiPersona`.
- [x] Buat migration dan model `KnowledgeBase`.
- [x] Buat migration dan model `KnowledgeChunk`.
- [x] Buat migration dan model `AiLog`.
- [x] Buat migration dan model `Feedback`.
- [x] Buat migration dan model `AuditLog`.
- [x] Tambahkan index penting: phone/whatsapp_number, booking date/time, conversation last_message_at, message wa_message_id, campaign status, reminder scheduled_at.
- [x] Tambahkan relationship antar model.
- [x] Buat enum/constant status untuk customer, booking, payment, conversation, reminder, campaign, AI log.
- [x] Buat seeder branch, service, therapist demo, AI persona default, knowledge base default.

Definition of done Phase 2:

- [x] `php artisan migrate:fresh --seed` sukses.
- [x] Semua tabel PRD MVP tersedia.
- [x] Data demo cukup untuk testing dashboard, booking, AI, dan WhatsApp.

## Phase 3 - Master Data CRM

- [x] Buat controller, request validation, service, dan view Customer CRUD.
- [x] Buat customer list dengan search, filter status, filter tag, pagination.
- [x] Buat customer detail dengan tab overview, conversations, bookings, follow-ups, campaigns, feedback, notes.
- [x] Buat import CSV sederhana untuk customer.
- [x] Buat export CSV sederhana untuk customer.
- [x] Buat controller, request, service, dan view Service CRUD.
- [x] Buat controller, request, service, dan view Branch CRUD.
- [x] Buat controller, request, service, dan view Therapist CRUD.
- [x] Terapkan permission per role pada semua menu master data.
- [x] Simpan audit log untuk create, update, delete/archive data penting.

Definition of done Phase 3:

- [x] Admin bisa mengelola customer.
- [x] Admin bisa mengelola layanan, cabang, dan terapis.
- [x] Customer detail menampilkan histori kosong atau data terkait tanpa error.
- [x] Role therapist hanya melihat data yang relevan.

## Phase 4 - WhatsApp Gateway WAHA

- [x] Buat `app/Services/WhatsApp/WahaService.php`.
- [x] Implement method start session, stop session, logout session, get QR, get status, send text.
- [x] Buat controller admin WhatsApp session.
- [x] Ganti route placeholder `/admin/whatsapp/session` menjadi controller nyata.
- [x] Buat halaman WhatsApp Gateway: status koneksi, tombol start, stop, logout, QR code, dan warning disconnect.
- [x] Buat endpoint webhook `POST /webhooks/waha/messages`.
- [x] Buat endpoint webhook `POST /webhooks/waha/status`.
- [x] Validasi webhook dengan `WAHA_WEBHOOK_SECRET` atau API key.
- [x] Buat `ProcessIncomingWhatsAppMessageJob`.
- [x] Saat pesan masuk, cari atau buat customer berdasarkan nomor WhatsApp.
- [x] Saat pesan masuk, cari atau buat conversation berdasarkan `wa_chat_id`.
- [x] Simpan pesan incoming ke tabel messages.
- [x] Update conversation unread count dan last message timestamp.
- [x] Buat `SendWhatsAppMessageJob` untuk outbound message.
- [x] Simpan pesan outgoing setelah reply admin atau AI.

Definition of done Phase 4:

- [x] Admin bisa start session WAHA.
- [x] QR code muncul untuk scan.
- [x] Status connected/disconnected tampil benar.
- [x] Webhook pesan masuk membuat customer, conversation, dan message.
- [x] Pesan keluar tersimpan di database.

## Phase 5 - WhatsApp Inbox CRM

- [x] Buat inbox controller nyata.
- [x] Buat conversation list dengan search dan filter unread, need follow-up, AI handled, assigned admin.
- [x] Buat chat window dengan bubble incoming/outgoing.
- [x] Buat customer sidebar: profil, status, booking terakhir, follow-up task, catatan.
- [x] Buat form manual reply admin.
- [x] Kirim manual reply melalui `SendWhatsAppMessageJob`.
- [x] Implement human takeover: ubah conversation menjadi `human_handled` dan `ai_enabled=false`.
- [x] Implement close conversation.
- [x] Implement mark as follow-up.
- [x] Implement internal note sederhana.
- [x] Tambahkan auto-refresh sederhana atau polling untuk chat baru.

Definition of done Phase 5:

- [x] Chat masuk tampil di inbox.
- [x] Admin bisa membuka detail conversation.
- [x] Admin bisa membalas dari dashboard.
- [x] Human takeover mematikan AI untuk conversation tersebut.
- [x] Semua pesan tersimpan lengkap.

## Phase 6 - AI Management Dasar

- [x] Buat `app/Services/AI/AiService.php` sebagai abstraction provider.
- [x] Buat `app/Services/AI/AiGuardrailService.php`.
- [x] Buat `app/Services/AI/KnowledgeRetrievalService.php`.
- [x] Buat AI Persona CRUD.
- [x] Pastikan hanya satu persona aktif.
- [x] Buat Knowledge Base CRUD.
- [x] Implement active/inactive knowledge base.
- [x] Implement valid_from dan valid_until untuk knowledge base.
- [x] Implement upload file knowledge base dengan batas tipe dan ukuran.
- [x] Buat chunking sederhana untuk knowledge base.
- [x] Buat AI guardrail settings: allowed topics, forbidden topics, fallback message, medical fallback.
- [x] Buat AI logs list dan detail.
- [x] Buat AI simulator untuk test prompt tanpa mengirim WhatsApp.
- [x] Semua AI response wajib parse JSON sesuai PRD.

Definition of done Phase 6:

- [x] Owner/manager bisa mengelola persona.
- [x] Admin bisa mengelola knowledge base.
- [x] Simulator bisa menghasilkan jawaban berdasarkan knowledge aktif.
- [x] Topik medis menghasilkan medical fallback.
- [x] AI log menyimpan prompt, response, confidence, dan knowledge source.

## Phase 7 - AI Auto Reply WhatsApp

- [x] Buat `GenerateAiReplyJob`.
- [x] Integrasikan job AI dari flow pesan masuk setelah message tersimpan.
- [x] Cek `ai_enabled` di conversation sebelum auto reply.
- [x] Ambil persona aktif.
- [x] Ambil knowledge base relevan dan aktif.
- [x] Jalankan guardrail sebelum call AI provider.
- [x] Jika forbidden topic atau confidence rendah, set conversation `escalated` atau `need_followup`.
- [x] Jika aman, kirim reply melalui `SendWhatsAppMessageJob`.
- [x] Simpan outgoing message dengan sender_type `ai`.
- [x] Simpan AI log lengkap.
- [x] Pastikan AI tidak menawarkan promo nonaktif.
- [x] Pastikan AI tidak memberi diagnosis medis atau rekomendasi obat.

Definition of done Phase 7:

- [x] Pesan WhatsApp masuk bisa dibalas AI jika mode auto reply aktif.
- [x] AI tidak membalas conversation yang sudah diambil admin.
- [x] AI fallback dan eskalasi saat knowledge tidak ditemukan atau topik sensitif.
- [x] Semua AI reply tercatat.

## Phase 8 - AI Data Automation Basic

- [x] Buat migration dan model `AiAutomationRule`.
- [x] Buat migration dan model `AiExtractedData`.
- [x] Buat migration dan model `AiAutomationApproval`.
- [x] Buat migration dan model `AiAutomationLog`.
- [x] Buat service `AiDataExtractionService`.
- [x] Buat service `AiAutomationRuleService`.
- [x] Buat service `AiAutomationApprovalService`.
- [x] Buat service `AiAutomationExecutorService`.
- [x] Buat job `ExtractDataFromIncomingMessageJob`.
- [ ] Buat job `ProcessAiAutomationRuleJob`.
- [ ] Buat job `CreateCustomerFromAiDataJob`.
- [ ] Buat job `UpdateCustomerFromAiDataJob`.
- [ ] Buat job `CreateBookingFromAiDataJob`.
- [ ] Buat job `CreateFollowupFromAiDataJob`.
- [ ] Buat job `CreateReminderFromAiDataJob`.
- [ ] Buat job `CreateAiAutomationApprovalJob`.
- [x] Buat UI Automation Rules.
- [x] Buat UI Extracted Data.
- [x] Buat UI Pending Approval.
- [x] Buat UI Automation Logs.
- [x] Buat UI Test Automation.
- [x] Implement auto create customer dari nomor WhatsApp baru.
- [x] Implement auto update data customer low risk.
- [x] Implement create booking draft dengan mode approval.
- [x] Implement create follow-up task otomatis.
- [x] Blokir automation untuk medis, refund, komplain, negosiasi harga, customer marah, dan kasus sensitif.

Definition of done Phase 8:

- [x] AI bisa ekstrak nama, usia bayi, minat layanan, alamat, dan intent booking.
- [x] Booking draft masuk Pending Approval jika data cukup.
- [x] Admin bisa approve, edit approve, atau reject.
- [x] Automation log mencatat semua aksi sukses/gagal.
- [x] Test automation berjalan tanpa mengirim pesan ke customer.

## Phase 9 - Booking dan Calendar

- [x] Buat `app/Services/CRM/BookingService.php`.
- [x] Buat booking controller, request validation, dan view list.
- [x] Buat booking create/edit manual.
- [x] Buat create booking dari customer detail.
- [x] Buat create booking dari inbox.
- [x] Implement booking code generator.
- [x] Implement validasi tanggal tidak boleh masa lalu.
- [x] Implement validasi layanan aktif.
- [x] Implement validasi jam operasional cabang.
- [x] Implement validasi konflik jadwal terapis.
- [x] Implement assign terapis.
- [x] Implement status transition: pending, confirmed, scheduled, completed, rescheduled, cancelled, no_show.
- [x] Implement payment status: unpaid, dp_paid, paid, refunded.
- [x] Buat calendar view day/week/month sederhana.
- [x] Kirim konfirmasi WhatsApp saat booking confirmed.

Definition of done Phase 9:

- [x] Admin bisa membuat booking manual.
- [x] Booking dari inbox otomatis prefill customer.
- [x] Sistem menolak jadwal bentrok.
- [x] Booking tampil di calendar.
- [x] Customer menerima konfirmasi WhatsApp saat confirmed.

## Phase 10 - Reminder Otomatis

- [x] Buat `app/Services/CRM/ReminderService.php`.
- [x] Saat booking confirmed, buat reminder H-1 dan H-0.
- [x] Buat scheduler untuk cek due reminders setiap menit.
- [x] Buat `SendBookingReminderJob`.
- [x] Kirim reminder melalui WAHA.
- [x] Update status reminder menjadi sent atau failed.
- [x] Simpan failed_reason jika gagal.
- [x] Buat halaman reminder list/log.
- [x] Tambahkan aksi retry reminder manual.

Definition of done Phase 10:

- [x] Reminder otomatis dibuat setelah booking confirmed.
- [x] Scheduler mengirim reminder due.
- [x] Gagal kirim tercatat dan bisa di-retry.

## Phase 11 - Follow-Up Lead dan Customer Retention

- [x] Buat `app/Services/CRM/RetentionService.php`.
- [x] Buat follow-up controller dan view list.
- [x] Tampilkan lead perlu follow-up berdasarkan status dan aktivitas chat.
- [x] Buat follow-up task manual.
- [x] Buat follow-up task otomatis dari AI automation.
- [x] Buat AI suggestion message untuk follow-up.
- [x] Kirim follow-up WhatsApp melalui queue.
- [x] Simpan result dan completed_at.
- [x] Buat segment customer inactive 30/60/90 hari.
- [x] Buat scheduler harian generate retention candidates.
- [x] Tampilkan customer lama di halaman retention.
- [x] Track conversion jika customer follow-up menjadi booking.

Definition of done Phase 11:

- [x] Sales bisa melihat follow-up hari ini.
- [x] Sales bisa mengirim pesan follow-up.
- [x] Customer inactive 30/60/90 hari tampil.
- [x] Conversion follow-up ke booking tercatat.

## Phase 12 - Promo dan Campaign Basic

- [x] Buat `app/Services/CRM/CampaignService.php`.
- [x] Buat Promo CRUD.
- [x] Implement promo active, inactive, expired, quota, dan valid date.
- [x] Promo aktif bisa diterapkan pada booking.
- [x] Kuota promo berkurang saat digunakan.
- [x] AI hanya boleh menyebut promo aktif.
- [x] Buat Campaign CRUD draft.
- [x] Buat segment target sederhana berdasarkan customer status, tag, service interest, inactive days.
- [x] Buat preview target campaign.
- [x] Buat approval campaign oleh owner/manager.
- [x] Buat schedule campaign.
- [x] Buat `ProcessCampaignRecipientJob`.
- [x] Kirim campaign bertahap dengan rate limit.
- [x] Respect opt-out dan blocked customer.
- [x] Track sent, failed, replied, booked.

Definition of done Phase 12:

- [x] Sales bisa membuat promo basic.
- [x] Sales bisa membuat campaign draft.
- [x] Owner/manager bisa approve campaign.
- [x] Campaign terkirim via queue dan hasil tercatat.

## Phase 13 - Feedback dan Rating

- [x] Saat booking completed, kirim pesan terima kasih dan permintaan rating.
- [x] Simpan feedback customer.
- [x] Buat halaman feedback list.
- [x] Eskalasi otomatis jika feedback berisi komplain.
- [x] Tampilkan rating rata-rata di dashboard owner.
- [ ] Tambahkan sentiment AI optional jika AI provider sudah stabil.

Definition of done Phase 13:

- [x] Feedback request terkirim setelah treatment selesai.
- [x] Rating tersimpan.
- [x] Komplain masuk eskalasi admin.

## Phase 14 - Dashboard, Reporting, dan Analytics

- [x] Hubungkan dashboard KPI ke query nyata.
- [x] Tampilkan total customer, customer baru hari ini, chat masuk hari ini, chat belum dibalas.
- [x] Tampilkan booking hari ini, minggu ini, bulan ini.
- [x] Tampilkan estimasi revenue.
- [x] Tampilkan follow-up pending.
- [x] Tampilkan campaign aktif.
- [x] Tampilkan AI answered count dan human takeover count.
- [x] Buat filter tanggal: today, this week, this month, custom range.
- [x] Buat report customer.
- [x] Buat report booking.
- [x] Buat report revenue.
- [x] Buat report campaign.
- [x] Buat report follow-up.
- [x] Buat report AI performance.
- [x] Buat export CSV/XLSX minimal CSV terlebih dahulu.

Definition of done Phase 14:

- [x] Owner melihat KPI utama.
- [x] Admin melihat KPI operasional.
- [x] Sales melihat KPI campaign/follow-up.
- [x] Report bisa difilter tanggal dan diekspor.

## Phase 15 - Security, Reliability, dan Polish

- [x] Audit log semua aksi penting.
- [x] Batasi file upload knowledge base berdasarkan tipe dan ukuran.
- [ ] Masking nomor customer untuk role terbatas jika diperlukan.
- [ ] Validasi semua form dengan Form Request.
- [x] Tambahkan pagination pada inbox, customer, booking, logs, campaign.
- [x] Tambahkan retry flow untuk pesan gagal.
- [x] Tambahkan fallback saat WAHA down.
- [x] Tambahkan fallback saat AI provider error.
- [x] Tambahkan notifikasi admin saat session WAHA disconnected.
- [ ] Tambahkan empty state dan error state UI.
- [x] Jalankan Laravel Pint untuk formatting.
- [x] Tambahkan test fitur utama: auth, customer CRUD, booking conflict, webhook message, AI guardrail, reminder due.
- [x] Buat dokumentasi singkat setup lokal dan demo scenario.

Definition of done Phase 15:

- [x] Aplikasi tidak crash saat WAHA atau AI error.
- [x] Semua fitur inti punya validasi dan permission.
- [ ] Flow utama lolos test manual.
- [x] Dokumentasi setup tersedia.

## Urutan Prioritas MVP

1. Selesaikan Phase 0 sampai Phase 2 agar database dan fondasi stabil.
2. Selesaikan Phase 3 agar data master bisa dikelola.
3. Selesaikan Phase 4 dan Phase 5 agar WhatsApp masuk dan admin bisa reply manual.
4. Selesaikan Phase 6 dan Phase 7 agar AI auto reply aman berjalan.
5. Selesaikan Phase 8 basic agar AI bisa ekstrak data dan membuat draft booking/follow-up dengan approval.
6. Selesaikan Phase 9 dan Phase 10 agar booking dan reminder berjalan.
7. Selesaikan Phase 11 agar sales bisa follow-up lead dan customer lama.
8. Selesaikan Phase 12 basic agar promo dan campaign sederhana berjalan.
9. Selesaikan Phase 14 dashboard/report dasar untuk demo owner.
10. Selesaikan Phase 15 untuk hardening sebelum dipakai operasional.

## Checklist Smoke Test Aplikasi Berjalan Normal

- [ ] `php artisan migrate:fresh --seed` sukses.
- [x] `php artisan test` sukses atau hanya menyisakan test yang terdokumentasi belum dibuat.
- [ ] Owner bisa login dengan akun seeder.
- [ ] Admin inactive tidak bisa login.
- [ ] Dashboard admin terbuka tanpa error.
- [ ] Customer bisa dibuat, diedit, dicari, dan dilihat detailnya.
- [ ] Service, branch, dan therapist bisa dibuat.
- [ ] WAHA session bisa start dan QR tampil.
- [x] Webhook WAHA membuat customer, conversation, dan message.
- [x] Inbox menampilkan chat masuk.
- [x] Admin bisa reply manual dan pesan tersimpan outgoing.
- [ ] Persona AI aktif tersedia.
- [ ] Knowledge base aktif tersedia.
- [x] AI simulator menjawab berdasarkan knowledge base.
- [x] Pertanyaan medis menghasilkan fallback dan eskalasi.
- [x] AI auto reply mengirim jawaban untuk pesan aman.
- [x] AI log tersimpan.
- [x] AI automation mengekstrak data customer dan intent booking.
- [x] Booking draft masuk Pending Approval.
- [x] Admin approve booking draft menjadi booking.
- [x] Sistem menolak booking bentrok untuk terapis yang sama.
- [x] Booking confirmed membuat reminder H-1 dan H-0.
- [x] Reminder due terkirim via queue.
- [x] Follow-up task bisa dibuat dan dikirim.
- [x] Customer inactive muncul di retention.
- [x] Promo aktif bisa dipakai booking.
- [x] Campaign draft bisa dibuat, dijadwalkan, dan dikirim bertahap.
- [x] Dashboard KPI menampilkan data nyata.
- [x] Report dasar bisa difilter tanggal.
- [x] Audit log mencatat aksi penting.

## Risiko yang Harus Dijaga Saat Implementasi

- WAHA berbasis WhatsApp Web, jadi wajib ada monitoring status, retry, dan rate limit campaign.
- AI bisa halusinasi, jadi wajib gunakan knowledge base aktif, confidence threshold, fallback, dan human takeover.
- Data customer sensitif, jadi permission, audit log, dan pembatasan export harus diterapkan.
- Booking bentrok adalah risiko operasional utama, jadi validasi slot terapis harus dibuat sebelum auto booking.
- Campaign bisa dianggap spam, jadi opt-out, blocked customer, dan jeda antar pesan wajib diterapkan.

## Target MVP Selesai

- [x] Login dan role berjalan.
- [x] Dashboard overview berjalan dengan data nyata.
- [x] Customer database CRUD berjalan.
- [x] WhatsApp WAHA QR dan status session berjalan.
- [x] Inbox WhatsApp menerima dan mengirim pesan.
- [x] Manual reply admin berjalan.
- [x] AI persona, knowledge base, guardrail, simulator, dan log berjalan.
- [x] AI auto reply aman berdasarkan knowledge base berjalan.
- [x] AI Data Automation basic berjalan dengan approval.
- [x] Booking dan reminder otomatis berjalan.
- [x] Follow-up lead dan customer retention berjalan.
- [x] Promo dan campaign basic berjalan.
- [x] Report dasar dan audit log tersedia.
