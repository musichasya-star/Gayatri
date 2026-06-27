<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('trigger_event')->index();
            $table->string('target_entity')->index();
            $table->string('action')->index();
            $table->string('mode')->default('need_confirmation');
            $table->decimal('confidence_threshold', 5, 2)->default(0.75);
            $table->json('required_fields')->nullable();
            $table->json('forbidden_intents')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('ai_extracted_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('intent')->index();
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->json('extracted_customer_data')->nullable();
            $table->json('extracted_booking_data')->nullable();
            $table->json('extracted_followup_data')->nullable();
            $table->json('missing_fields')->nullable();
            $table->json('raw_ai_response')->nullable();
            $table->string('status')->default('extracted')->index();
            $table->timestamps();
        });

        Schema::create('ai_automation_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_extracted_data_id')->constrained('ai_extracted_data')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_entity')->index();
            $table->string('action')->index();
            $table->string('mode')->default('need_confirmation');
            $table->json('proposed_data');
            $table->string('status')->default('pending')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('edited_data')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_automation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_extracted_data_id')->nullable()->constrained('ai_extracted_data')->nullOnDelete();
            $table->foreignId('ai_automation_approval_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_entity')->nullable()->index();
            $table->string('action')->nullable()->index();
            $table->string('mode')->nullable();
            $table->string('status')->index();
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('created_by_ai')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_automation_logs');
        Schema::dropIfExists('ai_automation_approvals');
        Schema::dropIfExists('ai_extracted_data');
        Schema::dropIfExists('ai_automation_rules');
    }
};
