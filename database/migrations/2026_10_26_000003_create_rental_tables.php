<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name', 150);
            $table->string('category', 100);
            $table->text('description')->nullable();
            $table->text('components')->nullable();
            $table->text('terms')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('daily_rate', 14, 2);
            $table->decimal('weekly_rate', 14, 2)->nullable();
            $table->decimal('monthly_rate', 14, 2)->nullable();
            $table->decimal('deposit', 14, 2)->default(0);
            $table->unsignedSmallInteger('turnaround_days')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('rental_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_product_id')->constrained()->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->string('serial_number', 100)->nullable();
            $table->string('status', 20)->default('ready')->index();
            $table->text('condition_notes')->nullable();
            $table->timestamp('last_inspected_at')->nullable();
            $table->timestamps();
        });
        Schema::create('rental_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->uuid('quote_id')->unique();
            $table->string('request_session_hash', 64);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('admin');
            $table->string('status', 20)->default('pending')->index();
            $table->date('start_date')->index();
            $table->date('end_date');
            $table->string('period', 10);
            $table->unsignedInteger('period_count');
            $table->timestamp('hold_expires_at')->nullable();
            $table->string('fulfilment', 20)->default('self');
            $table->text('address')->nullable();
            $table->string('landmark', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->decimal('rental_subtotal', 14, 2);
            $table->decimal('deposit_total', 14, 2);
            $table->decimal('delivery_fee', 14, 2)->default(0);
            $table->decimal('collection_fee', 14, 2)->default(0);
            $table->json('pricing_snapshot');
            $table->text('notes')->nullable();
            $table->text('dispatch_notes')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('rental_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_unit_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->string('unit_code', 60);
            $table->text('components')->nullable();
            $table->text('checkout_condition')->nullable();
            $table->decimal('rental_price', 14, 2);
            $table->decimal('deposit', 14, 2);
            $table->date('reserved_until')->index();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_notes')->nullable();
            $table->string('return_photo_path')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->text('inspection_notes')->nullable();
            $table->decimal('late_fee', 14, 2)->default(0);
            $table->decimal('damage_fee', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['rental_order_id', 'rental_unit_id']);
        });
        Schema::create('rental_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10);
            $table->decimal('amount', 14, 2);
            $table->string('method', 30);
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['rental_payments', 'rental_items', 'rental_orders', 'rental_units', 'rental_products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
