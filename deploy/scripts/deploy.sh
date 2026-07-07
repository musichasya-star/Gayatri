#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/gayatri/current}"
PHP_BIN="${PHP_BIN:-php}"

cd "$APP_DIR"

echo "Pulling latest source..."
git pull --ff-only origin main

echo "Installing PHP dependencies..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

echo "Installing and building frontend assets..."
npm ci
npm run build

echo "Preparing Laravel caches..."
$PHP_BIN artisan down || true
$PHP_BIN artisan migrate --force
$PHP_BIN artisan storage:link || true
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache
$PHP_BIN artisan queue:restart
$PHP_BIN artisan up

echo "Reloading services..."
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
sudo systemctl restart gayatri-queue
sudo systemctl restart gayatri-scheduler

echo "Deploy complete."
