<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table) {
            $table->json('terms_snapshot')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_acceptance_source', 20)->nullable();
            $table->decimal('cancellation_fee', 14, 2)->default(0);
            $table->json('cancellation_snapshot')->nullable();
        });
        Schema::table('rental_items', fn (Blueprint $table) => $table->json('late_fee_calculation')->nullable());
        Schema::create('rental_customer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_order_id')->constrained()->restrictOnDelete();
            $table->uuid('entry_key')->unique();
            $table->string('kind', 20);
            $table->text('reason');
            $table->json('estimate');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('approved_amount')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('rental_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->date('refund_date')->nullable();
            $table->string('refund_file_path')->nullable();
            $table->string('refund_file_mime', 80)->nullable();
            $table->string('refund_file_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_customer_requests');
        Schema::table('rental_items', fn (Blueprint $table) => $table->dropColumn('late_fee_calculation'));
        Schema::table('rental_orders', fn (Blueprint $table) => $table->dropColumn(['terms_snapshot', 'terms_accepted_at', 'terms_acceptance_source', 'cancellation_fee', 'cancellation_snapshot']));
    }
};
