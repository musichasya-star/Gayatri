# PRD VibeCoding — Aplikasi CRM WhatsApp & AI untuk Gayatri Mom & Baby SPA

**Nama Produk:** Gayatri CRM WhatsApp AI  
**Jenis Dokumen:** Product Requirement Document untuk AI Coding Agent / VibeCoding  
**Target Implementasi:** Dashboard Laravel + WhatsApp Gateway WAHA + AI CRM Assistant  
**Versi:** 1.0  
**Status:** Siap untuk VibeCoding / AI Agent Development  
**Bahasa Aplikasi:** Indonesia  
**Client:** Gayatri Mom & Baby SPA  

---

# 0. Instruksi Utama untuk AI Coding Agent

Dokumen ini dibuat agar AI coding agent dapat memahami konteks produk, tujuan bisnis, fitur, struktur database, flow aplikasi, UI, API, dan urutan implementasi.

AI agent harus membangun aplikasi secara bertahap, modular, dan mudah dikembangkan.

## 0.1 Prinsip Implementasi

1. Gunakan arsitektur Laravel yang rapi, modular, dan maintainable.
2. Pisahkan domain utama:
   - Customer CRM
   - WhatsApp Inbox
   - Booking
   - Reminder
   - Follow-Up
   - Promo Campaign
   - AI Management
   - Knowledge Base
   - Dashboard Analytics
3. Gunakan database relasional.
4. Gunakan queue/job untuk proses async seperti kirim pesan, reminder, campaign, AI processing, dan webhook.
5. Semua aktivitas penting harus disimpan dalam audit log.
6. AI tidak boleh menjawab di luar scope layanan Gayatri.
7. WhatsApp Gateway pada MVP menggunakan WAHA melalui REST API.
8. Sistem harus disiapkan agar bisa migrasi ke WhatsApp Business API resmi di masa depan.
9. Jangan hardcode konfigurasi penting. Gunakan `.env` dan halaman setting.
10. Buat kode yang sederhana dulu, lalu bisa dikembangkan.

## 0.2 Output yang Diharapkan dari AI Agent

AI agent harus menghasilkan aplikasi web CRM dengan fitur:

- Login dan role user
- Dashboard overview
- Customer database
- WhatsApp inbox
- Integrasi WAHA
- QR scan WhatsApp
- Auto reply AI
- Booking dan penjadwalan
- Reminder otomatis
- Follow-up customer
- Customer lama / retention
- Promo dan voucher
- Campaign WhatsApp
- Custom persona AI
- Knowledge base AI
- AI guardrail / scope restriction
- AI chat simulator
- AI log
- Laporan dan analytics

---

# 1. Ringkasan Produk

Gayatri CRM WhatsApp AI adalah aplikasi CRM berbasis dashboard web untuk membantu Gayatri Mom & Baby SPA dalam mengelola komunikasi customer melalui WhatsApp, melakukan booking, mengirim reminder, follow-up customer, promosi ke customer lama, serta memanfaatkan AI untuk auto reply, rekomendasi layanan, dan analisis customer.

Aplikasi ini fokus pada kebutuhan bisnis Mom & Baby SPA, yaitu:

- Customer banyak bertanya melalui WhatsApp.
- Admin membutuhkan auto reply agar respons lebih cepat.
- Booking treatment perlu dicatat secara rapi.
- Jadwal terapis dan cabang perlu dimonitor.
- Customer lama perlu di-follow-up untuk repeat order.
- Owner membutuhkan dashboard monitoring customer, booking, sales, dan campaign.
- AI harus bisa menjawab customer, tetapi tetap dibatasi sesuai data knowledge base dan scope layanan.

---

# 2. Problem Statement

Saat ini proses komunikasi dan pencatatan customer berpotensi masih manual melalui WhatsApp. Masalah yang ingin diselesaikan:

1. Chat customer tidak selalu terbalas cepat.
2. Riwayat customer tersebar di WhatsApp dan sulit dianalisis.
3. Booking bisa bentrok jika tidak ada sistem jadwal.
4. Reminder customer masih manual.
5. Follow-up customer belum konsisten.
6. Customer lama tidak selalu ditawarkan promo lanjutan.
7. Owner sulit melihat data customer, booking, dan penjualan secara real-time.
8. Admin perlu bantuan AI agar lebih cepat melayani customer.
9. AI perlu dikontrol agar tidak menjawab di luar scope, terutama pertanyaan medis.

---

# 3. Tujuan Produk

## 3.1 Tujuan Bisnis

1. Meningkatkan kecepatan respons customer.
2. Meningkatkan konversi chat menjadi booking.
3. Mengurangi no-show dengan reminder otomatis.
4. Meningkatkan repeat order dari customer lama.
5. Meningkatkan penjualan paket treatment dan promo.
6. Membantu owner memonitor performa bisnis.
7. Membuat customer service lebih rapi dan terukur.

## 3.2 Tujuan Teknis

1. Membuat dashboard Laravel yang mudah digunakan.
2. Mengintegrasikan WhatsApp Gateway berbasis WAHA.
3. Mengintegrasikan AI untuk auto reply dan rekomendasi.
4. Menyediakan knowledge base yang bisa di-update admin.
5. Menyediakan guardrail agar AI tidak keluar scope.
6. Menyediakan struktur database yang scalable.
7. Menyediakan job queue untuk automation.
8. Menyediakan API layer untuk pengembangan mobile app di masa depan.

---

# 4. Target Pengguna

## 4.1 Owner / Manager

Kebutuhan:

- Melihat dashboard ringkasan bisnis.
- Melihat jumlah customer, booking, revenue, campaign, dan performa admin.
- Melihat customer lama yang perlu di-follow-up.
- Melihat insight AI.
- Mengatur layanan, promo, AI persona, dan knowledge base.

## 4.2 Admin / Customer Service

Kebutuhan:

- Membalas chat WhatsApp customer.
- Melihat inbox customer.
- Menyetujui atau mengambil alih chat dari AI.
- Membuat booking.
- Mengubah status booking.
- Mengirim reminder manual jika dibutuhkan.
- Mengelola customer.

## 4.3 Sales / Marketing

Kebutuhan:

- Melihat lead customer.
- Melihat customer lama yang perlu follow-up.
- Mengirim promo via WhatsApp.
- Membuat campaign.
- Melihat conversion follow-up.
- Melihat rekomendasi AI untuk pesan promosi.

## 4.4 Terapis

Kebutuhan:

- Melihat jadwal treatment.
- Melihat detail customer dan layanan.
- Melaporkan treatment selesai.
- Mengisi catatan treatment.
- Memberi status no-show atau reschedule.

## 4.5 AI Assistant

Peran AI:

- Membalas chat customer otomatis.
- Menjawab berdasarkan knowledge base.
- Menawarkan booking.
- Mengklasifikasikan lead.
- Memberikan rekomendasi layanan.
- Membuat pesan follow-up.
- Mengalihkan chat ke admin jika keluar scope.

---

# 5. Scope Aplikasi

## 5.1 In Scope

Fitur yang harus masuk dalam aplikasi:

1. Auth dan role permission.
2. Dashboard overview.
3. Customer database.
4. WhatsApp Gateway WAHA.
5. QR scan session WhatsApp.
6. WhatsApp inbox.
7. Auto reply AI.
8. Manual reply admin.
9. Booking dan kalender jadwal.
10. Reminder otomatis.
11. Follow-up lead.
12. Follow-up customer lama.
13. Promo, voucher, dan diskon.
14. Campaign WhatsApp.
15. AI persona.
16. Knowledge base.
17. AI guardrail.
18. AI simulator.
19. AI logs.
20. Reporting analytics.
21. AI Data Automation untuk auto-post customer, booking, reminder, dan follow-up.
22. Settings aplikasi.

## 5.2 Out of Scope untuk MVP

Fitur berikut tidak wajib untuk MVP awal:

1. Mobile app native.
2. Payment gateway penuh.
3. Integrasi POS.
4. Multi-branch advanced inventory.
5. Loyalty point kompleks.
6. Integrasi Instagram DM.
7. WhatsApp Business API resmi.
8. Voice call automation.
9. Medical consultation module.

---

# 6. Stack Teknologi yang Direkomendasikan

AI agent boleh menyesuaikan dengan environment project, tetapi rekomendasi default:

## 6.1 Backend

- Laravel
- PHP
- MySQL atau PostgreSQL
- Laravel Queue
- Laravel Scheduler
- Laravel Notification
- Laravel Policies / Gates
- Laravel HTTP Client

## 6.2 Frontend Dashboard

Pilihan yang direkomendasikan:

- Laravel Blade + Tailwind CSS + Alpine.js
- atau Laravel Livewire
- atau Filament Admin Panel jika ingin cepat membuat dashboard admin

Prioritas: cepat, rapi, dan mudah dikembangkan.

## 6.3 WhatsApp Gateway

- WAHA sebagai service terpisah.
- WAHA diakses dari Laravel melalui REST API.
- WAHA mengirim event masuk ke Laravel melalui webhook.

## 6.4 AI Engine

- AI provider dapat dikonfigurasi melalui `.env`.
- Buat service abstraction: `AiService`.
- Jangan hardcode provider AI.
- Buat prompt system berdasarkan AI Persona + Knowledge Base + Guardrail.

## 6.5 Infrastruktur

- Docker optional.
- Redis direkomendasikan untuk queue.
- Storage lokal untuk upload knowledge base.
- Cron/scheduler untuk reminder dan follow-up automation.

---

# 7. Arsitektur Sistem

## 7.1 Diagram Arsitektur

```text
Customer WhatsApp
        ↓
WAHA WhatsApp Gateway
        ↓ Webhook Message
Laravel CRM API
        ↓
Database CRM
        ↓
AI Service
        ↓
Laravel Dashboard
        ↓
Admin / Sales / Owner / Terapis
```

## 7.2 Service Utama

1. `WhatsAppGatewayService`
   - Start session
   - Get QR
   - Check status
   - Send message
   - Receive webhook

2. `AiService`
   - Generate auto reply
   - Summarize chat
   - Lead scoring
   - Follow-up message generation
   - Service recommendation
   - Guardrail checking

3. `BookingService`
   - Create booking
   - Validate schedule conflict
   - Assign therapist
   - Update status

4. `ReminderService`
   - Generate reminder schedule
   - Send reminder via WhatsApp
   - Log result

5. `CampaignService`
   - Create campaign
   - Segment customer
   - Queue broadcast message
   - Track response

6. `CustomerRetentionService`
   - Detect inactive customer
   - Recommend promo
   - Create follow-up task

7. `AiDataAutomationService`
   - Extract customer data from chat
   - Create or update customer automatically
   - Create booking draft from chat
   - Create automation approval
   - Execute approved automation safely

---

# 8. Role dan Permission

## 8.1 Roles

1. `owner`
2. `manager`
3. `admin`
4. `sales`
5. `therapist`

## 8.2 Permission Matrix

| Modul | Owner | Manager | Admin | Sales | Terapis |
|---|---|---|---|---|---|
| Dashboard Overview | Ya | Ya | Terbatas | Terbatas | Tidak |
| Customer Database | Ya | Ya | Ya | Ya | Terbatas |
| WhatsApp Inbox | Ya | Ya | Ya | Ya | Tidak |
| Booking | Ya | Ya | Ya | Terbatas | Lihat |
| Reminder | Ya | Ya | Ya | Ya | Tidak |
| Follow-Up | Ya | Ya | Ya | Ya | Tidak |
| Promo | Ya | Ya | Tidak | Ya | Tidak |
| Campaign | Ya | Ya | Tidak | Ya | Tidak |
| AI Persona | Ya | Ya | Tidak | Tidak | Tidak |
| Knowledge Base | Ya | Ya | Ya | Tidak | Tidak |
| AI Guardrail | Ya | Ya | Tidak | Tidak | Tidak |
| Report | Ya | Ya | Terbatas | Terbatas | Tidak |
| Terapis Schedule | Ya | Ya | Ya | Tidak | Ya |

---

# 9. Modul Aplikasi

# 9.1 Modul Authentication

## Requirement

Sistem harus memiliki login user.

## Field User

- name
- email
- password
- phone
- role
- status
- last_login_at

## Acceptance Criteria

1. User bisa login.
2. User bisa logout.
3. Role menentukan akses menu.
4. Owner bisa membuat user baru.
5. User nonaktif tidak bisa login.

---

# 9.2 Modul Dashboard Overview

## Tujuan

Menampilkan ringkasan performa bisnis.

## Widget Dashboard

1. Total customer.
2. Customer baru hari ini.
3. Total chat masuk hari ini.
4. Chat belum dibalas.
5. Booking hari ini.
6. Booking minggu ini.
7. Booking bulan ini.
8. Revenue estimasi.
9. Customer repeat order.
10. Follow-up pending.
11. Campaign aktif.
12. Customer tidak aktif.
13. AI answered count.
14. Human takeover count.

## Chart

1. Grafik booking per hari.
2. Grafik chat per hari.
3. Grafik conversion chat ke booking.
4. Grafik revenue per layanan.
5. Grafik customer baru per periode.
6. Grafik campaign performance.

## Acceptance Criteria

1. Owner melihat semua data.
2. Admin hanya melihat data operasional.
3. Data dashboard terfilter berdasarkan tanggal.
4. Dashboard memiliki filter:
   - Today
   - This Week
   - This Month
   - Custom Range

---

# 9.3 Modul Customer Database

## Tujuan

Menyimpan data lengkap customer dan histori interaksi.

## Field Customer

- id
- name
- phone
- whatsapp_number
- email
- address
- city
- birth_date
- baby_name
- baby_birth_date
- baby_age_months
- pregnancy_age_weeks
- customer_type
- source
- status
- label
- tags
- total_booking
- total_spent
- last_booking_at
- last_chat_at
- last_followup_at
- notes
- created_at
- updated_at

## Customer Type

- new_mom
- pregnant_mom
- baby_customer
- kids_customer
- general_customer

## Customer Status

- new_lead
- hot_lead
- warm_lead
- cold_lead
- booked
- completed
- repeat_customer
- loyal_customer
- inactive
- need_followup
- blocked

## Fitur

1. List customer.
2. Search customer.
3. Filter by status.
4. Filter by layanan.
5. Filter by terakhir booking.
6. Detail customer.
7. Riwayat chat.
8. Riwayat booking.
9. Riwayat treatment.
10. Riwayat follow-up.
11. Riwayat campaign.
12. Manual add customer.
13. Import customer dari Excel/CSV.
14. Export customer.

## Acceptance Criteria

1. Customer otomatis dibuat saat chat baru masuk dari WhatsApp.
2. Jika nomor sudah ada, sistem update last_chat_at.
3. Admin bisa edit data customer.
4. Sistem bisa menampilkan histori customer lengkap.
5. Customer bisa diberi tag manual.

---

# 9.4 Modul WhatsApp Gateway WAHA

## Tujuan

Menghubungkan aplikasi Laravel dengan WhatsApp melalui WAHA.

## Fitur

1. Start WhatsApp session.
2. Stop session.
3. Logout session.
4. Tampilkan QR code.
5. Tampilkan status koneksi.
6. Kirim pesan text.
7. Terima pesan masuk via webhook.
8. Simpan semua pesan ke database.
9. Kirim media optional.
10. Multi-session optional untuk fase berikutnya.

## Setting WAHA

Field di settings:

- WAHA_BASE_URL
- WAHA_API_KEY
- WAHA_DEFAULT_SESSION
- WAHA_WEBHOOK_URL
- WAHA_TIMEOUT_SECONDS

## Endpoint Laravel untuk WAHA

```text
GET    /admin/whatsapp/session
POST   /admin/whatsapp/session/start
POST   /admin/whatsapp/session/stop
POST   /admin/whatsapp/session/logout
GET    /admin/whatsapp/session/qr
GET    /admin/whatsapp/session/status
POST   /webhooks/waha/messages
POST   /webhooks/waha/status
```

## Acceptance Criteria

1. Admin bisa membuka halaman WhatsApp Gateway.
2. Admin bisa klik Start Session.
3. Sistem menampilkan QR code untuk scan WhatsApp.
4. Setelah scan berhasil, status berubah menjadi connected.
5. Pesan masuk dari customer masuk ke Inbox.
6. Admin bisa membalas dari dashboard.
7. Pesan keluar tersimpan di database.
8. Jika session disconnected, dashboard menampilkan warning.

---

# 9.5 Modul WhatsApp Inbox CRM

## Tujuan

Menampilkan chat WhatsApp dalam bentuk inbox CRM.

## Layout Inbox

Tampilan seperti aplikasi customer service:

Kiri:
- List conversation
- Search nomor/nama
- Filter unread
- Filter need follow-up
- Filter AI handled
- Filter assigned admin

Tengah:
- Isi percakapan
- Bubble incoming/outgoing
- Status pesan
- Input reply
- Tombol kirim
- Tombol AI suggest reply
- Tombol takeover dari AI

Kanan:
- Profil customer
- Status customer
- Booking terakhir
- Follow-up task
- Rekomendasi AI
- Catatan admin

## Conversation Status

- open
- pending
- ai_handled
- human_handled
- need_followup
- closed
- escalated

## Message Direction

- incoming
- outgoing

## Sender Type

- customer
- ai
- admin
- system

## Fitur

1. Auto create conversation.
2. Auto assign conversation ke AI.
3. Manual assign ke admin.
4. Human takeover.
5. AI suggest reply.
6. Internal note.
7. Mark as closed.
8. Mark as follow-up.
9. Label conversation.
10. Summary chat AI.

## Acceptance Criteria

1. Chat masuk tampil real-time atau auto-refresh.
2. Admin bisa membalas chat.
3. AI bisa membalas otomatis jika enabled.
4. Admin bisa mematikan AI untuk conversation tertentu.
5. Semua pesan tersimpan lengkap.
6. Customer profile muncul di sidebar.

---

# 9.6 Modul AI Auto Reply

## Tujuan

AI membalas chat customer secara otomatis dengan bahasa yang sesuai persona Gayatri.

## Rule Utama

AI hanya boleh menjawab berdasarkan:

1. AI Persona.
2. Knowledge Base aktif.
3. Scope dan Guardrail.
4. Riwayat chat customer.
5. Data booking customer jika relevan.

## Flow AI Auto Reply

```text
Webhook pesan masuk
↓
Simpan message
↓
Cari customer
↓
Cari conversation
↓
Cek apakah AI enabled
↓
Cek guardrail topik
↓
Ambil knowledge base relevan
↓
Generate jawaban AI
↓
Cek confidence score
↓
Jika aman, kirim via WAHA
↓
Jika tidak aman, eskalasi ke admin
↓
Simpan AI log
```

## AI Response Mode

1. `auto_reply`
2. `suggest_only`
3. `human_approval_required`
4. `disabled`

## Acceptance Criteria

1. AI membalas chat yang masuk jika auto reply aktif.
2. AI tidak menjawab jika conversation diambil admin.
3. AI mengalihkan ke admin jika topik tidak aman.
4. AI menyimpan log prompt dan response.
5. AI menggunakan knowledge base yang aktif.
6. AI tidak boleh menjanjikan diskon di luar promo aktif.
7. AI tidak boleh memberi diagnosis medis.

---

# 9.7 Modul AI Persona

## Tujuan

Owner/Manager dapat mengatur karakter AI.

## Field AI Persona

- name
- assistant_name
- brand_name
- tone
- language
- greeting_style
- main_role
- allowed_topics
- forbidden_topics
- escalation_rules
- fallback_message
- is_active

## Default Persona

```text
Kamu adalah Gayatri Assistant, AI customer service resmi Gayatri Mom & Baby SPA.
Gunakan bahasa Indonesia yang ramah, sopan, lembut, dan keibuan.
Panggil customer dengan sapaan "Bunda".
Tugasmu membantu customer seputar layanan Gayatri, harga, promo, booking, jadwal, reminder, follow-up, dan rekomendasi treatment umum.
Jawab hanya berdasarkan knowledge base yang tersedia.
Jangan memberi diagnosis medis, rekomendasi obat, atau saran kesehatan serius.
Jika pertanyaan di luar scope, arahkan ke admin atau sarankan konsultasi dengan tenaga medis profesional.
```

## UI

Menu: `AI Management > AI Persona`

Fitur:

1. Create persona.
2. Edit persona.
3. Activate persona.
4. Preview persona.
5. Reset default persona.

## Acceptance Criteria

1. Owner bisa mengubah gaya bahasa AI.
2. Hanya satu persona aktif.
3. Perubahan persona langsung memengaruhi jawaban AI berikutnya.
4. Riwayat perubahan persona tersimpan.

---

# 9.8 Modul Knowledge Base AI

## Tujuan

Menyediakan sumber data resmi untuk AI.

## Jenis Knowledge Base

1. Layanan.
2. Harga.
3. Promo.
4. FAQ.
5. Cabang.
6. Jam operasional.
7. SOP booking.
8. SOP reschedule.
9. SOP cancel.
10. Template jawaban.
11. Edukasi treatment.
12. Kebijakan internal.
13. File upload PDF/Doc/Excel/TXT/MD.

## Field Knowledge Base

- title
- category
- content
- source_type
- file_path
- tags
- status
- valid_from
- valid_until
- created_by
- updated_by

## Category

- services
- pricing
- promo
- faq
- branch
- schedule
- booking_policy
- cancellation_policy
- medical_guardrail
- sales_script
- reminder_template
- followup_template
- other

## Fitur

1. Tambah knowledge manual.
2. Edit knowledge.
3. Aktif/nonaktif.
4. Upload file.
5. Search knowledge.
6. Tagging.
7. Validity date.
8. Preview AI usage.
9. Delete/archive.
10. Chunking untuk AI retrieval.

## Acceptance Criteria

1. AI hanya menggunakan knowledge yang aktif.
2. Knowledge expired tidak digunakan.
3. Admin bisa menambah FAQ.
4. AI bisa mengambil knowledge relevan berdasarkan pesan customer.
5. Owner bisa melihat knowledge mana yang dipakai AI dalam log.

---

# 9.9 Modul AI Scope & Guardrail

## Tujuan

Membatasi AI agar tidak keluar dari konteks bisnis Gayatri.

## Allowed Topics

AI boleh menjawab:

- Informasi layanan.
- Harga layanan.
- Promo aktif.
- Booking.
- Jadwal.
- Reminder.
- Follow-up.
- Lokasi.
- Cabang.
- Home service.
- Membership.
- Paket treatment.
- Rekomendasi treatment umum.
- Feedback customer.
- Kebijakan cancel/reschedule.

## Forbidden Topics

AI tidak boleh menjawab:

- Diagnosis medis.
- Rekomendasi obat.
- Tindakan medis.
- Kondisi darurat bayi/ibu.
- Politik.
- Agama.
- SARA.
- Konten dewasa.
- Kekerasan.
- Topik di luar layanan.
- Janji diskon tidak resmi.
- Klaim hasil medis.
- Informasi yang tidak ada di knowledge base.

## Escalation Conditions

AI harus mengalihkan ke admin jika:

1. Customer komplain.
2. Customer marah.
3. Customer bertanya medis.
4. Customer meminta refund.
5. Customer mengirim bukti pembayaran.
6. Customer ingin bicara dengan admin.
7. Customer meminta harga khusus.
8. Customer meminta perubahan jadwal mendadak.
9. Confidence score rendah.
10. Knowledge base tidak ditemukan.

## Default Fallback

```text
Mohon maaf Bunda, untuk pertanyaan tersebut saya belum bisa memastikan jawabannya. Saya bantu teruskan ke admin Gayatri agar Bunda mendapatkan informasi yang tepat ya.
```

## Medical Fallback

```text
Mohon maaf Bunda, untuk kondisi kesehatan atau pertanyaan medis, sebaiknya Bunda berkonsultasi langsung dengan dokter atau tenaga medis profesional. Gayatri dapat membantu untuk layanan relaksasi dan perawatan sesuai treatment yang tersedia.
```

## Acceptance Criteria

1. AI tidak menjawab pertanyaan medis secara spesifik.
2. AI membuat escalation task jika keluar scope.
3. Admin mendapat notifikasi jika terjadi eskalasi.
4. Semua guardrail hit tersimpan di AI log.
5. Owner dapat mengedit allowed dan forbidden topics.

---

# 9.10 Modul AI Chat Simulator

## Tujuan

Menguji jawaban AI sebelum digunakan ke customer.

## Fitur

1. Input contoh pertanyaan.
2. Pilih persona.
3. Pilih knowledge base.
4. Generate jawaban.
5. Tampilkan confidence score.
6. Tampilkan sumber knowledge base.
7. Tombol approve.
8. Tombol edit.
9. Tombol simpan sebagai template.
10. Tombol tandai tidak sesuai.

## Acceptance Criteria

1. Admin bisa test pertanyaan tanpa mengirim ke WhatsApp.
2. Hasil AI menampilkan sumber knowledge.
3. Admin bisa menyimpan hasil sebagai template.
4. Testing tidak membuat conversation customer.

---

# 9.11 Modul Booking dan Penjadwalan

## Tujuan

Mengelola booking customer untuk layanan Gayatri.

## Field Booking

- booking_code
- customer_id
- service_id
- therapist_id
- branch_id
- booking_date
- start_time
- end_time
- duration_minutes
- booking_type
- status
- payment_status
- total_amount
- discount_amount
- final_amount
- source
- notes
- created_by

## Booking Type

- onsite
- home_service

## Booking Status

- pending_confirmation
- confirmed
- waiting_payment
- scheduled
- completed
- rescheduled
- cancelled
- no_show

## Payment Status

- unpaid
- dp_paid
- paid
- refunded

## Fitur

1. Create booking manual.
2. Create booking dari chat.
3. Calendar view.
4. List booking.
5. Filter tanggal.
6. Filter terapis.
7. Filter cabang.
8. Cek konflik jadwal.
9. Reschedule.
10. Cancel.
11. Complete treatment.
12. Assign terapis.
13. Reminder booking.

## Acceptance Criteria

1. Admin bisa membuat booking dari halaman customer.
2. Admin bisa membuat booking dari inbox chat.
3. Sistem menolak jadwal bentrok untuk terapis yang sama.
4. Status booking bisa diubah.
5. Customer menerima konfirmasi WhatsApp saat booking confirmed.
6. Booking muncul di kalender.

---

# 9.12 Modul Reminder Otomatis

## Tujuan

Mengirim reminder WhatsApp kepada customer sebelum jadwal treatment.

## Jenis Reminder

1. H-1 sebelum treatment.
2. H-0 beberapa jam sebelum treatment.
3. Reminder pembayaran.
4. Reminder reschedule.
5. Reminder treatment lanjutan.
6. Reminder paket hampir habis.

## Field Reminder

- customer_id
- booking_id
- reminder_type
- scheduled_at
- message
- status
- sent_at
- failed_reason

## Reminder Status

- scheduled
- sent
- failed
- cancelled

## Flow

```text
Booking confirmed
↓
Generate reminder H-1 dan H-0
↓
Laravel Scheduler cek reminder due
↓
Dispatch SendWhatsAppMessageJob
↓
Kirim via WAHA
↓
Update status reminder
```

## Acceptance Criteria

1. Reminder otomatis dibuat setelah booking confirmed.
2. Reminder terkirim sesuai jadwal.
3. Reminder gagal tersimpan dengan alasan.
4. Admin bisa kirim ulang reminder.
5. Customer menerima reminder via WhatsApp.

---

# 9.13 Modul Follow-Up Lead

## Tujuan

Menindaklanjuti customer yang sudah chat tetapi belum booking.

## Lead Status

- new
- contacted
- interested
- followup_needed
- booked
- not_interested
- lost

## Follow-Up Trigger

1. Customer bertanya harga tetapi belum booking.
2. Customer tidak membalas setelah AI menawarkan jadwal.
3. Customer belum bayar setelah booking.
4. Customer meminta info promo.
5. Customer masuk hot lead dari AI scoring.

## Fitur

1. List lead perlu follow-up.
2. Assign ke sales/admin.
3. Generate pesan follow-up dengan AI.
4. Kirim WhatsApp.
5. Update status follow-up.
6. Catatan follow-up.
7. Reminder follow-up berikutnya.

## Acceptance Criteria

1. Sistem membuat task follow-up otomatis untuk lead belum booking.
2. Sales bisa melihat daftar follow-up hari ini.
3. Sales bisa kirim pesan follow-up.
4. Hasil follow-up tersimpan.
5. Jika customer booking, lead status berubah booked.

---

# 9.14 Modul Customer Retention & Promo Follow-Up

## Tujuan

Menghubungi customer lama untuk meningkatkan repeat order.

## Segmentasi Customer Lama

1. Tidak booking 30 hari.
2. Tidak booking 60 hari.
3. Tidak booking 90 hari.
4. Pernah baby spa.
5. Pernah mom treatment.
6. Pernah pregnancy massage.
7. Pernah postnatal treatment.
8. Customer loyal.
9. Customer high value.
10. Customer paket hampir habis.
11. Ulang tahun bayi.
12. Customer pernah cancel.
13. Customer belum pernah repeat.

## Fitur

1. Dashboard customer lama.
2. Segment builder.
3. AI recommendation.
4. Promo suggestion.
5. Template follow-up.
6. Kirim pesan manual.
7. Kirim campaign.
8. Jadwalkan follow-up.
9. Track response.
10. Track booking dari follow-up.

## AI Recommendation Example

```text
Customer: Bunda Rina
Status: Tidak booking 45 hari
Riwayat: Baby Spa 3x
Rekomendasi: Kirim promo repeat Baby Spa
Potensi: Tinggi
Pesan:
"Halo Bunda Rina, sudah lama si kecil belum treatment di Gayatri. Minggu ini ada promo khusus Baby Spa untuk customer lama. Mau kami bantu cek jadwalnya?"
```

## Acceptance Criteria

1. Sistem bisa menampilkan customer tidak aktif 30/60/90 hari.
2. Sales bisa memilih segment customer lama.
3. AI bisa membuat pesan promo personal.
4. Customer yang merespons bisa masuk pipeline booking.
5. Owner bisa melihat conversion follow-up customer lama.

---

# 9.15 Modul Promo, Voucher, dan Diskon

## Tujuan

Mengelola promo untuk campaign dan follow-up.

## Field Promo

- name
- code
- description
- discount_type
- discount_value
- service_id
- minimum_transaction
- quota
- used_count
- valid_from
- valid_until
- target_segment
- status

## Discount Type

- percentage
- fixed_amount

## Promo Status

- draft
- active
- expired
- inactive

## Fitur

1. Create promo.
2. Edit promo.
3. Activate/deactivate.
4. Kuota promo.
5. Masa berlaku.
6. Target segment.
7. Apply promo ke booking.
8. Report usage.

## Acceptance Criteria

1. Promo aktif bisa digunakan di booking.
2. Promo expired tidak bisa digunakan.
3. AI hanya boleh menawarkan promo aktif.
4. Kuota promo berkurang saat digunakan.
5. Owner bisa melihat laporan penggunaan promo.

---

# 9.16 Modul Campaign WhatsApp

## Tujuan

Mengirim broadcast tersegmentasi melalui WhatsApp.

## Campaign Type

- promo
- reminder
- education
- retention
- birthday
- membership
- reactivation

## Field Campaign

- name
- type
- segment_rule
- promo_id
- template_message
- scheduled_at
- status
- total_target
- total_sent
- total_failed
- total_replied
- total_booked
- created_by

## Campaign Status

- draft
- scheduled
- running
- completed
- cancelled
- failed

## Flow Campaign

```text
Create campaign
↓
Pilih segment customer
↓
Pilih template/promo
↓
Preview target
↓
Schedule
↓
Queue message
↓
Send via WAHA
↓
Track result
↓
Report conversion
```

## Guardrail Broadcast

1. Jangan kirim terlalu agresif.
2. Beri jeda antar pesan.
3. Simpan log pengiriman.
4. Hormati customer yang opt-out.
5. Jangan kirim ke customer blocked.
6. Gunakan template sopan dan relevan.

## Acceptance Criteria

1. Sales bisa membuat campaign draft.
2. Owner/Manager bisa approve campaign.
3. Campaign bisa dijadwalkan.
4. Pesan terkirim via queue.
5. Sistem menampilkan sent/failed/replied/booked.
6. Customer opt-out tidak menerima campaign.

---

# 9.17 Modul Manajemen Layanan

## Tujuan

Mengelola layanan Gayatri.

## Field Service

- name
- category
- description
- benefits
- duration_minutes
- price
- promo_price
- is_home_service_available
- is_active
- sort_order

## Category

- baby_spa
- baby_massage
- mom_treatment
- pregnancy_massage
- postnatal_treatment
- kids_treatment
- home_service
- package
- membership

## Acceptance Criteria

1. Admin bisa CRUD layanan.
2. Layanan aktif muncul di booking dan knowledge base.
3. Layanan nonaktif tidak muncul di booking baru.
4. AI dapat menggunakan data layanan aktif untuk menjawab customer.

---

# 9.18 Modul Manajemen Terapis

## Tujuan

Mengelola data terapis dan jadwal treatment.

## Field Therapist

- name
- phone
- email
- branch_id
- skills
- status
- working_days
- working_hours
- rating
- notes

## Fitur

1. CRUD terapis.
2. Set keahlian layanan.
3. Set jadwal kerja.
4. Lihat booking terapis.
5. Input hasil treatment.
6. Update status hadir/selesai.

## Acceptance Criteria

1. Terapis hanya melihat jadwal sendiri.
2. Admin bisa assign terapis ke booking.
3. Jadwal bentrok dicegah.
4. Terapis bisa update treatment selesai.

---

# 9.19 Modul Feedback dan Rating

## Tujuan

Mengumpulkan feedback customer setelah treatment.

## Field Feedback

- customer_id
- booking_id
- rating
- comment
- source
- sentiment
- created_at

## Flow

1. Booking selesai.
2. Sistem kirim pesan terima kasih.
3. Sistem meminta rating.
4. Customer membalas rating.
5. Sistem simpan feedback.
6. AI analisis sentiment optional.

## Acceptance Criteria

1. Feedback request terkirim setelah booking selesai.
2. Rating tersimpan.
3. Komplain otomatis eskalasi ke admin.
4. Owner bisa melihat rating rata-rata.

---

# 9.20 Modul Reporting dan Analytics

## Report Utama

1. Customer report.
2. Booking report.
3. Revenue report.
4. Campaign report.
5. Follow-up report.
6. AI performance report.
7. Terapis performance report.
8. Promo usage report.

## KPI

1. Total customer.
2. New customer.
3. Chat masuk.
4. Response time.
5. AI reply rate.
6. Human takeover rate.
7. Lead to booking conversion.
8. Booking completed.
9. Booking cancelled.
10. No-show.
11. Repeat order rate.
12. Revenue by service.
13. Revenue by campaign.
14. Campaign conversion.
15. Customer retention.
16. AI escalation rate.

## Acceptance Criteria

1. Report bisa difilter tanggal.
2. Report bisa diekspor CSV/XLSX.
3. Owner bisa melihat KPI utama.
4. Sales bisa melihat KPI campaign.
5. Admin bisa melihat KPI chat dan booking.

---



# 9.21 Modul AI Data Automation

## Tujuan

Modul AI Data Automation memungkinkan AI membaca percakapan customer WhatsApp, mengekstrak data penting, lalu membuat atau memperbarui data di sistem secara otomatis dengan aturan validasi, mode approval, dan audit log.

Modul ini membuat AI tidak hanya menjadi chatbot, tetapi juga menjadi asisten operasional CRM yang dapat:

1. Membuat customer baru otomatis.
2. Melengkapi data customer otomatis.
3. Membuat draft booking dari chat customer.
4. Membuat booking otomatis jika data lengkap dan rule mengizinkan.
5. Membuat reminder otomatis setelah booking.
6. Membuat follow-up task otomatis.
7. Mengubah status lead/customer berdasarkan percakapan.
8. Mengirim data ke modul booking, customer database, reminder, dan follow-up.

## Prinsip Utama

AI boleh melakukan posting data hanya jika:

1. Data customer cukup jelas.
2. Intent customer terdeteksi dengan confidence yang cukup.
3. Topik masih dalam scope layanan Gayatri.
4. Tidak masuk kategori medis, komplain, refund, atau kasus sensitif.
5. Rule automation mengizinkan.
6. Data penting sudah lengkap.
7. Jika rule membutuhkan approval, data hanya dibuat sebagai draft dan menunggu admin.

## Mode Automation

### 1. Auto Create

AI boleh langsung membuat data tanpa approval admin.

Cocok untuk:

- Customer baru dari WhatsApp.
- Update nama customer.
- Update usia bayi.
- Update minat layanan.
- Update alamat umum.
- Membuat follow-up task.
- Membuat reminder setelah booking confirmed.
- Update label customer.

### 2. Need Confirmation

AI membuat draft data, tetapi admin harus menyetujui sebelum data final dibuat.

Cocok untuk:

- Booking baru.
- Reschedule booking.
- Cancel booking.
- Home service dengan alamat baru.
- Update data penting customer.
- Penggunaan promo.
- Perubahan status booking.

### 3. Human Only

AI tidak boleh membuat data. AI hanya memberi saran kepada admin.

Cocok untuk:

- Komplain customer.
- Refund.
- Pertanyaan medis.
- Negosiasi harga.
- Permintaan diskon khusus.
- Customer marah.
- Kasus sensitif ibu/bayi.

## Menu Dashboard

Tambahkan menu:

```text
AI Management
└── AI Data Automation
    ├── Automation Rules
    ├── Extracted Data
    ├── Pending Approval
    ├── Automation Logs
    └── Test Automation
```

## Fitur AI Data Automation

### 1. Auto Create Customer

Ketika nomor WhatsApp baru mengirim pesan, sistem otomatis membuat customer.

Data minimal:

- Nomor WhatsApp
- Nama jika disebutkan
- Source = WhatsApp
- Status = New Lead
- Last chat date
- Interest dari intent percakapan

Contoh chat:

```text
Halo, saya Rina. Mau tanya baby spa untuk anak saya usia 6 bulan.
```

Data yang diekstrak:

```text
Nama customer: Rina
Minat layanan: Baby Spa
Usia bayi: 6 bulan
Status: New Lead
Label: Interested Baby Spa
Source: WhatsApp
```

### 2. Auto Update Customer Data

AI dapat memperbarui data customer berdasarkan percakapan.

Contoh:

```text
Alamat saya di Sidoarjo, mau home service.
```

Update data:

```text
address: Sidoarjo
customer_type: baby_customer
tags: ["home_service_prospect"]
```

### 3. Auto Create Booking Draft

Jika customer ingin booking, AI mengumpulkan data:

- Nama customer
- Nomor WhatsApp
- Layanan
- Tanggal
- Jam
- Cabang atau home service
- Alamat jika home service
- Nama bayi
- Usia bayi
- Catatan khusus

Jika data belum lengkap, AI bertanya ulang.

Contoh:

```text
Baik Bunda, saya bantu buatkan jadwal Baby Spa. Mohon info nama Bunda, nama si kecil, usia bayi, tanggal, jam, dan ingin treatment di cabang atau home service ya.
```

Jika data sudah lengkap, AI membuat booking draft.

Status default:

```text
pending_ai_review
```

atau jika rule mengizinkan auto booking:

```text
pending_confirmation
```

### 4. Auto Create Booking

AI boleh membuat booking otomatis jika:

1. Customer sudah memberikan data lengkap.
2. Jadwal tersedia.
3. Terapis tersedia.
4. Layanan aktif.
5. Tidak ada konflik jadwal.
6. Rule `auto_create_booking` aktif.
7. Confidence score minimal sesuai threshold.
8. Tidak ada indikasi risiko atau pertanyaan sensitif.

Jika berhasil, AI mengirim konfirmasi:

```text
Baik Bunda Rina, booking Baby Spa sudah kami catat untuk Selasa, 24 Juni 2026 pukul 10.00 WIB di cabang Gayatri. Mohon hadir 10 menit sebelum jadwal ya Bunda.
```

### 5. Auto Create Reminder

Setelah booking dibuat atau dikonfirmasi, sistem membuat reminder:

- H-1 sebelum jadwal.
- H-0 beberapa jam sebelum jadwal.
- Reminder pembayaran jika payment status belum paid.
- Follow-up setelah treatment selesai.

### 6. Auto Create Follow-Up Task

AI membuat task follow-up jika:

- Customer bertanya harga tetapi belum booking.
- Customer tertarik promo tetapi belum memilih jadwal.
- Customer tidak membalas setelah penawaran booking.
- Customer selesai treatment dan perlu repeat order.
- Customer lama tidak aktif.

### 7. Auto Update Customer Status

AI dapat mengubah status customer berdasarkan intent:

| Kondisi Chat | Status Customer |
|---|---|
| Baru pertama chat | new_lead |
| Tanya harga/layanan | warm_lead |
| Tanya jadwal/booking | hot_lead |
| Sudah booking | booked |
| Sudah treatment | completed |
| Repeat treatment | repeat_customer |
| Lama tidak aktif | inactive |
| Butuh admin | need_followup |

### 8. Auto Add Tags

AI bisa menambahkan tag:

- interested_baby_spa
- interested_mom_treatment
- home_service_prospect
- promo_hunter
- hot_lead
- need_followup
- repeat_potential
- medical_question
- complaint
- payment_confirmation

## Rule Automation

Admin/Owner dapat mengatur rule.

Field rule:

- Rule name
- Trigger event
- Target entity
- Action
- Mode
- Confidence threshold
- Required fields
- Forbidden intent
- Status
- Created by

Contoh rule:

```text
Rule: Auto Create New Customer from WhatsApp
Trigger: incoming_message_from_unknown_number
Target: customer
Action: create_customer
Mode: auto_create
Confidence threshold: 0.70
Status: active
```

Contoh rule booking:

```text
Rule: Create Booking from Chat
Trigger: detected_booking_intent
Target: booking
Action: create_booking
Mode: need_confirmation
Confidence threshold: 0.85
Required fields: service_id, booking_date, start_time, customer_name
Status: active
```

## AI Extraction Output Format

AI harus mengembalikan JSON agar sistem dapat memproses data secara aman.

```json
{
  "intent": "booking_request",
  "confidence_score": 0.91,
  "should_escalate": false,
  "extracted_data": {
    "customer": {
      "name": "Rina",
      "phone": "6281234567890",
      "baby_age_months": 6,
      "address": "Sidoarjo"
    },
    "booking": {
      "service_name": "Baby Spa",
      "booking_date": "2026-06-24",
      "start_time": "10:00",
      "booking_type": "onsite",
      "branch_name": "Gayatri"
    },
    "followup": {
      "needed": false,
      "reason": null
    }
  },
  "automation_action": {
    "target_entity": "booking",
    "action": "create_draft",
    "mode": "need_confirmation"
  },
  "missing_fields": [],
  "reply_to_customer": "Baik Bunda Rina, saya bantu buatkan jadwal Baby Spa untuk 24 Juni 2026 pukul 10.00 WIB. Mohon tunggu konfirmasi admin Gayatri ya."
}
```

## Flow AI Data Automation

```text
1. Customer mengirim chat WhatsApp.
2. WAHA mengirim webhook ke Laravel.
3. Sistem menyimpan pesan.
4. AI membaca intent dan mengekstrak data.
5. Sistem menjalankan guardrail.
6. Sistem mencari rule automation yang cocok.
7. Sistem memvalidasi data.
8. Jika data kurang, AI bertanya ulang ke customer.
9. Jika mode auto_create, sistem membuat/update data.
10. Jika mode need_confirmation, sistem membuat pending approval.
11. Jika mode human_only, sistem membuat task untuk admin.
12. Semua proses masuk ke automation log.
```

## Flow Auto Booking

```text
1. AI mendeteksi customer ingin booking.
2. AI mengekstrak layanan, tanggal, jam, cabang/home service.
3. Sistem validasi layanan aktif.
4. Sistem validasi jam operasional.
5. Sistem validasi slot terapis.
6. Sistem cek jadwal bentrok.
7. Jika slot tersedia:
   a. Buat booking draft atau booking pending confirmation.
   b. Buat reminder jika booking confirmed.
   c. Kirim pesan konfirmasi ke customer.
8. Jika slot tidak tersedia:
   a. AI menawarkan slot alternatif.
   b. Customer memilih ulang.
```

## Flow Auto Update Customer

```text
1. Customer memberi informasi baru.
2. AI mengekstrak data.
3. Sistem membandingkan dengan data lama.
4. Jika perubahan low risk, update otomatis.
5. Jika perubahan high risk, buat approval.
6. Simpan old value dan new value ke audit log.
```

## Validasi Data

Sistem harus memvalidasi:

1. Nomor WhatsApp valid.
2. Nama customer tidak kosong jika akan booking.
3. Layanan harus ada dan aktif.
4. Tanggal booking tidak boleh di masa lalu.
5. Jam booking harus dalam jam operasional.
6. Terapis tidak boleh bentrok.
7. Promo harus aktif dan belum expired.
8. Booking home service wajib memiliki alamat.
9. Confidence score harus di atas threshold.
10. Intent tidak boleh termasuk forbidden topic.

## Automation Approval

Jika mode `need_confirmation`, sistem membuat approval item.

Admin dapat:

- Approve
- Reject
- Edit before approve
- Assign to admin/sales
- Send confirmation to customer

Status approval:

- pending
- approved
- rejected
- edited
- expired

## UI Pending Approval

Kolom tabel:

- Waktu
- Customer
- Conversation
- Target data
- Action
- Extracted data
- Confidence score
- Mode
- Status
- Action button

Action button:

- View detail
- Approve
- Edit & Approve
- Reject
- Open conversation

## Automation Logs

Simpan semua aktivitas otomatis:

- Pesan input customer.
- Data yang diekstrak AI.
- Rule yang digunakan.
- Action yang dijalankan.
- Status sukses/gagal.
- User yang approve jika ada.
- Error message jika gagal.

## Database Tambahan

### ai_automation_rules

```text
id
name
trigger_event
target_entity
action
mode
confidence_threshold decimal
required_fields json nullable
forbidden_intents json nullable
conditions json nullable
is_active boolean
created_by
updated_by nullable
created_at
updated_at
```

### ai_extracted_data

```text
id
conversation_id nullable
message_id nullable
customer_id nullable
intent
confidence_score decimal
extracted_customer_data json nullable
extracted_booking_data json nullable
extracted_followup_data json nullable
missing_fields json nullable
raw_ai_response json nullable
status
created_at
updated_at
```

### ai_automation_approvals

```text
id
ai_extracted_data_id
customer_id nullable
conversation_id nullable
target_entity
action
mode
proposed_data json
status
approved_by nullable
approved_at nullable
rejected_by nullable
rejected_at nullable
rejection_reason text nullable
edited_data json nullable
expires_at nullable
created_at
updated_at
```

### ai_automation_logs

```text
id
ai_automation_rule_id nullable
ai_extracted_data_id nullable
ai_automation_approval_id nullable
conversation_id nullable
message_id nullable
customer_id nullable
target_entity
action
mode
status
input_payload json nullable
output_payload json nullable
error_message text nullable
created_by_ai boolean
approved_by nullable
created_at
updated_at
```

## Routes Tambahan

```text
GET    /admin/ai/data-automation
GET    /admin/ai/data-automation/rules
POST   /admin/ai/data-automation/rules
PUT    /admin/ai/data-automation/rules/{rule}
POST   /admin/ai/data-automation/rules/{rule}/activate
POST   /admin/ai/data-automation/rules/{rule}/deactivate

GET    /admin/ai/data-automation/extracted-data
GET    /admin/ai/data-automation/approvals
GET    /admin/ai/data-automation/approvals/{approval}
POST   /admin/ai/data-automation/approvals/{approval}/approve
POST   /admin/ai/data-automation/approvals/{approval}/edit-approve
POST   /admin/ai/data-automation/approvals/{approval}/reject

GET    /admin/ai/data-automation/logs
GET    /admin/ai/data-automation/test
POST   /admin/ai/data-automation/test
```

## Jobs Tambahan

Tambahkan queue job:

1. `ExtractDataFromIncomingMessageJob`
2. `ProcessAiAutomationRuleJob`
3. `CreateCustomerFromAiDataJob`
4. `UpdateCustomerFromAiDataJob`
5. `CreateBookingFromAiDataJob`
6. `CreateFollowupFromAiDataJob`
7. `CreateReminderFromAiDataJob`
8. `CreateAiAutomationApprovalJob`

## Services Tambahan

Tambahkan service:

```text
app/Services/AI/AiDataExtractionService.php
app/Services/AI/AiAutomationRuleService.php
app/Services/AI/AiAutomationApprovalService.php
app/Services/AI/AiAutomationExecutorService.php
app/Services/CRM/AutoBookingService.php
```

## Environment Variables Tambahan

Tambahkan ke `.env.example`:

```env
CRM_AI_DATA_AUTOMATION_ENABLED=true
CRM_AI_AUTO_CREATE_CUSTOMER=true
CRM_AI_AUTO_UPDATE_CUSTOMER=true
CRM_AI_AUTO_CREATE_BOOKING=false
CRM_AI_BOOKING_REQUIRES_APPROVAL=true
CRM_AI_DATA_EXTRACTION_CONFIDENCE_THRESHOLD=0.75
CRM_AI_BOOKING_CONFIDENCE_THRESHOLD=0.85
CRM_AI_AUTOMATION_APPROVAL_EXPIRE_HOURS=24
```

## Acceptance Criteria

1. Sistem otomatis membuat customer baru dari nomor WhatsApp baru.
2. AI bisa mengekstrak nama customer, usia bayi, minat layanan, alamat, dan intent booking.
3. AI bisa memperbarui data customer jika rule mengizinkan.
4. AI bisa membuat booking draft dari percakapan.
5. Booking otomatis tidak boleh dibuat jika data belum lengkap.
6. Booking otomatis tidak boleh dibuat jika jadwal bentrok.
7. Booking dengan mode approval harus masuk ke Pending Approval.
8. Admin bisa approve, reject, atau edit sebelum approve.
9. Setelah booking disetujui, customer menerima konfirmasi WhatsApp.
10. Setelah booking confirmed, reminder otomatis dibuat.
11. AI bisa membuat follow-up task otomatis.
12. Semua aksi otomatis tersimpan di automation log.
13. AI tidak boleh membuat data untuk pertanyaan medis, refund, komplain, atau kasus sensitif.
14. Owner bisa mengaktifkan/menonaktifkan rule automation.
15. AI Data Automation bisa diuji melalui halaman test automation tanpa mengirim pesan ke customer.

## Demo Scenario

### Scenario 1 — Auto Create Customer

Input:

```text
Halo saya Rina, mau tanya baby spa untuk anak usia 6 bulan.
```

Expected:

- Customer baru dibuat.
- Nama = Rina.
- Baby age = 6 bulan.
- Interest = Baby Spa.
- Status = warm_lead.
- AI log dan automation log tersimpan.

### Scenario 2 — Booking Draft

Input:

```text
Saya mau booking Baby Spa besok jam 10 di cabang Gayatri.
```

Expected:

- AI mengekstrak layanan, tanggal, dan jam.
- Sistem membuat booking draft.
- Data masuk Pending Approval.
- Admin bisa approve.
- Setelah approve, booking dibuat dan customer menerima konfirmasi.

### Scenario 3 — Booking Data Kurang

Input:

```text
Mau booking besok.
```

Expected:

- AI tidak membuat booking.
- AI menanyakan layanan dan jam.
- Missing fields tersimpan.
- Conversation tetap open.

### Scenario 4 — Medical Question

Input:

```text
Bayi saya demam, bisa dipijat tidak?
```

Expected:

- AI tidak membuat booking otomatis.
- AI memberikan medical fallback.
- Conversation dieskalasi ke admin.
- Automation tidak berjalan.
- Log mencatat forbidden intent.

### Scenario 5 — Auto Follow-Up

Input:

```text
Berapa harga baby spa?
```

Expected:

- AI menjawab harga berdasarkan knowledge base.
- Customer diberi status warm_lead.
- Follow-up task dibuat jika customer tidak booking.
- Sales melihat task di menu Follow-Up.

# 10. Database Schema Awal

AI agent harus membuat migration sesuai tabel berikut.

## 10.1 users

```text
id
name
email
password
phone
role
status
last_login_at
created_at
updated_at
```

## 10.2 customers

```text
id
name
phone
whatsapp_number
email
address
city
birth_date
baby_name
baby_birth_date
baby_age_months
pregnancy_age_weeks
customer_type
source
status
label
tags json
total_booking integer
total_spent decimal
last_booking_at
last_chat_at
last_followup_at
notes text
created_at
updated_at
```

## 10.3 branches

```text
id
name
address
city
phone
opening_hours json
status
created_at
updated_at
```

## 10.4 services

```text
id
name
category
description text
benefits text
duration_minutes integer
price decimal
promo_price decimal nullable
is_home_service_available boolean
is_active boolean
sort_order integer
created_at
updated_at
```

## 10.5 therapists

```text
id
user_id nullable
name
phone
email
branch_id
skills json
status
working_days json
working_hours json
rating decimal
notes text
created_at
updated_at
```

## 10.6 whatsapp_sessions

```text
id
session_name
phone_number nullable
status
qr_code text nullable
last_connected_at
last_disconnected_at
metadata json
created_at
updated_at
```

## 10.7 conversations

```text
id
customer_id
whatsapp_session_id nullable
wa_chat_id
status
assigned_user_id nullable
ai_enabled boolean
last_message_at
last_incoming_at
last_outgoing_at
unread_count integer
summary text nullable
tags json
created_at
updated_at
```

## 10.8 messages

```text
id
conversation_id
customer_id nullable
wa_message_id nullable
direction
sender_type
sender_user_id nullable
message_type
body text
media_url nullable
status
sent_at nullable
delivered_at nullable
read_at nullable
metadata json
created_at
updated_at
```

## 10.9 bookings

```text
id
booking_code
customer_id
service_id
therapist_id nullable
branch_id nullable
booking_date date
start_time time
end_time time
duration_minutes
booking_type
status
payment_status
total_amount decimal
discount_amount decimal
final_amount decimal
promo_id nullable
source
notes text
created_by nullable
created_at
updated_at
```

## 10.10 reminders

```text
id
customer_id
booking_id nullable
reminder_type
scheduled_at datetime
message text
status
sent_at nullable
failed_reason text nullable
metadata json
created_at
updated_at
```

## 10.11 followups

```text
id
customer_id
conversation_id nullable
booking_id nullable
assigned_user_id nullable
followup_type
priority
status
due_at datetime
message_suggestion text nullable
result text nullable
completed_at nullable
created_at
updated_at
```

## 10.12 promos

```text
id
name
code
description text
discount_type
discount_value decimal
service_id nullable
minimum_transaction decimal
quota integer nullable
used_count integer
valid_from date
valid_until date
target_segment json nullable
status
created_at
updated_at
```

## 10.13 campaigns

```text
id
name
type
segment_rule json
promo_id nullable
template_message text
scheduled_at datetime nullable
status
total_target integer
total_sent integer
total_failed integer
total_replied integer
total_booked integer
created_by
created_at
updated_at
```

## 10.14 campaign_recipients

```text
id
campaign_id
customer_id
phone
status
sent_at nullable
replied_at nullable
booked_at nullable
failed_reason text nullable
created_at
updated_at
```

## 10.15 ai_personas

```text
id
name
assistant_name
brand_name
tone
language
greeting_style
main_role text
allowed_topics json
forbidden_topics json
escalation_rules json
fallback_message text
is_active boolean
created_by
created_at
updated_at
```

## 10.16 knowledge_bases

```text
id
title
category
content longtext
source_type
file_path nullable
tags json
status
valid_from nullable
valid_until nullable
created_by
updated_by nullable
created_at
updated_at
```

## 10.17 knowledge_chunks

```text
id
knowledge_base_id
chunk_index integer
content text
embedding json nullable
metadata json nullable
created_at
updated_at
```

## 10.18 ai_logs

```text
id
conversation_id nullable
message_id nullable
customer_id nullable
ai_persona_id nullable
type
input_text longtext
retrieved_knowledge json nullable
prompt longtext nullable
response longtext
confidence_score decimal nullable
is_escalated boolean
escalation_reason text nullable
status
created_at
updated_at
```

## 10.19 feedbacks

```text
id
customer_id
booking_id
rating integer
comment text nullable
source
sentiment nullable
created_at
updated_at
```

## 10.20 audit_logs

```text
id
user_id nullable
action
entity_type
entity_id nullable
old_values json nullable
new_values json nullable
ip_address
user_agent
created_at
```

---

# 11. Laravel Routes yang Disarankan

## 11.1 Admin Web Routes

```text
GET    /admin/dashboard
GET    /admin/customers
GET    /admin/customers/create
POST   /admin/customers
GET    /admin/customers/{id}
GET    /admin/customers/{id}/edit
PUT    /admin/customers/{id}

GET    /admin/inbox
GET    /admin/inbox/{conversation}
POST   /admin/inbox/{conversation}/reply
POST   /admin/inbox/{conversation}/ai-suggest
POST   /admin/inbox/{conversation}/takeover
POST   /admin/inbox/{conversation}/close

GET    /admin/bookings
GET    /admin/bookings/calendar
POST   /admin/bookings
PUT    /admin/bookings/{booking}
POST   /admin/bookings/{booking}/confirm
POST   /admin/bookings/{booking}/complete
POST   /admin/bookings/{booking}/cancel
POST   /admin/bookings/{booking}/reschedule

GET    /admin/followups
POST   /admin/followups/{followup}/send
POST   /admin/followups/{followup}/complete

GET    /admin/retention
GET    /admin/retention/inactive
POST   /admin/retention/generate-followups

GET    /admin/promos
POST   /admin/promos
PUT    /admin/promos/{promo}

GET    /admin/campaigns
POST   /admin/campaigns
POST   /admin/campaigns/{campaign}/schedule
POST   /admin/campaigns/{campaign}/cancel

GET    /admin/ai/personas
POST   /admin/ai/personas
PUT    /admin/ai/personas/{persona}
POST   /admin/ai/personas/{persona}/activate

GET    /admin/ai/knowledge-base
POST   /admin/ai/knowledge-base
PUT    /admin/ai/knowledge-base/{knowledge}
POST   /admin/ai/knowledge-base/upload

GET    /admin/ai/simulator
POST   /admin/ai/simulator/test

GET    /admin/ai/logs

GET    /admin/whatsapp/session
POST   /admin/whatsapp/session/start
POST   /admin/whatsapp/session/stop
POST   /admin/whatsapp/session/logout
GET    /admin/whatsapp/session/qr
GET    /admin/whatsapp/session/status

GET    /admin/reports
GET    /admin/settings
```

## 11.2 Webhook Routes

```text
POST /webhooks/waha/messages
POST /webhooks/waha/status
POST /webhooks/waha/qr
```

---

# 12. Jobs dan Scheduler

## 12.1 Queue Jobs

Buat job berikut:

1. `ProcessIncomingWhatsAppMessageJob`
2. `GenerateAiReplyJob`
3. `SendWhatsAppMessageJob`
4. `SendBookingReminderJob`
5. `ProcessCampaignRecipientJob`
6. `GenerateFollowupRecommendationJob`
7. `UpdateCustomerMetricsJob`
8. `SummarizeConversationJob`
9. `AnalyzeFeedbackSentimentJob`

## 12.2 Scheduler

Laravel scheduler menjalankan:

```text
Every minute:
- Check due reminders
- Check scheduled campaigns

Every hour:
- Generate inactive customer follow-up candidates
- Update customer lead score

Daily:
- Generate retention segments
- Generate dashboard metrics snapshot
- Check expired promos
```

---

# 13. AI Prompt Engineering

## 13.1 System Prompt Template

```text
Kamu adalah {assistant_name}, AI customer service resmi {brand_name}.

Gaya bahasa:
- Bahasa: {language}
- Tone: {tone}
- Sapaan: {greeting_style}
- Ramah, sopan, lembut, dan profesional.

Tugas utama:
{main_role}

Aturan penting:
1. Jawab hanya seputar layanan, booking, jadwal, promo, reminder, follow-up, lokasi, dan informasi resmi {brand_name}.
2. Gunakan hanya informasi dari KNOWLEDGE BASE yang diberikan.
3. Jangan membuat informasi baru jika tidak tersedia.
4. Jangan memberi diagnosis medis, rekomendasi obat, atau tindakan medis.
5. Jangan menjanjikan diskon jika tidak ada promo aktif.
6. Jika pertanyaan di luar scope, gunakan fallback atau eskalasi ke admin.
7. Jika customer ingin booking, arahkan dengan menanyakan layanan, tanggal, jam, dan cabang/home service.
8. Jawaban harus singkat, jelas, dan WhatsApp-friendly.
```

## 13.2 User Context Template

```text
Data Customer:
Nama: {customer_name}
Nomor: {phone}
Status: {customer_status}
Riwayat Booking: {booking_history}
Booking Terakhir: {last_booking}
Catatan: {notes}

Percakapan Terakhir:
{recent_messages}

Knowledge Base Relevan:
{retrieved_knowledge}

Pertanyaan Customer:
{incoming_message}
```

## 13.3 Output Format AI

AI harus mengembalikan JSON agar mudah diproses.

```json
{
  "reply": "Isi jawaban WhatsApp ke customer",
  "intent": "booking_info|pricing|promo|complaint|medical|followup|other",
  "confidence_score": 0.92,
  "should_escalate": false,
  "escalation_reason": null,
  "suggested_customer_status": "warm_lead",
  "suggested_next_action": "offer_booking"
}
```

## 13.4 AI Guardrail Logic

Pseudo-code:

```text
if intent in forbidden_topics:
    should_escalate = true
    reply = medical_or_scope_fallback

if confidence_score < 0.65:
    should_escalate = true
    reply = fallback_message

if no_relevant_knowledge_found:
    should_escalate = true
    reply = fallback_message

if customer asks admin/human:
    should_escalate = true
    reply = "Baik Bunda, saya bantu teruskan ke admin Gayatri ya."
```

---

# 14. UI Pages untuk AI Agent

## 14.1 Sidebar Menu

```text
Dashboard
Customers
WhatsApp Inbox
Bookings
Calendar
Follow-Up
Customer Retention
Promos & Vouchers
Campaigns
Services
Therapists
AI Management
  - Persona
  - Knowledge Base
  - Scope & Guardrail
  - Data Automation
  - Simulator
  - AI Logs
Reports
Settings
WhatsApp Gateway
Users & Roles
```

## 14.2 Halaman Dashboard

Komponen:

- KPI cards
- Chart booking
- Chart chat
- Today booking list
- Pending follow-up
- AI alert
- Campaign performance

## 14.3 Halaman Inbox

Komponen:

- Conversation list
- Chat window
- Customer sidebar
- AI suggestion panel
- Booking quick action
- Follow-up quick action

## 14.4 Halaman Customer Detail

Tab:

- Overview
- Conversations
- Bookings
- Follow-Ups
- Campaigns
- Feedback
- Notes

## 14.5 Halaman Booking Calendar

Komponen:

- Calendar day/week/month
- Booking status color
- Filter therapist
- Filter branch
- Add booking modal
- Reschedule action

## 14.6 Halaman AI Knowledge Base

Komponen:

- List knowledge
- Add manual knowledge
- Upload document
- Category filter
- Active/inactive toggle
- Valid date

## 14.7 Halaman AI Simulator

Komponen:

- Input test message
- Select persona
- Select customer sample optional
- Generate answer
- Show response
- Show confidence score
- Show retrieved knowledge
- Save as template

---

# 15. User Stories

## 15.1 Owner

```text
Sebagai Owner, saya ingin melihat dashboard ringkasan customer, booking, dan campaign agar saya bisa memonitor performa bisnis.
```

Acceptance:

- Owner melihat KPI utama.
- Owner bisa filter tanggal.
- Owner bisa melihat data campaign dan repeat order.

## 15.2 Admin

```text
Sebagai Admin, saya ingin membalas chat WhatsApp customer dari dashboard agar tidak perlu membuka HP terus-menerus.
```

Acceptance:

- Chat masuk muncul di inbox.
- Admin bisa membalas.
- Balasan terkirim ke WhatsApp customer.
- Chat tersimpan di histori.

## 15.3 Sales

```text
Sebagai Sales, saya ingin melihat customer lama yang belum booking agar bisa mengirim promo follow-up.
```

Acceptance:

- Sales bisa melihat segment customer tidak aktif.
- Sales bisa generate pesan AI.
- Sales bisa mengirim WhatsApp.
- Hasil follow-up tersimpan.

## 15.4 Terapis

```text
Sebagai Terapis, saya ingin melihat jadwal treatment saya agar bisa mengetahui customer yang harus dilayani.
```

Acceptance:

- Terapis melihat jadwal sendiri.
- Terapis melihat detail layanan.
- Terapis bisa menandai treatment selesai.

## 15.5 AI Assistant

```text
Sebagai AI Assistant, saya harus menjawab chat customer berdasarkan knowledge base dan tidak boleh keluar dari scope.
```

Acceptance:

- AI menggunakan knowledge base aktif.
- AI menolak pertanyaan medis dengan fallback.
- AI mengalihkan ke admin jika confidence rendah.
- AI menyimpan log.

---

# 16. Flow Detail

## 16.1 Flow Chat Masuk dan Auto Reply

```text
1. Customer kirim WhatsApp.
2. WAHA menerima pesan.
3. WAHA kirim webhook ke Laravel.
4. Laravel validasi payload.
5. Sistem cari customer berdasarkan nomor.
6. Jika belum ada, buat customer baru.
7. Sistem cari/buat conversation.
8. Simpan pesan incoming.
9. Jika AI enabled:
   a. Dispatch GenerateAiReplyJob.
   b. Ambil persona aktif.
   c. Ambil knowledge base relevan.
   d. Generate response.
   e. Cek guardrail.
   f. Jika aman, kirim pesan via WAHA.
   g. Simpan pesan outgoing.
   h. Simpan AI log.
10. Jika AI disabled atau eskalasi:
   a. Tandai conversation need admin.
   b. Tampilkan di inbox admin.
```

## 16.2 Flow Booking dari Chat

```text
1. Customer ingin booking.
2. Admin klik "Create Booking" dari inbox.
3. Sistem prefill customer.
4. Admin pilih layanan.
5. Admin pilih tanggal dan jam.
6. Sistem cek ketersediaan terapis.
7. Admin pilih terapis/cabang.
8. Admin klik Confirm.
9. Booking dibuat.
10. Sistem kirim konfirmasi WhatsApp.
11. Sistem buat reminder otomatis.
12. Booking muncul di calendar.
```

## 16.3 Flow Reminder

```text
1. Booking confirmed.
2. Sistem membuat reminder H-1 dan H-0.
3. Scheduler mengecek reminder due.
4. Job kirim reminder via WAHA.
5. Reminder status menjadi sent.
6. Jika gagal, status failed dan admin bisa retry.
```

## 16.4 Flow Follow-Up Customer Lama

```text
1. Scheduler harian mencari customer tidak aktif.
2. Sistem segmentasi customer.
3. AI memberi rekomendasi promo/pesan.
4. Sales melihat daftar retention.
5. Sales memilih customer.
6. Sales kirim pesan promo.
7. Jika customer membalas, conversation terbuka.
8. Jika customer booking, conversion tercatat.
```

## 16.5 Flow Campaign WhatsApp

```text
1. Sales membuat campaign.
2. Pilih segment customer.
3. Pilih promo/template.
4. Sistem preview target.
5. Manager/Owner approve.
6. Campaign dijadwalkan.
7. Scheduler menjalankan campaign.
8. Pesan dikirim bertahap via queue.
9. Sistem track sent/failed/replied/booked.
10. Report campaign tersedia.
```

---

# 17. Acceptance Criteria Global

Aplikasi dianggap selesai MVP jika:

1. User bisa login sesuai role.
2. Dashboard overview tampil.
3. Customer database bisa CRUD.
4. WhatsApp Gateway WAHA bisa connect via QR.
5. Pesan WhatsApp masuk ke inbox.
6. Admin bisa membalas WhatsApp dari dashboard.
7. AI bisa auto reply berdasarkan persona dan knowledge base.
8. AI guardrail aktif untuk pertanyaan luar scope.
9. Admin bisa mengelola knowledge base.
10. Admin bisa membuat booking.
11. Reminder otomatis terkirim.
12. Sales bisa melihat follow-up lead.
13. Sales bisa melihat customer lama tidak aktif.
14. Sales bisa membuat promo.
15. Sales bisa membuat campaign sederhana.
16. Owner bisa melihat laporan dasar.
17. Semua pesan, booking, reminder, dan AI log tersimpan.
18. AI dapat mengekstrak data customer dan membuat draft booking secara otomatis sesuai rule.
19. AI Data Automation memiliki approval mode, automation log, dan guardrail.

---

# 18. Non-Functional Requirements

## 18.1 Security

1. Password harus di-hash.
2. Role permission wajib diterapkan.
3. Webhook WAHA harus divalidasi dengan secret/API key.
4. File upload harus dibatasi tipe dan ukuran.
5. Data customer tidak boleh tampil ke user tanpa permission.
6. Audit log untuk aksi penting.

## 18.2 Performance

1. Inbox harus bisa memuat conversation dengan pagination.
2. Campaign harus menggunakan queue.
3. Reminder harus menggunakan scheduler dan queue.
4. AI processing sebaiknya async.
5. Dashboard harus menggunakan aggregate query yang efisien.

## 18.3 Reliability

1. Pesan gagal harus bisa retry.
2. Session WAHA disconnect harus terdeteksi.
3. Reminder gagal harus tercatat.
4. Campaign gagal per recipient tidak boleh menghentikan campaign lain.
5. AI error harus fallback ke admin.

## 18.4 Maintainability

1. Gunakan service class untuk logic besar.
2. Controller tetap tipis.
3. Gunakan Form Request untuk validasi.
4. Gunakan migration dan seeder.
5. Gunakan enum/constant untuk status.
6. Gunakan policy untuk permission.

## 18.5 Compliance dan Safety

1. AI tidak memberi diagnosis medis.
2. AI tidak memberi rekomendasi obat.
3. AI tidak menjawab topik di luar layanan.
4. Customer bisa opt-out dari promo.
5. Chat sensitif bisa dieskalasi ke manusia.

---

# 19. Seed Data Awal

AI agent harus membuat seeder untuk data berikut.

## 19.1 User

```text
Owner:
email: owner@gayatri.local
password: password
role: owner

Admin:
email: admin@gayatri.local
password: password
role: admin

Sales:
email: sales@gayatri.local
password: password
role: sales

Terapis:
email: terapis@gayatri.local
password: password
role: therapist
```

## 19.2 Services

1. Baby Spa
2. Baby Massage
3. Kids Massage
4. Pregnancy Massage
5. Postnatal Massage
6. Mom Treatment
7. Home Service
8. Paket Baby Spa
9. Paket Membership

## 19.3 AI Persona Default

Gunakan persona default Gayatri Assistant.

## 19.4 Knowledge Base Default

1. FAQ layanan.
2. FAQ booking.
3. FAQ reschedule.
4. Guardrail medis.
5. Template follow-up.
6. Template reminder.
7. Template promo customer lama.

---

# 20. Environment Variables

Tambahkan variabel berikut di `.env.example`:

```env
APP_NAME="Gayatri CRM WhatsApp AI"

WAHA_BASE_URL=http://localhost:3000
WAHA_API_KEY=
WAHA_DEFAULT_SESSION=default
WAHA_WEBHOOK_SECRET=

AI_PROVIDER=
AI_API_KEY=
AI_MODEL=
AI_TEMPERATURE=0.3
AI_MAX_TOKENS=800

CRM_DEFAULT_AI_MODE=auto_reply
CRM_AI_CONFIDENCE_THRESHOLD=0.65
CRM_REMINDER_H1_ENABLED=true
CRM_REMINDER_H0_ENABLED=true
CRM_CAMPAIGN_RATE_LIMIT_SECONDS=10
```

---

# 21. Development Phases untuk AI Agent

## Phase 1 — Foundation

Bangun:

1. Laravel project setup.
2. Auth.
3. Role permission.
4. Layout dashboard.
5. Sidebar menu.
6. Database migration utama.
7. Seeder user dan data awal.

Output phase:

- User bisa login.
- Menu tampil sesuai role.
- Database siap.

## Phase 2 — Master Data CRM

Bangun:

1. Customer CRUD.
2. Service CRUD.
3. Branch CRUD.
4. Therapist CRUD.
5. Customer detail dengan tab.

Output phase:

- Admin bisa mengelola customer.
- Admin bisa mengelola layanan dan terapis.

## Phase 3 — WhatsApp Gateway

Bangun:

1. WAHA service class.
2. WhatsApp session page.
3. Start/stop/logout session.
4. QR display.
5. Webhook receive message.
6. Conversation dan message storage.
7. Inbox UI.
8. Manual reply.

Output phase:

- WhatsApp bisa connect.
- Chat masuk tampil.
- Admin bisa reply.

## Phase 4 — AI Management

Bangun:

1. AI Persona CRUD.
2. Knowledge Base CRUD.
3. Guardrail setting.
4. AI Service.
5. AI Auto Reply.
6. AI Logs.
7. AI Simulator.
8. AI Data Automation Rules.
9. Extracted Data.
10. Pending Approval.
11. Automation Logs.

Output phase:

- AI bisa menjawab chat.
- AI dibatasi scope.
- AI log tersimpan.
- AI bisa mengekstrak data customer dan membuat draft automation dengan approval.

## Phase 5 — Booking dan Reminder

Bangun:

1. Booking CRUD.
2. Calendar view.
3. Schedule conflict validation.
4. Booking confirmation WhatsApp.
5. Reminder scheduler.
6. Reminder log.

Output phase:

- Booking bisa dibuat.
- Reminder otomatis terkirim.

## Phase 6 — Follow-Up dan Retention

Bangun:

1. Follow-up lead.
2. Customer lama inactive segment.
3. AI follow-up suggestion.
4. Follow-up send WhatsApp.
5. Follow-up result tracking.

Output phase:

- Sales bisa follow-up lead dan customer lama.

## Phase 7 — Promo dan Campaign

Bangun:

1. Promo CRUD.
2. Campaign builder.
3. Segment target.
4. Campaign queue.
5. Campaign report.
6. Opt-out handling.

Output phase:

- Sales bisa kirim campaign promo tersegmentasi.

## Phase 8 — Reporting dan Polish

Bangun:

1. Dashboard KPI.
2. Report module.
3. Export report.
4. UI polish.
5. Error handling.
6. Audit log.
7. Documentation.

Output phase:

- MVP siap demo ke client.

---

# 22. Coding Guidelines untuk AI Agent

## 22.1 Laravel Structure

Gunakan struktur:

```text
app/
  Services/
    WhatsApp/
      WahaService.php
    AI/
      AiService.php
      AiGuardrailService.php
      KnowledgeRetrievalService.php
    CRM/
      CustomerService.php
      BookingService.php
      ReminderService.php
      CampaignService.php
      RetentionService.php

  Jobs/
    ProcessIncomingWhatsAppMessageJob.php
    GenerateAiReplyJob.php
    SendWhatsAppMessageJob.php
    SendBookingReminderJob.php
    ProcessCampaignRecipientJob.php

  Models/
    Customer.php
    Conversation.php
    Message.php
    Booking.php
    Reminder.php
    Followup.php
    Promo.php
    Campaign.php
    AiPersona.php
    KnowledgeBase.php
    AiLog.php

  Http/
    Controllers/
      Admin/
      Webhook/
    Requests/
```

## 22.2 Controller Rule

Controller hanya untuk:

- Validate request.
- Call service.
- Return view/response.

Business logic harus di service.

## 22.3 Status Constants

Buat enum/constant untuk:

- CustomerStatus
- BookingStatus
- PaymentStatus
- ConversationStatus
- ReminderStatus
- CampaignStatus
- AiLogStatus

## 22.4 Error Handling

Jika WAHA error:

- Tampilkan pesan di UI.
- Simpan error log.
- Jangan crash aplikasi.

Jika AI error:

- Conversation status menjadi `escalated`.
- Buat log.
- Admin diberi notifikasi.

---

# 23. Sample WAHA Service Pseudo-Code

```php
class WahaService
{
    public function startSession(string $session = 'default'): array
    {
        return Http::withHeaders($this->headers())
            ->post($this->baseUrl() . "/api/sessions/{$session}/start")
            ->json();
    }

    public function getQr(string $session = 'default'): string
    {
        return Http::withHeaders($this->headers())
            ->get($this->baseUrl() . "/api/{$session}/auth/qr", [
                'format' => 'raw'
            ])
            ->body();
    }

    public function sendText(string $chatId, string $text, string $session = 'default'): array
    {
        return Http::withHeaders($this->headers())
            ->post($this->baseUrl() . "/api/sendText", [
                'session' => $session,
                'chatId' => $chatId,
                'text' => $text,
            ])
            ->json();
    }
}
```

Catatan untuk AI agent:

- Sesuaikan endpoint dengan versi WAHA yang digunakan di environment.
- Buat konfigurasi endpoint di config file.
- Jangan hardcode URL WAHA di controller.

---

# 24. Sample AI Service Pseudo-Code

```php
class AiService
{
    public function generateReply(Conversation $conversation, Message $message): array
    {
        $persona = AiPersona::active()->first();
        $knowledge = app(KnowledgeRetrievalService::class)
            ->retrieve($message->body);

        $guardrail = app(AiGuardrailService::class)
            ->check($message->body, $knowledge);

        if ($guardrail->shouldEscalate()) {
            return [
                'reply' => $guardrail->fallbackMessage(),
                'should_escalate' => true,
                'confidence_score' => 0,
                'intent' => $guardrail->intent(),
            ];
        }

        $prompt = $this->buildPrompt($persona, $knowledge, $conversation, $message);

        $response = $this->callProvider($prompt);

        return $this->parseJsonResponse($response);
    }
}
```

---

# 25. Demo Scenario untuk Testing

## Scenario 1 — Customer Tanya Layanan

Input WhatsApp:

```text
Halo, mau tanya baby spa
```

Expected:

- Customer dibuat.
- Conversation dibuat.
- AI menjawab info Baby Spa.
- AI menawarkan cek jadwal.
- AI log tersimpan.

## Scenario 2 — Customer Tanya Medis

Input:

```text
Bayi saya demam, boleh baby spa?
```

Expected:

- AI tidak memberi diagnosis.
- AI menyarankan konsultasi dokter.
- Conversation dieskalasi ke admin.
- AI log mencatat forbidden medical topic.

## Scenario 3 — Customer Booking

Input:

```text
Saya mau booking baby spa besok jam 10
```

Expected:

- AI/admin meminta data tambahan jika belum lengkap.
- Admin bisa create booking dari inbox.
- Booking confirmed.
- Reminder dibuat.

## Scenario 4 — Customer Lama Tidak Aktif

Kondisi:

- Customer terakhir booking 60 hari lalu.

Expected:

- Masuk segment inactive 60 days.
- AI membuat rekomendasi follow-up.
- Sales bisa kirim promo.
- Jika customer booking, conversion retention tercatat.

## Scenario 5 — Campaign Promo

Input:

- Campaign target customer baby spa inactive 30 days.

Expected:

- Sistem preview target.
- Campaign dikirim bertahap.
- Result sent/failed/replied tercatat.

---

# 26. Definition of Done

Fitur dianggap selesai jika:

1. Migration dan model tersedia.
2. Controller dan service tersedia.
3. UI bisa digunakan.
4. Validasi input aktif.
5. Permission diterapkan.
6. Error handling ada.
7. Data tersimpan benar.
8. Acceptance criteria terpenuhi.
9. Minimal ada seed/test data.
10. Tidak ada hardcoded secret.
11. Flow utama sudah diuji manual.
12. Dokumentasi singkat tersedia.

---

# 27. Prioritas MVP

## Must Have

1. Login dan role.
2. Dashboard.
3. Customer database.
4. WhatsApp WAHA QR.
5. Inbox WhatsApp.
6. Manual reply.
7. AI persona.
8. Knowledge base.
9. AI auto reply.
10. AI guardrail.
11. AI Data Automation basic.
12. Booking.
13. Reminder.
14. Follow-up customer.
15. Customer retention.
16. Promo basic.
17. Campaign basic.

## Should Have

1. AI simulator.
2. AI log detail.
3. Calendar view.
4. Campaign report.
5. Customer import/export.
6. Feedback rating.

## Could Have

1. Payment gateway.
2. Loyalty point.
3. Membership advanced.
4. Mobile app.
5. WhatsApp Business API official.
6. Instagram DM integration.

---

# 28. Catatan Risiko

## 28.1 WhatsApp Gateway

WAHA berbasis WhatsApp Web dan bukan WhatsApp Business API resmi. Risiko:

- Session logout.
- QR scan ulang.
- Pembatasan akun jika spam.
- Perubahan WhatsApp Web bisa memengaruhi gateway.

Mitigasi:

- Gunakan rate limit.
- Jangan spam broadcast.
- Sediakan status monitoring session.
- Siapkan roadmap migrasi ke WhatsApp Business API resmi.

## 28.2 AI

Risiko:

- AI menjawab tidak sesuai.
- AI memberi informasi di luar knowledge base.
- AI menjawab topik medis.

Mitigasi:

- Gunakan guardrail.
- Gunakan fallback.
- Gunakan confidence threshold.
- Simpan AI log.
- Human takeover.
- Approval mode untuk fase awal.

## 28.3 Data Customer

Risiko:

- Data customer sensitif.
- Akses tidak sah.

Mitigasi:

- Role permission.
- Audit log.
- Backup database.
- Masking nomor jika perlu.
- Batasi export data.

---

# 29. Prompt Singkat untuk AI Coding Agent

Gunakan prompt berikut saat memulai vibecoding:

```text
Bangun aplikasi Laravel bernama "Gayatri CRM WhatsApp AI" berdasarkan PRD ini.

Prioritas pertama:
1. Setup auth dan role.
2. Buat layout dashboard.
3. Buat migration/model untuk customer, conversation, message, booking, reminder, promo, campaign, ai_persona, knowledge_base, ai_log.
4. Buat WhatsApp Gateway module menggunakan WAHA REST API.
5. Buat WhatsApp Inbox dengan conversation list dan chat view.
6. Buat AI Management: Persona, Knowledge Base, Guardrail, Simulator, Logs.
7. Buat AI auto reply flow yang hanya menjawab berdasarkan knowledge base dan tidak keluar scope.
8. Buat AI Data Automation agar AI dapat mengekstrak data customer, membuat/update customer, membuat booking draft, follow-up task, reminder, dan approval log.
9. Buat Booking dan Reminder.
10. Buat Follow-Up dan Customer Retention.
11. Buat Promo dan Campaign basic.

Gunakan service class untuk business logic, queue untuk proses kirim pesan dan AI, serta scheduler untuk reminder dan campaign.
Jangan hardcode secret. Gunakan env dan config.
Pastikan semua fitur memiliki acceptance criteria sesuai PRD.
```

---

# 30. Kesimpulan

Aplikasi Gayatri CRM WhatsApp AI adalah CRM khusus untuk Mom & Baby SPA yang menggabungkan WhatsApp Gateway, booking, reminder, follow-up, promo campaign, customer retention, dan AI Assistant.

PRD ini disusun agar AI coding agent dapat langsung memahami kebutuhan produk dan membangun aplikasi secara bertahap dengan pendekatan modular.

Target akhir MVP adalah aplikasi dashboard Laravel yang dapat:

- Menghubungkan WhatsApp via WAHA.
- Membalas customer melalui inbox dashboard.
- Menggunakan AI untuk auto reply.
- Mengatur persona dan knowledge base AI.
- Membatasi AI agar tidak keluar scope.
- Mengekstrak dan memposting data customer/booking secara otomatis dengan approval.
- Mengelola customer dan booking.
- Mengirim reminder.
- Melakukan follow-up customer lama.
- Mengirim promo dan campaign.
- Menampilkan dashboard monitoring bisnis.
