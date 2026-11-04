<?php

namespace App\Services\AI;

use App\Models\AiLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\AiDomain;
use Illuminate\Support\Facades\DB;

class AiTraceService
{
    public const INTENTS = ['pricing' => 'Tarif', 'catalog' => 'Katalog', 'faq' => 'Pertanyaan layanan', 'terms' => 'Ketentuan',
        'home_service' => 'Kunjungan / antar-jemput', 'availability' => 'Ketersediaan', 'payment_help' => 'Pembayaran manual', 'web_order' => 'Pemesanan web'];

    public const STATES = ['generated' => 'Sedang disiapkan', 'queued' => 'Menunggu worker', 'simulation' => 'Simulasi',
        'held' => 'Ditahan', 'sending' => 'Sedang dikirim', 'sent' => 'Diterima gateway', 'delivered' => 'Terkirim ke perangkat',
        'read' => 'Dibaca', 'failed' => 'Gagal', 'unknown' => 'Belum pasti terkirim', 'skipped' => 'Tidak dikirim', 'legacy' => 'Histori sebelum penelusuran'];

    public const REASONS = [
        'greeting' => 'Sapaan awal', 'menu_requested' => 'Pelanggan membuka menu', 'menu_choice' => 'Pilihan layanan', 'submenu_choice' => 'Pilihan pada menu layanan',
        'domain_resolved' => 'Layanan dikenali', 'domain_clarification' => 'Layanan perlu diperjelas', 'multiple_domains' => 'Beberapa layanan disebut sekaligus',
        'scoped_knowledge' => 'FAQ terbit sesuai layanan', 'system_procedure' => 'Panduan alur layanan', 'knowledge_missing' => 'Informasi belum tersedia',
        'multi_intent_answer' => 'Beberapa kebutuhan dijawab dari sumber resmi',
        'unsupported_media' => 'Media memerlukan pemeriksaan admin',
        'requested_human' => 'Pelanggan meminta admin', 'sensitive_request' => 'Keluhan membutuhkan admin', 'medical' => 'Pertanyaan kesehatan perlu tenaga layanan',
        'repeated_clarification' => 'Klarifikasi berulang, perlu bantuan admin', 'lookup_verified' => 'Pemilik terverifikasi; status dibaca',
        'lookup_denied' => 'Data tidak dapat ditampilkan', 'lookup_need_domain' => 'Menunggu pilihan layanan', 'lookup_need_code' => 'Menunggu kode transaksi',
        'lookup_need_phone' => 'Menunggu nomor pemesanan', 'lookup_identity_unverified' => 'Identitas pengirim belum terverifikasi',
        'lookup_unavailable' => 'Pengecekan aman belum tersedia', 'lookup_limited' => 'Batas percobaan tercapai', 'thanks' => 'Ucapan terima kasih',
        'stale_context' => 'Konteks atau kendali percakapan berubah', 'stale_policy' => 'Pengaturan AI berubah', 'human_takeover' => 'Percakapan sedang ditangani admin',
        'catalog_changed' => 'Katalog berubah', 'knowledge_changed' => 'Materi layanan berubah', 'lookup_status_changed' => 'Status transaksi berubah',
        'lookup_owner_changed' => 'Identitas atau pemilik berubah', 'lookup_expired_or_demo' => 'Hasil kedaluwarsa atau hanya demo',
        'reply_preferences_changed' => 'Gaya atau template diubah', 'worker_interrupted' => 'Worker terhenti; hasil pengiriman perlu diperiksa',
        'ai_disabled' => 'Balasan AI dinonaktifkan', 'recipient_changed' => 'Penerima, sesi, atau provider berubah', 'evidence_unavailable' => 'Sumber jawaban belum dapat diperiksa',
        'accepted_catalog_offer' => 'Pelanggan menyetujui penawaran katalog', 'offer_declined' => 'Pelanggan belum ingin melanjutkan',
        'http_401' => 'Gateway menolak autentikasi', 'http_403' => 'Akses gateway ditolak', 'http_409' => 'Sesi WhatsApp belum siap',
        'http_404' => 'Endpoint atau sesi tidak ditemukan', 'http_422' => 'Isi atau tujuan pesan ditolak', 'http_429' => 'Batas pengiriman gateway tercapai',
        'http_500' => 'Respons gateway belum memastikan pengiriman', 'transport_unknown' => 'Koneksi terputus; hasil kirim belum pasti',
        'queue_unavailable' => 'Antrean belum dapat menerima pesan; perlu bantuan admin',
        'gateway_delivery_failed' => 'Balasan AI gagal dikirim', 'gateway_delivery_unknown' => 'Hasil kirim balasan AI belum pasti',
    ];

    public function outcome(?int $id, string $state, ?string $reason = null): void
    {
        if (! $id) {
            return;
        }
        $log = AiLog::find($id);
        if (! $log) {
            return;
        }
        $meta = $log->meta ?? [];
        if ($reason !== null) {
            $meta['execution_reason'] = preg_match('/^[a-z0-9_]{1,80}$/D', $reason) ? $reason : 'evidence_unavailable';
        }
        $log->update(['execution_state' => $state, 'meta' => $meta]);
    }

    public function link(Message $outgoing): void
    {
        $context = $outgoing->ai_reply_context ?? [];
        if ($outgoing->sender_type !== 'ai' || empty($context['decision_id'])) {
            return;
        }
        AiLog::whereKey($context['decision_id'])->where('conversation_id', $outgoing->conversation_id)
            ->where('message_id', $context['source_message_id'] ?? 0)->where(fn ($q) => $q->whereNull('outgoing_message_id')->orWhere('outgoing_message_id', $outgoing->id))
            ->update(['outgoing_message_id' => $outgoing->id]);
    }

    public function queued(?int $id, array $context): void
    {
        if (! $id || ! ($log = AiLog::find($id))) {
            return;
        }
        $meta = $log->meta ?? [];
        $meta['control_revision'] = (int) $context['control_revision'];
        $meta['context_revision'] = $context['evidence']['context_revision'] ?? null;
        $log->update(['execution_state' => 'queued', 'meta' => $meta]);
    }

    public function attention(?int $id, string $reason, bool $pause = false): void
    {
        if (! $id || ! ($log = AiLog::find($id)) || ! $log->conversation_id) {
            return;
        }
        DB::transaction(function () use ($log, $reason, $pause) {
            $conversation = Conversation::with('customer')->whereKey($log->conversation_id)->lockForUpdate()->first();
            if (! $conversation || ! app(AiInteractionPolicy::class)->canHandle($conversation)
                || (int) $conversation->ai_control_revision !== (int) data_get($log->meta, 'control_revision', -1)) {
                return;
            }
            $changes = ['ai_needs_attention' => true, 'ai_handoff_reason' => $reason, 'unread_count' => max(1, $conversation->unread_count)];
            if ($pause) {
                $changes['ai_enabled'] = false;
                $changes['status'] = 'escalated';
            }
            $conversation->update($changes);
        });
    }

    public function describe(AiLog $log): array
    {
        $out = $log->outgoingMessage;
        $state = $out?->delivery_status ?? $log->execution_state ?? (data_get($log->meta, 'mode') === 'simulator' ? 'simulation' : 'legacy');
        if ($out?->read_at) {
            $state = 'read';
        } elseif ($out?->delivered_at) {
            $state = 'delivered';
        } elseif ($out?->sent_at || $out?->wa_message_id) {
            $state = 'sent';
        }
        $decisionReason = $log->reason_code ?? data_get($log->meta, 'routing.reason_code', $log->fallback_reason);
        $reason = $out?->failure_code ?: data_get($log->meta, 'execution_reason', $decisionReason);
        if (in_array($state, ['sent', 'delivered', 'read'], true)) {
            $reason = $log->reason_code ?? data_get($log->meta, 'routing.reason_code');
        }
        if ($state === 'sending' && ($out->sending_started_at ?? $out->updated_at)->lt(now()->subMinutes(10))) {
            $state = 'unknown';
            $reason = 'worker_interrupted';
        }

        return ['domain' => $log->domain ?? data_get($log->meta, 'routing.domain'),
            'domain_label' => AiDomain::LABELS[$log->domain ?? data_get($log->meta, 'routing.domain', 'general')] ?? 'Belum dipilih',
            'intent' => $log->intent ?? data_get($log->meta, 'routing.intent'), 'state' => $state,
            'state_label' => self::STATES[$state] ?? 'Perlu diperiksa', 'reason' => $reason,
            'reason_label' => self::REASONS[$reason ?? ''] ?? 'Lihat kode pemeriksaan',
            'decision_reason' => $decisionReason,
            'decision_label' => self::REASONS[$decisionReason ?? ''] ?? 'Periksa keputusan pada percakapan asal'];
    }
}
