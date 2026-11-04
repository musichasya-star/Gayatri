import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setImmediate as tick } from 'node:timers/promises';
import pino from 'pino';
import { Store } from '../src/store.mjs';
import { Sessions } from '../src/sessions.mjs';
import { createApp } from '../src/app.mjs';
import { normalizeIncoming, normalizeAck, toWhatsAppJid } from '../src/normalize.mjs';
import { WebhookWorker } from '../src/webhooks.mjs';

const logger = pino({ level: 'silent' });
const apiKey = 'test-only-'.repeat(8);
const message = (id = 'incoming-1', extra = {}) => ({
  key: { id, fromMe: false, remoteJid: '628123456789@s.whatsapp.net' },
  message: { conversation: 'Halo' }, messageTimestamp: 1793000000, ...extra,
});

function fixture(t) {
  const directory = mkdtempSync(join(tmpdir(), 'gayatri-baileys-'));
  const store = new Store(directory);
  const sockets = [];
  const factory = (config) => {
    const socket = {
      ev: new EventEmitter(), user: { id: '628999999:3@s.whatsapp.net', name: 'Test' },
      sendMessage: async (jid, payload) => ({ key: { id: 'sent-1', remoteJid: jid, fromMe: true }, message: { conversation: payload.text } }),
      sendPresenceUpdate: async () => {}, end: () => {}, logout: async () => {}, config,
    };
    sockets.push(socket);
    return socket;
  };
  const sessions = new Sessions(store, { logger, socketFactory: factory, gayatriUrl: 'http://127.0.0.1:8000' });
  t.after(() => { sessions.shutdown(); store.close(); rmSync(directory, { recursive: true, force: true }); });
  return { store, sessions, sockets, directory, factory };
}

test('normalizes existing phone chat IDs, LID aliases and interactive replies', () => {
  assert.equal(normalizeIncoming('main', message()).payload.from, '628123456789@c.us');
  const lid = message('lid', { key: { id: 'lid', fromMe: false, remoteJid: '123456@lid', remoteJidAlt: '628777@s.whatsapp.net' } });
  assert.equal(normalizeIncoming('main', lid).payload.from, '628777@c.us');
  delete lid.key.remoteJidAlt;
  assert.equal(normalizeIncoming('main', lid).payload.from, '123456@lid');
  assert.equal(normalizeIncoming('main', lid, '628888@s.whatsapp.net').payload.from, '628888@c.us');
  const button = normalizeIncoming('main', message('button', { message: { buttonsResponseMessage: { selectedButtonId: 'booking_confirm_yes' } } }));
  assert.equal(button.payload.selectedButtonId, 'booking_confirm_yes');
  assert.equal(normalizeIncoming('main', message('own', { key: { id: 'own', fromMe: true, remoteJid: '6281@s.whatsapp.net' } })), null);
  assert.equal(normalizeIncoming('main', message('group', { key: { id: 'group', remoteJid: '12345@g.us' } })), null);
  assert.equal(normalizeIncoming('main', message('protocol', { message: { protocolMessage: {} } })), null);
  assert.equal(toWhatsAppJid('628123@c.us'), '628123@s.whatsapp.net');
  assert.throws(() => toWhatsAppJid('../../data'), /chatId/);
});

test('maps Baileys server, delivery and read ACKs consistently', () => {
  const key = { id: 'ack-1', fromMe: true };
  assert.equal(normalizeAck('main', { key, update: { status: 2 } }).payload.ack, 1);
  assert.equal(normalizeAck('main', { key, update: { status: 3 } }).payload.ack, 2);
  assert.equal(normalizeAck('main', { key, update: { status: 4 } }).payload.ack, 3);
  assert.equal(normalizeAck('main', { key: { ...key, fromMe: false }, update: { status: 4 } }), null);
});

test('sender identity metadata distinguishes phone numbers from unresolved LIDs', () => {
  const pn = normalizeIncoming('main', message()).payload.senderIdentity;
  assert.deepEqual(pn, { version: 1, originalJid: '628123456789@s.whatsapp.net', phoneJid: '628123456789@s.whatsapp.net', lid: null, source: 'phone_jid' });
  const lid = message('identity-lid', { key: { id: 'identity-lid', remoteJid: '987654321@lid', remoteJidAlt: '628123456789@s.whatsapp.net' } });
  assert.equal(normalizeIncoming('main', lid).payload.senderIdentity.source, 'message_key_alt');
  assert.equal(normalizeIncoming('main', lid).payload.senderIdentity.lid, '987654321@lid');
  delete lid.key.remoteJidAlt;
  assert.equal(normalizeIncoming('main', lid).payload.senderIdentity.phoneJid, null);
  assert.equal(normalizeIncoming('main', lid).payload.senderIdentity.source, 'unmapped_lid');
  assert.equal(normalizeIncoming('main', lid, '628123456789@s.whatsapp.net').payload.senderIdentity.source, 'baileys_cache');
  const wrongAlt = message('wrong-alt', { key: { id: 'wrong-alt', remoteJid: '628123456789@s.whatsapp.net', remoteJidAlt: '628999999999@s.whatsapp.net' } });
  assert.equal(normalizeIncoming('main', wrongAlt).payload.from, '628123456789@c.us');
});

test('LID cache failure preserves unverified sender and continues incoming delivery', async (t) => {
  const { sessions, sockets, store } = fixture(t);
  await sessions.create('identity-main');
  sockets[0].signalRepository = { lidMapping: { getPNForLID: async () => { throw new Error('cache unavailable'); } } };
  sockets[0].ev.emit('messages.upsert', { type: 'notify', messages: [message('cache-failed', { key: { id: 'cache-failed', remoteJid: '987654321@lid' } })] });
  await tick();
  const row = store.pending(100).find((entry) => entry.event.event === 'message');
  assert.equal(row.event.payload.from, '987654321@lid');
  assert.equal(row.event.payload.senderIdentity.phoneJid, null);
});

test('persists credentials and binary Signal keys; logout clears only the selected session', async (t) => {
  const { store } = fixture(t);
  const auth = store.authState('main');
  auth.state.creds.registered = true;
  await auth.saveCreds();
  await auth.state.keys.set({ 'pre-key': { first: { private: Buffer.from([1, 2, 3]) } } });
  const other = store.authState('other');
  await other.saveCreds();
  const restored = store.authState('main');
  assert.equal(restored.state.creds.registered, true);
  assert.deepEqual((await restored.state.keys.get('pre-key', ['first'])).first.private, Buffer.from([1, 2, 3]));
  await restored.state.keys.set({ 'pre-key': { first: null } });
  assert.equal((await restored.state.keys.get('pre-key', ['first'])).first, undefined);
  store.clearAuth('main');
  assert.equal(store.authState('main').state.creds.registered, false);
  assert.deepEqual(store.authState('other').state.creds.noiseKey, other.state.creds.noiseKey);
});

test('filters backfill and own messages, deduplicates incoming events durably', async (t) => {
  const { sessions, sockets, store } = fixture(t);
  await sessions.create('main');
  const socket = sockets[0];
  socket.ev.emit('messages.upsert', { type: 'append', messages: [message('history')] });
  socket.ev.emit('messages.upsert', { type: 'notify', messages: [message(), message(), message('own', { key: { id: 'own', fromMe: true } })] });
  await tick();
  assert.equal(store.pending(100).filter((row) => row.event.event === 'message').length, 1);
  const row = store.pending(100).find((row) => row.event.event === 'message');
  store.delivered(row.id);
  socket.ev.emit('messages.upsert', { type: 'notify', messages: [message()] });
  await tick();
  assert.equal(store.pending(100).filter((item) => item.event.event === 'message').length, 0);
});

test('SQLite restores auth and undelivered webhooks after closing and reopening the database', async (t) => {
  const directory = mkdtempSync(join(tmpdir(), 'gayatri-baileys-reopen-'));
  let store = new Store(directory);
  t.after(() => { store.close(); rmSync(directory, { recursive: true, force: true }); });
  const auth = store.authState('main');
  auth.state.creds.registered = true;
  await auth.saveCreds();
  await auth.state.keys.set({ 'pre-key': { restart: { private: Buffer.from([4, 5, 6]) } } });
  store.saveSession('main', {}, true);
  store.enqueue({ event: 'message', session: 'main', payload: { id: 'reopen-1' } }, 'reopen-1');
  store.close();
  store = new Store(directory);
  assert.equal(store.authState('main').state.creds.registered, true);
  assert.deepEqual((await store.authState('main').state.keys.get('pre-key', ['restart'])).restart.private, Buffer.from([4, 5, 6]));
  assert.equal(store.sessions()[0].enabled, true);
  assert.equal(store.pending()[0].event.payload.id, 'reopen-1');
  assert.equal(store.enqueue({ event: 'message' }, 'reopen-1'), false);
});

test('restart is serialized and old socket events cannot overwrite the new state', async (t) => {
  const { sessions, sockets } = fixture(t);
  await sessions.create('main');
  sockets[0].ev.emit('connection.update', { connection: 'open' });
  await tick();
  assert.equal(sessions.get('main').status, 'WORKING');
  assert.equal(sessions.get('main').me.id, '628999999@s.whatsapp.net');
  await sessions.restart('main');
  sockets[0].ev.emit('connection.update', { connection: 'close' });
  sockets[1].ev.emit('connection.update', { qr: 'test-qr-code' });
  await tick();
  assert.equal(sessions.get('main').status, 'SCAN_QR_CODE');
  assert.equal(sessions.qr('main'), 'test-qr-code');
  await sessions.stop('main');
  assert.equal(sessions.get('main').status, 'STOPPED');
  assert.equal(sessions.item('main').timer, null);
  assert.throws(() => sessions.working('main'), /belum tersambung/);
});

test('logout/connection replacement stops reconnect; transient loss uses backoff', async (t) => {
  const { sessions, sockets, store } = fixture(t);
  await sessions.create('main');
  sockets[0].ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { output: { statusCode: 408 } } } });
  await tick();
  assert.ok(sessions.item('main').timer);
  await sessions.restart('main');
  sockets[1].ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { output: { statusCode: 440 } } } });
  await tick();
  assert.equal(sessions.item('main').timer, null);
  assert.equal(sessions.get('main').status, 'FAILED');
  assert.equal(store.sessions()[0].enabled, false);
});

test('API requires a key, protects QR, validates session config and sends via the socket', async (t) => {
  const { sessions, store, sockets } = fixture(t);
  const server = createApp({ sessions, store, apiKey, logger }).listen(0, '127.0.0.1');
  await new Promise((resolve) => server.once('listening', resolve));
  t.after(() => server.close());
  const base = 'http://127.0.0.1:' + server.address().port;
  const request = (path, body, key = apiKey) => fetch(base + path, {
    method: body === undefined ? 'GET' : 'POST',
    headers: { 'X-Api-Key': key, 'Content-Type': 'application/json' },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  assert.equal((await request('/api/sessions', undefined, '')).status, 401);
  assert.equal((await request('/api/sessions', { name: '../main' })).status, 422);
  assert.equal((await request('/api/sessions', { name: 'bad-url', config: { webhooks: [{ url: 'http://evil.test/' }] } })).status, 422);
  assert.equal((await request('/api/sessions', { name: 'main' })).status, 201);
  assert.equal((await request('/api/sendText', { session: 'main', chatId: '628123@c.us', text: 'Halo' })).status, 409);
  sockets[0].ev.emit('connection.update', { qr: 'fake-qr-content' });
  await tick();
  const qr = await request('/api/main/auth/qr');
  assert.equal(qr.status, 200);
  assert.equal(qr.headers.get('content-type'), 'image/png');
  assert.equal((await request('/api/main/auth/qr', undefined, 'wrong')).status, 401);
  sockets[0].ev.emit('connection.update', { connection: 'open' });
  await tick();
  const sent = await request('/api/sendText', { session: 'main', chatId: '628123@c.us', text: 'Halo' });
  assert.equal(sent.status, 200);
  assert.equal((await sent.json()).id, 'sent-1');
  assert.deepEqual(store.getMessage('main', 'sent-1'), { conversation: 'Halo' });
  assert.equal((await request('/api/startTyping', { session: 'main', chatId: '628123@c.us' })).status, 200);
  assert.equal((await request('/api/sessions/main/logout', {})).status, 200);
});

test('webhook delivery retains events on failure and verifies JSON acknowledgement', async (t) => {
  const { store } = fixture(t);
  store.enqueue({ event: 'message', session: 'main', payload: { id: 'retry-1' } }, 'retry-1');
  const calls = [];
  const worker = new WebhookWorker(store, { logger, gayatriUrl: 'http://127.0.0.1:8000', webhookSecret: 'webhook-only-secret',
    fetchImpl: async (url, options) => { calls.push({ url, options }); return new Response('login HTML', { status: 200 }); },
  });
  worker.stopped = false;
  await worker.deliverOnce();
  assert.equal(store.stats().pending, 1);
  assert.equal(store.stats().max_attempts, 1);
  assert.equal(calls[0].url, 'http://127.0.0.1:8000/webhooks/whatsapp/messages');
  assert.equal(calls[0].options.headers['X-Webhook-Secret'], 'webhook-only-secret');
  store.db.prepare('UPDATE outbox SET next_at=0').run();
  worker.fetch = async () => Response.json({ ok: true });
  await worker.deliverOnce();
  assert.equal(store.stats().pending, 0);
});

test('enabled sessions restore without losing auth and disabled sessions stay stopped', async (t) => {
  const { sessions, store, factory } = fixture(t);
  await sessions.create('active');
  await sessions.create('stopped', {}, false);
  sessions.shutdown();
  const restored = new Sessions(store, { logger, socketFactory: factory, gayatriUrl: 'http://127.0.0.1:8000' });
  t.after(() => restored.shutdown());
  await restored.restore();
  assert.equal(restored.get('active').status, 'STARTING');
  assert.equal(restored.get('stopped').status, 'STOPPED');
});
