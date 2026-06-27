<?php

namespace Database\Seeders;

use App\Models\AiLog;
use App\Models\AiPersona;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Message;
use App\Models\Promo;
use App\Models\Reminder;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Support\BookingStatus;
use App\Support\CampaignStatus;
use App\Support\ConversationStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use App\Support\ReminderStatus;
use App\Support\UserRole;
use App\Support\UserStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Owner Gayatri', 'email' => 'owner@gayatri.local', 'role' => UserRole::OWNER],
            ['name' => 'Manager Gayatri', 'email' => 'manager@gayatri.local', 'role' => UserRole::MANAGER],
            ['name' => 'Admin Gayatri', 'email' => 'admin@gayatri.local', 'role' => UserRole::ADMIN],
            ['name' => 'Sales Gayatri', 'email' => 'sales@gayatri.local', 'role' => UserRole::SALES],
            ['name' => 'Terapis Gayatri', 'email' => 'terapis@gayatri.local', 'role' => UserRole::THERAPIST],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'phone' => null,
                    'role' => $user['role'],
                    'status' => UserStatus::ACTIVE,
                    'password' => Hash::make('password'),
                ]
            );
        }

        $owner = User::where('email', 'owner@gayatri.local')->firstOrFail();
        $admin = User::where('email', 'admin@gayatri.local')->firstOrFail();
        $therapistUser = User::where('email', 'terapis@gayatri.local')->firstOrFail();

        $branch = Branch::updateOrCreate(
            ['code' => 'GAY-JKT'],
            [
                'name' => 'Gayatri Mom & Baby SPA Jakarta',
                'phone' => '0215550123',
                'address' => 'Jl. Mawar No. 10, Jakarta Selatan',
                'city' => 'Jakarta Selatan',
                'timezone' => 'Asia/Jakarta',
                'status' => UserStatus::ACTIVE,
            ]
        );

        $services = collect([
            [
                'name' => 'Baby Spa Premium',
                'category' => 'baby-spa',
                'duration_minutes' => 60,
                'price' => 250000,
                'description' => 'Perawatan relaksasi bayi dengan hydrotherapy dan pijat lembut.',
            ],
            [
                'name' => 'Mom Postnatal Massage',
                'category' => 'mom-care',
                'duration_minutes' => 90,
                'price' => 350000,
                'description' => 'Perawatan pijat ibu pasca melahirkan untuk relaksasi dan pemulihan.',
            ],
        ])->map(fn (array $service) => Service::updateOrCreate(
            ['branch_id' => $branch->id, 'name' => $service['name']],
            $service + ['branch_id' => $branch->id, 'is_active' => true]
        ));

        $therapist = Therapist::updateOrCreate(
            ['user_id' => $therapistUser->id],
            [
                'branch_id' => $branch->id,
                'name' => 'Terapis Gayatri',
                'phone' => '081200000001',
                'specialization' => 'Mom & Baby Relaxation',
                'status' => UserStatus::ACTIVE,
                'notes' => 'Terapis demo untuk testing booking dan schedule.',
            ]
        );

        $session = WhatsAppSession::updateOrCreate(
            ['session_name' => 'gayatri-main'],
            [
                'device_name' => 'Front Desk Device',
                'phone_number' => '628111111111',
                'status' => 'connected',
                'connected_at' => now()->subDay(),
                'last_seen_at' => now(),
                'meta' => ['source' => 'database-seeder'],
            ]
        );

        $customer = Customer::updateOrCreate(
            ['whatsapp_number' => '6281234567890'],
            [
                'branch_id' => $branch->id,
                'name' => 'Bunda Alya',
                'phone' => '081234567890',
                'email' => 'alya@example.test',
                'baby_name' => 'Aira',
                'city' => 'Jakarta Selatan',
                'tags' => ['new-lead', 'baby-spa'],
                'status' => CustomerStatus::ACTIVE,
                'notes' => 'Customer demo untuk inbox, booking, dan AI.',
                'last_interaction_at' => now()->subHours(2),
                'last_booking_at' => now()->subDays(7),
            ]
        );

        $conversation = Conversation::updateOrCreate(
            ['wa_chat_id' => '6281234567890@c.us'],
            [
                'customer_id' => $customer->id,
                'whatsapp_session_id' => $session->id,
                'assigned_user_id' => $admin->id,
                'channel' => 'whatsapp',
                'status' => ConversationStatus::OPEN,
                'ai_enabled' => true,
                'unread_count' => 1,
                'last_message_at' => now()->subMinutes(5),
                'last_incoming_at' => now()->subMinutes(5),
            ]
        );

        Message::updateOrCreate(
            ['wa_message_id' => 'wamid-demo-incoming-001'],
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'incoming',
                'sender_type' => 'customer',
                'message_type' => 'text',
                'content' => 'Halo admin, saya mau tanya jadwal baby spa untuk besok sore.',
                'payload' => ['seeded' => true],
                'sent_at' => now()->subMinutes(5),
            ]
        );

        Message::updateOrCreate(
            ['wa_message_id' => 'wamid-demo-outgoing-001'],
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'user_id' => $admin->id,
                'direction' => 'outgoing',
                'sender_type' => 'admin',
                'message_type' => 'text',
                'content' => 'Tentu Bunda, kami tersedia besok jam 15.00 dan 16.30.',
                'payload' => ['seeded' => true],
                'sent_at' => now()->subMinutes(3),
            ]
        );

        $booking = Booking::updateOrCreate(
            ['booking_code' => 'BK-GAY-0001'],
            [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'service_id' => $services->first()->id,
                'therapist_id' => $therapist->id,
                'conversation_id' => $conversation->id,
                'created_by' => $admin->id,
                'booking_date' => now()->addDay()->toDateString(),
                'start_time' => '15:00:00',
                'end_time' => '16:00:00',
                'status' => BookingStatus::CONFIRMED,
                'payment_status' => PaymentStatus::UNPAID,
                'source' => 'whatsapp',
                'notes' => 'Booking demo hasil follow-up chat WhatsApp.',
            ]
        );

        Reminder::updateOrCreate(
            ['customer_id' => $customer->id, 'booking_id' => $booking->id, 'type' => 'h1'],
            [
                'conversation_id' => $conversation->id,
                'channel' => 'whatsapp',
                'status' => ReminderStatus::SCHEDULED,
                'scheduled_at' => now()->addHours(12),
                'payload' => ['template' => 'booking-h1'],
            ]
        );

        Followup::updateOrCreate(
            ['customer_id' => $customer->id, 'title' => 'Follow-up pasca treatment'],
            [
                'conversation_id' => $conversation->id,
                'assigned_user_id' => $owner->id,
                'notes' => 'Hubungi customer 3 hari setelah treatment untuk tawarkan paket lanjutan.',
                'status' => 'open',
                'priority' => 'high',
                'due_at' => now()->addDays(4),
            ]
        );

        $promo = Promo::updateOrCreate(
            ['code' => 'BABY10'],
            [
                'title' => 'Promo Baby Spa 10%',
                'type' => 'percentage',
                'value' => 10,
                'description' => 'Diskon 10% untuk booking baby spa minggu ini.',
                'start_at' => now()->startOfDay(),
                'end_at' => now()->addWeek(),
                'is_active' => true,
            ]
        );

        $campaign = Campaign::updateOrCreate(
            ['name' => 'Winback Customer Juni'],
            [
                'promo_id' => $promo->id,
                'created_by' => $owner->id,
                'message_template' => 'Halo Bunda, ada promo spesial baby spa minggu ini. Mau kami bantu jadwalkan?',
                'status' => CampaignStatus::SCHEDULED,
                'scheduled_at' => now()->addDay(),
                'audience_filters' => ['segment' => 'inactive-30d'],
                'recipient_count' => 1,
                'sent_count' => 0,
                'failed_count' => 0,
            ]
        );

        CampaignRecipient::updateOrCreate(
            ['campaign_id' => $campaign->id, 'customer_id' => $customer->id],
            ['status' => 'pending']
        );

        $persona = AiPersona::updateOrCreate(
            ['slug' => 'gayatri-default'],
            [
                'name' => 'Gayatri Default Persona',
                'prompt' => 'Anda adalah admin WhatsApp Gayatri Mom & Baby SPA yang hangat, sopan, singkat, dan fokus pada layanan yang tersedia.',
                'tone' => 'warm-professional',
                'is_active' => true,
                'settings' => ['language' => 'id', 'fallback_style' => 'human-handoff'],
            ]
        );

        $knowledgeBase = KnowledgeBase::updateOrCreate(
            ['slug' => 'layanan-dan-jadwal-dasar'],
            [
                'title' => 'Layanan dan Jadwal Dasar',
                'type' => 'text',
                'content' => "Gayatri menyediakan baby spa, mom massage, dan konsultasi booking dasar.\nJam operasional 09.00-18.00.\nUntuk kondisi bayi yang sedang demam, sakit, atau memiliki keluhan kesehatan, sarankan Bunda berkonsultasi dengan dokter sebelum mengambil layanan relaksasi.",
                'status' => UserStatus::ACTIVE,
                'valid_from' => now()->startOfDay(),
                'meta' => ['seeded' => true],
            ]
        );

        KnowledgeChunk::updateOrCreate(
            ['knowledge_base_id' => $knowledgeBase->id, 'chunk_index' => 0],
            [
                'content' => 'Gayatri menyediakan baby spa, mom massage, dan operasional 09.00-18.00.',
                'token_count' => 12,
                'meta' => ['section' => 'overview'],
            ]
        );

        AiLog::updateOrCreate(
            ['conversation_id' => $conversation->id, 'message_id' => Message::where('wa_message_id', 'wamid-demo-incoming-001')->firstOrFail()->id],
            [
                'customer_id' => $customer->id,
                'persona_id' => $persona->id,
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt' => 'Customer menanyakan jadwal baby spa besok sore.',
                'response' => 'Kami tersedia besok jam 15.00 dan 16.30, Bunda.',
                'confidence' => 0.91,
                'status' => 'success',
                'sources' => ['layanan-dan-jadwal-dasar'],
                'meta' => ['seeded' => true],
            ]
        );
    }
}
