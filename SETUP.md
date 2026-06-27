# Setup Lokal Gayatri CRM

## Requirement

- PHP 8.2 dari XAMPP: `C:\xampp\php\php.exe`
- Composer
- Docker Desktop untuk WAHA
- Browser modern

## Instalasi Aplikasi

1. Install dependency PHP.

```powershell
composer install
```

2. Salin environment jika belum ada.

```powershell
copy .env.example .env
```

3. Generate app key.

```powershell
C:\xampp\php\php.exe artisan key:generate
```

4. Jalankan migrasi dan seeder.

```powershell
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan db:seed --force
```

5. Jalankan aplikasi lokal.

```powershell
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

## WAHA Docker

Start WAHA:

```powershell
docker compose -f docker-compose.waha.yml up -d
```

Stop WAHA:

```powershell
docker compose -f docker-compose.waha.yml down
```

Lihat log:

```powershell
docker logs -f gayatri-waha
```

Default lokal:

- WAHA API: `http://localhost:3000`
- Dashboard WAHA: `http://localhost:3000/dashboard`
- Dashboard user: `admin`
- Dashboard password: `gayatri-waha-admin`
- API key: `gayatri-waha-local-key`
- Session aplikasi: `default`

Set di menu `Settings` aplikasi:

- WAHA Base URL: `http://localhost:3000`
- WAHA API Key: `gayatri-waha-local-key`
- Default Session: `default`

## Demo Scenario

1. Login ke aplikasi.
2. Buka `Settings`, test koneksi WAHA.
3. Buka `WhatsApp Gateway`, start session `default`, scan QR.
4. Kirim WhatsApp ke nomor yang tersambung.
5. Pastikan chat muncul di Inbox.
6. Buat booking dari Inbox atau Booking menu.
7. Ubah booking ke `confirmed` untuk reminder.
8. Ubah booking ke `completed` untuk feedback request.
9. Buat promo, campaign draft, approve, schedule, lalu dispatch dengan command campaign.
10. Buka Dashboard dan Reports untuk melihat KPI dan export CSV.

## Command Penting

```powershell
C:\xampp\php\php.exe artisan test
C:\xampp\php\php.exe artisan crm:send-due-reminders
C:\xampp\php\php.exe artisan crm:send-due-campaigns
C:\xampp\php\php.exe artisan queue:work
```

## Production MySQL

Local development boleh memakai SQLite. Production wajib memakai MySQL/MariaDB agar webhook WhatsApp, queue, AI logs, dan pesan masuk tidak terkena limit locking SQLite.

Gunakan template:

```powershell
copy .env.production.example .env
```

Lihat panduan lengkap di `DEPLOYMENT_MYSQL.md`.
