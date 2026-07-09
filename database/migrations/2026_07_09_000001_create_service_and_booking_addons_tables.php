<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('addon_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('addon_name')->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->decimal('price_adjustment', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['service_id', 'addon_service_id']);
        });

        Schema::create('booking_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('service_addon_id')->nullable()->constrained('service_addons')->nullOnDelete();
            $table->foreignId('addon_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_addons');
        Schema::dropIfExists('service_addons');
    }
};
