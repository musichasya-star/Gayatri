<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversation_contexts', function (Blueprint $table) {
            // Domain defines which catalog the ID belongs to; this is never an authorization grant.
            $table->unsignedBigInteger('catalog_item_id')->nullable();
        });
        DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
    }

    public function down(): void
    {
        Schema::table('ai_conversation_contexts', fn (Blueprint $table) => $table->dropColumn('catalog_item_id'));
    }
};
