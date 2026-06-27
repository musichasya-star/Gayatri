<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\ConversationStatus;
use App\Support\CustomerStatus;
use App\Support\UserRole;
use App\Support\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboxManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('waha.base_url', 'http://waha.test');
    }

    public function test_admin_can_view_inbox_and_selected_conversation_marks_unread_as_read(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        [$conversation] = $this->seedConversationThread();

        $this->actingAs($admin)
            ->get(route('admin.inbox', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Percakapan Customer')
            ->assertSee('Bunda Sinta')
            ->assertSee('Halo admin, ada slot besok?');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'unread_count' => 0,
        ]);
    }

    public function test_admin_can_reply_takeover_followup_close_and_save_note_from_inbox(): void
    {
        Http::fake([
            'http://waha.test/api/sendText' => Http::response([
                'id' => 'wamid-inbox-reply-001',
            ], 200),
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        [$conversation, $customer] = $this->seedConversationThread();

        $this->actingAs($admin)
            ->post(route('admin.inbox.reply', $conversation), [
                'message' => 'Baik Bunda, kami cekkan jadwal sore ini ya.',
            ])
            ->assertRedirect(route('admin.inbox', ['conversation' => $conversation->id]));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outgoing',
            'sender_type' => 'admin',
            'content' => 'Baik Bunda, kami cekkan jadwal sore ini ya.',
        ]);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'status' => ConversationStatus::HUMAN_HANDLED,
            'ai_enabled' => 0,
            'assigned_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.inbox.followup', $conversation), [
                'notes' => 'Hubungi ulang jam 15.00 bila belum balas.',
            ])
            ->assertRedirect(route('admin.inbox', ['conversation' => $conversation->id]));

        $this->assertDatabaseHas('followups', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'assigned_user_id' => $admin->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'status' => ConversationStatus::NEED_FOLLOWUP,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.inbox.note', $conversation), [
                'internal_note' => 'Customer sensitif soal jam treatment siang.',
            ])
            ->assertRedirect(route('admin.inbox', ['conversation' => $conversation->id]));

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'internal_note' => 'Customer sensitif soal jam treatment siang.',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.inbox.close', $conversation))
            ->assertRedirect(route('admin.inbox', ['conversation' => $conversation->id]));

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'status' => ConversationStatus::CLOSED,
            'unread_count' => 0,
        ]);
    }

    public function test_inbox_limits_visible_messages_and_can_delete_chat_history(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);

        [$conversation, $customer] = $this->seedConversationThread();
        $conversation->messages()->delete();
        foreach (range(1, 70) as $index) {
            Message::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'wa_message_id' => 'wamid-history-'.$index,
                'direction' => $index % 2 === 0 ? 'outgoing' : 'incoming',
                'sender_type' => $index % 2 === 0 ? 'admin' : 'customer',
                'message_type' => 'text',
                'content' => 'Pesan history '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'sent_at' => now()->subMinutes(80 - $index),
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.inbox', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Menampilkan 60 pesan terbaru')
            ->assertSee('Pesan history 70')
            ->assertDontSee('Pesan history 01')
            ->assertSee('Hapus History');

        $this->actingAs($admin)
            ->post(route('admin.inbox.delete-history', $conversation))
            ->assertRedirect(route('admin.inbox', ['conversation' => $conversation->id]));

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'unread_count' => 0,
        ]);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Conversation::class,
            'auditable_id' => $conversation->id,
            'action' => 'conversation.history_deleted',
        ]);
    }

    private function seedConversationThread(): array
    {
        $customer = Customer::create([
            'name' => 'Bunda Sinta',
            'whatsapp_number' => '6281230000999',
            'phone' => '081230000999',
            'status' => CustomerStatus::ACTIVE,
            'city' => 'Jakarta',
        ]);

        $session = WhatsAppSession::create([
            'session_name' => 'default',
            'status' => 'working',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => '6281230000999@c.us',
            'status' => ConversationStatus::OPEN,
            'channel' => 'whatsapp',
            'ai_enabled' => true,
            'unread_count' => 2,
            'last_message_at' => now(),
            'last_incoming_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'wamid-incoming-01',
            'direction' => 'incoming',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'content' => 'Halo admin, ada slot besok?',
            'sent_at' => now()->subMinutes(5),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'wa_message_id' => 'wamid-ai-01',
            'direction' => 'outgoing',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'content' => 'Halo Bunda, kami bantu cek jadwal ya.',
            'sent_at' => now()->subMinutes(4),
        ]);

        return [$conversation, $customer];
    }
}
