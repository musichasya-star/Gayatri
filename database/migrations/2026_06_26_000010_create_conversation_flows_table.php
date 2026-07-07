<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('last_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('completed_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('intent')->index();
            $table->string('step')->index();
            $table->string('status')->default('active')->index();
            $table->json('payload')->nullable();
            $table->json('attempts')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_flows');
    }
};
