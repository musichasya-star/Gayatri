<?php

namespace Tests\Feature;

use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\LandingPageSetting;
use App\Models\Message;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrmSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'Asia/Jakarta'));
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function customer(array $changes = []): Customer
    {
        return Customer::create(array_replace(['name' => 'Bunda Terverifikasi', 'whatsapp_number' => '6280000092000', 'email' => 'asli@example.test', 'baby_name' => 'Nama Asli', 'notes' => 'Riwayat CRM tetap utuh.', 'status' => 'lead'], $changes));
    }

    private function conversation(Customer $customer, ?User $assignee = null): Conversation
    {
        return Conversation::create(['customer_id' => $customer->id, 'wa_chat_id' => $customer->whatsapp_number.'@c.us', 'assigned_user_id' => $assignee?->id, 'status' => 'open', 'ai_enabled' => true, 'unread_count' => 0]);
    }

    private function publicData(Customer $customer): array
    {
        $service = Service::create(['name' => 'Layanan Uji', 'duration_minutes' => 60, 'price' => 100000, 'is_active' => true]);
        $slot = AvailabilitySlot::create(['service_id' => $service->id, 'slot_date' => '2026-11-01', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'capacity' => 2, 'booked_count' => 0, 'status' => 'available']);

        return ['name' => 'Nama Pemesan Belum Diverifikasi', 'whatsapp_number' => $customer->whatsapp_number, 'email' => 'baru@example.test', 'baby_name' => 'Nama Baru', 'service_id' => $service->id, 'availability_slot_id' => $slot->id, 'booking_date' => '2026-11-01', 'start_time' => '10:00', 'branch_id' => '', 'notes' => 'Catatan permintaan publik.'];
    }

    public function test_login_limits_failures_and_success_clears_identity_limit(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->travel(301)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_demo_credentials_and_demo_seed_are_blocked_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/login')->assertOk()->assertDontSee('Demo account')->assertDontSee('owner@gayatri.local');
        $this->expectException(\RuntimeException::class);
        (new DatabaseSeeder)->run();
    }

    public function test_reset_email_does_not_enumerate_accounts_and_only_notifies_active_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['status' => 'active']);
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $first = session('status');
        $this->post('/forgot-password', ['email' => 'absent@example.test'])->assertSessionHas('status', $first);
        Notification::assertSentTo($user, ResetPassword::class);
        $inactive = User::factory()->create(['status' => 'inactive']);
        $this->post('/forgot-password', ['email' => $inactive->email]);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
    }

    public function test_reset_token_is_single_use_and_revokes_old_dashboard_session(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = Password::createToken($user);
        $this->actingAs($user)->withSession(['auth_version' => 1]);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'StrongNewPassword2026', 'password_confirmation' => 'StrongNewPassword2026'];
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->assertSame(2, $user->fresh()->auth_version);
        $this->actingAs($user->fresh())->withSession(['auth_version' => 1])->get('/admin/dashboard')->assertRedirect('/login');
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
    }

    public function test_therapist_inbox_requires_assignment_and_matching_branch(): void
    {
        $user = User::factory()->create(['role' => 'therapist', 'status' => 'active']);
        $branch = Branch::create(['code' => 'A', 'name' => 'Cabang A']);
        Therapist::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'name' => 'Terapis A', 'status' => 'active']);
        $customer = $this->customer(['branch_id' => $branch->id]);
        $own = $this->conversation($customer, $user);
        $otherCustomer = $this->customer(['whatsapp_number' => '6280000092001', 'name' => 'PELANGGAN CABANG LAIN']);
        $foreign = $this->conversation($otherCustomer, $user);
        $this->actingAs($user)->get(route('admin.inbox', ['conversation' => $own->id]))->assertOk()->assertDontSee('PELANGGAN CABANG LAIN');
        foreach (['note', 'close', 'takeover', 'followup', 'reply'] as $action) {
            $this->post(route('admin.inbox.'.$action, $foreign), ['message' => 'Uji', 'internal_note' => 'Catatan'])->assertNotFound();
        }
        $this->get(route('admin.inbox', ['conversation' => $foreign->id]))->assertNotFound();
        $this->post(route('admin.inbox.delete-history', $own), ['confirmed' => 1, 'reason' => 'Tidak boleh oleh terapis'])->assertForbidden();
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('Estimasi Revenue');
    }

    public function test_history_deletion_requires_owner_or_manager_and_explicit_reason(): void
    {
        $conversation = $this->conversation($this->customer());
        $message = Message::create(['conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id, 'direction' => 'incoming', 'sender_type' => 'customer', 'message_type' => 'text', 'content' => 'UJI RAHASIA']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.inbox.delete-history', $conversation), ['confirmed' => 1, 'reason' => 'Alasan cukup panjang'])->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($owner)->post(route('admin.inbox.delete-history', $conversation))->assertSessionHasErrors(['confirmed', 'reason']);
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
        $this->post(route('admin.inbox.delete-history', $conversation), ['confirmed' => 1, 'reason' => 'History uji disetujui owner.'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_public_booking_preserves_verified_profile_and_stores_separate_request(): void
    {
        $customer = $this->customer();
        $before = $customer->fresh()->getAttributes();
        $this->post(route('landing.booking'), $this->publicData($customer))->assertSessionHasNoErrors();
        $this->assertSame($before, $customer->fresh()->getAttributes());
        $booking = Booking::latest('id')->firstOrFail();
        $this->assertSame('Nama Pemesan Belum Diverifikasi', $booking->public_request_data['name']);
        $this->assertNull($booking->contact_verified_at);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.bookings.verify-contact', $booking), ['reference' => 'Sudah dikonfirmasi melalui telepon.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($booking->fresh()->contact_verified_at);
        $this->assertSame('Bunda Terverifikasi', $customer->fresh()->name);
    }

    public function test_blacklisted_profile_cannot_be_reactivated_through_public_booking(): void
    {
        $customer = $this->customer(['status' => 'blacklisted']);
        $this->post(route('landing.booking'), $this->publicData($customer))->assertSessionHasErrors('booking');
        $this->assertSame('blacklisted', $customer->fresh()->status);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_unpublished_preview_is_private_and_dashboard_is_not_cacheable(): void
    {
        LandingPageSetting::where('slug', 'home')->update(['is_published' => false]);
        $this->get('/?preview=1')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get('/?preview=1')->assertOk();
        $response = $this->get('/admin/dashboard')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_customer_with_booking_cannot_be_permanently_deleted(): void
    {
        $customer = $this->customer();
        $service = Service::create(['name' => 'Uji', 'duration_minutes' => 60, 'price' => 1000, 'is_active' => true]);
        Booking::create(['customer_id' => $customer->id, 'service_id' => $service->id, 'booking_code' => 'CRM-PRESERVE', 'booking_date' => '2026-10-31', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'completed', 'payment_status' => 'paid']);
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($owner)->delete(route('admin.customers.destroy', $customer))->assertSessionHasErrors('customer');
        $this->assertDatabaseCount('bookings', 1);
        $this->post(route('admin.customers.archive', $customer))->assertSessionHasNoErrors();
        $this->assertSame('inactive', $customer->fresh()->status);
    }

    public function test_csv_import_preserves_duplicates_and_requires_owner_acknowledgement_to_update(): void
    {
        $customer = $this->customer(['status' => 'blacklisted']);
        $csv = "\xEF\xBB\xBFname,whatsapp_number,email,status,notes\nNama Impor,080000092000,impor@example.test,lead,Catatan impor\n";
        $file = fn () => UploadedFile::fake()->createWithContent('customers.csv', $csv);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.customers.import'), ['csv_file' => $file()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('Bunda Terverifikasi', $customer->fresh()->name);
        $this->post(route('admin.customers.import'), ['csv_file' => $file(), 'update_existing' => 1, 'confirmed_updates' => 1])->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $this->actingAs($owner)->post(route('admin.customers.import'), ['csv_file' => $file(), 'update_existing' => 1])->assertSessionHasErrors('confirmed_updates');
        $this->post(route('admin.customers.import'), ['csv_file' => $file(), 'update_existing' => 1, 'confirmed_updates' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Nama Impor', $customer->fresh()->name);
        $this->assertSame('blacklisted', $customer->fresh()->status);
        $this->assertStringContainsString('Riwayat CRM tetap utuh.', $customer->fresh()->notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.updated', 'auditable_id' => $customer->id]);
    }

    public function test_csv_invalid_rows_are_skipped_and_formula_cells_are_exported_as_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin);
        $csv = "name,whatsapp_number,email\nInvalid,6280000092002,not-an-email\n=SUM(1),6280000092003,valid@example.test\n";
        $this->post(route('admin.customers.import'), ['csv_file' => UploadedFile::fake()->createWithContent('customers.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('=SUM(1)', Customer::first()->name);
        $csv = $this->get(route('admin.customers.export'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        $this->assertStringContainsString("'=SUM(1)", $csv);
        $this->post(route('admin.customers.store'), ['name' => 'Invalid input', 'status' => 'lead', 'whatsapp_number' => ['6280000092004']])->assertSessionHasErrors('whatsapp_number');
    }

    public function test_repeated_public_submission_uses_same_booking_even_when_slot_is_full(): void
    {
        $customer = $this->customer();
        $data = $this->publicData($customer);
        AvailabilitySlot::findOrFail($data['availability_slot_id'])->update(['capacity' => 1]);
        foreach (range(1, 2) as $_) {
            $this->post(route('landing.booking'), $data)->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(1, AvailabilitySlot::findOrFail($data['availability_slot_id'])->booked_count);
        $this->get(route('landing.booking-page'))->assertOk()->assertDontSee('asli@example.test')->assertDontSee('Nama Asli');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_failed_public_booking_does_not_leave_an_orphan_customer(): void
    {
        $customer = $this->customer();
        $data = $this->publicData($customer);
        $data['whatsapp_number'] = '6280000092999';
        Service::findOrFail($data['service_id'])->update(['is_active' => false]);
        $this->post(route('landing.booking'), $data)->assertSessionHasErrors('booking');
        $this->assertDatabaseMissing('customers', ['whatsapp_number' => '6280000092999']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_owner_role_changes_revoke_the_previous_user_session(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $staff = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($owner)->put(route('admin.users.update', $staff), ['name' => $staff->name, 'email' => $staff->email, 'role' => 'sales', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame(2, $staff->fresh()->auth_version);
        $this->actingAs($staff->fresh())->withSession(['auth_version' => 1])->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_old_number_format_cannot_bypass_blacklist_or_create_duplicate_crm_profile(): void
    {
        $customer = $this->customer(['whatsapp_number' => '0800-0009-2000', 'status' => 'blacklisted']);
        $data = $this->publicData($customer);
        $data['whatsapp_number'] = '+62 800 0009 2000';
        $this->post(route('landing.booking'), $data)->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('bookings', 0);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.customers.store'), ['name' => 'Profil duplikat', 'status' => 'lead', 'whatsapp_number' => '6280000092000'])->assertSessionHasErrors('whatsapp_number');
        $this->assertSame('blacklisted', $customer->fresh()->status);
    }

    public function test_existing_baileys_lid_profile_can_be_edited_without_changing_its_identifier(): void
    {
        $customer = $this->customer(['whatsapp_number' => '1234567890123@lid']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->put(route('admin.customers.update', $customer), ['name' => 'Profil Inbox Diperiksa', 'status' => 'lead', 'whatsapp_number' => $customer->whatsapp_number, 'notes' => 'Nomor aktual belum diberikan pelanggan.'])->assertSessionHasNoErrors();
        $this->assertSame('1234567890123@lid', $customer->fresh()->whatsapp_number);
        $this->assertSame('Profil Inbox Diperiksa', $customer->fresh()->name);
    }
}
