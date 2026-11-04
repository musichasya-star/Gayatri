<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_greetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('birthday_type', 20);
            $table->unsignedSmallInteger('year');
            $table->date('birthday_on');
            $table->dateTime('scheduled_at');
            $table->string('status', 20)->default('queued');
            $table->string('session_name')->nullable();
            $table->string('chat_id')->nullable();
            $table->text('message');
            $table->string('wa_message_id')->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'birthday_type', 'year'], 'birthday_greetings_recipient_year_unique');
            $table->index(['birthday_on', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_greetings');
    }
};
