<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapists', function (Blueprint $table) {
            $table->decimal('fee_spa_percent', 5, 2)->nullable();
            $table->decimal('fee_lactation_percent', 5, 2)->nullable();
        });
        Schema::create('therapist_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('therapist_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('booking_code');
            $table->string('therapist_name');
            $table->string('service_name');
            $table->string('service_group', 30)->index();
            $table->date('service_date')->index();
            $table->unsignedBigInteger('base_cents');
            $table->unsignedSmallInteger('rate_bps');
            $table->unsignedBigInteger('fee_cents');
            $table->string('status', 20)->default('accrued')->index();
            $table->boolean('is_demo')->default(false)->index();
            $table->string('signature', 64);
            $table->json('snapshot');
            $table->string('void_reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_fees');
        Schema::table('therapists', fn (Blueprint $table) => $table->dropColumn(['fee_spa_percent', 'fee_lactation_percent']));
    }
};
