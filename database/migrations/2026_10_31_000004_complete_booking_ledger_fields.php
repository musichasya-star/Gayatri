<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Supports the local database that received the first part of this deployment.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'legacy_credit_cents')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_credit_cents')->default(0);
                $table->string('legacy_payment_status', 20)->nullable();
                $table->timestamp('finance_opened_at')->nullable();
            });
        }
        if (Schema::hasColumn('booking_payments', 'amount') && ! Schema::hasColumn('booking_payments', 'amount_cents')) {
            Schema::table('booking_payments', fn (Blueprint $table) => $table->renameColumn('amount', 'amount_cents'));
        }
    }

    public function down(): void {}
};
