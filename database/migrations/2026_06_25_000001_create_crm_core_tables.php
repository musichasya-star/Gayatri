<?php

use App\Support\AiLogStatus;
use App\Support\BookingStatus;
use App\Support\CampaignStatus;
use App\Support\ConversationStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use App\Support\ReminderStatus;
use App\Support\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('timezone')->default('Asia/Jakarta');
            $table->string('status')->default(UserStatus::ACTIVE);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('therapists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('specialization')->nullable();
            $table->string('status')->default(UserStatus::ACTIVE);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable()->index();
            $table->string('whatsapp_number')->nullable()->unique();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('baby_name')->nullable();
            $table->date('baby_birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->json('tags')->nullable();
            $table->string('status')->default(CustomerStatus::LEAD);
            $table->text('notes')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamp('last_booking_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_name')->unique();
            $table->string('device_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('status')->default('disconnected');
            $table->longText('qr_code')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('wa_chat_id')->unique();
            $table->string('channel')->default('whatsapp');
            $table->string('status')->default(ConversationStatus::OPEN);
            $table->boolean('ai_enabled')->default(true);
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_incoming_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('therapist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('booking_code')->unique();
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('status')->default(BookingStatus::DRAFT);
            $table->string('payment_status')->default(PaymentStatus::UNPAID);
            $table->string('source')->default('manual');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['booking_date', 'start_time']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wa_message_id')->nullable()->unique();
            $table->string('direction')->default('incoming');
            $table->string('sender_type')->default('customer');
            $table->string('message_type')->default('text');
            $table->longText('content')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('h1');
            $table->string('channel')->default('whatsapp');
            $table->string('status')->default(ReminderStatus::PENDING);
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('code')->nullable()->unique();
            $table->string('type')->default('fixed');
            $table->decimal('value', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->longText('message_template');
            $table->string('status')->default(CampaignStatus::DRAFT)->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('audience_filters')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('wa_message_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_personas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->longText('prompt');
            $table->string('tone')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('type')->default('text');
            $table->string('source_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->longText('content')->nullable();
            $table->string('status')->default(UserStatus::ACTIVE);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->unsignedInteger('token_count')->default(0);
            $table->string('embedding_key')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_base_id', 'chunk_index']);
        });

        Schema::create('ai_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('ai_personas')->nullOnDelete();
            $table->foreignId('knowledge_base_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('prompt');
            $table->longText('response')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('status')->default(AiLogStatus::PENDING);
            $table->string('fallback_reason')->nullable();
            $table->json('sources')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('new');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('auditable');
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('ai_logs');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_bases');
        Schema::dropIfExists('ai_personas');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('promos');
        Schema::dropIfExists('followups');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('whatsapp_sessions');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('therapists');
        Schema::dropIfExists('services');
        Schema::dropIfExists('branches');
    }
};
