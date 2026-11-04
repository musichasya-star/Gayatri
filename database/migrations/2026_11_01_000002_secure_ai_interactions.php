<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interaction_policies', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('mode', 32);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
        });
        DB::table('ai_interaction_policies')->insert([
            'id' => 1, 'mode' => 'information_web', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('messages', function (Blueprint $table) {
            $table->json('ai_reply_context')->nullable();
            $table->timestamp('ai_reply_dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn(['ai_reply_context', 'ai_reply_dispatched_at']));
        Schema::dropIfExists('ai_interaction_policies');
    }
};
