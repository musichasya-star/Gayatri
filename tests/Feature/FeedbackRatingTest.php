<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Feedback;
use App\Models\Followup;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeedbackRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_booking_sends_feedback_request(): void
    {
        config()->set('waha.base_url', 'http://waha.test');
        Http::fake(['http://waha.test/api/sendText' => Http::response(['id' => 'wamid-feedback-request-001'], 200)]);

        [$admin, $customer, $branch, $service, $booking] = $this->seedFeedbackData();

        $this->actingAs($admin)
            ->put(route('admin.bookings.update', $booking), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
                'status' => BookingStatus::COMPLETED,
                'payment_status' => PaymentStatus::PAID,
            ])
            ->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseHas('feedback', [
            'customer_id' => $customer->id,
            'booking_id' => $booking->id,
            'status' => 'requested',
        ]);
        $this->assertDatabaseHas('messages', [
            'wa_message_id' => 'wamid-feedback-request-001',
            'sender_type' => 'system',
            'content' => 'Terima kasih Bunda sudah berkunjung dan treatment di Gayatri. Jika ada saran atau masukan, silakan hubungi kami melalui chat ini ya. Bunda juga boleh beri rating pengalaman hari ini dari 1-5.',
        ]);
    }

    public function test_incoming_rating_is_saved_and_complaint_escalates_followup(): void
    {
        [$admin, $customer, , , $booking] = $this->seedFeedbackData();
        Feedback::create([
            'customer_id' => $customer->id,
            'booking_id' => $booking->id,
            'status' => 'requested',
        ]);

        (new ProcessIncomingWhatsAppMessageJob([
            'session' => 'default',
            'payload' => [
                'from' => $customer->whatsapp_number.'@c.us',
                'id' => 'wamid-feedback-reply-001',
                'body' => '2 kecewa karena terlalu lama menunggu',
                'timestamp' => time(),
            ],
        ]))->handle();

        $this->assertDatabaseHas('feedback', [
            'customer_id' => $customer->id,
            'booking_id' => $booking->id,
            'rating' => 2,
            'status' => 'escalated',
        ]);
        $this->assertDatabaseHas('followups', [
            'customer_id' => $customer->id,
            'priority' => 'high',
            'status' => 'open',
        ]);
        $this->assertSame(1, Followup::count());
    }

    public function test_admin_can_view_and_mark_feedback_responded(): void
    {
        [$admin, $customer, , , $booking] = $this->seedFeedbackData();
        $feedback = Feedback::create([
            'customer_id' => $customer->id,
            'booking_id' => $booking->id,
            'rating' => 5,
            'message' => '5 sangat puas',
            'status' => 'received',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.feedback.index'))
            ->assertOk()
            ->assertSee('Feedback &amp; Rating', false)
            ->assertSee('5/5');

        $this->actingAs($admin)
            ->post(route('admin.feedback.respond', $feedback))
            ->assertRedirect();

        $this->assertDatabaseHas('feedback', [
            'id' => $feedback->id,
            'status' => 'responded',
        ]);
    }

    public function test_dashboard_shows_average_rating(): void
    {
        [$admin, $customer, , , $booking] = $this->seedFeedbackData();
        Feedback::create(['customer_id' => $customer->id, 'booking_id' => $booking->id, 'rating' => 4, 'status' => 'received']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Rating Rata-Rata')
            ->assertSee('4');
    }

    private function seedFeedbackData(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $branch = Branch::create(['name' => 'Gayatri Jakarta', 'code' => 'GJKT', 'status' => 'active']);
        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name' => 'Bunda Feedback',
            'phone' => '628155500001',
            'whatsapp_number' => '628155500001',
            'status' => CustomerStatus::ACTIVE,
        ]);
        $service = Service::create(['branch_id' => $branch->id, 'name' => 'Baby Spa Premium', 'duration_minutes' => 60, 'price' => 250000, 'is_active' => true]);
        $session = WhatsAppSession::create(['session_name' => 'default', 'status' => 'working']);
        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_session_id' => $session->id,
            'wa_chat_id' => $customer->whatsapp_number.'@c.us',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $booking = Booking::create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'conversation_id' => $conversation->id,
            'created_by' => $admin->id,
            'booking_code' => 'BK-FEEDBACK-001',
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => BookingStatus::CONFIRMED,
            'payment_status' => PaymentStatus::UNPAID,
            'source' => 'manual',
        ]);

        return [$admin, $customer, $branch, $service, $booking];
    }
}
