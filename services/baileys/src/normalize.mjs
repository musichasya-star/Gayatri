import { jidNormalizedUser, normalizeMessageContent } from '@whiskeysockets/baileys';

export function toWhatsAppJid(chatId) {
  const input = String(chatId ?? '').replace(/@c\.us$/, '@s.whatsapp.net');
  if (! /^\d+(?::\d+)?@(s\.whatsapp\.net|lid)$/.test(input)) {
    throw Object.assign(new Error('chatId harus nomor WhatsApp atau LID pribadi yang valid.'), { status: 422 });
  }
  return jidNormalizedUser(input);
}

export function toCrmChatId(jid) {
  return jidNormalizedUser(jid).replace(/@s\.whatsapp\.net$/, '@c.us');
}

export function normalizeIncoming(session, message, phoneJid) {
  const key = message?.key;
  if (!key?.id || key.fromMe || !/^\d+(?::\d+)?@(s\.whatsapp\.net|lid)$/.test(key.remoteJid ?? '')) return null;
  const content = normalizeMessageContent(message.message);
  if (!content) return null;
  // Prefer a known phone-number alias to preserve existing CRM contacts.
  const alternative = phoneJid ?? key.remoteJidAlt;
  const jid = key.remoteJid.endsWith('@lid') && alternative?.endsWith('@s.whatsapp.net') ? alternative : key.remoteJid;
  const originalJid = jidNormalizedUser(key.remoteJid);
  const phone = jid?.endsWith('@s.whatsapp.net') ? jidNormalizedUser(jid) : null;
  const lid = originalJid.endsWith('@lid') ? originalJid
    : key.remoteJidAlt?.endsWith('@lid') ? jidNormalizedUser(key.remoteJidAlt) : null;
  const media = content.imageMessage ?? content.videoMessage ?? content.audioMessage ?? content.documentMessage ?? content.stickerMessage;
  const body = content.conversation ?? content.extendedTextMessage?.text
    ?? content.buttonsResponseMessage?.selectedDisplayText ?? content.listResponseMessage?.title
    ?? content.templateButtonReplyMessage?.selectedDisplayText ?? media?.caption ?? '';
  const selectedButtonId = content.buttonsResponseMessage?.selectedButtonId
    ?? content.listResponseMessage?.singleSelectReply?.selectedRowId
    ?? content.templateButtonReplyMessage?.selectedId;
  if (!body && !media && !selectedButtonId) return null;
  return {
    event: 'message', session, engine: 'BAILEYS',
    payload: {
      id: key.id, from: toCrmChatId(jid), fromMe: false, body,
      timestamp: Number(message.messageTimestamp ?? Math.floor(Date.now() / 1000)),
      hasMedia: Boolean(media), ...(selectedButtonId ? { selectedButtonId } : {}),
      pushName: message.pushName ?? null,
      originalChatId: jidNormalizedUser(key.remoteJid),
      senderIdentity: { version: 1, originalJid, phoneJid: phone, lid,
        source: originalJid.endsWith('@s.whatsapp.net') ? 'phone_jid' : phoneJid ? 'baileys_cache' : key.remoteJidAlt ? 'message_key_alt' : 'unmapped_lid' },
    },
  };
}

export function normalizeAck(session, { key, update }) {
  // Baileys protobuf: SERVER_ACK=2, DELIVERY_ACK=3, READ=4, PLAYED=5.
  if (!key?.id || !key.fromMe || !Number.isInteger(update?.status) || update.status < 2) return null;
  return { event: 'message.ack', session, engine: 'BAILEYS', payload: { id: key.id, ack: Math.min(4, update.status - 1) } };
}
