import { resolve } from 'node:path';
import pino from 'pino';
import { Store } from './store.mjs';
import { Sessions } from './sessions.mjs';
import { WebhookWorker } from './webhooks.mjs';
import { createApp } from './app.mjs';

const apiKey = process.env.API_KEY ?? '';
const webhookSecret = process.env.WEBHOOK_SECRET ?? '';
if (apiKey.length < 32 || webhookSecret.length < 32) throw new Error('Set API_KEY dan WEBHOOK_SECRET minimal 32 karakter sebelum menjalankan service.');
const gayatriUrl = new URL(process.env.GAYATRI_URL ?? 'http://127.0.0.1:8000');
if (!['http:', 'https:'].includes(gayatriUrl.protocol) || gayatriUrl.username || gayatriUrl.password || gayatriUrl.search || gayatriUrl.hash) {
  throw new Error('GAYATRI_URL harus alamat http(s) aplikasi Gayatri tanpa credentials, query, atau fragment.');
}
const logger = pino({ level: process.env.LOG_LEVEL ?? 'warn', redact: ['apiKey', 'webhookSecret', 'qr', 'creds', 'keys', 'headers', 'message'] });
const store = new Store(resolve(process.env.DATA_DIR ?? './data'));
const sessions = new Sessions(store, { logger, gayatriUrl: gayatriUrl.href });
const worker = new WebhookWorker(store, { logger, gayatriUrl: gayatriUrl.href, webhookSecret });
const app = createApp({ sessions, store, logger, apiKey });
const server = app.listen(Number(process.env.PORT ?? 3001), process.env.HOST ?? '127.0.0.1', async () => {
  console.log('Gayatri Baileys gateway listening at http://' + (process.env.HOST ?? '127.0.0.1') + ':' + (process.env.PORT ?? 3001));
  worker.start();
  await sessions.restore();
});
server.on('error', (error) => { logger.fatal({ reason: error.message }, 'Gateway cannot listen'); process.exit(1); });
let closing = false;
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => {
  if (closing) return;
  closing = true;
  sessions.shutdown();
  const shutdownTimer = setTimeout(() => process.exit(0), 10_000);
  shutdownTimer.unref();
  await worker.stop();
  server.close(() => { store.close(); process.exit(0); });
});
