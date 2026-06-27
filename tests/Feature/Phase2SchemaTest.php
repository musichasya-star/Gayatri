<?php

namespace Tests\Feature;

use App\Models\AiPersona;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\Service;
use App\Models\Therapist;
use App\Models\WhatsAppSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase2SchemaTest extends TestCase
{
    public function test_phase_2_tables_exist_after_migrate_fresh_seed(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true]);

        foreach ([
            'branches',
            'services',
            'therapists',
            'customers',
            'whatsapp_sessions',
            'conversations',
            'messages',
            'bookings',
            'reminders',
            'followups',
            'promos',
            'campaigns',
            'campaign_recipients',
            'ai_personas',
            'knowledge_bases',
            'knowledge_chunks',
            'ai_logs',
            'feedback',
            'audit_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [$table] to exist.");
        }
    }

    public function test_phase_2_demo_seed_creates_connected_crm_data(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true]);

        $branch = Branch::where('code', 'GAY-JKT')->first();
        $service = Service::where('name', 'Baby Spa Premium')->first();
        $therapist = Therapist::where('name', 'Terapis Gayatri')->first();
        $customer = Customer::where('whatsapp_number', '6281234567890')->first();
        $session = WhatsAppSession::where('session_name', 'gayatri-main')->first();
        $conversation = Conversation::where('wa_chat_id', '6281234567890@c.us')->first();
        $booking = Booking::where('booking_code', 'BK-GAY-0001')->first();
        $persona = AiPersona::where('slug', 'gayatri-default')->first();
        $knowledgeBase = KnowledgeBase::where('slug', 'layanan-dan-jadwal-dasar')->first();

        $this->assertNotNull($branch);
        $this->assertNotNull($service);
        $this->assertNotNull($therapist);
        $this->assertNotNull($customer);
        $this->assertNotNull($session);
        $this->assertNotNull($conversation);
        $this->assertNotNull($booking);
        $this->assertNotNull($persona);
        $this->assertNotNull($knowledgeBase);

        $this->assertSame($customer->id, $conversation->customer_id);
        $this->assertSame($conversation->id, $booking->conversation_id);
        $this->assertTrue($conversation->ai_enabled);
    }
}
