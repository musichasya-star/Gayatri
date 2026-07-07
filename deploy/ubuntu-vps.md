# Deploy Gayatri ke VPS Ubuntu

Panduan ini menyiapkan Gayatri Laravel agar bisa diakses publik melalui domain, Nginx, PHP-FPM, MySQL, queue worker, scheduler, dan HTTPS.

## 1. Paket Server

Contoh untuk Ubuntu 24.04 dengan PHP 8.3:

```bash
sudo apt update
sudo apt install -y nginx mysql-server git unzip curl ca-certificates
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl
```

Install Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Install Node.js LTS:

```bash
curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
sudo apt install -y nodejs
```

## 2. Database MySQL

```sql
CREATE DATABASE gayatri_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gayatri_user'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON gayatri_crm.* TO 'gayatri_user'@'localhost';
FLUSH PRIVILEGES;
```

## 3. Ambil Source Code

```bash
sudo mkdir -p /var/www/gayatri
sudo chown -R $USER:www-data /var/www/gayatri
git clone https://github.com/musichasya-star/Gayatri.git /var/www/gayatri/current
cd /var/www/gayatri/current
```

## 4. Environment Production

```bash
cp .env.production.example .env
php artisan key:generate
```

Wajib ubah `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domain-anda.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=gayatri_crm
DB_USERNAME=gayatri_user
DB_PASSWORD=GANTI_PASSWORD_KUAT
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
WAHA_BASE_URL=http://127.0.0.1:3000
WAHA_API_KEY=GANTI_API_KEY_WAHA
WAHA_WEBHOOK_SECRET=GANTI_SECRET_WEBHOOK
```

## 5. Install Aplikasi

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
sudo chown -R www-data:www-data storage bootstrap/cache public/build
```

## 6. Nginx

```bash
sudo cp deploy/nginx/gayatri.conf /etc/nginx/sites-available/gayatri
sudo nano /etc/nginx/sites-available/gayatri
sudo ln -s /etc/nginx/sites-available/gayatri /etc/nginx/sites-enabled/gayatri
sudo nginx -t
sudo systemctl reload nginx
```

Ubah `server_name example.com www.example.com` menjadi domain asli. Jika server memakai PHP selain 8.3, ubah juga socket `php8.3-fpm.sock`.

## 7. Queue dan Scheduler

```bash
sudo mkdir -p /var/log/gayatri
sudo chown -R www-data:www-data /var/log/gayatri
sudo cp deploy/systemd/gayatri-queue.service /etc/systemd/system/gayatri-queue.service
sudo cp deploy/systemd/gayatri-scheduler.service /etc/systemd/system/gayatri-scheduler.service
sudo systemctl daemon-reload
sudo systemctl enable --now gayatri-queue
sudo systemctl enable --now gayatri-scheduler
```

Cek status:

```bash
sudo systemctl status gayatri-queue
sudo systemctl status gayatri-scheduler
```

## 8. HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d domain-anda.com -d www.domain-anda.com
```

## 9. WAHA

WAHA dapat dijalankan di VPS yang sama atau server terpisah. Jika memakai server yang sama, jangan expose port WAHA ke publik kecuali dibutuhkan. Aplikasi cukup mengakses:

```env
WAHA_BASE_URL=http://127.0.0.1:3000
```

Set webhook WAHA ke:

```text
https://domain-anda.com/webhooks/waha/messages
https://domain-anda.com/webhooks/waha/status
```

Gunakan header:

```text
X-Api-Key: isi_WAHA_API_KEY
X-Webhook-Secret: isi_WAHA_WEBHOOK_SECRET
```

## 10. Deploy Update Berikutnya

```bash
cd /var/www/gayatri/current
bash deploy/scripts/deploy.sh
```

Jika script belum executable:

```bash
chmod +x deploy/scripts/deploy.sh
```

## 11. Smoke Test

```bash
php artisan about
php artisan migrate:status
php artisan schedule:list
php artisan queue:failed
curl -I https://domain-anda.com
```

Cek dari browser:

- `/login`
- `/admin`
- `/booking`
- `/admin/whatsapp/sessions`
- `/admin/ai/data-automation/approvals`

## 12. Troubleshooting Cepat

Log aplikasi:

```bash
tail -f storage/logs/laravel.log
```

Log Nginx:

```bash
sudo tail -f /var/log/nginx/error.log
```

Log queue dan scheduler:

```bash
sudo tail -f /var/log/gayatri/queue.log
sudo tail -f /var/log/gayatri/scheduler.log
```

Permission umum:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache public/build
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```
