<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table) {
            $table->string('portal_token_hash', 64)->nullable()->unique();
            $table->timestamp('portal_expires_at')->nullable();
            $table->unsignedInteger('portal_version')->default(0);
            $table->json('customer_snapshot')->nullable();
            $table->json('payment_terms')->nullable();
            $table->timestamp('dp_due_at')->nullable();
            $table->timestamp('balance_due_at')->nullable();
        });
        Schema::table('rental_payments', fn (Blueprint $table) => $table->string('party_name', 150)->nullable());
        Schema::create('rental_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_order_id')->constrained()->restrictOnDelete();
            $table->uuid('entry_key')->unique();
            $table->string('kind', 20);
            $table->unsignedBigInteger('amount');
            $table->string('payer_name', 150);
            $table->date('payment_date');
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('file_path');
            $table->string('file_mime', 80);
            $table->string('file_hash', 64);
            $table->unsignedInteger('file_size');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('rental_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('rental_extension_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_order_id')->constrained()->restrictOnDelete();
            $table->uuid('entry_key')->unique();
            $table->date('new_end_date');
            $table->unsignedBigInteger('quoted_amount');
            $table->text('notes');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('rental_extension_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_extension_requests');
        Schema::dropIfExists('rental_payment_proofs');
        Schema::table('rental_payments', fn (Blueprint $table) => $table->dropColumn('party_name'));
        Schema::table('rental_orders', function (Blueprint $table) {
            $table->dropUnique(['portal_token_hash']);
            $table->dropColumn(['portal_token_hash', 'portal_expires_at', 'portal_version', 'customer_snapshot', 'payment_terms', 'dp_due_at', 'balance_due_at']);
        });
    }
};
