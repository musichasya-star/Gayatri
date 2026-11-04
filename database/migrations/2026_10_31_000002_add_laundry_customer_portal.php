<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_public_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('entry_key')->unique();
            $table->string('input_hash', 64);
            $table->string('name', 150);
            $table->string('whatsapp_number', 20);
            $table->string('fulfilment', 20);
            $table->boolean('delivery_required')->default(false);
            $table->dateTime('preferred_at')->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->json('items');
            $table->json('estimate');
            $table->json('outlet_snapshot');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('laundry_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('pickup_task_id')->nullable()->unique()->constrained('laundry_transport_tasks')->restrictOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_reference', 500)->nullable();
            $table->text('review_notes')->nullable();
            $table->string('portal_token_hash', 64)->nullable()->unique();
            $table->text('portal_token_secret')->nullable();
            $table->unsignedInteger('portal_version')->default(1);
            $table->dateTime('portal_expires_at');
            $table->dateTime('portal_revoked_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamps();
        });
        Schema::table('laundry_orders', function (Blueprint $table) {
            $table->string('approval_fingerprint', 64)->nullable();
        });
        Schema::create('laundry_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('laundry_public_request_id')->constrained()->restrictOnDelete();
            $table->uuid('entry_key')->unique();
            $table->string('input_hash', 64);
            $table->string('pricing_fingerprint', 64);
            $table->unsignedBigInteger('amount');
            $table->dateTime('paid_at');
            $table->string('payer_name', 150);
            $table->string('reference', 150);
            $table->text('notes')->nullable();
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('laundry_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_payment_proofs');
        Schema::dropIfExists('laundry_public_requests');
        Schema::table('laundry_orders', fn (Blueprint $table) => $table->dropColumn('approval_fingerprint'));
    }
};
