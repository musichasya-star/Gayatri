<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', fn (Blueprint $table) => $table->boolean('is_demo')->default(false)->index());
        Schema::table('ai_conversation_contexts', function (Blueprint $table) {
            $table->text('lookup_payload')->nullable();
            $table->timestamp('lookup_expires_at')->nullable();
        });
        Schema::create('whatsapp_identities', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->foreignId('whatsapp_session_id')->constrained()->cascadeOnDelete();
            $table->string('jid', 100);
            $table->string('phone', 20);
            $table->string('source', 40);
            $table->string('status', 20)->default('verified');
            $table->foreignId('last_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['provider', 'whatsapp_session_id', 'jid'], 'wa_identity_scope_unique');
        });
        Schema::create('ai_lookup_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('scope_hash', 64)->index();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('result', 40);
            $table->timestamp('created_at')->index();
        });
        Schema::create('ai_lookup_limits', function (Blueprint $table) {
            $table->string('scope_hash', 64)->primary();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('window_started_at');
        });
        Schema::create('ai_demo_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 20)->unique();
            $table->json('examples');
            $table->timestamps();
        });
        DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_demo_profiles');
        Schema::dropIfExists('ai_lookup_limits');
        Schema::dropIfExists('ai_lookup_attempts');
        Schema::dropIfExists('whatsapp_identities');
        Schema::table('ai_conversation_contexts', fn (Blueprint $table) => $table->dropColumn(['lookup_payload', 'lookup_expires_at']));
        Schema::table('knowledge_bases', fn (Blueprint $table) => $table->dropColumn('is_demo'));
    }
};
