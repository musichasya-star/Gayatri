import { DatabaseSync } from 'node:sqlite';
import { mkdirSync, chmodSync } from 'node:fs';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { BufferJSON, initAuthCreds, proto } from '@whiskeysockets/baileys';

const encode = (value) => JSON.stringify(value, BufferJSON.replacer);
const decode = (value) => JSON.parse(value, BufferJSON.reviver);

export class Store {
  constructor(directory) {
    mkdirSync(directory, { recursive: true, mode: 0o700 });
    const filename = join(directory, 'gateway.sqlite');
    this.db = new DatabaseSync(filename);
    if (process.platform !== 'win32') chmodSync(filename, 0o600);
    this.db.exec(`
      PRAGMA journal_mode=WAL;
      PRAGMA synchronous=FULL;
      PRAGMA busy_timeout=5000;
      CREATE TABLE IF NOT EXISTS sessions (
        name TEXT PRIMARY KEY, config TEXT NOT NULL DEFAULT '{}', enabled INTEGER NOT NULL DEFAULT 0
      );
      CREATE TABLE IF NOT EXISTS auth (
        session TEXT NOT NULL, category TEXT NOT NULL, id TEXT NOT NULL, value TEXT NOT NULL,
        PRIMARY KEY (session, category, id)
      );
      CREATE TABLE IF NOT EXISTS messages (
        session TEXT NOT NULL, id TEXT NOT NULL, value TEXT NOT NULL, created_at INTEGER NOT NULL,
        PRIMARY KEY (session, id)
      );
      CREATE TABLE IF NOT EXISTS outbox (
        id TEXT PRIMARY KEY, dedup TEXT NOT NULL UNIQUE, event TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL, delivered_at INTEGER, last_error TEXT
      );
      CREATE INDEX IF NOT EXISTS outbox_pending ON outbox(delivered_at, next_at);
    `);
  }

  transaction(fn) {
    this.db.exec('BEGIN IMMEDIATE');
    try {
      const value = fn();
      this.db.exec('COMMIT');
      return value;
    } catch (error) {
      this.db.exec('ROLLBACK');
      throw error;
    }
  }

  sessions() {
    return this.db.prepare('SELECT * FROM sessions ORDER BY name').all().map((row) => ({
      name: row.name, config: JSON.parse(row.config), enabled: Boolean(row.enabled),
    }));
  }

  saveSession(name, config, enabled = false) {
    this.db.prepare(`INSERT INTO sessions(name,config,enabled) VALUES (?,?,?)
      ON CONFLICT(name) DO UPDATE SET config=excluded.config, enabled=excluded.enabled`)
      .run(name, JSON.stringify(config), Number(enabled));
  }

  clearAuth(name) {
    this.transaction(() => {
      this.db.prepare('DELETE FROM auth WHERE session=?').run(name);
      this.db.prepare('DELETE FROM messages WHERE session=?').run(name);
    });
  }

  authState(name) {
    const read = (category, id) => {
      const row = this.db.prepare('SELECT value FROM auth WHERE session=? AND category=? AND id=?').get(name, category, id);
      return row ? decode(row.value) : undefined;
    };
    const write = (category, id, value) => {
      if (value == null) {
        this.db.prepare('DELETE FROM auth WHERE session=? AND category=? AND id=?').run(name, category, id);
      } else {
        this.db.prepare(`INSERT INTO auth(session,category,id,value) VALUES (?,?,?,?)
          ON CONFLICT(session,category,id) DO UPDATE SET value=excluded.value`).run(name, category, id, encode(value));
      }
    };
    const creds = read('creds', 'current') ?? initAuthCreds();
    return {
      state: {
        creds,
        keys: {
          get: async (category, ids) => Object.fromEntries(ids.map((id) => {
            let value = read(category, id);
            if (category === 'app-state-sync-key' && value) value = proto.Message.AppStateSyncKeyData.fromObject(value);
            return [id, value];
          })),
          set: async (data) => this.transaction(() => {
            for (const [category, entries] of Object.entries(data)) {
              for (const [id, value] of Object.entries(entries)) write(category, id, value);
            }
          }),
        },
      },
      saveCreds: async () => write('creds', 'current', creds),
    };
  }

  rememberMessage(session, message) {
    if (message?.key?.id && message.message) {
      this.db.prepare(`INSERT OR REPLACE INTO messages(session,id,value,created_at) VALUES (?,?,?,?)`)
        .run(session, message.key.id, encode(message.message), Date.now());
    }
  }

  getMessage(session, id) {
    const row = this.db.prepare('SELECT value FROM messages WHERE session=? AND id=?').get(session, id ?? '');
    return row ? decode(row.value) : undefined;
  }

  enqueue(event, dedup = randomUUID()) {
    return this.db.prepare(`INSERT OR IGNORE INTO outbox(id,dedup,event,next_at,created_at) VALUES (?,?,?,?,?)`)
      .run(randomUUID(), dedup, encode(event), Date.now(), Date.now()).changes > 0;
  }

  pending(limit = 10) {
    return this.db.prepare('SELECT * FROM outbox WHERE delivered_at IS NULL AND next_at<=? ORDER BY created_at LIMIT ?')
      .all(Date.now(), limit).map((row) => ({ ...row, event: decode(row.event) }));
  }

  delivered(id) {
    this.db.prepare('UPDATE outbox SET delivered_at=?, last_error=NULL WHERE id=?').run(Date.now(), id);
  }

  retry(row, reason) {
    const delay = Math.min(60_000, 1000 * 2 ** Math.min(row.attempts, 6));
    this.db.prepare('UPDATE outbox SET attempts=attempts+1,next_at=?,last_error=? WHERE id=?')
      .run(Date.now() + delay, String(reason).slice(0, 250), row.id);
  }

  stats() {
    return this.db.prepare(`SELECT count(*) AS pending, coalesce(max(attempts),0) AS max_attempts
      FROM outbox WHERE delivered_at IS NULL`).get();
  }

  prune() {
    const cutoff = Date.now() - 7 * 86400_000;
    this.db.prepare('DELETE FROM outbox WHERE delivered_at IS NOT NULL AND delivered_at<?').run(cutoff);
    this.db.prepare('DELETE FROM messages WHERE created_at<?').run(cutoff);
  }

  close() { this.db.close(); }
}
