import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync, openSync, closeSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';
import { parseEnv } from 'node:util';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const service = resolve(root, 'services/baileys');
const applicationEnv = resolve(root, '.env.baileys-local');
const serviceEnv = resolve(service, '.env');
const appUrl = 'http://127.0.0.1:8000';
mkdirSync(resolve(root, 'storage/app'), { recursive: true });
if (!existsSync(serviceEnv)) {
  writeFileSync(serviceEnv, [
    'HOST=127.0.0.1', 'PORT=3001', 'API_KEY=' + randomBytes(32).toString('hex'),
    'WEBHOOK_SECRET=' + randomBytes(32).toString('hex'), 'GAYATRI_URL=' + appUrl,
    'DATA_DIR=./data', 'LOG_LEVEL=warn', '',
  ].join('\n'), { flag: 'wx', mode: 0o600 });
}
const gateway = parseEnv(readFileSync(serviceEnv, 'utf8'));
if (gateway.GAYATRI_URL !== appUrl || gateway.HOST !== '127.0.0.1' || gateway.PORT !== '3001') {
  throw new Error('Konfigurasi local memerlukan HOST=127.0.0.1, PORT=3001 dan GAYATRI_URL=' + appUrl + '. Sesuaikan file service terlebih dahulu.');
}
if (!gateway.API_KEY || !gateway.WEBHOOK_SECRET) throw new Error('Kredensial service Baileys belum lengkap.');
const database = resolve(root, 'storage/app/baileys-local.sqlite').replaceAll('\\', '/');
if (!existsSync(applicationEnv)) {
  writeFileSync(applicationEnv, [
    'APP_NAME="Gayatri Local Baileys"', 'APP_ENV=local', 'APP_DEBUG=false',
    'APP_KEY=base64:' + randomBytes(32).toString('base64'), 'APP_URL=' + appUrl,
    'APP_LOCALE=id', 'APP_FALLBACK_LOCALE=en', 'LOG_CHANNEL=stack', 'LOG_STACK=single',
    'DB_CONNECTION=sqlite', 'DB_DATABASE="' + database + '"',
    'SESSION_DRIVER=database', 'SESSION_SECURE_COOKIE=false', 'SESSION_COOKIE=gayatri_baileys_local',
    'QUEUE_CONNECTION=database', 'CACHE_STORE=database',
    'WHATSAPP_GATEWAY=baileys', 'BAILEYS_BASE_URL=http://127.0.0.1:3001',
    'BAILEYS_API_KEY=' + gateway.API_KEY, 'BAILEYS_WEBHOOK_SECRET=' + gateway.WEBHOOK_SECRET,
    'BAILEYS_DEFAULT_SESSION=gayatri-main', 'BAILEYS_WEBHOOK_BASE_URL=' + appUrl,
    'AI_PROVIDER=local', 'AI_MODEL=local-deterministic', '',
  ].join('\n'), { flag: 'wx', mode: 0o600 });
}
const application = parseEnv(readFileSync(applicationEnv, 'utf8'));
if (application.BAILEYS_API_KEY !== gateway.API_KEY || application.BAILEYS_WEBHOOK_SECRET !== gateway.WEBHOOK_SECRET
    || application.DB_CONNECTION !== 'sqlite' || application.DB_DATABASE !== database || application.APP_URL !== appUrl) {
  throw new Error('Konfigurasi .env.baileys-local tidak cocok. Samakan kredensial, APP_URL dan database SQLite local sebelum melanjutkan.');
}
closeSync(openSync(database, 'a', 0o600));
console.log('Konfigurasi local siap. Aplikasi: ' + appUrl + '; gateway: http://127.0.0.1:3001. Kredensial disimpan tanpa ditampilkan.');
