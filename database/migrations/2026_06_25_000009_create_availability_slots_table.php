<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('therapist_id')->nullable()->constrained()->nullOnDelete();
            $table->date('slot_date')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('capacity')->default(1);
            $table->unsignedInteger('booked_count')->default(0);
            $table->string('status')->default('available')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['slot_date', 'start_time', 'status']);
            $table->unique(['branch_id', 'service_id', 'therapist_id', 'slot_date', 'start_time'], 'availability_unique_slot');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('availability_slot_id')->nullable()->after('promo_id')->constrained('availability_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('availability_slot_id');
        });

        Schema::dropIfExists('availability_slots');
    }
};
