<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', fn (Blueprint $table) => $table->unsignedInteger('schedule_version')->default(1));
        Schema::table('rental_items', fn (Blueprint $table) => $table->date('rental_end_date')->nullable());
        DB::table('rental_items')->update(['rental_end_date' => DB::raw('(select end_date from rental_orders where rental_orders.id = rental_items.rental_order_id)')]);
        Schema::create('rental_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('entry_key')->unique();
            $table->date('old_end_date');
            $table->date('new_end_date');
            $table->unsignedInteger('schedule_version');
            $table->decimal('additional_charge', 14, 2);
            $table->json('pricing_snapshot');
            $table->text('notes');
            $table->timestamps();
        });
        Schema::create('rental_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('kind', 30);
            $table->string('event_key', 191)->unique();
            $table->string('status', 20)->default('queued')->index();
            $table->json('context')->nullable();
            $table->text('content')->nullable();
            $table->string('chat_id')->nullable();
            $table->string('session_name')->nullable();
            $table->string('wa_message_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_notifications');
        Schema::dropIfExists('rental_extensions');
        Schema::table('rental_items', fn (Blueprint $table) => $table->dropColumn('rental_end_date'));
        Schema::table('rental_orders', fn (Blueprint $table) => $table->dropColumn('schedule_version'));
    }
};
