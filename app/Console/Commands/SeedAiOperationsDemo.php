<?php

namespace App\Console\Commands;

use App\Models\AiLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedAiOperationsDemo extends Command
{
    protected $signature = 'ai:seed-operations-demo';

    protected $description = 'Create inactive local-only examples of failed, uncertain and held AI deliveries without dispatching anything';

    public function handle(): int
    {
        if (! app()->environment('local', 'baileys-local', 'testing')) {
            $this->error('Contoh monitoring hanya tersedia di lokal/pengujian.');

            return self::FAILURE;
        }
        DB::transaction(function () {
            $customer = Customer::firstOrCreate(['whatsapp_number' => '6280000000950'], ['name' => '[DEMO] Monitoring AI Paket D', 'status' => 'inactive']);
            if ($customer->name !== '[DEMO] Monitoring AI Paket D' || $customer->status !== 'inactive') {
                throw new \RuntimeException('Kontak contoh dipakai data lain. Tidak ada data yang diganti.');
            }
            $conversation = Conversation::firstOrCreate(['wa_chat_id' => 'demo-ai-operations@local'], ['customer_id' => $customer->id, 'status' => 'escalated', 'channel' => 'whatsapp',
                'ai_enabled' => false, 'ai_needs_attention' => true, 'ai_handoff_reason' => 'gateway_delivery_unknown', 'unread_count' => 1, 'last_message_at' => now()]);
            foreach (['failed' => ['http_409', 'Contoh: sesi gateway belum siap.'], 'unknown' => ['transport_unknown', 'Contoh: koneksi terputus, hasil kirim belum pasti.'], 'skipped' => ['human_takeover', 'Contoh: admin mengambil alih sebelum pesan dikirim.']] as $state => [$reason,$explanation]) {
                $source = Message::firstOrCreate(['wa_message_id' => 'DEMO-AI-D-IN-'.$state], ['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'direction' => 'incoming',
                    'sender_type' => 'customer', 'message_type' => 'text', 'content' => '[DEMO] Bagaimana kelengkapan alat rental?', 'sent_at' => now(), 'ai_reply_dispatched_at' => now()]);
                $out = Message::firstOrCreate(['conversation_id' => $conversation->id, 'direction' => 'outgoing', 'content' => '[DEMO '.$state.'] Periksa kelengkapan alat bersama petugas saat serah terima.'],
                    ['customer_id' => $customer->id, 'sender_type' => 'ai', 'message_type' => 'text', 'delivery_status' => $state, 'send_provider' => 'demo', 'send_chat_id' => 'demo-ai-operations@local',
                        'failure_code' => $reason, 'failed_reason' => $explanation, 'failed_at' => in_array($state, ['failed', 'unknown']) ? now() : null, 'send_attempts' => 0]);
                AiLog::firstOrCreate(['message_id' => $source->id], ['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'prompt' => '[Contoh monitoring lokal]',
                    'response' => 'Periksa kelengkapan alat bersama petugas saat serah terima.', 'status' => 'success', 'domain' => 'rental', 'intent' => 'faq', 'reason_code' => 'scoped_knowledge',
                    'execution_state' => $state, 'outgoing_message_id' => $out->id, 'sources' => [], 'meta' => ['mode' => 'operations_demo', 'ops_demo' => true, 'provider' => 'local', 'execution_reason' => $reason]]);
            }
        });
        $this->info('Tiga contoh monitoring dibuat. Kontak nonaktif, tujuan bukan JID WhatsApp, tanpa job/antrean/panggilan gateway.');

        return self::SUCCESS;
    }
}
