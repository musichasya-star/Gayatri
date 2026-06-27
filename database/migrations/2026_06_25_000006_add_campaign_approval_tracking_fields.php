<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('scheduled_at');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->timestamp('replied_at')->nullable()->after('read_at');
            $table->timestamp('booked_at')->nullable()->after('replied_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
            $table->dropColumn(['replied_at', 'booked_at']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
