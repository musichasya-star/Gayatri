<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('service_mode', 20)->default('clinic');
            $table->text('visit_address')->nullable();
            $table->string('visit_landmark', 500)->nullable();
            $table->decimal('visit_latitude', 10, 7)->nullable();
            $table->decimal('visit_longitude', 11, 7)->nullable();
            $table->decimal('travel_fee', 14, 2)->default(0);
            $table->decimal('service_price_snapshot', 14, 2)->nullable();
            $table->json('home_service_quote')->nullable();
            $table->dateTime('busy_from')->nullable()->index();
            $table->dateTime('busy_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['busy_from']);
            $table->dropColumn(['service_mode', 'visit_address', 'visit_landmark', 'visit_latitude', 'visit_longitude', 'travel_fee', 'service_price_snapshot', 'home_service_quote', 'busy_from', 'busy_until']);
        });
    }
};
