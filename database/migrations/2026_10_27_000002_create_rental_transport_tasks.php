<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_transport_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');
            $table->unsignedInteger('schedule_version');
            $table->unsignedInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->string('recipient_name', 150)->nullable();
            $table->text('completion_notes')->nullable();
            $table->string('proof_photo_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_notes')->nullable();
            // Receipts retain exact retries, including after another task mutation.
            $table->json('mutation_receipts');
            $table->timestamps();
            $table->unique(['rental_order_id', 'type']);
            $table->index(['assigned_user_id', 'status', 'scheduled_start'], 'rental_transport_staff_window');
            $table->index(['status', 'scheduled_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_transport_tasks');
    }
};
