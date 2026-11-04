<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('auth_version')->default(1);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('public_request_data')->nullable();
            $table->timestamp('contact_verified_at')->nullable();
            $table->foreignId('contact_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_verification_reference', 500)->nullable();
            $table->boolean('finance_managed')->default(false)->index();
            $table->unsignedInteger('finance_revision')->default(1);
            $table->unsignedBigInteger('legacy_credit_cents')->default(0);
            $table->string('legacy_payment_status', 20)->nullable();
            $table->timestamp('finance_opened_at')->nullable();
        });
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->uuid('entry_key')->unique();
            $table->string('input_hash', 64);
            $table->string('kind', 20)->default('payment');
            $table->unsignedBigInteger('amount_cents');
            $table->string('method', 20);
            $table->dateTime('paid_at')->index();
            $table->string('payer_name', 150);
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->string('receipt_number', 60)->unique();
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
        Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('contact_verified_by'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['public_request_data', 'contact_verified_at', 'contact_verification_reference', 'finance_managed', 'finance_revision', 'legacy_credit_cents', 'legacy_payment_status', 'finance_opened_at']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auth_version');
        });
    }
};
