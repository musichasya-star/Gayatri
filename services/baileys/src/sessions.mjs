import makeWASocket, { DisconnectReason, makeCacheableSignalKeyStore, jidNormalizedUser } from '@whiskeysockets/baileys';
import { normalizeIncoming, normalizeAck, toWhatsAppJid } from './normalize.mjs';

export const httpError = (status, message) => Object.assign(new Error(message), { status });

export class Sessions {
  constructor(store, { logger, socketFactory = makeWASocket, gayatriUrl }) {
    this.store = store;
    this.logger = logger;
    this.socketFactory = socketFactory;
    this.gayatriUrl = gayatriUrl.replace(/\/$/, '');
    this.items = new Map();
    this.locks = new Map();
    for (const saved of store.sessions()) this.items.set(saved.name, this.runtime(saved));
  }

  runtime(saved) {
    return { ...saved, status: 'STOPPED', socket: null, qr: null, qrAt: null, me: null, epoch: 0, attempts: 0, timer: null, lastError: null };
  }

  validName(name) {
    if (!/^[a-zA-Z0-9_-]{1,100}$/.test(name ?? '')) throw httpError(422, 'Nama session tidak valid.');
    return name;
  }

  item(name) {
    this.validName(name);
    const item = this.items.get(name);
    if (!item) throw httpError(404, 'Session tidak ditemukan.');
    return item;
  }

  snapshot(item) {
    return { name: item.name, status: item.status, me: item.me, engine: 'BAILEYS', lastError: item.lastError };
  }

  list() { return [...this.items.values()].map((item) => this.snapshot(item)); }
  get(name) { return this.snapshot(this.item(name)); }

  validateConfig(config) {
    if (!config || typeof config !== 'object' || Array.isArray(config)) throw httpError(422, 'config harus berupa object.');
    if (config.webhooks != null && !Array.isArray(config.webhooks)) throw httpError(422, 'webhooks harus berupa array.');
    const allowed = ['messages', 'status'].map((suffix) => this.gayatriUrl + '/webhooks/whatsapp/' + suffix);
    for (const webhook of config.webhooks ?? []) {
      if (!allowed.includes(webhook.url)) throw httpError(422, 'Webhook URL tidak cocok dengan GAYATRI_URL pada service Baileys.');
    }
    // Callback destinations and credentials come exclusively from service configuration.
    return {};
  }

  async exclusive(name, action) {
    const previous = this.locks.get(name) ?? Promise.resolve();
    const next = previous.catch(() => {}).then(action);
    this.locks.set(name, next);
    try { return await next; } finally { if (this.locks.get(name) === next) this.locks.delete(name); }
  }

  async create(name, config = {}, start = true) {
    this.validName(name);
    return this.exclusive(name, async () => {
      if (this.items.has(name)) throw httpError(409, 'Session sudah ada.');
      const item = this.runtime({ name, config: this.validateConfig(config), enabled: false });
      this.items.set(name, item);
      this.store.saveSession(name, item.config, false);
      if (start) await this.connect(item);
      return this.snapshot(item);
    });
  }

  async update(name, config) {
    return this.exclusive(name, async () => {
      const item = this.item(name);
      item.config = this.validateConfig(config);
      this.store.saveSession(name, item.config, item.enabled);
      return this.snapshot(item);
    });
  }

  async start(name) {
    return this.exclusive(name, async () => {
      const item = this.item(name);
      if (!item.socket && !item.timer) await this.connect(item);
      return this.snapshot(item);
    });
  }

  async stop(name) {
    return this.exclusive(name, async () => {
      const item = this.item(name);
      this.disconnect(item);
      item.enabled = false;
      item.me = null;
      this.store.saveSession(name, item.config, false);
      this.status(item, 'STOPPED');
      return this.snapshot(item);
    });
  }

  async restart(name) {
    return this.exclusive(name, async () => {
      const item = this.item(name);
      this.disconnect(item);
      await this.connect(item);
      return this.snapshot(item);
    });
  }

  async logout(name) {
    return this.exclusive(name, async () => {
      const item = this.item(name);
      const socket = item.socket;
      this.disconnect(item, false);
      item.enabled = false;
      this.store.saveSession(name, item.config, false);
      try { if (socket) await socket.logout(); } finally {
        socket?.end(new Error('Logged out by administrator'));
        this.store.clearAuth(name);
        item.me = null;
        this.status(item, 'STOPPED');
      }
      return this.snapshot(item);
    });
  }

  disconnect(item, end = true) {
    ++item.epoch;
    clearTimeout(item.timer);
    item.timer = null;
    item.qr = null;
    item.qrAt = null;
    const socket = item.socket;
    item.socket = null;
    if (end) socket?.end(new Error('Stopped by administrator'));
  }

  status(item, status) {
    item.status = status;
    this.store.enqueue({ event: 'session.status', session: item.name, engine: 'BAILEYS', me: item.me, payload: { status, me: item.me } });
  }

  async connect(item) {
    const epoch = ++item.epoch;
    item.enabled = true;
    item.lastError = null;
    item.qr = null;
    item.me = null;
    this.store.saveSession(item.name, item.config, true);
    this.status(item, 'STARTING');
    const auth = this.store.authState(item.name);
    let socket;
    try {
      socket = this.socketFactory({
        auth: { creds: auth.state.creds, keys: makeCacheableSignalKeyStore(auth.state.keys, this.logger) },
        logger: this.logger.child({ component: 'socket', session: item.name }, { level: 'error' }),
        markOnlineOnConnect: false, syncFullHistory: false,
        shouldSyncHistoryMessage: () => false,
        getMessage: async (key) => this.store.getMessage(item.name, key.id),
      });
      item.socket = socket;
    } catch (error) {
      item.lastError = error.message;
      this.status(item, 'FAILED');
      this.reconnect(item);
      throw httpError(503, 'Tidak dapat memulai koneksi Baileys. Periksa log service.');
    }
    const listen = (event, fn) => socket.ev.on(event, (value) => {
      if (item.epoch !== epoch) return;
      Promise.resolve().then(() => item.epoch === epoch ? fn(value) : undefined).catch((error) => {
        this.logger.error({ session: item.name, event, reason: error.message }, 'Baileys event failed');
      });
    });
    listen('creds.update', async (update) => { Object.assign(auth.state.creds, update); await auth.saveCreds(); });
    listen('connection.update', async ({ connection, qr, lastDisconnect }) => {
      if (qr) {
        item.qr = qr;
        item.qrAt = Date.now();
        this.status(item, 'SCAN_QR_CODE');
      }
      if (connection === 'open') {
        item.attempts = 0;
        item.qr = null;
        item.qrAt = null;
        item.me = { id: jidNormalizedUser(socket.user?.id ?? auth.state.creds.me?.id ?? ''), pushName: socket.user?.name ?? null };
        this.status(item, 'WORKING');
      }
      if (connection === 'close') {
        item.socket = null;
        item.qr = null;
        item.me = null;
        const code = lastDisconnect?.error?.output?.statusCode;
        item.lastError = code ? 'WhatsApp disconnected (' + code + ')' : 'WhatsApp disconnected';
        const terminal = [DisconnectReason.loggedOut, DisconnectReason.connectionReplaced, DisconnectReason.badSession,
          DisconnectReason.multideviceMismatch, DisconnectReason.forbidden].filter(Number.isInteger).includes(code);
        if (terminal) {
          item.enabled = false;
          this.store.saveSession(item.name, item.config, false);
          this.status(item, 'FAILED');
        } else {
          this.status(item, 'STARTING');
          this.reconnect(item);
        }
      }
    });
    listen('messages.upsert', async ({ messages, type }) => {
      if (type !== 'notify') return;
      for (const message of messages) {
        let phoneJid;
        if (message.key?.remoteJid?.endsWith('@lid') && !message.key?.remoteJidAlt) {
          try { phoneJid = await socket.signalRepository?.lidMapping?.getPNForLID?.(message.key.remoteJid); }
          catch { /* Keep the LID unverified; a cache failure must never invent a phone number. */ }
        }
        const normalized = normalizeIncoming(item.name, message, phoneJid);
        if (normalized) {
          this.store.rememberMessage(item.name, message);
          this.store.enqueue(normalized, ['message', item.name, normalized.payload.id].join(':'));
        }
      }
    });
    listen('messages.update', async (updates) => {
      for (const update of updates) {
        const event = normalizeAck(item.name, update);
        if (event) this.store.enqueue(event, ['ack', item.name, event.payload.id, event.payload.ack].join(':'));
      }
    });
  }

  reconnect(item) {
    if (!item.enabled || item.timer) return;
    const delay = Math.min(60_000, 1000 * 2 ** Math.min(item.attempts++, 6));
    item.timer = setTimeout(() => {
      item.timer = null;
      this.start(item.name).catch((error) => this.logger.warn({ session: item.name, reason: error.message }, 'Reconnect delayed'));
    }, delay);
  }

  async restore() {
    for (const item of this.items.values()) {
      if (item.enabled) await this.start(item.name).catch((error) => this.logger.warn({ session: item.name, reason: error.message }, 'Restore delayed'));
    }
  }

  working(name) {
    const item = this.item(name);
    if (item.status !== 'WORKING' || !item.socket) throw httpError(409, 'WhatsApp belum tersambung. Start session dan scan QR di dashboard Gayatri.');
    return item;
  }

  qr(name) {
    const item = this.item(name);
    if (!item.qr || Date.now() - item.qrAt > 60_000) throw httpError(409, 'QR belum tersedia atau kedaluwarsa. Tunggu beberapa detik lalu refresh.');
    return item.qr;
  }

  async sendText(name, chatId, text) {
    const item = this.working(name);
    const jid = toWhatsAppJid(chatId);
    if (typeof text !== 'string' || !text.trim() || text.length > 65536) throw httpError(422, 'text wajib diisi (maksimal 65536 karakter).');
    const result = await item.socket.sendMessage(jid, { text });
    if (!result?.key?.id) throw httpError(502, 'WhatsApp tidak mengembalikan message ID.');
    this.store.rememberMessage(name, result);
    return { id: result.key.id, session: name, chatId, status: 'sent' };
  }

  async typing(name, chatId, active) {
    await this.working(name).socket.sendPresenceUpdate(active ? 'composing' : 'paused', toWhatsAppJid(chatId));
    return { ok: true };
  }

  shutdown() {
    // Leave persisted enabled flags intact so active sessions resume after restart.
    for (const item of this.items.values()) this.disconnect(item);
  }
}
