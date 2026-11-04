<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('pickup_fee')->default(0);
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->json('transport_pricing')->nullable();
        });
        Schema::create('laundry_couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('laundry_transport_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('entry_key')->unique();
            $table->string('input_hash', 64);
            $table->foreignId('laundry_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('laundry_courier_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('planned')->index();
            $table->json('customer_snapshot');
            $table->text('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('quote_snapshot');
            $table->dateTime('scheduled_start')->index();
            $table->dateTime('scheduled_end');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->string('receiver_name', 150)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->json('history');
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_demo')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('laundry_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('laundry_transport_task_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_key', 64)->unique();
            $table->string('kind', 30);
            $table->string('status', 20)->index();
            $table->json('context');
            $table->string('fingerprint', 64);
            $table->string('chat_id', 80)->nullable();
            $table->string('session_name', 100)->nullable();
            $table->text('content')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->string('wa_message_id')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_notifications');
        Schema::dropIfExists('laundry_transport_tasks');
        Schema::dropIfExists('laundry_couriers');
        Schema::table('laundry_orders', fn (Blueprint $table) => $table->dropColumn(['pickup_fee', 'delivery_fee', 'transport_pricing']));
    }
};
