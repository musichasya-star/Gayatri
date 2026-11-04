export class WebhookWorker {
  constructor(store, { gayatriUrl, webhookSecret, logger, fetchImpl = fetch }) {
    this.store = store;
    this.base = gayatriUrl.replace(/\/$/, '');
    this.secret = webhookSecret;
    this.logger = logger;
    this.fetch = fetchImpl;
    this.stopped = true;
  }

  url(event) {
    return this.base + '/webhooks/whatsapp/' + (event === 'session.status' ? 'status' : 'messages');
  }

  async deliverOnce() {
    for (const row of this.store.pending()) {
      if (this.stopped) break;
      try {
        const response = await this.fetch(this.url(row.event.event), {
          method: 'POST', redirect: 'error', signal: AbortSignal.timeout(8000),
          headers: { 'Content-Type': 'application/json', 'X-Webhook-Secret': this.secret },
          body: JSON.stringify(row.event),
        });
        // A redirect, HTML login response, or non-JSON 200 must not drop an event.
        const body = await response.json().catch(() => null);
        if (!response.ok || body?.ok !== true) throw new Error('Webhook HTTP ' + response.status + ' (expected JSON ok=true)');
        this.store.delivered(row.id);
      } catch (error) {
        this.store.retry(row, error.message);
        this.logger.warn({ webhookId: row.id, attempts: row.attempts + 1, reason: error.message }, 'Webhook retained for retry');
      }
    }
  }

  start() {
    this.stopped = false;
    const tick = async () => {
      if (this.stopped) return;
      this.active = this.deliverOnce();
      try { await this.active; } catch (error) { this.logger.error({ reason: error.message }, 'Webhook worker failed'); }
      if (!this.stopped) this.timer = setTimeout(tick, 1000);
    };
    void tick();
    this.pruner = setInterval(() => this.store.prune(), 3600_000);
    this.pruner.unref();
  }

  async stop() {
    this.stopped = true;
    clearTimeout(this.timer);
    clearInterval(this.pruner);
    await this.active?.catch(() => {});
  }
}
