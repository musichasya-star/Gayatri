<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->unsignedInteger('quota')->nullable()->after('value');
            $table->unsignedInteger('used_count')->default(0)->after('quota');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('promo_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $table->decimal('promo_discount', 12, 2)->default(0)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_id');
            $table->dropColumn('promo_discount');
        });

        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn(['quota', 'used_count']);
        });
    }
};
