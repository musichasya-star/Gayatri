<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiIncomingReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $customer = Customer::create(['name' => 'Penguji Ingress', 'whatsapp_number' => '6280000000861', 'status' => 'active']);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'WORKING']);
        $this->conversation = Conversation::create(['customer_id' => $customer->id, 'whatsapp_session_id' => $session->id, 'wa_chat_id' => '6280000000861@c.us', 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
    }

    private function receive(string $id = 'qa-e-incoming', bool $media = false): void
    {
        (new ProcessIncomingWhatsAppMessageJob(['session' => 'default', 'payload' => [
            'from' => '6280000000861@c.us', 'id' => $id, 'body' => 'Halo min', 'timestamp' => now()->timestamp, 'hasMedia' => $media,
        ]]))->handle();
    }

    public function test_duplicate_webhook_does_not_increment_unread_or_enqueue_twice(): void
    {
        $this->receive();
        $this->receive();
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame(1, $this->conversation->fresh()->unread_count);
        Queue::assertPushed(GenerateAiReplyJob::class, 1);
        Queue::assertNotPushed(SendWhatsAppMessageJob::class);
        Http::assertNothingSent();
    }

    public static function controlledStatuses(): array
    {
        return [['human_handled'], ['closed'], ['escalated']];
    }

    #[DataProvider('controlledStatuses')]
    public function test_new_incoming_does_not_reopen_a_conversation_paused_by_admin(string $status): void
    {
        $this->conversation->update(['status' => $status, 'ai_enabled' => false]);
        $revision = $this->conversation->ai_control_revision;
        $this->receive();
        $this->assertSame($status, $this->conversation->fresh()->status);
        $this->assertFalse($this->conversation->fresh()->ai_enabled);
        $this->assertSame($revision, $this->conversation->fresh()->ai_control_revision);
        Queue::assertPushed(GenerateAiReplyJob::class, function ($job) {
            app()->call([$job, 'handle']);

            return true;
        });
        $this->assertDatabaseCount('ai_logs', 0);
        Queue::assertNotPushed(SendWhatsAppMessageJob::class);
    }

    public function test_takeover_between_initial_read_and_message_storage_is_preserved(): void
    {
        $id = $this->conversation->id;
        Message::creating(function ($message) use ($id) {
            if ($message->wa_message_id === 'qa-e-incoming') {
                Conversation::findOrFail($id)->update(['status' => 'human_handled', 'ai_enabled' => false]);
            }
        });
        $this->receive();
        $this->assertSame('human_handled', $this->conversation->fresh()->status);
        $this->assertFalse($this->conversation->fresh()->ai_enabled);
        $this->assertSame(1, $this->conversation->fresh()->unread_count);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_unprocessed_media_pauses_ai_and_is_visible_for_admin_without_fake_understanding(): void
    {
        $this->receive(media: true);
        $this->assertDatabaseHas('conversations', ['id' => $this->conversation->id, 'status' => 'escalated', 'ai_enabled' => false,
            'ai_needs_attention' => true, 'ai_handoff_reason' => 'unsupported_media']);
        $this->assertDatabaseHas('messages', ['message_type' => 'media', 'direction' => 'incoming']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->receive('next-text');
        $this->assertSame('escalated', $this->conversation->fresh()->status);
    }
}
