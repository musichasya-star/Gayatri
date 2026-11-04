<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->string('domain', 20)->nullable()->index();
            $table->string('intent', 40)->nullable()->index();
            $table->string('reason_code', 80)->nullable()->index();
            $table->string('execution_state', 24)->nullable()->index();
            $table->foreignId('outgoing_message_id')->nullable()->constrained('messages')->nullOnDelete();
        });
        Schema::table('ai_conversation_contexts', fn (Blueprint $table) => $table->unsignedTinyInteger('clarification_count')->default(0));
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('ai_needs_attention')->default(false)->index();
            $table->string('ai_handoff_reason', 80)->nullable();
        });
        DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
    }

    public function down(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outgoing_message_id');
            $table->dropColumn(['domain', 'intent', 'reason_code', 'execution_state']);
        });
        Schema::table('ai_conversation_contexts', fn (Blueprint $table) => $table->dropColumn('clarification_count'));
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn(['ai_needs_attention', 'ai_handoff_reason']));
    }
};
