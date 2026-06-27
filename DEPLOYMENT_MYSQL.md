# Deploy Production dengan MySQL

Dokumen ini menjelaskan langkah production untuk Gayatri CRM agar tidak memakai SQLite. Production wajib memakai MySQL/MariaDB karena webhook WAHA, queue, AI logs, dan message write berjalan paralel.

## Requirement

- PHP 8.2+ dengan ekstensi `pdo_mysql`.
- MySQL 8.x atau MariaDB 10.6+.
- Web server: Nginx/Apache.
- Queue worker yang berjalan terus.
- Scheduler Laravel aktif.
- WAHA berjalan sebagai service/container terpisah.

## 1. Buat Database dan User

```sql
CREATE DATABASE gayatri_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gayatri_user'@'localhost' IDENTIFIED BY 'change-me-strong-password';
GRANT ALL PRIVILEGES ON gayatri_crm.* TO 'gayatri_user'@'localhost';
FLUSH PRIVILEGES;
```

Jika database berada di host berbeda, ganti `localhost` sesuai host database yang dipakai.

## 2. Siapkan Environment Production

Salin template:

```bash
cp .env.production.example .env
```

Wajib ubah nilai berikut:

- `APP_KEY`: generate dengan `php artisan key:generate`.
- `APP_URL`: domain production.
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- `WAHA_API_KEY`.
- `WAHA_WEBHOOK_SECRET`.
- `AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL` jika memakai provider external.

Pastikan production memakai:

```env
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Jakarta
DB_CONNECTION=mysql
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

## 3. Deploy Dependency dan Cache

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

Jika `.env` sudah memiliki `APP_KEY`, jangan generate ulang karena akan memengaruhi data terenkripsi seperti app settings secret.

## 4. Jalankan Migration dan Seeder

Untuk production baru:

```bash
php artisan migrate --force
php artisan db:seed --force
```

Seeder membuat data awal/demo seperti user dan master data. Jika production tidak ingin data demo tertentu, review seeder sebelum menjalankan.

## 5. Queue Worker

Queue wajib berjalan terus. Contoh command:

```bash
php artisan queue:work --tries=3 --timeout=120
```

Untuk Linux production, jalankan via Supervisor/systemd. Pastikan worker restart setelah deploy:

```bash
php artisan queue:restart
```

## 6. Scheduler

Gunakan cron:

```cron
* * * * * cd /path/to/gayatri && php artisan schedule:run >> /dev/null 2>&1
```

Alternatif untuk service long-running:

```bash
php artisan schedule:work
```

Scheduler dipakai untuk reminder, campaign due, dan retention tasks.

## 7. WAHA Production

WAHA harus mengirim webhook ke domain production:

```text
https://domain-production.com/webhooks/waha/messages
https://domain-production.com/webhooks/waha/status
```

Header wajib:

```text
X-Api-Key: <WAHA_API_KEY>
```

Jika memakai `WAHA_WEBHOOK_SECRET`, kirim juga:

```text
X-Webhook-Secret: <WAHA_WEBHOOK_SECRET>
```

Setelah WAHA connected, test:

```bash
curl -H "X-Api-Key: <WAHA_API_KEY>" http://127.0.0.1:3000/api/sessions
```

## 8. Migrasi Data dari SQLite ke MySQL

Jika data lokal SQLite hanya data test, jangan migrasikan. Mulai production dari database bersih.

Jika data SQLite perlu dibawa, migrasikan dengan urutan tabel agar relasi aman:

1. `users`
2. `branches`
3. `services`
4. `therapists`
5. `customers`
6. `whats_app_sessions`
7. `conversations`
8. `messages`
9. `availability_slots`
10. `bookings`
11. `reminders`
12. `followups`
13. `promos`
14. `campaigns`
15. `campaign_recipients`
16. `ai_personas`
17. `knowledge_bases`
18. `knowledge_chunks`
19. `ai_logs`
20. `ai_automation_rules`
21. `ai_extracted_data`
22. `ai_automation_approvals`
23. `ai_automation_logs`
24. `app_settings`
25. `audit_logs`

Rekomendasi praktis: migrasi via script khusus atau tool ETL, bukan dump SQL mentah dari SQLite, karena tipe data JSON/datetime/boolean berbeda antara SQLite dan MySQL.

## 9. Smoke Test Production

Jalankan checklist berikut setelah deploy:

- Login owner/admin.
- Dashboard terbuka tanpa error.
- Customer list tampil.
- WAHA session connected.
- Kirim WhatsApp test ke nomor WAHA.
- Pesan masuk muncul di Inbox.
- AI auto reply terkirim.
- Booking dari chat berhasil meminta konfirmasi.
- Admin approve booking.
- Ubah booking ke `confirmed`, customer menerima WA konfirmasi.
- Ubah booking ke `completed`, customer menerima pesan terima kasih/feedback.
- Reminder terbentuk untuk booking confirmed.
- Follow-up bisa dikirim WA.
- Campaign draft bisa dibuat, approve, schedule, dan dikirim via queue.
- `php artisan queue:failed` kosong.

## 10. Backup dan Monitoring

Backup harian:

```bash
mysqldump -u gayatri_user -p gayatri_crm > backup-gayatri-$(date +%F).sql
```

Monitor rutin:

- `storage/logs/laravel.log`
- `php artisan queue:failed`
- jumlah job pending di tabel `jobs`
- status WAHA session
- disk space server
- ukuran tabel `messages`, `ai_logs`, dan `audit_logs`

## Command Verifikasi Cepat

```bash
php artisan about
php artisan migrate:status
php artisan queue:failed
php artisan test
```
