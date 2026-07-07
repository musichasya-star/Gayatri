<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\CustomerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FollowupRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_page_shows_inactive_customer_and_generates_followup(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $customer = $this->customer('628123451001', now()->subDays(45));

        $this->actingAs($sales)
            ->get(route('admin.retention', ['days' => 30]))
            ->assertOk()
            ->assertSee('Customer Lama')
            ->assertSee($customer->name);

        $this->actingAs($sales)
            ->post(route('admin.retention.generate-followups'), ['days' => 30])
            ->assertRedirect();

        $this->assertDatabaseHas('followups', [
            'customer_id' => $customer->id,
            'assigned_user_id' => $sales->id,
            'status' => 'open',
        ]);
    }

    public function test_retention_index_supports_search_and_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        foreach (range(1, 16) as $index) {
            $this->customer("6281234512{$index}", now()->subDays(45));
        }

        $this->actingAs($admin)
            ->get(route('admin.retention', ['days' => 30]))
            ->assertOk()
            ->assertSee('Menampilkan')
            ->assertSee('Berikutnya');

        $this->actingAs($admin)
            ->get(route('admin.retention', ['days' => 30, 'q' => 'Bunda Retention 62812345121']))
            ->assertOk()
            ->assertSee('Bunda Retention 62812345121')
            ->assertDontSee('Bunda Retention 62812345122');
    }

    public function test_retention_index_supports_tag_filter_exact_match(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $spaCustomer = $this->customer('628123451401', now()->subDays(45), ['baby-spa']);
        $vipCustomer = $this->customer('628123451402', now()->subDays(45), ['vip']);
        $vipsCustomer = $this->customer('628123451403', now()->subDays(45), ['vips']);

        $this->actingAs($admin)
            ->get(route('admin.retention', ['days' => 30, 'tag' => 'vip']))
            ->assertOk()
            ->assertSee($vipCustomer->name)
            ->assertDontSee($spaCustomer->name)
            ->assertDontSee($vipsCustomer->name);
    }

    public function test_retention_index_chain_tag_search_and_followup_status_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $matched = $this->customer('628123451600', now()->subDays(45), ['vip', 'baby-spa']);
        $tagMatchButNoActive = $this->customer('628123451601', now()->subDays(45), ['vip']);
        $tagMatchWithCompleted = $this->customer('628123451602', now()->subDays(45), ['vip']);
        $nonMatch = $this->customer('628123451603', now()->subDays(45), ['vip']);

        Followup::create([
            'customer_id' => $matched->id,
            'assigned_user_id' => $admin->id,
            'title' => 'Retention 30 Hari',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ]);

        Followup::create([
            'customer_id' => $tagMatchWithCompleted->id,
            'assigned_user_id' => $admin->id,
            'title' => 'Retention 30 Hari',
            'status' => 'completed',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.retention', [
                'days' => 30,
                'q' => 'Bunda Retention 6281234516',
                'tag' => 'vip',
                'followup_status' => 'active',
            ]));

        $response->assertOk()
            ->assertSee($matched->name)
            ->assertDontSee($tagMatchButNoActive->name)
            ->assertDontSee($tagMatchWithCompleted->name)
            ->assertDontSee($nonMatch->name);
    }

    public function test_retention_index_supports_followup_status_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $withOpenFollowup = $this->customer('628123451300', now()->subDays(45));
        $withSentFollowup = $this->customer('628123451301', now()->subDays(45));
        $withCompletedFollowup = $this->customer('628123451302', now()->subDays(45));
        $withoutFollowup = $this->customer('628123451303', now()->subDays(45));

        Followup::create([
            'customer_id' => $withOpenFollowup->id,
            'assigned_user_id' => $admin->id,
            'title' => 'Retention 30 Hari',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ]);

        Followup::create([
            'customer_id' => $withSentFollowup->id,
            'assigned_user_id' => $admin->id,
            'title' => 'Retention 30 Hari',
            'status' => 'sent',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ]);

        Followup::create([
            'customer_id' => $withCompletedFollowup->id,
            'assigned_user_id' => $admin->id,
            'title' => 'Retention 30 Hari',
            'status' => 'completed',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.retention', ['days' => 30, 'followup_status' => 'active']))
            ->assertOk()
            ->assertSee($withOpenFollowup->name)
            ->assertSee($withSentFollowup->name)
            ->assertDontSee($withCompletedFollowup->name)
            ->assertDontSee($withoutFollowup->name);

        $this->actingAs($admin)
            ->get(route('admin.retention', ['days' => 30, 'followup_status' => 'none']))
            ->assertOk()
            ->assertSee($withoutFollowup->name)
            ->assertDontSee($withOpenFollowup->name)
            ->assertDontSee($withSentFollowup->name)
            ->assertDontSee($withCompletedFollowup->name);

        $this->actingAs($admin)
            ->get(route('admin.retention', ['days' => 30, 'followup_status' => 'completed']))
            ->assertOk()
            ->assertSee($withCompletedFollowup->name)
            ->assertDontSee($withoutFollowup->name)
            ->assertDontSee($withOpenFollowup->name)
            ->assertDontSee($withSentFollowup->name);
    }

    public function test_retention_page_buat_followup_button_points_to_prefill_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $customer = $this->customer('628123451500', now()->subDays(45), ['baby-spa']);

        $response = $this->actingAs($admin)
            ->get(route('admin.retention', ['days' => 30]));

        $response
            ->assertOk()
            ->assertSee(route('admin.followups.create', ['customer_id' => $customer->id]), false)
            ->assertSee('Buat Follow-Up');

        $this->actingAs($admin)
            ->get(route('admin.followups.create', ['customer_id' => $customer->id, 'title' => 'Retention 30 Hari', 'status' => 'open', 'priority' => 'normal']))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee('Retention 30 Hari')
            ->assertSee('open')
            ->assertSee('normal');
    }

    public function test_retention_generate_skips_active_followup(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $customer = $this->customer('628123451100', now()->subDays(45));

        Followup::create([
            'customer_id' => $customer->id,
            'assigned_user_id' => $sales->id,
            'title' => 'Retention 30 Hari',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($sales)
            ->post(route('admin.retention.generate-followups'), ['days' => 30])
            ->assertRedirect();

        $this->assertSame(1, Followup::query()->where('customer_id', $customer->id)->count());
    }

    public function test_retention_quick_followup_prefills_customer_and_title(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $customer = $this->customer('628123451200', now()->subDays(75));

        $this->actingAs($admin)
            ->get(route('admin.followups.create', ['customer_id' => $customer->id, 'title' => 'Retention 30 Hari', 'status' => 'open', 'priority' => 'normal']))
            ->assertOk()
            ->assertSee('Bunda Retention 628123451200')
            ->assertSee('Retention 30 Hari');
    }

    public function test_followup_can_be_sent_and_completed(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'followup-msg-001'], 200)]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $customer = $this->customer('628123451002', now()->subDays(60));
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);
        $followup = Followup::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $admin->id,
            'title' => 'Retention 60 Hari',
            'notes' => 'Halo Bunda, mau kami bantu cek jadwal treatment lanjutan?',
            'status' => 'open',
            'priority' => 'high',
            'due_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.followups.send', $followup))
            ->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'wa_message_id' => 'followup-msg-001',
            'sender_type' => 'admin',
        ]);
        $this->assertSame('sent', $followup->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.followups.complete', $followup), ['result' => 'Customer tertarik booking minggu depan.'])
            ->assertRedirect();

        $this->assertSame('completed', $followup->fresh()->status);
        $this->assertNotNull($followup->fresh()->completed_at);
    }

    public function test_followup_menu_supports_create_detail_edit_and_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        $customer = $this->customer('628123451003', now()->subDays(10));
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.followups.index'))
            ->assertOk()
            ->assertSee('Buat Follow-Up')
            ->assertSee('Sebelumnya')
            ->assertSee('Berikutnya')
            ->assertDontSee('<svg', false);

        $this->actingAs($admin)
            ->get(route('admin.followups.create'))
            ->assertOk()
            ->assertSee('Buat Follow-Up')
            ->assertSee($customer->name);

        $response = $this->actingAs($admin)
            ->post(route('admin.followups.store'), [
                'customer_id' => $customer->id,
                'conversation_id' => $conversation->id,
                'assigned_user_id' => $sales->id,
                'title' => 'Follow-up manual test',
                'notes' => 'Halo Bunda, mau kami bantu cek jadwal?',
                'status' => 'open',
                'priority' => 'high',
                'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ]);

        $followup = Followup::where('title', 'Follow-up manual test')->firstOrFail();
        $response->assertRedirect(route('admin.followups.show', $followup));

        $this->actingAs($admin)
            ->get(route('admin.followups.show', $followup))
            ->assertOk()
            ->assertSee('Detail Follow-Up')
            ->assertSee('Kirim WA')
            ->assertSee('Tandai Complete')
            ->assertSee($customer->whatsapp_number);

        $this->actingAs($admin)
            ->put(route('admin.followups.update', $followup), [
                'customer_id' => $customer->id,
                'conversation_id' => $conversation->id,
                'assigned_user_id' => $admin->id,
                'title' => 'Follow-up manual updated',
                'notes' => 'Catatan updated',
                'status' => 'sent',
                'priority' => 'normal',
                'due_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.followups.show', $followup));

        $followup->refresh();
        $this->assertSame('Follow-up manual updated', $followup->title);
        $this->assertSame('sent', $followup->status);
        $this->assertSame('normal', $followup->priority);
        $this->assertSame($admin->id, $followup->assigned_user_id);
    }

    private function customer(string $phone, $lastBookingAt, array $tags = ['baby-spa']): Customer
    {
        return Customer::create([
            'name' => 'Bunda Retention '.$phone,
            'phone' => $phone,
            'whatsapp_number' => $phone,
            'status' => CustomerStatus::ACTIVE,
            'tags' => $tags,
            'last_booking_at' => $lastBookingAt,
        ]);
    }
}
