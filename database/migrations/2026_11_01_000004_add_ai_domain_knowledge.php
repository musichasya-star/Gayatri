<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversation_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('domain', 24)->nullable();
            $table->string('menu', 24)->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('control_revision')->default(0);
            $table->foreignId('last_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->string('publication_status', 24)->default('needs_review')->index();
            $table->string('audience', 24)->default('customer_public');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
        });
        Schema::create('knowledge_base_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->string('domain', 24);
            $table->unique(['knowledge_base_id', 'domain']);
        });
        // Previously queued temporary replies must not survive a behavior change.
        DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_domains');
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['publication_status', 'audience', 'reviewed_by', 'reviewed_at', 'revision']);
        });
        Schema::dropIfExists('ai_conversation_contexts');
    }
};
