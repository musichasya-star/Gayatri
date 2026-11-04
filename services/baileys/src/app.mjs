import express from 'express';
import { timingSafeEqual } from 'node:crypto';
import QRCode from 'qrcode';
import { httpError } from './sessions.mjs';

export function createApp({ sessions, store, apiKey, logger }) {
  if (typeof apiKey !== 'string' || apiKey.length < 32) throw new Error('API_KEY wajib berupa secret acak minimal 32 karakter.');
  const app = express();
  app.disable('x-powered-by');
  app.use((req, res, next) => {
    res.set('Cache-Control', 'no-store');
    const supplied = Buffer.from(req.get('X-Api-Key') ?? '');
    const expected = Buffer.from(apiKey);
    if (supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) return res.status(401).json({ message: 'Unauthorized.' });
    next();
  });
  app.use(express.json({ limit: '256kb' }));
  app.get('/health', (req, res) => res.json({ ok: true, engine: 'BAILEYS', version: '7.0.0-rc14', sessions: sessions.list(), webhooks: store.stats() }));
  app.get('/api/sessions', (req, res) => res.json(sessions.list()));
  app.post('/api/sessions', async (req, res) => res.status(201).json(await sessions.create(req.body.name, req.body.config ?? {}, req.body.start !== false)));
  app.post('/api/sessions/logout', async (req, res) => res.json(await sessions.logout(req.body.name)));
  app.get('/api/sessions/:name', (req, res) => res.json(sessions.get(req.params.name)));
  app.put('/api/sessions/:name', async (req, res) => res.json(await sessions.update(req.params.name, req.body.config ?? {})));
  app.get('/api/sessions/:name/me', (req, res) => res.json(sessions.working(req.params.name).me));
  for (const action of ['start', 'stop', 'restart', 'logout']) {
    app.post('/api/sessions/:name/' + action, async (req, res) => res.json(await sessions[action](req.params.name)));
  }
  app.get('/api/:name/auth/qr', async (req, res) => {
    const data = await QRCode.toBuffer(sessions.qr(req.params.name), { type: 'png', width: 320, margin: 2, errorCorrectionLevel: 'L' });
    res.type('png').send(data);
  });
  app.post('/api/sendText', async (req, res) => res.json(await sessions.sendText(req.body.session, req.body.chatId, req.body.text)));
  app.post('/api/startTyping', async (req, res) => res.json(await sessions.typing(req.body.session, req.body.chatId, true)));
  app.post('/api/stopTyping', async (req, res) => res.json(await sessions.typing(req.body.session, req.body.chatId, false)));
  app.use((req, res, next) => next(httpError(404, 'Endpoint tidak ditemukan.')));
  app.use((error, req, res, next) => {
    if (res.headersSent) return next(error);
    const status = Number.isInteger(error.status) ? error.status : 500;
    if (status >= 500) logger.error({ path: req.path, reason: error.message }, 'Gateway request failed');
    res.status(status).json({ message: status < 500 ? error.message : 'Gateway gagal memproses permintaan. Periksa log service.' });
  });
  return app;
}
