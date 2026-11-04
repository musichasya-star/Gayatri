<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('outbound_key', 64)->nullable()->unique();
            $table->string('delivery_status', 20)->nullable()->index();
            $table->string('send_provider', 20)->nullable();
            $table->string('send_session', 100)->nullable();
            $table->string('send_chat_id', 100)->nullable();
            $table->string('dispatch_token', 64)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->unsignedInteger('send_attempts')->default(0);
            $table->unsignedInteger('delivery_revision')->default(1);
            $table->string('failure_code', 40)->nullable();
        });
        Schema::create('whatsapp_retry_requests', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('previous_status', 20);
            $table->string('reason', 500);
            $table->timestamp('created_at');
            $table->index(['source', 'source_id']);
        });
        Schema::table('reminders', fn (Blueprint $table) => $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_retry_requests');
        Schema::table('reminders', fn (Blueprint $table) => $table->dropConstrainedForeignId('message_id'));
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['outbound_key']);
            $table->dropIndex(['delivery_status']);
            $table->dropColumn(['outbound_key', 'delivery_status', 'send_provider', 'send_session', 'send_chat_id', 'dispatch_token', 'queued_at', 'sending_started_at', 'send_attempts', 'delivery_revision', 'failure_code']);
        });
    }
};
