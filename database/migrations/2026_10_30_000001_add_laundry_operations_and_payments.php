<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_orders', function (Blueprint $table) {
            $table->string('workflow_status', 30)->default('pending')->index();
            $table->json('process_steps')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('handed_over_at')->nullable();
            $table->string('receiver_name', 150)->nullable();
            $table->string('receiver_phone', 30)->nullable();
            $table->text('handover_notes')->nullable();
        });
        DB::table('laundry_orders')->where('status', 'cancelled')->update(['workflow_status' => 'cancelled']);
        Schema::create('laundry_quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('passed');
            $table->json('items');
            $table->text('notes')->nullable();
            $table->string('restart_stage', 30)->nullable();
            $table->unsignedInteger('revision');
            $table->timestamp('created_at');
        });
        Schema::create('laundry_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained()->restrictOnDelete();
            $table->uuid('entry_key')->unique();
            $table->string('input_hash', 64);
            $table->unsignedBigInteger('amount');
            $table->string('method', 20);
            $table->dateTime('paid_at');
            $table->string('payer_name', 150);
            $table->string('reference', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('laundry_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('laundry_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('number', 60)->unique();
            $table->json('snapshot');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at');
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_documents');
        Schema::dropIfExists('laundry_payments');
        Schema::dropIfExists('laundry_quality_checks');
        Schema::table('laundry_orders', function (Blueprint $table) {
            $table->dropIndex(['workflow_status']);
            $table->dropColumn(['workflow_status', 'process_steps', 'processing_started_at', 'ready_at', 'handed_over_at', 'receiver_name', 'receiver_phone', 'handover_notes']);
        });
    }
};
