<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->string('audience', 20);
            $table->string('unit', 10);
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('minimum_milli');
            $table->unsignedInteger('increment_milli');
            $table->unsignedSmallInteger('duration_hours');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
        });
        Schema::create('laundry_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->uuid('entry_key')->unique();
            $table->string('input_hash', 64);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('customer_snapshot');
            $table->json('outlet_snapshot');
            $table->dateTime('received_at')->index();
            $table->dateTime('estimated_ready_at');
            $table->string('intake_mode', 20);
            $table->text('collection_address')->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedBigInteger('estimated_total')->nullable();
            $table->unsignedBigInteger('actual_total')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 30)->default('received')->index();
            $table->boolean('is_demo')->default(false)->index();
            $table->dateTime('approved_at')->nullable();
            $table->string('approved_name', 150)->nullable();
            $table->string('approval_channel', 30)->nullable();
            $table->string('approval_reference', 500)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('laundry_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('laundry_tariff_id')->constrained()->restrictOnDelete();
            $table->string('bag_code', 50)->unique();
            $table->string('bag_label', 100);
            $table->json('tariff_snapshot');
            $table->unsignedInteger('received_pieces');
            $table->unsignedInteger('estimated_milli')->nullable();
            $table->unsignedInteger('estimated_billable_milli')->nullable();
            $table->unsignedBigInteger('estimated_amount')->nullable();
            $table->text('condition_notes');
            $table->unsignedInteger('actual_pieces')->nullable();
            $table->unsignedInteger('actual_milli')->nullable();
            $table->unsignedInteger('actual_billable_milli')->nullable();
            $table->unsignedBigInteger('actual_amount')->nullable();
            $table->text('weighing_notes')->nullable();
            $table->timestamps();
        });
        Schema::create('laundry_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 30);
            $table->unsignedInteger('revision');
            $table->json('details');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_order_events');
        Schema::dropIfExists('laundry_order_items');
        Schema::dropIfExists('laundry_orders');
        Schema::dropIfExists('laundry_tariffs');
    }
};
