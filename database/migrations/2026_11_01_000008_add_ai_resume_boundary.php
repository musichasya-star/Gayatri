<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_reply_after_message_id')->default(0);
            $table->dateTime('ai_resumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn(['ai_reply_after_message_id', 'ai_resumed_at']));
    }
};
